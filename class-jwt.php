<?php
/**
 * Google OAuth2 JWT builder and Indexing API client.
 *
 * Builds a signed RS256 JWT, exchanges it for a Google access token,
 * and submits URLs to the Web Search Indexing API — no external libraries required.
 *
 * @package dc-google-indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DC_GI_JWT {

	// -------------------------------------------------------------------------
	// JWT helpers
	// -------------------------------------------------------------------------

	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Build and sign an RS256 JWT for Google OAuth2.
	 *
	 * @param  array           $sa     Decoded service account JSON.
	 * @param  string          $scope  OAuth2 scope (space-separated for multiple).
	 * @return string|WP_Error         Signed JWT or WP_Error.
	 */
	private static function build_jwt( array $sa, string $scope = 'https://www.googleapis.com/auth/indexing' ) {
		$now = time();

		$header  = self::base64url_encode(
			(string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] )
		);
		$payload = self::base64url_encode(
			(string) wp_json_encode( [
				'iss'   => $sa['client_email'],
				'scope' => $scope,
				'aud'   => 'https://oauth2.googleapis.com/token',
				'iat'   => $now,
				'exp'   => $now + 3600,
			] )
		);

		$signing_input = $header . '.' . $payload;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$private_key = @openssl_pkey_get_private( $sa['private_key'] );
		if ( false === $private_key ) {
			return new WP_Error(
				'dc_gi_invalid_key',
				__( 'Could not parse the private key in the service account JSON. Ensure you pasted the complete JSON file from Google Cloud.', 'dc-google-indexing' )
			);
		}

		openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		return $signing_input . '.' . self::base64url_encode( $signature );
	}

	// -------------------------------------------------------------------------
	// OAuth2 token
	// -------------------------------------------------------------------------

	/**
	 * Exchange a signed JWT for a Google OAuth2 access token and cache it.
	 *
	 * @param  array           $sa            Decoded service account JSON.
	 * @param  string          $scope         OAuth2 scope string.
	 * @param  string          $transient_key WP transient key for caching.
	 * @return string|WP_Error                Bearer token or WP_Error.
	 */
	private static function get_token_for_scope( array $sa, string $scope, string $transient_key ) {
		$cached = get_transient( $transient_key );
		if ( $cached ) {
			return $cached;
		}

		$jwt = self::build_jwt( $sa, $scope );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$msg = $body['error_description']
				?? ( $body['error']
				?? __( 'Google OAuth2: failed to obtain access token.', 'dc-google-indexing' ) );
			return new WP_Error( 'dc_gi_token_error', $msg );
		}

		$ttl = isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 60 : 3540;
		set_transient( $transient_key, $body['access_token'], $ttl );

		return $body['access_token'];
	}

	/**
	 * Obtain (or return cached) access token for the Indexing API.
	 *
	 * @param  array           $sa  Decoded service account JSON.
	 * @return string|WP_Error      Bearer token or WP_Error.
	 */
	public static function get_access_token( array $sa ) {
		return self::get_token_for_scope(
			$sa,
			'https://www.googleapis.com/auth/indexing',
			'dc_gi_access_token'
		);
	}

	/**
	 * Obtain (or return cached) access token for the URL Inspection API.
	 *
	 * @param  array           $sa  Decoded service account JSON.
	 * @return string|WP_Error      Bearer token or WP_Error.
	 */
	public static function get_inspection_token( array $sa ) {
		return self::get_token_for_scope(
			$sa,
			'https://www.googleapis.com/auth/webmasters.readonly',
			'dc_gi_inspection_token'
		);
	}

	// -------------------------------------------------------------------------
	// Indexing API
	// -------------------------------------------------------------------------

	/**
	 * Submit a single URL to the Google Web Search Indexing API.
	 *
	 * @param  array           $sa    Decoded service account JSON.
	 * @param  string          $url   Fully qualified URL.
	 * @param  string          $type  'URL_UPDATED' or 'URL_DELETED'.
	 * @return array|WP_Error         API response body or WP_Error.
	 */
	public static function submit_url( array $sa, string $url, string $type = 'URL_UPDATED' ) {
		$token = self::get_access_token( $sa );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			'https://indexing.googleapis.com/v3/urlNotifications:publish',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => (string) wp_json_encode( [
					'url'  => $url,
					'type' => $type,
				] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = $body['error']['message']
				?? sprintf(
					/* translators: %d: HTTP response code from Google API */
					__( 'Google Indexing API returned HTTP %d.', 'dc-google-indexing' ),
					$code
				);
			if ( 401 === $code ) {
				delete_transient( 'dc_gi_access_token' );
			}
			if ( 403 === $code && false !== stripos( $msg, 'URL ownership' ) ) {
				$msg = __(
					'Permission denied: service account lacks Owner permission. In Search Console → Settings → Users and permissions, remove the service account and re-add it with "Owner" permission (not "Full" or "Restricted").',
					'dc-google-indexing'
				);
			}
			return new WP_Error( 'dc_gi_api_error', $msg, [ 'status' => $code ] );
		}

		return (array) $body;
	}

	/**
	 * Inspect a URL via the Google Search Console URL Inspection API.
	 *
	 * @param  array           $sa        Decoded service account JSON.
	 * @param  string          $url       Fully qualified URL to inspect.
	 * @param  string          $site_url  Search Console property URL (e.g. https://example.com/).
	 * @return array|WP_Error             API response body or WP_Error.
	 */
	public static function inspect_url( array $sa, string $url, string $site_url ) {
		$token = self::get_inspection_token( $sa );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => (string) wp_json_encode( [
					'inspectionUrl' => $url,
					'siteUrl'       => $site_url,
				] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = $body['error']['message']
				?? sprintf(
					/* translators: %d: HTTP response code from Google API */
					__( 'URL Inspection API returned HTTP %d.', 'dc-google-indexing' ),
					$code
				);
			if ( 401 === $code ) {
				delete_transient( 'dc_gi_inspection_token' );
			}
			return new WP_Error( 'dc_gi_inspect_error', $msg, [ 'status' => $code ] );
		}

		return (array) $body;
	}

	/**
	 * Submit multiple URLs in a single HTTP batch request.
	 *
	 * Uses the Google multipart batch endpoint to reduce HTTP overhead when
	 * processing several URLs at once.  Supports up to 100 items per call.
	 *
	 * @param  array $sa    Decoded service account JSON.
	 * @param  array $items Array of ['url' => '…', 'type' => 'URL_UPDATED|URL_DELETED'].
	 * @return array<string,array|WP_Error> Map of URL → API response array or WP_Error.
	 */
	public static function submit_batch( array $sa, array $items ): array {
		if ( empty( $items ) ) {
			return [];
		}

		// Single-item shortcut — avoids multipart overhead for one URL.
		if ( 1 === count( $items ) ) {
			$item = reset( $items );
			return [ $item['url'] => self::submit_url( $sa, $item['url'], $item['type'] ) ];
		}

		$token = self::get_access_token( $sa );
		if ( is_wp_error( $token ) ) {
			$out = [];
			foreach ( $items as $item ) {
				$out[ $item['url'] ] = $token;
			}
			return $out;
		}

		// Build multipart/mixed body.
		// The boundary is a MIME delimiter, not used in a security context — uniqueness
		// is the only requirement. We combine time + random to guarantee uniqueness.
		$boundary = 'batch_' . wp_generate_password( 16, false ) . '_' . time();
		$parts    = [];

		foreach ( $items as $i => $item ) {
			$json  = (string) wp_json_encode( [ 'url' => $item['url'], 'type' => $item['type'] ] );
			$part  = "Content-Type: application/http\r\n";
			$part .= "Content-ID: <item{$i}>\r\n\r\n";
			$part .= "POST /v3/urlNotifications:publish HTTP/1.1\r\n";
			$part .= "Content-Type: application/json\r\n";
			$part .= 'Content-Length: ' . strlen( $json ) . "\r\n\r\n";
			$part .= $json;
			$parts[] = $part;
		}

		$body = '--' . $boundary . "\r\n"
			. implode( "\r\n--{$boundary}\r\n", $parts )
			. "\r\n--{$boundary}--\r\n";

		$response = wp_remote_post(
			'https://indexing.googleapis.com/batch',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => "multipart/mixed; boundary={$boundary}",
				],
				'body'    => $body,
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			$out = [];
			foreach ( $items as $item ) {
				$out[ $item['url'] ] = $response;
			}
			return $out;
		}

		$outer_code = (int) wp_remote_retrieve_response_code( $response );
		$outer_body = wp_remote_retrieve_body( $response );

		if ( 401 === $outer_code ) {
			delete_transient( 'dc_gi_access_token' );
		}

		if ( $outer_code >= 400 ) {
			$body_arr = json_decode( $outer_body, true );
			$msg = is_array( $body_arr ) ? ( $body_arr['error']['message'] ?? '' ) : '';
			if ( ! $msg ) {
				$msg = sprintf(
					/* translators: %d: HTTP response code from Google batch API */
					__( 'Google Indexing API batch returned HTTP %d.', 'dc-google-indexing' ),
					$outer_code
				);
			}
			$err = new WP_Error( 'dc_gi_batch_error', $msg, [ 'status' => $outer_code ] );
			$out = [];
			foreach ( $items as $item ) {
				$out[ $item['url'] ] = $err;
			}
			return $out;
		}

		// Extract response boundary from Content-Type header.
		$ct = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! preg_match( '/boundary=([^\s;]+)/i', (string) $ct, $bm ) ) {
			// Fallback: scan body for first boundary line.
			if ( ! preg_match( '/--([^\r\n]+)/', $outer_body, $bm ) ) {
				$err = new WP_Error( 'dc_gi_parse_error', __( 'Could not determine batch response boundary.', 'dc-google-indexing' ) );
				$out = [];
				foreach ( $items as $item ) {
					$out[ $item['url'] ] = $err;
				}
				return $out;
			}
		}
		$resp_boundary = trim( $bm[1], '"' );

		// Build URL-by-index map for matching.
		$indexed_urls = [];
		foreach ( $items as $i => $item ) {
			$indexed_urls[ $i ] = $item['url'];
		}

		// Pre-fill all results with a "no response" error.
		$out = [];
		foreach ( $items as $item ) {
			$out[ $item['url'] ] = new WP_Error( 'dc_gi_no_response', __( 'No response in batch.', 'dc-google-indexing' ) );
		}

		// Split and process each part.  Google returns parts in the same order as
		// the request, so we can use a sequential index as a fallback.
		$raw_parts = preg_split( '/--' . preg_quote( $resp_boundary, '/' ) . '(?:--)?/', $outer_body );
		$part_idx  = 0;

		foreach ( (array) $raw_parts as $raw_part ) {
			$raw_part = trim( (string) $raw_part );
			if ( '' === $raw_part ) {
				continue;
			}

			// Find blank line separating outer part-headers from inner HTTP response.
			$dblnl = strpos( $raw_part, "\r\n\r\n" );
			if ( false === $dblnl ) {
				$dblnl = strpos( $raw_part, "\n\n" );
				if ( false === $dblnl ) {
					$part_idx++;
					continue;
				}
				$outer_hdr  = substr( $raw_part, 0, $dblnl );
				$inner_resp = ltrim( substr( $raw_part, $dblnl + 2 ) );
			} else {
				$outer_hdr  = substr( $raw_part, 0, $dblnl );
				$inner_resp = ltrim( substr( $raw_part, $dblnl + 4 ) );
			}

			// Determine URL index from Content-ID header, falling back to order.
			// Google returns the response Content-ID as the original ID prefixed with
			// "response-". Our request sends "<item{N}>", so the response can come
			// back as "<response-item{N}>", "response-item{N}", or "response-<item{N}>".
			$idx = $part_idx;
			if ( preg_match( '/Content-ID:\s*<response-item(\d+)>/i', $outer_hdr, $cm ) ) {
				$idx = (int) $cm[1];
			} elseif ( preg_match( '/Content-ID:\s*response-<item(\d+)>/i', $outer_hdr, $cm ) ) {
				$idx = (int) $cm[1];
			} elseif ( preg_match( '/Content-ID:\s*response-item(\d+)/i', $outer_hdr, $cm ) ) {
				$idx = (int) $cm[1];
			}
			$part_idx++;

			$url = $indexed_urls[ $idx ] ?? null;
			if ( null === $url ) {
				continue;
			}

			// Extract HTTP status code from inner response status line.
			if ( ! preg_match( '/HTTP\/[\d.]+\s+(\d{3})/', $inner_resp, $sm ) ) {
				$out[ $url ] = new WP_Error( 'dc_gi_parse_error', __( 'Could not parse inner HTTP status.', 'dc-google-indexing' ) );
				continue;
			}
			$inner_code = (int) $sm[1];

			// Find and decode the JSON body that follows the inner headers.
			$inner_sep = strpos( $inner_resp, "\r\n\r\n" );
			if ( false === $inner_sep ) {
				$inner_sep = strpos( $inner_resp, "\n\n" );
				$step      = 2;
			} else {
				$step = 4;
			}
			$inner_json = false !== $inner_sep
				? json_decode( trim( substr( $inner_resp, $inner_sep + $step ) ), true )
				: [];
			$inner_json = is_array( $inner_json ) ? $inner_json : [];

			if ( 200 === $inner_code ) {
				$out[ $url ] = $inner_json;
			} else {
				$msg = is_array( $inner_json ) ? ( $inner_json['error']['message'] ?? '' ) : '';
				if ( ! $msg ) {
					$msg = sprintf(
						/* translators: %d: HTTP status code from inner batch response */
						__( 'Google Indexing API returned HTTP %d.', 'dc-google-indexing' ),
						$inner_code
					);
				}
				if ( 401 === $inner_code ) {
					delete_transient( 'dc_gi_access_token' );
				}
				if ( 403 === $inner_code && false !== stripos( $msg, 'URL ownership' ) ) {
					$msg = __(
						'Permission denied: service account lacks Owner permission. In Search Console → Settings → Users and permissions, remove the service account and re-add it with "Owner" permission (not "Full" or "Restricted").',
						'dc-google-indexing'
					);
				}
				$out[ $url ] = new WP_Error( 'dc_gi_api_error', $msg, [ 'status' => $inner_code ] );
			}
		}

		return $out;
	}

	/**
	 * Validate credentials by obtaining a fresh access token.
	 * Does not submit any URL.
	 *
	 * @param  array           $sa  Decoded service account JSON.
	 * @return true|WP_Error        True on success, WP_Error on failure.
	 */
	public static function test_connection( array $sa ) {
		delete_transient( 'dc_gi_access_token' );
		$token = self::get_access_token( $sa );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return true;
	}
}

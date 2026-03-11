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
					'Permission denied: the service account is not verified as a property owner. Open Search Console → Settings → Users and permissions, add the service account email with Full permission.',
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

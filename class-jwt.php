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
	 * @param  array           $sa  Decoded service account JSON.
	 * @return string|WP_Error      Signed JWT or WP_Error.
	 */
	private static function build_jwt( array $sa ) {
		$now = time();

		$header  = self::base64url_encode(
			(string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] )
		);
		$payload = self::base64url_encode(
			(string) wp_json_encode( [
				'iss'   => $sa['client_email'],
				'scope' => 'https://www.googleapis.com/auth/indexing',
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
	 * Obtain (or return cached) Google access token.
	 *
	 * @param  array           $sa  Decoded service account JSON.
	 * @return string|WP_Error      Bearer token or WP_Error.
	 */
	public static function get_access_token( array $sa ) {
		$cached = get_transient( 'dc_gi_access_token' );
		if ( $cached ) {
			return $cached;
		}

		$jwt = self::build_jwt( $sa );
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
		set_transient( 'dc_gi_access_token', $body['access_token'], $ttl );

		return $body['access_token'];
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
			return new WP_Error( 'dc_gi_api_error', $msg, [ 'status' => $code ] );
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

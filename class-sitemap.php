<?php
/**
 * XML Sitemap discovery and URL extraction.
 *
 * Crawls a site's XML sitemaps (including sitemap indexes) and returns
 * a flat list of URLs suitable for URL Inspection API polling.
 *
 * @package dc-google-indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DC_GI_Sitemap {

	/**
	 * Return a flat array of URLs from the site's XML sitemap(s).
	 *
	 * @param  int             $limit  Maximum number of URLs to return.
	 * @return array|WP_Error          URL strings or WP_Error.
	 */
	public static function get_urls( int $limit = 500 ) {
		$sitemaps = self::discover_sitemaps();
		if ( is_wp_error( $sitemaps ) ) {
			return $sitemaps;
		}

		$all_urls = [];
		foreach ( $sitemaps as $sitemap_url ) {
			$urls = self::parse_sitemap( $sitemap_url, $limit - count( $all_urls ) );
			if ( ! is_wp_error( $urls ) ) {
				$all_urls = array_merge( $all_urls, $urls );
			}
			if ( count( $all_urls ) >= $limit ) {
				break;
			}
		}

		return array_values( array_unique( $all_urls ) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Discover sitemap URLs by checking robots.txt first, then common paths.
	 *
	 * @return array|WP_Error  Array of discovered sitemap URLs or WP_Error.
	 */
	private static function discover_sitemaps() {
		$found = [];

		// 1. Parse Sitemap: directives from robots.txt
		$robots_response = wp_remote_get(
			get_home_url( null, '/robots.txt' ),
			[ 'timeout' => 8, 'redirection' => 3 ]
		);
		if ( ! is_wp_error( $robots_response )
			&& 200 === (int) wp_remote_retrieve_response_code( $robots_response ) ) {
			$robots_body = wp_remote_retrieve_body( $robots_response );
			preg_match_all( '/^Sitemap:\s*(.+)$/im', $robots_body, $matches );
			foreach ( $matches[1] as $sm_url ) {
				$sm_url = esc_url_raw( trim( $sm_url ) );
				if ( $sm_url ) {
					$found[] = $sm_url;
				}
			}
		}

		// 2. Common sitemap locations (fallback when robots.txt has none)
		$candidates = [
			get_home_url( null, '/wp-sitemap.xml' ),      // WordPress 5.5+ built-in
			get_home_url( null, '/sitemap_index.xml' ),   // Yoast SEO
			get_home_url( null, '/sitemap.xml' ),         // Generic / RankMath
			get_home_url( null, '/sitemap-index.xml' ),   // AIOSEO
		];

		foreach ( $candidates as $candidate ) {
			$head = wp_remote_head( $candidate, [ 'timeout' => 5, 'redirection' => 3 ] );
			if ( ! is_wp_error( $head )
				&& 200 === (int) wp_remote_retrieve_response_code( $head ) ) {
				$found[] = $candidate;
				break; // Use only the first valid candidate
			}
		}

		$found = array_values( array_unique( $found ) );

		if ( empty( $found ) ) {
			return new WP_Error(
				'dc_gi_no_sitemap',
				__( 'No sitemap found. Ensure your site has a public XML sitemap (e.g. /wp-sitemap.xml or /sitemap.xml).', 'dc-google-indexing' )
			);
		}

		return $found;
	}

	/**
	 * Fetch and parse a single sitemap (index or regular).
	 * Recursively handles sitemap index files.
	 *
	 * @param  string          $url    Sitemap URL.
	 * @param  int             $limit  Max URLs to collect.
	 * @return array|WP_Error          URL strings or WP_Error.
	 */
	private static function parse_sitemap( string $url, int $limit ) {
		if ( $limit <= 0 ) {
			return [];
		}

		$response = wp_remote_get( $url, [ 'timeout' => 15, 'redirection' => 3 ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'dc_gi_sitemap_fetch',
				/* translators: 1: sitemap URL 2: HTTP status code */
				sprintf( __( 'Could not fetch sitemap %1$s (HTTP %2$d).', 'dc-google-indexing' ), esc_url( $url ), $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );

		// Strip XML namespaces so SimpleXML can use plain property access.
		$body = preg_replace( '/\s+xmlns(?::\w+)?="[^"]*"/i', '', $body );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.libxml_use_internal_errors
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		libxml_clear_errors();

		if ( false === $xml ) {
			return new WP_Error(
				'dc_gi_sitemap_parse',
				/* translators: %s: sitemap URL */
				sprintf( __( 'Could not parse XML from sitemap %s.', 'dc-google-indexing' ), esc_url( $url ) )
			);
		}

		$urls = [];

		// Sitemap index — recurse into child sitemaps.
		if ( isset( $xml->sitemap ) ) {
			foreach ( $xml->sitemap as $child ) {
				$child_url  = esc_url_raw( (string) $child->loc );
				$child_urls = self::parse_sitemap( $child_url, $limit - count( $urls ) );
				if ( ! is_wp_error( $child_urls ) ) {
					$urls = array_merge( $urls, $child_urls );
				}
				if ( count( $urls ) >= $limit ) {
					break;
				}
			}
		}

		// Regular sitemap — collect <url><loc> entries.
		if ( isset( $xml->url ) ) {
			foreach ( $xml->url as $entry ) {
				$loc = esc_url_raw( (string) $entry->loc );
				if ( $loc ) {
					$urls[] = $loc;
				}
				if ( count( $urls ) >= $limit ) {
					break;
				}
			}
		}

		return $urls;
	}
}

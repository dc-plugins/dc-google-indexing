<?php
/**
 * URL Inspection Cache
 *
 * Manages a local DB table that mirrors Google's index-coverage state for every
 * URL in the sitemap.  A background cron (DC_GI_INSPECT_HOOK) inspects a small
 * batch of URLs each minute using the Search Console URL Inspection API and
 * stores the result.  The polling loop reads this table to find URLs that are
 * currently "excluded" by Google instead of calling inspect_url() on every URL
 * during each poll cycle — eliminating thousands of redundant API calls.
 *
 * Table: {wpdb->prefix}dc_gi_url_cache
 * Columns:
 *   url           VARCHAR(600)  PK
 *   index_verdict VARCHAR(30)   PASS | FAIL | NEUTRAL | VERDICT_UNSPECIFIED
 *   coverage_state TEXT
 *   page_fetch_state VARCHAR(60)
 *   last_inspected DATETIME     UTC
 *   last_submitted DATETIME NULL UTC
 *
 * @package DC_Google_Indexing
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum number of URLs to inspect in a single background cron run.
 * At 1 call/minute this keeps us well under the GSC URL Inspection API's
 * 2 000 requests/day hard limit.
 */
define( 'DC_GI_INSPECT_BATCH_SIZE', 3 );

/**
 * Re-inspect a cached URL once it is older than this many seconds (default 7 days).
 * Keeps the cache fresh without hammering the API.
 */
define( 'DC_GI_CACHE_TTL', 7 * DAY_IN_SECONDS );

/** URL inspection cache — mirrors Google's index-coverage state for sitemap URLs. */
class DC_GI_URL_Cache {

	/**
	 * Return the fully-qualified table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dc_gi_url_cache';
	}

	// =========================================================================
	// SCHEMA
	// =========================================================================

	/**
	 * Create (or upgrade) the cache table.
	 * Safe to call on every activation — uses CREATE TABLE IF NOT EXISTS.
	 */
	public static function create_table(): void {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			url            VARCHAR(600)  NOT NULL,
			index_verdict  VARCHAR(30)   NOT NULL DEFAULT '',
			coverage_state TEXT          NOT NULL,
			page_fetch_state VARCHAR(60) NOT NULL DEFAULT '',
			last_inspected DATETIME      NOT NULL,
			last_submitted DATETIME      NULL DEFAULT NULL,
			PRIMARY KEY (url(600))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the cache table.  Called on plugin uninstall.
	 */
	public static function drop_table(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// =========================================================================
	// READ
	// =========================================================================

	/**
	 * Return URLs whose Google index status is NOT "PASS", i.e. they are
	 * excluded / not yet indexed.  Filters out $skip_urls and honours an
	 * optional freshness cutoff.
	 *
	 * @param int      $limit      Maximum number of URLs to return.
	 * @param string[] $skip_urls  URLs to exclude from the result (watchlisted, etc.).
	 * @param int      $max_age_s  Only return rows inspected within this many seconds.
	 *                             Defaults to DC_GI_CACHE_TTL.  Pass 0 to skip TTL check.
	 * @return string[]
	 */
	public static function get_excluded_urls( int $limit, array $skip_urls = [], int $max_age_s = 0 ): array {
		global $wpdb;

		if ( $max_age_s <= 0 ) {
			$max_age_s = DC_GI_CACHE_TTL;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $max_age_s );

		// Build NOT IN clause safely.
		if ( ! empty( $skip_urls ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $skip_urls ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->prepare(
					"SELECT url FROM `{$wpdb->prefix}dc_gi_url_cache`
				  WHERE index_verdict IN ('NEUTRAL','VERDICT_UNSPECIFIED')
				    AND last_inspected >= %s
				    AND url NOT IN ({$placeholders})
				  ORDER BY last_inspected ASC
				  LIMIT %d",
					array_merge( [ $cutoff ], $skip_urls, [ $limit ] )
				)
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT url FROM `{$wpdb->prefix}dc_gi_url_cache`
				  WHERE index_verdict IN ('NEUTRAL','VERDICT_UNSPECIFIED')
				    AND last_inspected >= %s
				  ORDER BY last_inspected ASC
				  LIMIT %d",
					$cutoff,
					$limit
				)
			);
		}

		return $rows ? (array) $rows : [];
	}

	/**
	 * Return the number of URLs in the cache that need submission (NEUTRAL or VERDICT_UNSPECIFIED).
	 * FAIL URLs (noindex, canonical, blocked, etc.) are not counted — they cannot be helped by re-submission.
	 */
	public static function count_excluded(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}dc_gi_url_cache` WHERE index_verdict IN ('NEUTRAL','VERDICT_UNSPECIFIED')"
		);
	}

	/**
	 * Truncate the cache table (remove all rows, keep schema).
	 * Called from the admin "Clear Cache" action.
	 */
	public static function truncate(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Return total number of cached URLs (any verdict).
	 */
	public static function count_total(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}dc_gi_url_cache`" );
	}

	/**
	 * Age of the oldest row in the cache, expressed as days since last_inspected.
	 * Returns null when the table is empty.
	 */
	public static function oldest_entry_age_days(): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$oldest = $wpdb->get_var( "SELECT MIN(last_inspected) FROM `{$wpdb->prefix}dc_gi_url_cache`" );
		if ( ! $oldest ) {
			return null;
		}
		return (int) round( ( time() - strtotime( $oldest ) ) / DAY_IN_SECONDS );
	}

	/**
	 * Return the verdict + coverage for a single URL (or null if not cached).
	 *
	 * @param string $url Fully qualified URL to look up.
	 * @return array{index_verdict:string,coverage_state:string}|null
	 */
	public static function get_entry( string $url ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT index_verdict, coverage_state FROM `{$wpdb->prefix}dc_gi_url_cache` WHERE url = %s LIMIT 1",
				$url
			),
			ARRAY_A
		);
		return $row;
	}

	// =========================================================================
	// WRITE
	// =========================================================================

	/**
	 * Upsert a single inspection result into the cache.
	 *
	 * @param string $url              Fully qualified URL.
	 * @param string $index_verdict    e.g. 'PASS', 'FAIL', 'NEUTRAL', 'VERDICT_UNSPECIFIED'.
	 * @param string $coverage_state   e.g. 'Submitted and indexed'.
	 * @param string $page_fetch_state Google page fetch state string.
	 */
	public static function upsert( string $url, string $index_verdict, string $coverage_state, string $page_fetch_state = '' ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$wpdb->prefix}dc_gi_url_cache`
				(url, index_verdict, coverage_state, page_fetch_state, last_inspected)
			 VALUES (%s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE
				index_verdict    = VALUES(index_verdict),
				coverage_state   = VALUES(coverage_state),
				page_fetch_state = VALUES(page_fetch_state),
				last_inspected   = VALUES(last_inspected)",
				$url,
				$index_verdict,
				$coverage_state,
				$page_fetch_state,
				$now
			)
		);
	}

	/**
	 * Stamp a URL as submitted at the current time (updates last_submitted only).
	 * Called by the poll loop after enqueuing a URL for submission.
	 *
	 * @param string $url Fully qualified URL.
	 */
	public static function mark_submitted( string $url ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			self::table(),
			[ 'last_submitted' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'url' => $url ],
			[ '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Remove a single URL from the cache (e.g. when it no longer exists in sitemap).
	 *
	 * @param string $url Fully qualified URL to remove.
	 */
	public static function delete_url( string $url ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( self::table(), [ 'url' => $url ], [ '%s' ] );
	}

	// =========================================================================
	// INSPECTION CRON
	// =========================================================================

	/**
	 * Return URLs from the sitemap that are either not yet cached, or whose
	 * cache entry is older than DC_GI_CACHE_TTL.  Ordered stale-first so the
	 * freshest entries are refreshed last.
	 *
	 * @param string[] $all_sitemap_urls Full sitemap URL list.
	 * @param int      $limit            Maximum number of URLs to return.
	 * @return string[]
	 */
	public static function get_urls_needing_inspection( array $all_sitemap_urls, int $limit ): array {
		global $wpdb;

		if ( empty( $all_sitemap_urls ) ) {
			return [];
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DC_GI_CACHE_TTL );

		// Fetch already-fresh URLs in one query so we can subtract them locally.
		$placeholders = implode( ',', array_fill( 0, count( $all_sitemap_urls ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$fresh_urls = $wpdb->get_col(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->prepare(
				"SELECT url FROM `{$wpdb->prefix}dc_gi_url_cache`
			  WHERE url IN ({$placeholders})
			    AND last_inspected >= %s",
				array_merge( $all_sitemap_urls, [ $cutoff ] )
			)
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		$fresh_set = array_flip( $fresh_urls ?? [] );

		// URLs not in cache, or stale, in original sitemap order.
		$needing = [];
		foreach ( $all_sitemap_urls as $u ) {
			if ( ! isset( $fresh_set[ $u ] ) ) {
				$needing[] = $u;
				if ( count( $needing ) >= $limit ) {
					break;
				}
			}
		}

		return $needing;
	}

	/**
	 * Parse the URL Inspection API response and derive an index_verdict.
	 *
	 * The API returns inspectionResult.indexStatusResult.verdict for Pro users.
	 * For free / limited responses the verdict field may be absent — we derive
	 * a best-effort verdict from coverageState in that case.
	 *
	 * @param array $result Decoded API response body.
	 * @return array{index_verdict:string,coverage_state:string,page_fetch_state:string}
	 */
	public static function parse_api_result( array $result ): array {
		$isr            = $result['inspectionResult']['indexStatusResult'] ?? [];
		$raw_verdict    = $isr['verdict'] ?? '';
		$coverage_state = $isr['coverageState'] ?? '';
		$fetch_state    = $isr['pageFetchState'] ?? '';

		// Map raw API verdict to our normalised set.
		$verdict_map   = [
			'PASS'                => 'PASS',
			'FAIL'                => 'FAIL',
			'NEUTRAL'             => 'NEUTRAL',
			'VERDICT_UNSPECIFIED' => 'VERDICT_UNSPECIFIED',
			'PARTIAL'             => 'NEUTRAL',
		];
		$index_verdict = $verdict_map[ $raw_verdict ] ?? '';

		// Derive from coverage state when verdict is absent (legacy or limited responses).
		if ( '' === $index_verdict ) {
			$indexed_states = [
				'Submitted and indexed',
				'Indexed, not submitted in sitemap',
			];
			if ( in_array( $coverage_state, $indexed_states, true ) ) {
				$index_verdict = 'PASS';
			} elseif ( '' !== $coverage_state ) {
				$index_verdict = 'NEUTRAL'; // Known but not indexed.
			}
		}

		return [
			'index_verdict'    => $index_verdict,
			'coverage_state'   => $coverage_state,
			'page_fetch_state' => $fetch_state,
		];
	}

	// =========================================================================
	// INSPECT-BATCH CRON RUNNER
	// =========================================================================

	/**
	 * Run one inspection batch:
	 *  1. Fetch sitemap URLs.
	 *  2. Find the first DC_GI_INSPECT_BATCH_SIZE that are absent or stale.
	 *  3. Call DC_GI_JWT::inspect_url() for each and cache the result.
	 *
	 * Separate from the Indexing API quota — uses the webmasters.readonly scope.
	 * Returns a short status string (for logging / admin AJAX).
	 *
	 * @param array $sa Decoded service-account JSON credentials.
	 * @return string  'ok', 'ok:complete', 'early:sitemap_error', 'early:no_urls'
	 */
	public static function run_inspect_batch( array $sa ): string {
		$site_url = trailingslashit( get_home_url() );

		$all_urls = DC_GI_Sitemap::get_urls( 2000 );
		if ( is_wp_error( $all_urls ) || empty( $all_urls ) ) {
			return is_wp_error( $all_urls )
				? 'early:sitemap_error:' . $all_urls->get_error_message()
				: 'early:no_urls';
		}

		$candidates = self::get_urls_needing_inspection( $all_urls, DC_GI_INSPECT_BATCH_SIZE );

		if ( empty( $candidates ) ) {
			// All sitemap URLs are fresh — nothing to do this run.
			return 'ok:complete';
		}

		foreach ( $candidates as $url ) {
			$result = DC_GI_JWT::inspect_url( $sa, $url, $site_url );

			if ( is_wp_error( $result ) ) {
				// Store a placeholder so we don't hammer a broken URL every minute.
				self::upsert( $url, 'VERDICT_UNSPECIFIED', 'inspect_error: ' . $result->get_error_message() );
				continue;
			}

			$parsed = self::parse_api_result( $result );
			self::upsert( $url, $parsed['index_verdict'], $parsed['coverage_state'], $parsed['page_fetch_state'] );
		}

		return 'ok';
	}

	/**
	 * Returns the count of each index_verdict present in the cache.
	 *
	 * @return array<string,int>  e.g. ['PASS'=>339,'NEUTRAL'=>44,'FAIL'=>8,'VERDICT_UNSPECIFIED'=>6]
	 */
	public static function get_verdict_counts(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( "SELECT index_verdict, COUNT(*) AS cnt FROM {$table} GROUP BY index_verdict", ARRAY_A );
		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['index_verdict'] ] = (int) $row['cnt'];
		}
		return $counts;
	}

	/**
	 * Returns coverage_state breakdown ordered by count descending.
	 *
	 * @return array<int,array{coverage_state:string,count:int}>
	 */
	public static function get_coverage_state_breakdown(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( "SELECT coverage_state, COUNT(*) AS cnt FROM {$table} GROUP BY coverage_state ORDER BY cnt DESC", ARRAY_A );
		$result = [];
		foreach ( (array) $rows as $row ) {
			$result[] = [
				'coverage_state' => (string) $row['coverage_state'],
				'count'          => (int) $row['cnt'],
			];
		}
		return $result;
	}
}

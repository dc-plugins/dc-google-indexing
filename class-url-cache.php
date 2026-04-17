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
 *   url              VARCHAR(600)  PK
 *   index_verdict    VARCHAR(30)   PASS | FAIL | NEUTRAL | VERDICT_UNSPECIFIED
 *   coverage_state   TEXT
 *   page_fetch_state VARCHAR(60)
 *   last_inspected   DATETIME      UTC
 *   last_submitted   DATETIME NULL UTC
 *   sa_clicks        INT           Search Analytics clicks
 *   sa_impressions   INT           Search Analytics impressions
 *   sa_ctr           FLOAT         Search Analytics click-through rate (0–1)
 *   sa_position      FLOAT         Search Analytics average position
 *   sa_updated       DATETIME NULL UTC — when analytics were last fetched
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
	 * After dbDelta, calls maybe_upgrade_columns() to explicitly add any columns
	 * that dbDelta may have silently skipped on existing tables.
	 */
	public static function create_table(): void {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			url              VARCHAR(600)  NOT NULL,
			index_verdict    VARCHAR(30)   NOT NULL DEFAULT '',
			coverage_state   TEXT          NOT NULL,
			page_fetch_state VARCHAR(60)   NOT NULL DEFAULT '',
			robots_txt_state VARCHAR(30)   NOT NULL DEFAULT '',
			indexing_state   VARCHAR(60)   NOT NULL DEFAULT '',
			last_crawl_time  DATETIME      NULL DEFAULT NULL,
			google_canonical VARCHAR(600)  NOT NULL DEFAULT '',
			user_canonical   VARCHAR(600)  NOT NULL DEFAULT '',
			crawled_as       VARCHAR(20)   NOT NULL DEFAULT '',
			rich_results     TEXT          NOT NULL,
			last_inspected   DATETIME      NOT NULL,
			last_submitted   DATETIME      NULL DEFAULT NULL,
			sa_clicks        INT           NOT NULL DEFAULT 0,
			sa_impressions   INT           NOT NULL DEFAULT 0,
			sa_ctr           FLOAT         NOT NULL DEFAULT 0,
			sa_position      FLOAT         NOT NULL DEFAULT 0,
			sa_updated       DATETIME      NULL DEFAULT NULL,
			PRIMARY KEY (url(600))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// dbDelta is unreliable when adding columns to tables that already have rows.
		// Explicitly add any columns that may have been skipped.
		self::maybe_upgrade_columns();
		self::maybe_add_indexes();
	}

	/**
	 * Explicitly add any schema columns that dbDelta may have missed on existing
	 * tables.  Uses INFORMATION_SCHEMA so it is safe to call repeatedly — it is
	 * a no-op when all columns already exist.
	 */
	public static function maybe_upgrade_columns(): void {
		global $wpdb;
		$table = self::table();

		// Map of column name => ALTER TABLE fragment (type + constraints).
		$expected = array(
			'robots_txt_state' => "VARCHAR(30) NOT NULL DEFAULT ''",
			'indexing_state'   => "VARCHAR(60) NOT NULL DEFAULT ''",
			'last_crawl_time'  => 'DATETIME NULL DEFAULT NULL',
			'google_canonical' => "VARCHAR(600) NOT NULL DEFAULT ''",
			'user_canonical'   => "VARCHAR(600) NOT NULL DEFAULT ''",
			'crawled_as'       => "VARCHAR(20) NOT NULL DEFAULT ''",
			'rich_results'     => 'TEXT NOT NULL',
			'sa_clicks'        => 'INT NOT NULL DEFAULT 0',
			'sa_impressions'   => 'INT NOT NULL DEFAULT 0',
			'sa_ctr'           => 'FLOAT NOT NULL DEFAULT 0',
			'sa_position'      => 'FLOAT NOT NULL DEFAULT 0',
			'sa_updated'       => 'DATETIME NULL DEFAULT NULL',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);

		foreach ( $expected as $col => $definition ) {
			if ( ! in_array( $col, (array) $existing, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}" );
			}
		}
	}

	/**
	 * Drop the cache table.  Called on plugin uninstall.
	 */
	public static function drop_table(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Add performance indexes if they do not already exist.
	 * Safe to call repeatedly — checks information_schema before altering.
	 */
	private static function maybe_add_indexes(): void {
		global $wpdb;
		$table   = self::table();
		$indexes = array(
			'idx_last_inspected' => 'last_inspected',
			'idx_index_verdict'  => 'index_verdict(30)',
		);
		foreach ( $indexes as $name => $col ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.statistics
					  WHERE table_schema = DATABASE()
					    AND table_name = %s
					    AND index_name = %s',
					$table,
					$name
				)
			);
			if ( ! $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$col})" );
			}
		}
	}

	// =========================================================================
	// READ
	// =========================================================================

	/**
	 * Return the number of URLs in the cache that need submission (NEUTRAL or VERDICT_UNSPECIFIED).
	 * FAIL URLs (noindex, canonical, blocked, etc.) are not counted — they cannot be helped by re-submission.
	 */
	public static function count_excluded(): int {
		global $wpdb;
		$not_like = $wpdb->esc_like( 'inspect_error:' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}dc_gi_url_cache`
				 WHERE index_verdict IN ('NEUTRAL','VERDICT_UNSPECIFIED')
				   AND coverage_state NOT LIKE %s",
				$not_like
			)
		);
	}

	/**
	 * Count cache rows whose coverage_state is an internal inspection error string
	 * (e.g. 'inspect_error: Quota exceeded …').
	 *
	 * These rows are excluded from submission and treated as stale so they are
	 * re-inspected automatically on the next cron run.
	 *
	 * @return int
	 */
	public static function get_inspect_error_count(): int {
		global $wpdb;
		$like = $wpdb->esc_like( 'inspect_error:' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}dc_gi_url_cache` WHERE coverage_state LIKE %s",
				$like
			)
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
				"SELECT url, index_verdict, coverage_state, page_fetch_state,
				        robots_txt_state, indexing_state, last_crawl_time,
				        google_canonical, user_canonical, crawled_as, rich_results,
				        last_inspected, last_submitted,
				        sa_clicks, sa_impressions, sa_ctr, sa_position, sa_updated
				 FROM `{$wpdb->prefix}dc_gi_url_cache` WHERE url = %s LIMIT 1",
				$url
			),
			ARRAY_A
		);
		return $row;
	}

	/**
	 * Fetch multiple cache rows in a single query, keyed by URL.
	 *
	 * @param string[] $urls List of fully qualified URLs to look up.
	 * @return array<string, array> Map of url => row (same shape as get_entry()).
	 */
	public static function get_entries_batch( array $urls ): array {
		if ( empty( $urls ) ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $urls ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				"SELECT * FROM `{$wpdb->prefix}dc_gi_url_cache` WHERE url IN ({$placeholders})",
				$urls
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			),
			ARRAY_A
		);
		return $rows ? array_column( $rows, null, 'url' ) : array();
	}

	// =========================================================================
	// WRITE
	// =========================================================================

	/**
	 * Upsert a single inspection result into the cache.
	 *
	 * Accepts all fields parsed from the URL Inspection API response.
	 * Provide at minimum 'index_verdict' and 'coverage_state'; all other
	 * fields default to empty / null when omitted.
	 *
	 * Keys: index_verdict, coverage_state, page_fetch_state, robots_txt_state,
	 * indexing_state, last_crawl_time (nullable string), google_canonical,
	 * user_canonical, crawled_as, rich_results (JSON string).
	 *
	 * @param string $url    Fully qualified URL.
	 * @param array  $fields Inspection data fields — see docblock above for supported keys.
	 */
	public static function upsert( string $url, array $fields ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$wpdb->prefix}dc_gi_url_cache`
				(url, index_verdict, coverage_state, page_fetch_state,
				 robots_txt_state, indexing_state, last_crawl_time,
				 google_canonical, user_canonical, crawled_as, rich_results,
				 last_inspected)
			 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE
				index_verdict    = VALUES(index_verdict),
				coverage_state   = VALUES(coverage_state),
				page_fetch_state = VALUES(page_fetch_state),
				robots_txt_state = VALUES(robots_txt_state),
				indexing_state   = VALUES(indexing_state),
				last_crawl_time  = VALUES(last_crawl_time),
				google_canonical = VALUES(google_canonical),
				user_canonical   = VALUES(user_canonical),
				crawled_as       = VALUES(crawled_as),
				rich_results     = VALUES(rich_results),
				last_inspected   = VALUES(last_inspected)",
				$url,
				(string) ( $fields['index_verdict'] ?? '' ),
				(string) ( $fields['coverage_state'] ?? '' ),
				(string) ( $fields['page_fetch_state'] ?? '' ),
				(string) ( $fields['robots_txt_state'] ?? '' ),
				(string) ( $fields['indexing_state'] ?? '' ),
				$fields['last_crawl_time'] ?? null,
				(string) ( $fields['google_canonical'] ?? '' ),
				(string) ( $fields['user_canonical'] ?? '' ),
				(string) ( $fields['crawled_as'] ?? '' ),
				(string) ( $fields['rich_results'] ?? '' ),
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
			array( 'last_submitted' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'url' => $url ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Mark an existing cached URL as indexed (PASS) without resetting last_inspected.
	 *
	 * Used when the watchlist confirms a URL is indexed but no fresh API result is
	 * available (e.g. after "Clear All Indexed").  Only updates existing rows so the
	 * background cron's TTL-based re-inspection schedule is preserved — the URL will
	 * still be re-inspected after DC_GI_CACHE_TTL seconds, giving Google time to
	 * confirm the state independently.
	 *
	 * @param string $url           Fully qualified URL.
	 * @param string $coverage_state Coverage state string from the watchlist entry.
	 */
	public static function mark_indexed( string $url, string $coverage_state = 'Submitted and indexed' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'index_verdict'  => 'PASS',
				'coverage_state' => $coverage_state,
			),
			array( 'url' => $url ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Update the Search Analytics columns for an existing cached URL.
	 *
	 * Only updates rows that are already in the cache — does not insert new rows.
	 * Called by run_analytics_batch() after fetching data from the Search Analytics API.
	 *
	 * @param string $url    Fully qualified URL.
	 * @param array  $fields Analytics data: sa_clicks, sa_impressions, sa_ctr, sa_position.
	 */
	public static function upsert_analytics( string $url, array $fields ): void {
		global $wpdb;
		$data    = array(
			'sa_clicks'      => (int) ( $fields['sa_clicks'] ?? 0 ),
			'sa_impressions' => (int) ( $fields['sa_impressions'] ?? 0 ),
			'sa_ctr'         => (float) ( $fields['sa_ctr'] ?? 0.0 ),
			'sa_position'    => (float) ( $fields['sa_position'] ?? 0.0 ),
			'sa_updated'     => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%d', '%d', '%f', '%f', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( self::table(), $data, array( 'url' => $url ), $formats, array( '%s' ) );

		if ( ! $wpdb->rows_affected ) {
			// Retry with trailing slash toggled — Google canonical may differ from sitemap form.
			$alt_url = str_ends_with( $url, '/' ) ? rtrim( $url, '/' ) : $url . '/';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( self::table(), $data, array( 'url' => $alt_url ), $formats, array( '%s' ) );
		}
	}

	/**
	 * Fetch Search Analytics data from the Google Search Console API and store it
	 * in the URL cache table for all matching URLs.
	 *
	 * Paginates through the API (up to 25,000 rows per call) and upserts results
	 * into the cache.  URLs in the analytics response that are not yet cached are
	 * silently skipped — only rows already present are updated.
	 *
	 * @param array  $sa   Decoded service-account JSON credentials.
	 * @param string $site_url Search Console property URL.
	 * @param int    $days Number of days to look back (end date = today).
	 * @return string 'ok:N' (N rows updated), or 'ok:0' (no data), or 'error:…'.
	 */
	public static function run_analytics_batch( array $sa, string $site_url, int $days = 28 ): string {
		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', time() - ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS );

		$start_row = 0;
		$updated   = 0;

		do {
			$result = DC_GI_JWT::fetch_search_analytics( $sa, $site_url, $start_date, $end_date, $start_row );
			if ( is_wp_error( $result ) ) {
				return 'error:' . $result->get_error_message();
			}

			$rows = (array) ( $result['rows'] ?? array() );
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$url = (string) ( $row['keys'][0] ?? '' );
				if ( ! $url ) {
					continue;
				}
				self::upsert_analytics(
					$url,
					array(
						'sa_clicks'      => (int) round( (float) ( $row['clicks'] ?? 0 ) ),
						'sa_impressions' => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
						'sa_ctr'         => (float) ( $row['ctr'] ?? 0.0 ),
						'sa_position'    => (float) ( $row['position'] ?? 0.0 ),
					)
				);
				++$updated;
			}

			$rows_count = count( $rows );
			$start_row += $rows_count;
		} while ( $rows_count >= 25000 );

		return 'ok:' . $updated;
	}

	/**
	 * Return the timestamp (UTC) of the most recent Search Analytics update across all rows,
	 * or null when no analytics data has been fetched yet.
	 *
	 * @return string|null MySQL DATETIME string or null.
	 */
	public static function get_analytics_last_updated(): ?string {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var( "SELECT MAX(sa_updated) FROM {$table} WHERE sa_updated IS NOT NULL" );
		return $val ? (string) $val : null;
	}

	/**
	 * Remove a single URL from the cache (e.g. when it no longer exists in sitemap).
	 *
	 * @param string $url Fully qualified URL to remove.
	 */
	public static function delete_url( string $url ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( self::table(), array( 'url' => $url ), array( '%s' ) );
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
			return array();
		}

		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - DC_GI_CACHE_TTL );
		$not_like = $wpdb->esc_like( 'inspect_error:' ) . '%';

		// Fetch already-fresh URLs in one query so we can subtract them locally.
		$placeholders = implode( ',', array_fill( 0, count( $all_sitemap_urls ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$fresh_urls = $wpdb->get_col(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->prepare(
				"SELECT url FROM `{$wpdb->prefix}dc_gi_url_cache`
			  WHERE url IN ({$placeholders})
			    AND last_inspected >= %s
			    AND coverage_state NOT LIKE %s",
				array_merge( $all_sitemap_urls, array( $cutoff, $not_like ) )
			)
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		$fresh_set = array_flip( $fresh_urls ?? array() );

		// URLs not in cache, or stale, in original sitemap order.
		$needing = array();
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
	 * Parse the URL Inspection API response into a flat field array ready for upsert().
	 *
	 * Extracts the full GSC inspection result: verdict, coverage state, crawl metadata,
	 * canonical URLs, robots/indexing state, crawl agent, and rich results.
	 * When the API omits the verdict (limited/legacy responses), it is derived from
	 * coverageState so the cache always holds a usable verdict.
	 *
	 * @param array $result Decoded URL Inspection API response body.
	 * @return array{
	 *     index_verdict:string,
	 *     coverage_state:string,
	 *     page_fetch_state:string,
	 *     robots_txt_state:string,
	 *     indexing_state:string,
	 *     last_crawl_time:string|null,
	 *     google_canonical:string,
	 *     user_canonical:string,
	 *     crawled_as:string,
	 *     rich_results:string
	 * }
	 */
	public static function parse_api_result( array $result ): array {
		$isr            = $result['inspectionResult']['indexStatusResult'] ?? array();
		$raw_verdict    = $isr['verdict'] ?? '';
		$coverage_state = $isr['coverageState'] ?? '';
		$fetch_state    = $isr['pageFetchState'] ?? '';

		// Map raw API verdict to our normalised set.
		$verdict_map   = array(
			'PASS'                => 'PASS',
			'FAIL'                => 'FAIL',
			'NEUTRAL'             => 'NEUTRAL',
			'VERDICT_UNSPECIFIED' => 'VERDICT_UNSPECIFIED',
			'PARTIAL'             => 'NEUTRAL',
		);
		$index_verdict = $verdict_map[ $raw_verdict ] ?? '';

		// Derive from coverage state when verdict is absent (legacy or limited responses).
		if ( '' === $index_verdict ) {
			$indexed_states = array(
				'Submitted and indexed',
				'Indexed, not submitted in sitemap',
			);
			if ( in_array( $coverage_state, $indexed_states, true ) ) {
				$index_verdict = 'PASS';
			} elseif ( '' !== $coverage_state ) {
				$index_verdict = 'NEUTRAL'; // Known but not indexed.
			}
		}

		// Last crawl time: normalize ISO 8601 → MySQL DATETIME (UTC), or null.
		$raw_crawl  = $isr['lastCrawlTime'] ?? '';
		$last_crawl = '' !== $raw_crawl ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $raw_crawl ) ) : null;

		// Rich results — encode detected items + issues as compact JSON.
		$rich_raw     = $result['inspectionResult']['richResultsResult']['detectedItems'] ?? array();
		$rich_encoded = '';
		if ( ! empty( $rich_raw ) ) {
			$rich_clean = array();
			foreach ( $rich_raw as $ritem ) {
				$items = array();
				foreach ( (array) ( $ritem['items'] ?? array() ) as $it ) {
					$issues = array();
					foreach ( (array) ( $it['issues'] ?? array() ) as $iss ) {
						$issues[] = array(
							'm' => (string) ( $iss['issueMessage'] ?? '' ),
							's' => (string) ( $iss['severity'] ?? '' ),
						);
					}
					$items[] = array(
						'n' => (string) ( $it['name'] ?? '' ),
						'i' => $issues,
					);
				}
				$rich_clean[] = array(
					't' => (string) ( $ritem['richResultType'] ?? '' ),
					'i' => $items,
				);
			}
			$json         = wp_json_encode( $rich_clean );
			$rich_encoded = $json ? (string) $json : '';
		}

		return array(
			'index_verdict'    => $index_verdict,
			'coverage_state'   => $coverage_state,
			'page_fetch_state' => $fetch_state,
			'robots_txt_state' => (string) ( $isr['robotsTxtState'] ?? '' ),
			'indexing_state'   => (string) ( $isr['indexingState'] ?? '' ),
			'last_crawl_time'  => $last_crawl,
			'google_canonical' => (string) ( $isr['googleCanonical'] ?? '' ),
			'user_canonical'   => (string) ( $isr['userCanonical'] ?? '' ),
			'crawled_as'       => (string) ( $isr['crawledAs'] ?? '' ),
			'rich_results'     => $rich_encoded,
		);
	}

	// =========================================================================
	// INSPECT-BATCH CRON RUNNER
	// =========================================================================

	/**
	 * Run one inspection batch:
	 *  1. Fetch sitemap URLs (cached for 5 minutes to reduce HTTP overhead).
	 *  2. Find the first DC_GI_INSPECT_BATCH_SIZE that are absent or stale.
	 *  3. Call DC_GI_JWT::inspect_url() for each and cache the result.
	 *
	 * Separate from the Indexing API quota — uses the webmasters.readonly scope.
	 * Returns a short status string (for logging / admin AJAX).
	 *
	 * @param array $sa Decoded service-account JSON credentials.
	 * @return array {
	 *   @type string   $status    'ok', 'ok:complete', 'early:sitemap_error', 'early:no_urls', 'early:quota_backoff'
	 *   @type string[] $upserted  URLs successfully inspected and upserted this run, keyed by URL with coverage_state as value.
	 * }
	 */
	public static function run_inspect_batch( array $sa ): array {
		// Bail early when the URL Inspection API quota is known to be exhausted.
		// The backoff transient is set for 1 hour so the cron doesn't hammer the API.
		if ( get_transient( 'dc_gi_inspect_quota_backoff' ) ) {
			return array(
				'status'   => 'early:quota_backoff',
				'upserted' => array(),
			);
		}

		$site_url = dc_gi_get_search_console_property();

		// Use the cached sitemap URL list to avoid making HTTP requests on every
		// 1-minute cron tick — the cache is refreshed automatically every 5 minutes.
		$all_urls = dc_gi_get_sitemap_urls_cached();
		if ( empty( $all_urls ) ) {
			return array(
				'status'   => 'early:no_urls',
				'upserted' => array(),
			);
		}

		$candidates = self::get_urls_needing_inspection( $all_urls, DC_GI_INSPECT_BATCH_SIZE );

		if ( empty( $candidates ) ) {
			// All sitemap URLs are fresh — nothing to do this run.
			return array(
				'status'   => 'ok:complete',
				'upserted' => array(),
			);
		}

		$upserted = array();

		foreach ( $candidates as $url ) {
			$result = DC_GI_JWT::inspect_url( $sa, $url, $site_url );

			if ( is_wp_error( $result ) ) {
				// 429 = Inspection API rate-limited — set a 1-hour backoff so subsequent
				// cron runs skip API calls until the quota window resets.
				if ( 'dc_gi_inspect_quota_exceeded' === $result->get_error_code() ) {
					set_transient( 'dc_gi_inspect_quota_backoff', 1, HOUR_IN_SECONDS );
					return array(
						'status'   => 'ok',
						'upserted' => $upserted,
					);
				}
				// Other transient errors — store a placeholder so we don't hammer
				// a broken URL every minute, but keep processing remaining candidates.
				self::upsert(
					$url,
					array(
						'index_verdict'  => 'VERDICT_UNSPECIFIED',
						'coverage_state' => 'inspect_error: ' . $result->get_error_message(),
					)
				);
				continue;
			}

			$parsed = self::parse_api_result( $result );
			self::upsert( $url, $parsed );
			$upserted[ $url ] = $parsed['coverage_state'] ?? '';
		}

		return array(
			'status'   => 'ok',
			'upserted' => $upserted,
		);
	}

	/**
	 * Return a page of cache rows for display in the Index Status tab.
	 *
	 * @param int    $page           1-based page number.
	 * @param int    $per_page       Rows per page.
	 * @param string $verdict_filter '' = all; 'PASS'|'NEUTRAL'|'FAIL'|'VERDICT_UNSPECIFIED'|'EXCLUDED' (NEUTRAL+UNSPECIFIED).
	 * @param string $order_by       Column to sort by.
	 * @param string $order          ASC or DESC.
	 * @return array<int,array{
	 *   url:string,index_verdict:string,coverage_state:string,page_fetch_state:string,
	 *   robots_txt_state:string,indexing_state:string,last_crawl_time:string|null,
	 *   google_canonical:string,user_canonical:string,crawled_as:string,rich_results:string,
	 *   last_inspected:string,last_submitted:string|null
	 * }>
	 */
	public static function get_paginated_urls( int $page, int $per_page, string $verdict_filter = '', string $order_by = 'last_inspected', string $order = 'DESC' ): array {
		global $wpdb;

		$allowed_cols = array( 'url', 'index_verdict', 'coverage_state', 'last_crawl_time', 'last_inspected', 'last_submitted' );
		if ( ! in_array( $order_by, $allowed_cols, true ) ) {
			$order_by = 'last_inspected';
		}
		$order  = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$table  = self::table();

		$cols = 'url, index_verdict, coverage_state, page_fetch_state,
		         robots_txt_state, indexing_state, last_crawl_time,
		         google_canonical, user_canonical, crawled_as, rich_results,
		         last_inspected, last_submitted,
		         sa_clicks, sa_impressions, sa_ctr, sa_position, sa_updated';
		if ( 'EXCLUDED' === $verdict_filter ) {
			$sql  = "SELECT {$cols} FROM {$table} WHERE index_verdict IN ('NEUTRAL','VERDICT_UNSPECIFIED') ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$args = array( $per_page, $offset );
		} elseif ( ! empty( $verdict_filter ) ) {
			$sql  = "SELECT {$cols} FROM {$table} WHERE index_verdict = %s ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$args = array( $verdict_filter, $per_page, $offset );
		} else {
			$sql  = "SELECT {$cols} FROM {$table} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$args = array( $per_page, $offset );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return $rows ? (array) $rows : array();
	}

	/**
	 * Count rows matching a verdict filter (used for pagination totals).
	 *
	 * @param string $verdict_filter '' = all; 'PASS'|'NEUTRAL'|'FAIL'|'VERDICT_UNSPECIFIED'|'EXCLUDED'.
	 * @return int
	 */
	public static function count_filtered( string $verdict_filter = '' ): int {
		global $wpdb;
		$table = self::table();

		if ( 'EXCLUDED' === $verdict_filter ) {
			$sql = "SELECT COUNT(*) FROM {$table} WHERE index_verdict IN ('NEUTRAL','VERDICT_UNSPECIFIED')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( ! empty( $verdict_filter ) ) {
			$csql = "SELECT COUNT(*) FROM {$table} WHERE index_verdict = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $csql, $verdict_filter ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		}

		return self::count_total();
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
		$counts = array();
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
		$table    = self::table();
		$not_like = $wpdb->esc_like( 'inspect_error:' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT coverage_state, COUNT(*) AS cnt FROM {$table} WHERE coverage_state NOT LIKE %s GROUP BY coverage_state ORDER BY cnt DESC",
				$not_like
			),
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$result = array();
		foreach ( (array) $rows as $row ) {
			$result[] = array(
				'coverage_state' => (string) $row['coverage_state'],
				'count'          => (int) $row['cnt'],
			);
		}
		return $result;
	}
}

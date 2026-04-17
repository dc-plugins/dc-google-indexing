<?php
/**
 * DC Google Indexing — submit URLs to Google's Web Search Indexing API.
 *
 * @wordpress-plugin
 * Plugin Name: DC Google Indexing
 * Plugin URI:  https://github.com/dc-plugins/dc-google-indexing
 * Description: Submit URLs to Google's Web Search Indexing API for instant crawling. Supports manual batch submission and automatic submission on publish/update.
 * Version:     1.4.0
 * Author:      lennilg
 * Author URI:  https://www.dampcig.dk
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dc-google-indexing
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * @package dc-google-indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DC_GI_VERSION', '1.4.0' );
define( 'DC_GI_DB_VERSION', '1.4.0' ); // Increment when the URL-cache table schema changes.
define( 'DC_GI_FILE', __FILE__ );
define( 'DC_GI_DIR', plugin_dir_path( __FILE__ ) );
define( 'DC_GI_CRON_HOOK', 'dc_gi_process_queue' );
define( 'DC_GI_WATCH_HOOK', 'dc_gi_check_watchlist' );
define( 'DC_GI_WATCH_CHECK_HOOK', 'dc_gi_watch_check_one_cron' );
define( 'DC_GI_POLL_HOOK', 'dc_gi_poll_batch' );
define( 'DC_GI_INSPECT_HOOK', 'dc_gi_inspect_batch' );
define( 'DC_GI_ANALYTICS_HOOK', 'dc_gi_analytics_batch' );
define( 'DC_GI_INSPECT_SIGNAL_HOOK', 'dc_gi_inspection_signal' );
define( 'DC_GI_DAILY_CAP', 200 );

// Coverage states that indicate a URL is not yet indexed and benefits from
// re-submission via the Indexing API.  Defined once so both watchlist functions
// stay in sync if Google renames or adds states in future.
define(
	'DC_GI_RESUBMIT_STATES',
	[
		'Crawled - currently not indexed',
		'Discovered - currently not indexed',
		'URL is unknown to Google',
		'', // API returns empty string for completely unknown URLs.
	]
);

// Coverage states where manual QA review is warranted (Google has seen the URL
// but chosen not to index it).  Subset of DC_GI_RESUBMIT_STATES; empty string
// is excluded because there is nothing actionable for a URL Google has never seen.
define(
	'DC_GI_QA_STATES',
	[
		'Crawled - currently not indexed',
		'Discovered - currently not indexed',
		'URL is unknown to Google',
	]
);

/**
 * Return the current date string in Pacific Time (America/Los_Angeles).
 *
 * Google's Indexing API quota resets at Pacific midnight, so we key our
 * local counter on the Pacific date rather than UTC to ensure our 200-request
 * ceiling aligns with Google's 24-hour window.
 *
 * @return string Date in Y-m-d format, e.g. "2026-03-22".
 */
function dc_gi_quota_date_key(): string {
	$tz = new DateTimeZone( 'America/Los_Angeles' );
	return ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
}

require_once DC_GI_DIR . 'class-jwt.php';
require_once DC_GI_DIR . 'class-sitemap.php';
require_once DC_GI_DIR . 'class-url-cache.php';
require_once DC_GI_DIR . 'admin.php';

// =============================================================================
// CRON SCHEDULE
// =============================================================================

// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The 5-minute interval is required for timely queue processing.
add_filter( 'cron_schedules', 'dc_gi_cron_schedules' );
/**
 * Register custom WP-Cron schedule intervals used by this plugin.
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified cron schedules.
 */
function dc_gi_cron_schedules( array $schedules ): array {
	if ( ! isset( $schedules['dc_gi_every5'] ) ) {
		$schedules['dc_gi_every5'] = [
			'interval' => 300, // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
			'display'  => __( 'Every 5 Minutes (DC Google Indexing)', 'dc-google-indexing' ),
		];
	}
	if ( ! isset( $schedules['dc_gi_sixhourly'] ) ) {
		$schedules['dc_gi_sixhourly'] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 Hours (DC Google Indexing)', 'dc-google-indexing' ),
		];
	}
	if ( ! isset( $schedules['dc_gi_every1'] ) ) {
		$schedules['dc_gi_every1'] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every 1 Minute (DC Google Indexing)', 'dc-google-indexing' ),
		];
	}
	return $schedules;
}

// =============================================================================
// AUTO-SUBMIT ON PUBLISH / UPDATE
// =============================================================================

add_action( 'transition_post_status', 'dc_gi_on_status_change', 10, 3 );
/**
 * Enqueue URL submission when a post is published or de-published.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       Post object.
 */
function dc_gi_on_status_change( string $new_status, string $old_status, WP_Post $post ): void {
	$settings   = dc_gi_get_settings();
	$post_types = $settings['post_types'] ?? [ 'post', 'page' ];
	if ( ! in_array( $post->post_type, $post_types, true ) ) {
		return;
	}

	if ( 'publish' === $new_status ) {
		if ( empty( $settings['auto_submit'] ) ) {
			return;
		}
		// Published or re-published — notify Google to index it.
		$url = get_permalink( $post->ID );
		if ( $url ) {
			dc_gi_enqueue_url( $url, 'URL_UPDATED' );
		}
		return;
	}

	// Transitioning away from publish to draft / private / pending — remove from index.
	// Controlled by the separate auto_delete toggle (defaults to 1 for backward compat).
	if ( 'publish' === $old_status && in_array( $new_status, [ 'draft', 'private', 'pending' ], true ) ) {
		$auto_delete = isset( $settings['auto_delete'] ) ? (bool) $settings['auto_delete'] : true;
		if ( ! $auto_delete ) {
			return;
		}
		// Build the public permalink from the post data: at this point the post
		// is already saved with the new status, so get_permalink() returns '?p=ID'
		// for drafts. Clone the object with 'publish' status and filter='sample'
		// so WordPress computes the real URL from the post slug.
		$pub = clone $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$pub->post_status = 'publish'; // Tells get_permalink() to use the real structure.
		$pub->filter      = 'sample';  // Tells get_permalink() to use the object as-is.
		$url              = get_permalink( $pub );
		if ( $url ) {
			dc_gi_enqueue_url( $url, 'URL_DELETED' );
		}
	}
}

// Queue URL_DELETED when a published post is trashed.
// Hooks into wp_trash_post instead of transition_post_status because the
// post slug gets an "__trashed" suffix appended during the update — this
// hook fires before that mangling, so get_permalink() still returns the
// real public URL.
add_action( 'wp_trash_post', 'dc_gi_on_post_trashed', 10, 2 );
/**
 * Enqueue URL_DELETED when a published post is moved to the trash.
 *
 * @param int    $post_id         Post ID being trashed.
 * @param string $previous_status Post status before trashing.
 */
function dc_gi_on_post_trashed( int $post_id, string $previous_status ): void {
	if ( 'publish' !== $previous_status ) {
		return;
	}
	$settings = dc_gi_get_settings();
	// auto_delete defaults to 1 for backward compatibility.
	$auto_delete = isset( $settings['auto_delete'] ) ? (bool) $settings['auto_delete'] : true;
	if ( ! $auto_delete ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
		return;
	}
	$post_types = $settings['post_types'] ?? [ 'post', 'page' ];
	if ( ! in_array( $post->post_type, $post_types, true ) ) {
		return;
	}
	// Post is still published in the DB at this point — permalink is correct.
	$url = get_permalink( $post_id );
	if ( $url ) {
		dc_gi_enqueue_url( $url, 'URL_DELETED' );
	}
}

// Queue URL_DELETED when a published post has a password added to it.
// The transition_post_status hook fires first (adding URL_UPDATED); this
// hook fires afterwards and replaces that entry with URL_DELETED so the
// correct notification is sent to Google.
add_action( 'post_updated', 'dc_gi_on_post_password_set', 10, 3 );
/**
 * Replace URL_UPDATED with URL_DELETED when a password is added to a published post.
 *
 * @param int     $post_id     Post ID.
 * @param WP_Post $post_after  Post object after the update.
 * @param WP_Post $post_before Post object before the update.
 */
function dc_gi_on_post_password_set( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
	if ( 'publish' !== $post_before->post_status || 'publish' !== $post_after->post_status ) {
		return;
	}
	if ( ! empty( $post_before->post_password ) || empty( $post_after->post_password ) ) {
		// Either already had a password, or no password added — nothing to do.
		return;
	}
	$settings = dc_gi_get_settings();
	// auto_delete defaults to 1 for backward compatibility.
	$auto_delete = isset( $settings['auto_delete'] ) ? (bool) $settings['auto_delete'] : true;
	if ( ! $auto_delete ) {
		return;
	}
	$post_types = $settings['post_types'] ?? [ 'post', 'page' ];
	if ( ! in_array( $post_after->post_type, $post_types, true ) ) {
		return;
	}
	$url = get_permalink( $post_id );
	if ( ! $url ) {
		return;
	}
	// Remove any URL_UPDATED entry queued by transition_post_status and replace
	// it with URL_DELETED — a password-protected post should be de-indexed.
	$queue   = get_option( 'dc_gi_queue', [] );
	$queue   = array_values( array_filter( $queue, fn( $item ) => $item['url'] !== $url ) );
	$queue[] = [
		'url'   => esc_url_raw( $url ),
		'type'  => 'URL_DELETED',
		'added' => time(),
	];
	update_option( 'dc_gi_queue', $queue, false );
	wp_cache_delete( 'dc_gi_queue', 'options' ); // Force-bust Redis persistent object cache.
}

// =============================================================================
// QUEUE
// =============================================================================

/**
 * Add a URL to the submission queue (idempotent — no duplicates).
 *
 * @param string $url  Fully qualified URL to enqueue.
 * @param string $type Notification type: 'URL_UPDATED' or 'URL_DELETED'.
 */
function dc_gi_enqueue_url( string $url, string $type = 'URL_UPDATED' ): void {
	$queue = get_option( 'dc_gi_queue', [] );
	foreach ( $queue as $item ) {
		if ( $item['url'] === $url ) {
			return;
		}
	}
	$queue[] = [
		'url'   => esc_url_raw( $url ),
		'type'  => in_array( $type, [ 'URL_UPDATED', 'URL_DELETED' ], true ) ? $type : 'URL_UPDATED',
		'added' => time(),
	];
	update_option( 'dc_gi_queue', $queue, false );
	wp_cache_delete( 'dc_gi_queue', 'options' ); // Force-bust Redis persistent object cache.
}

add_action( DC_GI_CRON_HOOK, 'dc_gi_process_queue' );
/**
 * WP-Cron callback: submit pending URLs to the Google Indexing API in batches.
 * Respects the daily quota and re-queues failed items for the next run.
 */
function dc_gi_process_queue(): void {
	$settings = dc_gi_get_settings();
	$sa       = dc_gi_get_validated_sa( $settings );
	if ( is_wp_error( $sa ) ) {
		return;
	}

	// Daily quota — keyed on Pacific Time date to match Google's quota reset boundary.
	$quota_key = 'dc_gi_quota_' . dc_gi_quota_date_key();
	$used      = (int) get_transient( $quota_key );
	$limit     = min( DC_GI_DAILY_CAP, (int) ( $settings['daily_quota'] ?? DC_GI_DAILY_CAP ) );
	if ( $used >= $limit ) {
		return;
	}

	$queue = get_option( 'dc_gi_queue', [] );
	if ( empty( $queue ) ) {
		return;
	}

	// Google Batch API allows up to 100 requests per batch call.
	$can_process = min( 100, $limit - $used );
	$batch       = array_splice( $queue, 0, $can_process );
	dc_gi_update_option( 'dc_gi_queue', $queue );

	// Submit all items in a single batch request to reduce HTTP overhead.
	$results = DC_GI_JWT::submit_batch( $sa, $batch );

	$failed = [];
	foreach ( $batch as $item ) {
		$result = $results[ $item['url'] ] ?? new WP_Error( 'dc_gi_no_response', __( 'No response received.', 'dc-google-indexing' ) );
		dc_gi_add_log( $item['url'], $item['type'], $result );
		if ( ! is_wp_error( $result ) ) {
			set_transient( $quota_key, ++$used, DAY_IN_SECONDS );
		} else {
			$failed[] = $item;
		}
	}

	// Re-add failed items to the front of the queue so they are retried on the
	// next run rather than being silently dropped.
	if ( ! empty( $failed ) ) {
		$current_queue = get_option( 'dc_gi_queue', [] );
		$existing_urls = array_column( $current_queue, 'url' );
		foreach ( array_reverse( $failed ) as $item ) {
			if ( ! in_array( $item['url'], $existing_urls, true ) ) {
				array_unshift( $current_queue, $item );
				$existing_urls[] = $item['url'];
			}
		}
		dc_gi_update_option( 'dc_gi_queue', $current_queue );
	}
}

// =============================================================================
// LOG
// =============================================================================

/**
 * Record a URL submission result in the log and add the URL to the watchlist.
 *
 * @param string         $url    Submitted URL.
 * @param string         $type   Notification type (URL_UPDATED or URL_DELETED).
 * @param array|WP_Error $result API response or WP_Error on failure.
 */
function dc_gi_add_log( string $url, string $type, $result ): void {
	dc_gi_push_log_entry(
		[
			'url'    => $url,
			'type'   => $type,
			'status' => is_wp_error( $result ) ? 'error' : 'ok',
			'detail' => is_wp_error( $result )
				? $result->get_error_message()
				: ( $result['urlNotificationMetadata']['latestUpdate']['type'] ?? 'submitted' ),
		]
	);

	// On successful submission, add to watchlist to track indexing / removal.
	if ( ! is_wp_error( $result ) ) {
		if ( 'URL_DELETED' === $type ) {
			dc_gi_watchlist_add( $url, 'removal_pending' );
		} else {
			dc_gi_watchlist_add( $url );
		}
	}
}

/**
 * Add a plain informational entry to the log without triggering watchlist side effects.
 * Use this for automatic actions (sitemap removal, 404 detection) that should be
 * visible in the log but must not create new watchlist entries.
 *
 * @param string $url    Fully qualified URL.
 * @param string $type   Log entry type identifier (e.g. 'SITEMAP_REMOVED', 'POLL_404').
 * @param string $detail Human-readable detail message.
 */
function dc_gi_log_info( string $url, string $type, string $detail ): void {
	dc_gi_push_log_entry(
		[
			'url'    => $url,
			'type'   => $type,
			'status' => 'info',
			'detail' => $detail,
		]
	);
}

// =============================================================================
// WATCHLIST — track submitted URLs until Google indexes them
// =============================================================================

/**
 * Add a URL to the watchlist (idempotent — won't duplicate).
 * Status: 'pending' → 'indexed' once Google confirms.
 *
 * @param string $url    Fully qualified URL to track.
 * @param string $status Initial watchlist status ('pending' or 'removal_pending').
 */
function dc_gi_watchlist_add( string $url, string $status = 'pending' ): void {
	$list = get_option( 'dc_gi_watchlist', [] );
	foreach ( $list as &$entry ) {
		if ( $entry['url'] === $url ) {
			// If re-submitted for deletion, upgrade existing entry status.
			if ( 'removal_pending' === $status && 'removed' !== $entry['status'] ) {
				$entry['status']       = 'removal_pending';
				$entry['submitted_at'] = time();
				unset( $entry );
				dc_gi_update_option( 'dc_gi_watchlist', $list );
			} elseif ( 'pending' === $status ) {
				// Refresh the submission timestamp on every re-submission so the
				// watchlist-check throttle (HOUR_IN_SECONDS) gets a fresh baseline.
				$entry['submitted_at'] = time();
				unset( $entry );
				dc_gi_update_option( 'dc_gi_watchlist', $list );
			}
			return;
		}
	}
	unset( $entry );
	array_unshift(
		$list,
		[
			'url'          => esc_url_raw( $url ),
			'submitted_at' => time(),
			'status'       => in_array( $status, [ 'pending', 'removal_pending' ], true ) ? $status : 'pending',
			'last_checked' => 0,
			'coverage'     => '',
		]
	);
	// Cap at 500 entries — oldest drop off the bottom.
	dc_gi_update_option( 'dc_gi_watchlist', array_slice( $list, 0, 500 ) );
}

/**
 * Remove a single URL from the watchlist.
 *
 * @param string $url Fully qualified URL to remove.
 */
function dc_gi_watchlist_remove( string $url ): void {
	$list = get_option( 'dc_gi_watchlist', [] );
	$list = array_values( array_filter( $list, fn( $e ) => $e['url'] !== $url ) );
	dc_gi_update_option( 'dc_gi_watchlist', $list );
}

/**
 * Return all URLs currently in the watchlist.
 */
function dc_gi_watchlist_get(): array {
	return (array) get_option( 'dc_gi_watchlist', [] );
}

/**
 * Add a URL to the QA-pending list (idempotent).
 * Called when the Watchlist finds a URL is "Discovered - currently not indexed".
 *
 * @param string $url Fully qualified URL to flag for manual QA.
 */
function dc_gi_qa_pending_add( string $url ): void {
	$pending = (array) get_option( 'dc_gi_qa_pending', [] );
	if ( ! in_array( $url, $pending, true ) ) {
		$pending[] = $url;
		update_option( 'dc_gi_qa_pending', $pending, false );
	}
}

/**
 * Return sitemap URLs, caching the result for 5 minutes to avoid repeated
 * network fetches during a single watchlist-check or poll-batch run.
 *
 * @return array Empty array when the sitemap is unavailable.
 */
function dc_gi_get_sitemap_urls_cached(): array {
	$cached = get_transient( 'dc_gi_sitemap_urls_cache' );
	if ( false !== $cached ) {
		return (array) $cached;
	}
	$urls = DC_GI_Sitemap::get_urls( 2000 );
	if ( is_wp_error( $urls ) ) {
		return [];
	}
	set_transient( 'dc_gi_sitemap_urls_cache', $urls, 5 * MINUTE_IN_SECONDS );
	return $urls;
}

add_action( DC_GI_WATCH_HOOK, 'dc_gi_run_watchlist_check' );
/**
 * WP-Cron callback: inspect pending watchlist URLs via the URL Inspection API.
 * Updates coverage state, marks indexed/removed entries, and re-submits stale URLs.
 */
function dc_gi_run_watchlist_check(): void {
	$settings = dc_gi_get_settings();
	$sa       = dc_gi_get_validated_sa( $settings );
	if ( is_wp_error( $sa ) ) {
		return;
	}

	// When the daily Indexing API quota is exhausted, skip re-submissions but
	// still update coverage states so the watchlist stays current.
	$quota_ok = ! dc_gi_is_quota_exhausted();

	$site_url     = dc_gi_get_search_console_property( $settings );
	$list         = get_option( 'dc_gi_watchlist', [] );
	$updated      = false;
	$checked      = 0;
	$sitemap_urls = null; // Lazy-load on first resubmit candidate.

	$done_statuses = [ 'indexed', 'removed' ];

	// Build a prioritised processing order: sort pending entries by last_checked
	// ascending so URLs that have never been checked (last_checked = 0) or
	// were checked longest ago are inspected first.
	$pending_keys = [];
	foreach ( $list as $k => $entry ) {
		if ( ! in_array( $entry['status'], $done_statuses, true ) ) {
			$pending_keys[ $k ] = $entry['last_checked'];
		}
	}
	asort( $pending_keys ); // Ascending: oldest/never-checked first.

	foreach ( array_keys( $pending_keys ) as $k ) {
		if ( $checked >= 20 ) {
			break; // Quota-safe batch limit per run.
		}

		$result = DC_GI_JWT::inspect_url( $sa, $list[ $k ]['url'], $site_url );

		if ( is_wp_error( $result ) ) {
			if ( 'dc_gi_inspect_quota_exceeded' === $result->get_error_code() ) {
				// Quota hit mid-batch — don't record last_checked so the URL retries next run.
				break;
			}
			$list[ $k ]['last_checked'] = time();
			++$checked;
			$updated                = true;
			$list[ $k ]['coverage'] = 'error: ' . $result->get_error_message();
			continue;
		}

		$list[ $k ]['last_checked'] = time();
		++$checked;
		$updated = true;

		$coverage               = $result['inspectionResult']['indexStatusResult']['coverageState'] ?? '';
		$list[ $k ]['coverage'] = $coverage;

		// Sync the URL cache with this fresh result — keeps "need submission" counts
		// accurate without waiting for the background inspection cron to re-visit.
		DC_GI_URL_Cache::upsert( $list[ $k ]['url'], DC_GI_URL_Cache::parse_api_result( $result ) );

		$new_status = dc_gi_resolve_watchlist_status( $list[ $k ]['status'], $coverage );
		if ( 'removed' === $new_status || 'indexed' === $new_status ) {
			$list[ $k ]['status'] = $new_status;
		} elseif ( 'resubmit' === $new_status ) {
			// Before re-submitting, verify the URL is still in the sitemap.
			// If it has been removed from the site, auto-delete it from the
			// watchlist and add an informational log entry instead.
			if ( null === $sitemap_urls ) {
				$sitemap_urls = dc_gi_get_sitemap_urls_cached();
			}
			if ( ! empty( $sitemap_urls ) && ! in_array( $list[ $k ]['url'], $sitemap_urls, true ) ) {
				dc_gi_log_info(
					$list[ $k ]['url'],
					'SITEMAP_REMOVED',
					__( 'URL no longer in sitemap — auto-removed from watchlist', 'dc-google-indexing' )
				);
				unset( $list[ $k ] );
				continue;
			}
			// Only re-submit if the daily Indexing API quota has not been exhausted
			// and at least one hour has elapsed since the last submission — this
			// prevents Check Now from immediately re-populating the queue with URLs
			// that were just submitted and haven't been crawled by Google yet.
			if ( $quota_ok && time() - (int) ( $list[ $k ]['submitted_at'] ?? 0 ) > HOUR_IN_SECONDS ) {
				// Google has not indexed the URL yet — re-submit via Indexing API to
				// signal it is ready. This covers unknown, discovered, and crawled-but-
				// not-indexed states, giving Google a stronger hint to prioritise it.
				dc_gi_enqueue_url( $list[ $k ]['url'], 'URL_UPDATED' );
			}
			// Flag for manual QA when Google has seen the URL but not indexed it yet.
			if ( in_array( $coverage, DC_GI_QA_STATES, true ) ) {
				dc_gi_qa_pending_add( $list[ $k ]['url'] );
			}
		}
	}

	if ( $updated ) {
		dc_gi_update_option( 'dc_gi_watchlist', array_values( $list ) );
	}
}

// =============================================================================
// POLLING BATCH — reads URL cache, submits excluded URLs (no API calls here)
// =============================================================================

add_action( DC_GI_POLL_HOOK, 'dc_gi_run_poll_batch' );
/**
 * WP-Cron callback: read excluded URLs from the inspection cache and enqueue them for submission.
 *
 * @param bool $force Skip the active-flag check when triggered manually.
 * @return string Status string such as 'ok', 'cycle_complete', 'early:not_active', 'early:locked', 'early:cache_empty'.
 */
function dc_gi_run_poll_batch( bool $force = false ): string {
	// Only run when polling is active (skip check when forced via manual trigger).
	if ( ! $force && ! get_option( 'dc_gi_poll_active', false ) ) {
		return 'early:not_active';
	}
	// Simple lock to prevent concurrent cron + AJAX runs.
	if ( get_transient( 'dc_gi_poll_lock' ) ) {
		return 'early:locked';
	}
	set_transient( 'dc_gi_poll_lock', 1, 30 );

	try {
		// The inspection cache must be populated before polling can begin.
		// The DC_GI_INSPECT_HOOK cron fills it in the background.
		$cache_total = DC_GI_URL_Cache::count_total();
		if ( 0 === $cache_total ) {
			return 'early:cache_empty';
		}

		$poll_seen         = (array) get_option( 'dc_gi_poll_seen', [] );
		$poll_seen_initial = $poll_seen; // Snapshot to detect mid-batch resets.
		// Skip only URLs already processed in this cycle — the watchlist is intentionally
		// excluded from the skip list so polling can re-submit watchlist-tracked URLs that
		// Google still hasn't indexed. dc_gi_enqueue_url() is idempotent and handles
		// queue-level deduplication, so there is no risk of duplicate submissions.
		$skip_urls = $poll_seen;

		// Up to 5 URLs per batch — keeps each run small so the cron processes URLs incrementally
		// and the interactive long-poll session stays within PHP's execution time budget.
		$batch_size = 5;
		$candidates = DC_GI_URL_Cache::get_excluded_urls( $batch_size, $skip_urls );

		if ( empty( $candidates ) ) {
			// Full cycle done — deactivate polling so the cron does not restart automatically.
			// The user must click "Start Polling" again to begin a fresh cycle.
			dc_gi_set_poll_active( false );
			delete_option( 'dc_gi_poll_seen' );
			$cache_excluded = DC_GI_URL_Cache::count_excluded();
			set_transient(
				'dc_gi_last_poll',
				array_merge(
					(array) get_transient( 'dc_gi_last_poll' ),
					[
						'cycle_seen'      => count( $poll_seen ),
						'cycle_total'     => max( $cache_excluded, count( $poll_seen ) ),
						'cycle_done'      => true,
						'cycle_inspected' => count( $poll_seen ), // Backward-compat field.
						'source'          => 'cache',
					]
				),
				DAY_IN_SECONDS
			);
			return 'cycle_complete';
		}

		$queued     = 0;
		$skipped    = 0;
		$errors     = 0;
		$newly_seen = [];

		foreach ( $candidates as $url ) {
			$entry        = DC_GI_URL_Cache::get_entry( $url );
			$coverage     = $entry['coverage_state'] ?? '';
			$newly_seen[] = $url;

			// Log 404s for visibility but skip submission — submitting them is pointless.
			if ( in_array( $coverage, [ 'Not found (404)', 'Soft 404' ], true ) ) {
				++$skipped;
				dc_gi_log_info(
					$url,
					'POLL_404',
					/* translators: %s: Google coverage state (e.g. 'Not found (404)') */
					sprintf( __( '%s during polling — skipped submission', 'dc-google-indexing' ), $coverage )
				);
				continue;
			}

			dc_gi_enqueue_url( $url, 'URL_UPDATED' );
			DC_GI_URL_Cache::mark_submitted( $url );
			++$queued;
		}

		// Advance cursor.
		$poll_seen   = array_values( array_unique( array_merge( $poll_seen, $newly_seen ) ) );
		$cycle_seen  = count( $poll_seen );
		$cache_excl  = DC_GI_URL_Cache::count_excluded();
		$cycle_total = max( $cache_excl, $cycle_seen );
		$cycle_done  = false;

		// Carry forward cumulative cycle totals from previous batches.
		$prev          = (array) get_transient( 'dc_gi_last_poll' );
		$cycle_queued  = ( $prev['cycle_queued'] ?? 0 ) + $queued;
		$cycle_skipped = ( $prev['cycle_skipped'] ?? 0 ) + $skipped;
		$cycle_errors  = ( $prev['cycle_errors'] ?? 0 ) + $errors;

		// Detect if a Reset Cycle happened while this batch was running.
		if ( ! empty( $poll_seen_initial ) ) {
			global $wpdb;
			$fresh_seen = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					'dc_gi_poll_seen'
				)
			);
			if ( null === $fresh_seen ) {
				return 'ok:reset_detected';
			}
		}

		update_option( 'dc_gi_poll_seen', $poll_seen, false );

		set_transient(
			'dc_gi_last_poll',
			[
				'time'            => time(),
				'queued'          => $queued,
				'skipped'         => $skipped,
				'errors'          => $errors,
				'cycle_queued'    => $cycle_queued,
				'cycle_skipped'   => $cycle_skipped,
				'cycle_errors'    => $cycle_errors,
				'cycle_inspected' => $cycle_seen,  // Backward-compat — repurposed as "seen from cache".
				'cycle_seen'      => $cycle_seen,
				'cycle_total'     => $cycle_total,
				'cycle_done'      => $cycle_done,
				'source'          => 'cache',
			],
			DAY_IN_SECONDS
		);

		return 'ok';

	} finally {
		delete_transient( 'dc_gi_poll_lock' );
	}
}

// =============================================================================
// INSPECTION BATCH — populate URL cache from sitemap via GSC Inspection API
// =============================================================================

add_action( DC_GI_INSPECT_HOOK, 'dc_gi_run_inspect_batch' );
/**
 * WP-Cron callback: run one inspection batch to populate the URL cache from the sitemap.
 *
 * Returns the status string from DC_GI_URL_Cache::run_inspect_batch(), or
 * 'early:no_sa' when service-account credentials are missing/invalid.
 * The cron hook ignores this return value; callers such as the long-poll
 * AJAX handler use it to surface the reason the cache is not yet populated.
 *
 * @return string Status string: 'ok', 'ok:complete', 'early:no_sa', 'early:quota_backoff', 'early:no_urls'.
 */
function dc_gi_run_inspect_batch(): string {
	$settings = dc_gi_get_settings();
	$sa       = dc_gi_get_validated_sa( $settings );
	if ( is_wp_error( $sa ) ) {
		// Only log once per hour so we don't flood the log on every cron tick.
		if ( false === get_transient( 'dc_gi_inspect_sa_warn' ) ) {
			$msg = 'dc_gi_no_sa' === $sa->get_error_code()
				? __( 'Inspection cache not building — service account credentials not configured.', 'dc-google-indexing' )
				: __( 'Inspection cache not building — service account JSON is invalid (missing client_email or private_key).', 'dc-google-indexing' );
			dc_gi_log_info( '', 'INSPECT_SKIP', $msg );
			set_transient( 'dc_gi_inspect_sa_warn', 1, HOUR_IN_SECONDS );
		}
		return 'early:no_sa';
	}
	$status = DC_GI_URL_Cache::run_inspect_batch( $sa );
	if ( 'early:quota_backoff' === $status ) {
		// URL Inspection API quota is temporarily exhausted — cron will retry after backoff expires.
		return $status;
	} elseif ( 'early:no_urls' === $status ) {
		if ( false === get_transient( 'dc_gi_inspect_sitemap_warn' ) ) {
			dc_gi_log_info( '', 'INSPECT_SITEMAP_ERR', __( 'Inspection cache not building — no URLs found in sitemap.', 'dc-google-indexing' ) );
			set_transient( 'dc_gi_inspect_sitemap_warn', 1, HOUR_IN_SECONDS );
		}
	}
	return $status;
}

// =============================================================================
// ANALYTICS BATCH — fetch Search Analytics data twice daily
// =============================================================================

add_action( DC_GI_ANALYTICS_HOOK, 'dc_gi_run_analytics_batch' );
/**
 * WP-Cron callback: fetch Search Analytics data for all cached URLs.
 *
 * Runs twice daily (twicedaily schedule).  Silently skips when credentials
 * are not configured, logging a warning at most once per hour.
 */
function dc_gi_run_analytics_batch(): void {
	$settings = dc_gi_get_settings();
	$sa       = dc_gi_get_validated_sa( $settings );
	if ( is_wp_error( $sa ) ) {
		if ( false === get_transient( 'dc_gi_analytics_sa_warn' ) ) {
			$msg = 'dc_gi_no_sa' === $sa->get_error_code()
				? __( 'Search Analytics not fetching — service account credentials not configured.', 'dc-google-indexing' )
				: __( 'Search Analytics not fetching — service account JSON is invalid.', 'dc-google-indexing' );
			dc_gi_log_info( '', 'ANALYTICS_SKIP', $msg );
			set_transient( 'dc_gi_analytics_sa_warn', 1, HOUR_IN_SECONDS );
		}
		return;
	}

	$days     = max( 1, (int) ( $settings['analytics_days'] ?? 28 ) );
	$site_url = dc_gi_get_search_console_property( $settings );
	DC_GI_URL_Cache::run_analytics_batch( $sa, $site_url, $days );
}

// =============================================================================
// ON-DEMAND INSPECTION SIGNAL
// =============================================================================

add_action( DC_GI_INSPECT_SIGNAL_HOOK, 'dc_gi_cron_inspection_signal' );
/**
 * WP-Cron callback: fire a single URL Inspection API signal.
 *
 * Scheduled on-demand by dc_gi_ajax_watch_resubmit_one() via
 * wp_schedule_single_event() + spawn_cron() so the Google API call runs in
 * the background and never blocks the admin AJAX response.  The API result
 * is intentionally discarded — this is a crawl-signal call only.
 *
 * @param string $url The URL to signal.
 */
function dc_gi_cron_inspection_signal( string $url ): void {
	$settings = dc_gi_get_settings();
	$sa       = dc_gi_get_validated_sa( $settings );
	if ( is_wp_error( $sa ) ) {
		return;
	}
	$site_url = dc_gi_get_search_console_property( $settings );
	if ( ! $site_url ) {
		return;
	}
	DC_GI_JWT::inspect_url( $sa, $url, $site_url );
}

// =============================================================================
// HELPERS
// =============================================================================

/**
 * Return the plugin settings array from the database.
 *
 * @return array Plugin settings (empty array when not yet saved).
 */
function dc_gi_get_settings(): array {
	return (array) get_option( 'dc_gi_settings', [] );
}

/**
 * Normalize a Search Console property string for storage and API calls.
 *
 * Accepts either a URL-prefix property (https://example.com/) or a domain
 * property (sc-domain:example.com). Falls back to an empty string when the
 * value cannot be normalized safely.
 *
 * @param string $value Raw property value from user input or saved settings.
 * @return string
 */
function dc_gi_normalize_search_console_property( string $value ): string {
	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	if ( 0 === strpos( $value, 'sc-domain:' ) ) {
		$domain = strtolower( trim( substr( $value, strlen( 'sc-domain:' ) ) ) );
		$domain = preg_replace( '/[^a-z0-9.-]/', '', $domain );
		return $domain ? 'sc-domain:' . $domain : '';
	}

	$url = esc_url_raw( $value, [ 'http', 'https' ] );
	if ( ! $url ) {
		return '';
	}

	return trailingslashit( $url );
}

/**
 * Return the configured Search Console property, or fall back to the site URL.
 *
 * @param array|null $settings Optional settings array to avoid re-loading options.
 * @return string
 */
function dc_gi_get_search_console_property( ?array $settings = null ): string {
	if ( null === $settings ) {
		$settings = dc_gi_get_settings();
	}

	$stored = dc_gi_normalize_search_console_property( (string) ( $settings['search_console_property'] ?? '' ) );
	if ( '' !== $stored ) {
		return $stored;
	}

	return trailingslashit( get_home_url() );
}

/**
 * Reliably write dc_gi_poll_active and ensure the value is immediately visible
 * to subsequent get_option() calls within the same request.
 *
 * Uses update_option() / add_option() instead of raw $wpdb queries so WordPress's
 * options cache (including the autoloaded alloptions bucket) is properly invalidated.
 * The row-existence check is kept via $wpdb so the correct API is used for each case.
 *
 * @param bool $active True to activate polling, false to deactivate.
 */
function dc_gi_set_poll_active( bool $active ): void {
	global $wpdb;
	$value  = $active ? '1' : '';
	$exists = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
			'dc_gi_poll_active'
		)
	);
	if ( $exists ) {
		update_option( 'dc_gi_poll_active', $value, 'yes' );
	} else {
		add_option( 'dc_gi_poll_active', $value, '', 'yes' );
	}
}

/**
 * Return the number of Indexing API submissions made today (Pacific Time).
 *
 * @return int Number of submissions used against today's quota.
 */
function dc_gi_get_quota_used(): int {
	return (int) get_transient( 'dc_gi_quota_' . dc_gi_quota_date_key() );
}

/**
 * Return true when the daily Indexing API quota is fully consumed.
 */
function dc_gi_is_quota_exhausted(): bool {
	$settings = dc_gi_get_settings();
	$limit    = min( DC_GI_DAILY_CAP, (int) ( $settings['daily_quota'] ?? DC_GI_DAILY_CAP ) );
	return dc_gi_get_quota_used() >= $limit;
}

// =============================================================================
// SHARED HELPERS — extracted to eliminate duplication across cron callbacks
// =============================================================================

/**
 * Retrieve and validate the service account credentials from plugin settings.
 *
 * Returns the decoded JSON array on success, or a WP_Error when credentials
 * are absent or malformed.  Accepts a pre-loaded settings array to avoid a
 * redundant get_option() call when the caller already holds settings.
 *
 * @param array|null $settings Pre-loaded settings array, or null to load from DB.
 * @return array|WP_Error Decoded service account array or WP_Error on failure.
 */
function dc_gi_get_validated_sa( ?array $settings = null ) {
	if ( null === $settings ) {
		$settings = dc_gi_get_settings();
	}
	if ( empty( $settings['service_account_json'] ) ) {
		return new WP_Error( 'dc_gi_no_sa', __( 'Service account credentials not configured.', 'dc-google-indexing' ) );
	}
	$sa = json_decode( $settings['service_account_json'], true );
	if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
		return new WP_Error( 'dc_gi_invalid_sa', __( 'Service account JSON is invalid (missing client_email or private_key).', 'dc-google-indexing' ) );
	}
	return $sa;
}

/**
 * Persist an option and immediately invalidate the persistent object cache entry.
 *
 * Drop-in replacement for update_option(..., false) that also busts any Redis /
 * persistent object cache so stale values are never served after a write.
 *
 * @param string $option Option name.
 * @param mixed  $value  Option value.
 * @return bool True if the value was updated, false on failure or no change.
 */
function dc_gi_update_option( string $option, $value ): bool {
	$result = update_option( $option, $value, false );
	wp_cache_delete( $option, 'options' );
	return $result;
}

/**
 * Prepend a log entry to the submission log and cap the list at 100 entries.
 *
 * The 'time' key is added automatically; callers supply url, type, status,
 * and detail.
 *
 * @param array $entry Associative array of log fields (url, type, status, detail).
 */
function dc_gi_push_log_entry( array $entry ): void {
	$log = get_option( 'dc_gi_log', [] );
	array_unshift( $log, array_merge( [ 'time' => time() ], $entry ) );
	update_option( 'dc_gi_log', array_slice( $log, 0, 100 ), false );
}

/**
 * Tear down the live watchlist check loop.
 *
 * Clears the active flag, resets the cursor, and removes the recurring
 * per-minute cron event.  Call whenever the loop reaches its natural end or
 * is stopped early (quota hit, SA missing, all entries done).
 */
function dc_gi_cleanup_watch_check(): void {
	delete_option( 'dc_gi_watch_active' );
	delete_option( 'dc_gi_watch_offset' );
	wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
}

/**
 * Determine the new watchlist status for an entry from Google's coverage state.
 *
 * Returns one of four tokens:
 *   'indexed'   — URL is now indexed; mark complete.
 *   'removed'   — URL is gone from Google; mark complete (removal flow).
 *   'resubmit'  — URL is known but not indexed; caller should re-enqueue and QA-flag.
 *   ''          — No status change warranted (unrecognised or ambiguous state).
 *
 * Callers remain responsible for quota checks, sitemap verification, enqueueing,
 * and QA flagging within the 'resubmit' branch.
 *
 * @param string $current_status The entry's current watchlist status field.
 * @param string $coverage       Coverage state string from the URL Inspection API response.
 * @return string Status token (see above), or '' for no change.
 */
function dc_gi_resolve_watchlist_status( string $current_status, string $coverage ): string {
	if ( 'removal_pending' === $current_status ) {
		$removed_coverage = [ '', 'URL is unknown to Google', 'Not found (404)', 'Soft 404' ];
		return in_array( $coverage, $removed_coverage, true ) ? 'removed' : '';
	}
	if ( 'Submitted and indexed' === $coverage || 'Indexed, not submitted in sitemap' === $coverage ) {
		return 'indexed';
	}
	if ( in_array( $coverage, DC_GI_RESUBMIT_STATES, true ) ) {
		return 'resubmit';
	}
	return '';
}

// =============================================================================
// ACTIVATION / DEACTIVATION
// =============================================================================

register_activation_hook( DC_GI_FILE, 'dc_gi_activate' );
/**
 * Plugin activation: create the URL cache table and schedule all WP-Cron events.
 */
function dc_gi_activate(): void {
	DC_GI_URL_Cache::create_table();
	update_option( 'dc_gi_db_version', DC_GI_DB_VERSION );
	if ( ! wp_next_scheduled( DC_GI_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'dc_gi_every5', DC_GI_CRON_HOOK );
	}
	if ( ! wp_next_scheduled( DC_GI_WATCH_HOOK ) ) {
		wp_schedule_event( time() + 300, 'dc_gi_sixhourly', DC_GI_WATCH_HOOK );
	}
	if ( ! wp_next_scheduled( DC_GI_POLL_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_every1', DC_GI_POLL_HOOK );
	}
	if ( ! wp_next_scheduled( DC_GI_INSPECT_HOOK ) ) {
		wp_schedule_event( time() + 90, 'dc_gi_every1', DC_GI_INSPECT_HOOK );
	}
	if ( ! wp_next_scheduled( DC_GI_ANALYTICS_HOOK ) ) {
		wp_schedule_event( time() + 120, 'twicedaily', DC_GI_ANALYTICS_HOOK );
	}
}

add_action( 'init', 'dc_gi_maybe_reschedule_crons' );
/**
 * Reschedule any missing WP-Cron events on every page load (self-healing).
 * Also ensures the URL-cache table exists after plugin upgrades — WordPress
 * does not fire register_activation_hook on updates, so users upgrading from
 * v1.0.x (before the cache table was introduced) would otherwise never have
 * the table created.
 */
function dc_gi_maybe_reschedule_crons(): void {
	// Create (or upgrade) the URL-cache DB table if this is a fresh install or a
	// plugin upgrade that changed the schema.  dbDelta uses CREATE TABLE IF NOT EXISTS
	// so this is safe to call repeatedly and is a no-op once the table is current.
	if ( get_option( 'dc_gi_db_version' ) !== DC_GI_DB_VERSION ) {
		DC_GI_URL_Cache::create_table();
		update_option( 'dc_gi_db_version', DC_GI_DB_VERSION );
	}

	// Fire immediately — queue processor runs every 5 minutes.
	if ( ! wp_next_scheduled( DC_GI_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'dc_gi_every5', DC_GI_CRON_HOOK );
	}
	// Stagger by 5 min to avoid all crons firing at the same second on activation.
	if ( ! wp_next_scheduled( DC_GI_WATCH_HOOK ) ) {
		wp_schedule_event( time() + 300, 'dc_gi_sixhourly', DC_GI_WATCH_HOOK );
	}
	// Stagger by 1 min so polling cron does not collide with queue cron at t=0.
	if ( ! wp_next_scheduled( DC_GI_POLL_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_every1', DC_GI_POLL_HOOK );
	}
	// Inspection cron: stagger by 90s so it doesn't fire at the same time as polling.
	if ( ! wp_next_scheduled( DC_GI_INSPECT_HOOK ) ) {
		wp_schedule_event( time() + 90, 'dc_gi_every1', DC_GI_INSPECT_HOOK );
	}
	// Analytics cron: runs twice daily to fetch Search Analytics data.
	if ( ! wp_next_scheduled( DC_GI_ANALYTICS_HOOK ) ) {
		wp_schedule_event( time() + 120, 'twicedaily', DC_GI_ANALYTICS_HOOK );
	}
	// Restore the recurring watchlist check-one cron if it was lost but is still needed.
	if ( get_option( 'dc_gi_watch_active', false ) && ! wp_next_scheduled( DC_GI_WATCH_CHECK_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_every1', DC_GI_WATCH_CHECK_HOOK );
	}
}

// =============================================================================
// WATCH CHECK-ONE CRON — drives the live-check loop server-side when JS is gone
// =============================================================================

add_action( DC_GI_WATCH_CHECK_HOOK, 'dc_gi_run_watch_check_one_cron' );
/**
 * WP-Cron callback: inspect one watchlist URL per minute to drive the background live-check loop.
 */
function dc_gi_run_watch_check_one_cron(): void {
	// Bail if the user already stopped via JS.
	if ( ! get_option( 'dc_gi_watch_active', false ) ) {
		return;
	}

	$settings = dc_gi_get_settings();
	$sa       = dc_gi_get_validated_sa( $settings );
	if ( is_wp_error( $sa ) ) {
		delete_option( 'dc_gi_watch_active' );
		return;
	}

	$offset   = (int) get_option( 'dc_gi_watch_offset', 0 );
	$site_url = dc_gi_get_search_console_property( $settings );
	$list     = get_option( 'dc_gi_watchlist', [] );
	$keys     = array_keys( $list );
	$total    = count( $keys );

	$done_statuses = [ 'indexed', 'removed' ];

	// Advance past already-done entries.
	while ( $offset < $total && in_array( $list[ $keys[ $offset ] ]['status'] ?? '', $done_statuses, true ) ) {
		++$offset;
	}

	if ( $offset >= $total ) {
		// All done — clean up and remove the recurring cron.
		dc_gi_cleanup_watch_check();
		return;
	}

	$key   = $keys[ $offset ];
	$entry = &$list[ $key ];

	$result = DC_GI_JWT::inspect_url( $sa, $entry['url'], $site_url );

	if ( is_wp_error( $result ) ) {
		if ( 'dc_gi_inspect_quota_exceeded' === $result->get_error_code() ) {
			// Quota hit — don't record last_checked or advance offset.
			return;
		}
		$entry['last_checked'] = time();
		$entry['coverage']     = 'error: ' . $result->get_error_message();
		$entry['status']       = 'error';
	} else {
		$entry['last_checked'] = time();
		$coverage              = $result['inspectionResult']['indexStatusResult']['coverageState'] ?? '';
		$entry['coverage']     = $coverage;

		// Sync the URL cache with this fresh result — keeps "need submission" counts
		// accurate without waiting for the background inspection cron to re-visit.
		DC_GI_URL_Cache::upsert( $entry['url'], DC_GI_URL_Cache::parse_api_result( $result ) );

		$new_status = dc_gi_resolve_watchlist_status( $entry['status'], $coverage );
		if ( 'removed' === $new_status || 'indexed' === $new_status ) {
			$entry['status'] = $new_status;
		} elseif ( 'resubmit' === $new_status ) {
			// Before re-submitting, check that the URL is still in the sitemap.
			$sitemap_urls = dc_gi_get_sitemap_urls_cached();
			if ( ! empty( $sitemap_urls ) && ! in_array( $entry['url'], $sitemap_urls, true ) ) {
				$entry_url = $entry['url'];
				unset( $entry );
				dc_gi_log_info(
					$entry_url,
					'SITEMAP_REMOVED',
					__( 'URL no longer in sitemap — auto-removed from watchlist', 'dc-google-indexing' )
				);
				unset( $list[ $key ] );
				$list = array_values( $list );
				dc_gi_update_option( 'dc_gi_watchlist', $list );
				// Recalculate keys/total after removal and continue from same position.
				$keys  = array_keys( $list );
				$total = count( $keys );
				$next  = $offset; // Stay at same position since we removed an entry.
				while ( $next < $total && in_array( $list[ $keys[ $next ] ]['status'] ?? '', $done_statuses, true ) ) {
					++$next;
				}
				if ( $next >= $total ) {
					dc_gi_cleanup_watch_check();
				} else {
					dc_gi_update_option( 'dc_gi_watch_offset', $next );
				}
				return;
			}
			// Re-enqueue only when enough time has elapsed since the last submission —
			// prevents immediately re-populating the queue with freshly submitted URLs
			// that Google has not yet had a chance to crawl.
			if ( time() - (int) ( $entry['submitted_at'] ?? 0 ) > HOUR_IN_SECONDS ) {
				dc_gi_enqueue_url( $entry['url'], 'URL_UPDATED' );
				$entry['coverage']  = ( ! empty( $coverage ) ) ? $coverage : 'URL is unknown to Google';
				$entry['coverage'] .= ' (re-queued for submission)';
			}
			// Flag for manual QA when Google has seen the URL but not indexed it yet.
			if ( in_array( $coverage, DC_GI_QA_STATES, true ) ) {
				dc_gi_qa_pending_add( $entry['url'] );
			}
			$entry['status'] = 'pending';
		} else {
			$entry['status'] = 'pending';
		}
	}
	unset( $entry );
	dc_gi_update_option( 'dc_gi_watchlist', $list );

	$next = $offset + 1;
	while ( $next < $total && in_array( $list[ $keys[ $next ] ]['status'] ?? '', $done_statuses, true ) ) {
		++$next;
	}

	if ( $next >= $total ) {
		// Cycle complete — clean up and remove the recurring cron.
		dc_gi_cleanup_watch_check();
	} else {
		// Advance cursor — recurring 1-minute cron will fire the next check automatically.
		dc_gi_update_option( 'dc_gi_watch_offset', $next );
	}
}

register_deactivation_hook( DC_GI_FILE, 'dc_gi_deactivate' );
/**
 * Plugin deactivation: remove all scheduled WP-Cron events and clear active flags.
 */
function dc_gi_deactivate(): void {
	wp_clear_scheduled_hook( DC_GI_CRON_HOOK );
	wp_clear_scheduled_hook( DC_GI_WATCH_HOOK );
	wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
	wp_clear_scheduled_hook( DC_GI_POLL_HOOK );
	wp_clear_scheduled_hook( DC_GI_INSPECT_HOOK );
	wp_clear_scheduled_hook( DC_GI_ANALYTICS_HOOK );
	update_option( 'dc_gi_poll_active', false );
	delete_option( 'dc_gi_watch_active' );
	delete_option( 'dc_gi_watch_offset' );
}

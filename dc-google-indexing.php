<?php
/**
 * @wordpress-plugin
 * Plugin Name: DC Google Indexing
 * Plugin URI:  https://github.com/dc-plugins/dc-google-indexing
 * Description: Submit URLs to Google's Web Search Indexing API for instant crawling. Supports manual batch submission and automatic submission on publish/update.
 * Version:     1.0.0
 * Author:      Dampcig
 * Author URI:  https://www.dampcig.dk
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dc-google-indexing
 * Requires at least: 6.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DC_GI_VERSION',     '1.0.0' );
define( 'DC_GI_FILE',        __FILE__ );
define( 'DC_GI_DIR',         plugin_dir_path( __FILE__ ) );
define( 'DC_GI_CRON_HOOK',        'dc_gi_process_queue' );
define( 'DC_GI_WATCH_HOOK',       'dc_gi_check_watchlist' );
define( 'DC_GI_WATCH_CHECK_HOOK', 'dc_gi_watch_check_one_cron' );
define( 'DC_GI_POLL_HOOK',        'dc_gi_poll_batch' );
define( 'DC_GI_DAILY_CAP',   200 );

require_once DC_GI_DIR . 'class-jwt.php';
require_once DC_GI_DIR . 'class-sitemap.php';
require_once DC_GI_DIR . 'admin.php';

// =============================================================================
// CRON SCHEDULE
// =============================================================================

add_filter( 'cron_schedules', 'dc_gi_cron_schedules' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_cron_schedules( array $schedules ): array {
	if ( ! isset( $schedules['dc_gi_every5'] ) ) {
		$schedules['dc_gi_every5'] = [
			'interval' => 300,
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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_on_status_change( string $new_status, string $old_status, WP_Post $post ): void {
	$settings = dc_gi_get_settings();
	if ( empty( $settings['auto_submit'] ) ) {
		return;
	}
	$post_types = $settings['post_types'] ?? [ 'post', 'page' ];
	if ( ! in_array( $post->post_type, $post_types, true ) ) {
		return;
	}

	if ( 'publish' === $new_status ) {
		// Published or re-published — notify Google to index it.
		$url = get_permalink( $post->ID );
		if ( $url ) {
			dc_gi_enqueue_url( $url, 'URL_UPDATED' );
		}
		return;
	}

	// Transitioning away from publish to draft / private / pending — remove from index.
	if ( 'publish' === $old_status && in_array( $new_status, [ 'draft', 'private', 'pending' ], true ) ) {
		// Build the public permalink from the post data: at this point the post
		// is already saved with the new status, so get_permalink() returns '?p=ID'
		// for drafts. Clone the object with 'publish' status and filter='sample'
		// so WordPress computes the real URL from the post slug.
		$pub = clone $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$pub->post_status = 'publish'; // Tells get_permalink() to use the real structure.
		$pub->filter      = 'sample';  // Tells get_permalink() to use the object as-is.
		$url = get_permalink( $pub );
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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_on_post_trashed( int $post_id, string $previous_status ): void {
	if ( 'publish' !== $previous_status ) {
		return;
	}
	$settings = dc_gi_get_settings();
	if ( empty( $settings['auto_submit'] ) ) {
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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_on_post_password_set( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
	if ( 'publish' !== $post_before->post_status || 'publish' !== $post_after->post_status ) {
		return;
	}
	if ( ! empty( $post_before->post_password ) || empty( $post_after->post_password ) ) {
		// Either already had a password, or no password added — nothing to do.
		return;
	}
	$settings = dc_gi_get_settings();
	if ( empty( $settings['auto_submit'] ) ) {
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
}

// =============================================================================
// QUEUE
// =============================================================================

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
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
}

add_action( DC_GI_CRON_HOOK, 'dc_gi_process_queue' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_process_queue(): void {
	$settings = dc_gi_get_settings();
	if ( empty( $settings['service_account_json'] ) ) {
		return;
	}
	$sa = json_decode( $settings['service_account_json'], true );
	if ( ! $sa || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
		return;
	}

	// Daily quota
	$quota_key = 'dc_gi_quota_' . gmdate( 'Y-m-d' );
	$used      = (int) get_transient( $quota_key );
	$limit     = min( DC_GI_DAILY_CAP, (int) ( $settings['daily_quota'] ?? DC_GI_DAILY_CAP ) );
	if ( $used >= $limit ) {
		return;
	}

	$queue = get_option( 'dc_gi_queue', [] );
	if ( empty( $queue ) ) {
		return;
	}

	$can_process = min( 10, $limit - $used );
	$batch       = array_splice( $queue, 0, $can_process );
	update_option( 'dc_gi_queue', $queue, false );

	foreach ( $batch as $item ) {
		$result = DC_GI_JWT::submit_url( $sa, $item['url'], $item['type'] );
		dc_gi_add_log( $item['url'], $item['type'], $result );
		if ( ! is_wp_error( $result ) ) {
			set_transient( $quota_key, ++$used, DAY_IN_SECONDS );
		}
	}
}

// =============================================================================
// LOG
// =============================================================================

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_add_log( string $url, string $type, $result ): void {
	$log = get_option( 'dc_gi_log', [] );
	array_unshift( $log, [
		'url'    => $url,
		'type'   => $type,
		'time'   => time(),
		'status' => is_wp_error( $result ) ? 'error' : 'ok',
		'detail' => is_wp_error( $result )
			? $result->get_error_message()
			: ( $result['urlNotificationMetadata']['latestUpdate']['type'] ?? 'submitted' ),
	] );
	update_option( 'dc_gi_log', array_slice( $log, 0, 100 ), false );

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
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_log_info( string $url, string $type, string $detail ): void {
	$log = get_option( 'dc_gi_log', [] );
	array_unshift( $log, [
		'url'    => $url,
		'type'   => $type,
		'time'   => time(),
		'status' => 'info',
		'detail' => $detail,
	] );
	update_option( 'dc_gi_log', array_slice( $log, 0, 100 ), false );
}

// =============================================================================
// WATCHLIST — track submitted URLs until Google indexes them
// =============================================================================

/**
 * Add a URL to the watchlist (idempotent — won't duplicate).
 * Status: 'pending' → 'indexed' once Google confirms.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_watchlist_add( string $url, string $status = 'pending' ): void {
	$list = get_option( 'dc_gi_watchlist', [] );
	foreach ( $list as &$entry ) {
		if ( $entry['url'] === $url ) {
			// If re-submitted for deletion, upgrade existing entry status.
			if ( 'removal_pending' === $status && 'removed' !== $entry['status'] ) {
				$entry['status']    = 'removal_pending';
				$entry['submitted_at'] = time();
				unset( $entry );
				update_option( 'dc_gi_watchlist', $list, false );
			}
			return;
		}
	}
	unset( $entry );
	array_unshift( $list, [
		'url'          => esc_url_raw( $url ),
		'submitted_at' => time(),
		'status'       => in_array( $status, [ 'pending', 'removal_pending' ], true ) ? $status : 'pending',
		'last_checked' => 0,
		'coverage'     => '',
	] );
	// Cap at 500 entries — oldest drop off the bottom.
	update_option( 'dc_gi_watchlist', array_slice( $list, 0, 500 ), false );
}

/**
 * Remove a single URL from the watchlist.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_watchlist_remove( string $url ): void {
	$list = get_option( 'dc_gi_watchlist', [] );
	$list = array_values( array_filter( $list, fn( $e ) => $e['url'] !== $url ) );
	update_option( 'dc_gi_watchlist', $list, false );
}

/**
 * Return all URLs currently in the watchlist.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_watchlist_get(): array {
	return (array) get_option( 'dc_gi_watchlist', [] );
}

/**
 * Return sitemap URLs, caching the result for 5 minutes to avoid repeated
 * network fetches during a single watchlist-check or poll-batch run.
 *
 * @return array Empty array when the sitemap is unavailable.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
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

/**
 * Background cron: inspect pending watchlist URLs and mark indexed ones.
 * Checks up to 20 pending URLs per run to stay well within quota.
 */
add_action( DC_GI_WATCH_HOOK, 'dc_gi_run_watchlist_check' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_run_watchlist_check(): void {
	$settings = dc_gi_get_settings();
	if ( empty( $settings['service_account_json'] ) ) {
		return;
	}
	$sa       = json_decode( $settings['service_account_json'], true );
	if ( ! $sa || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
		return;
	}

	$site_url      = trailingslashit( get_home_url() );
	$list          = get_option( 'dc_gi_watchlist', [] );
	$updated       = false;
	$checked       = 0;
	$sitemap_urls  = null; // Lazy-load on first resubmit candidate.

	$done_statuses = [ 'indexed', 'removed' ];

	// Coverage states that benefit from re-submission via the Indexing API.
	// Google has seen the URL but has not indexed it yet — a re-submit signal
	// can accelerate indexing for these states.
	$resubmit_states = [
		'Crawled - currently not indexed',
		'Discovered - currently not indexed',
		'URL is unknown to Google',
		'', // API returns empty for completely unknown URLs.
	];

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
		$list[ $k ]['last_checked'] = time();
		$checked++;
		$updated = true;

		if ( is_wp_error( $result ) ) {
			$list[ $k ]['coverage'] = 'error: ' . $result->get_error_message();
			continue;
		}

		$coverage = $result['inspectionResult']['indexStatusResult']['coverageState'] ?? '';
		$list[ $k ]['coverage'] = $coverage;

		if ( 'removal_pending' === $list[ $k ]['status'] ) {
			// Waiting for de-indexing — mark removed when Google no longer knows the URL.
			if ( '' === $coverage || 'URL is unknown to Google' === $coverage
				|| 'Not found (404)' === $coverage || 'Soft 404' === $coverage ) {
				$list[ $k ]['status'] = 'removed';
			}
		} elseif ( 'Submitted and indexed' === $coverage
			|| 'Indexed, not submitted in sitemap' === $coverage ) {
			$list[ $k ]['status'] = 'indexed';
		} elseif ( in_array( $coverage, $resubmit_states, true ) ) {
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
			// Google has not indexed the URL yet — re-submit via Indexing API to
			// signal it is ready. This covers unknown, discovered, and crawled-but-
			// not-indexed states, giving Google a stronger hint to prioritise it.
			dc_gi_enqueue_url( $list[ $k ]['url'], 'URL_UPDATED' );
		}
	}

	if ( $updated ) {
		update_option( 'dc_gi_watchlist', array_values( $list ), false );
	}
}

// =============================================================================
// POLLING BATCH — 5 URLs per run, cursor-aware, lock-protected
// =============================================================================

add_action( DC_GI_POLL_HOOK, 'dc_gi_run_poll_batch' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
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
		$settings = dc_gi_get_settings();
		if ( empty( $settings['service_account_json'] ) ) {
			return 'early:no_service_account';
		}
		$sa = json_decode( $settings['service_account_json'], true );
		if ( ! $sa || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return 'early:invalid_service_account';
		}

		$site_url    = trailingslashit( get_home_url() );
		$batch_size  = 1;
		$submittable = [
			'Crawled - currently not indexed',
			'Discovered - currently not indexed',
			'URL is unknown to Google', // Never seen by Google — submit to make it discoverable.
			'',                          // API returns empty string for completely unknown URLs.
		];

		$all_urls = DC_GI_Sitemap::get_urls( 2000 );
		if ( is_wp_error( $all_urls ) ) {
			return 'early:sitemap_error:' . $all_urls->get_error_message();
		}

		$watched_urls = array_column( dc_gi_watchlist_get(), 'url' );
		$poll_seen    = (array) get_option( 'dc_gi_poll_seen', [] );
		$eligible     = array_values( array_diff( $all_urls, $watched_urls ) );
		$candidates   = array_values( array_diff( $eligible, $poll_seen ) );

		if ( empty( $candidates ) ) {
			// Full cycle done — reset cursor and begin the next cycle automatically.
			delete_option( 'dc_gi_poll_seen' );
			set_transient( 'dc_gi_last_poll', array_merge(
				(array) get_transient( 'dc_gi_last_poll' ),
				[
					'cycle_seen'  => count( $eligible ),
					'cycle_total' => count( $eligible ),
					'cycle_done'  => true,
				]
			), DAY_IN_SECONDS );
			return 'cycle_complete';
		}

		$inspected  = 0;
		$queued     = 0;
		$skipped    = 0;
		$errors     = 0;
		$newly_seen = [];

		foreach ( array_slice( $candidates, 0, $batch_size ) as $url ) {
			$result = DC_GI_JWT::inspect_url( $sa, $url, $site_url );
			$inspected++;
			$newly_seen[] = $url;

			if ( is_wp_error( $result ) ) {
				$errors++;
				dc_gi_add_log( $url, 'INSPECT', $result );
				continue;
			}

			$coverage = $result['inspectionResult']['indexStatusResult']['coverageState'] ?? '';
			if ( in_array( $coverage, $submittable, true ) ) {
				dc_gi_enqueue_url( $url, 'URL_UPDATED' );
				$queued++;
			} elseif ( in_array( $coverage, [ 'Not found (404)', 'Soft 404' ], true ) ) {
				// URL is in the sitemap but returns 404 — log it as an informational
				// note so the site owner can investigate and fix the sitemap.
				$skipped++;
				dc_gi_log_info(
					$url,
					'POLL_404',
					/* translators: %s: Google coverage state (e.g. 'Not found (404)') */
					sprintf( __( '%s during polling — skipped submission', 'dc-google-indexing' ), $coverage )
				);
			} else {
				$skipped++;
			}
		}

		// Advance cursor.
		$poll_seen   = array_values( array_unique( array_merge( $poll_seen, $newly_seen ) ) );
		$remaining   = array_diff( $eligible, $poll_seen );
		$cycle_done  = count( $remaining ) === 0;
		$cycle_seen  = count( $poll_seen );
		$cycle_total = count( $eligible );

		// Carry forward cumulative cycle totals from previous batches.
		$prev             = (array) get_transient( 'dc_gi_last_poll' );
		$cycle_inspected  = ( $prev['cycle_inspected'] ?? 0 ) + $inspected;
		$cycle_queued     = ( $prev['cycle_queued']    ?? 0 ) + $queued;
		$cycle_skipped    = ( $prev['cycle_skipped']   ?? 0 ) + $skipped;
		$cycle_errors     = ( $prev['cycle_errors']    ?? 0 ) + $errors;

		if ( $cycle_done ) {
			delete_option( 'dc_gi_poll_seen' );
			$cycle_seen = $cycle_total;
		} else {
			update_option( 'dc_gi_poll_seen', $poll_seen, false );
		}

		set_transient( 'dc_gi_last_poll', [
			'time'             => time(),
			'inspected'        => $inspected,
			'queued'           => $queued,
			'skipped'          => $skipped,
			'errors'           => $errors,
			'cycle_inspected'  => $cycle_inspected,
			'cycle_queued'     => $cycle_queued,
			'cycle_skipped'    => $cycle_skipped,
			'cycle_errors'     => $cycle_errors,
			'cycle_seen'       => $cycle_seen,
			'cycle_total'      => $cycle_total,
			'cycle_done'       => $cycle_done,
		], DAY_IN_SECONDS );

		return 'ok';

	} finally {
		delete_transient( 'dc_gi_poll_lock' );
	}
}

// =============================================================================
// HELPERS
// =============================================================================

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_get_settings(): array {
	return (array) get_option( 'dc_gi_settings', [] );
}

/**
 * Reliably write dc_gi_poll_active — update_option silently fails if the row doesn't exist.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_set_poll_active( bool $active ): void {
	global $wpdb;
	$exists = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
		'dc_gi_poll_active'
	) );
	if ( $exists ) {
		$wpdb->update( $wpdb->options, [ 'option_value' => $active ? '1' : '' ], [ 'option_name' => 'dc_gi_poll_active' ] );
	} else {
		$wpdb->insert( $wpdb->options, [ 'option_name' => 'dc_gi_poll_active', 'option_value' => $active ? '1' : '', 'autoload' => 'yes' ] );
	}
	wp_cache_delete( 'dc_gi_poll_active', 'options' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_get_quota_used(): int {
	return (int) get_transient( 'dc_gi_quota_' . gmdate( 'Y-m-d' ) );
}

// =============================================================================
// ACTIVATION / DEACTIVATION
// =============================================================================

// =============================================================================
// FOOTER CREDIT
// Replaces the first © inside <footer>…</footer> with a linked ©
// pointing to dampcig.dk. Defers to dc-sw-prefetch if that plugin is active
// and has its own footer-credit enabled — one link per page.
// =============================================================================

define( 'DC_GI_FOOTER_TRANSIENT', 'dc_gi_footer_strategy' ); // 'copyright' | 'none'

add_action( 'template_redirect', 'dc_gi_footer_credit_start' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_footer_credit_start(): void {
	if ( is_admin() ) {
		return;
	}
	$settings = dc_gi_get_settings();
	if ( empty( $settings['footer_credit'] ) ) {
		return;
	}
	// dc-sw-prefetch owns the credit when both plugins are active — avoid duplicates.
	if ( function_exists( 'dc_swp_footer_credit_start' )
		&& get_option( 'dampcig_pwa_footer_credit', 'no' ) === 'yes' ) {
		return;
	}
	ob_start( 'dc_gi_footer_credit_process' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_footer_credit_process( string $html ): string {
	$link  = '<a href="https://www.dampcig.dk" title="Powered by Dampcig.dk" target="_blank" rel="noopener noreferrer">&copy;</a>';
	$group = 'dc_gi';
	$key   = DC_GI_FOOTER_TRANSIENT;

	$strategy = wp_cache_get( $key, $group );
	if ( false === $strategy ) {
		$strategy = get_transient( $key );
	}

	if ( 'copyright' === $strategy ) {
		return dc_gi_do_copyright_replace( $html, $link );
	}
	if ( 'none' === $strategy ) {
		return $html;
	}

	$replaced = dc_gi_do_copyright_replace( $html, $link );
	if ( $replaced !== $html ) {
		wp_cache_set( $key, 'copyright', $group, WEEK_IN_SECONDS );
		set_transient( $key, 'copyright', WEEK_IN_SECONDS );
		return $replaced;
	}

	wp_cache_set( $key, 'none', $group, WEEK_IN_SECONDS );
	set_transient( $key, 'none', WEEK_IN_SECONDS );
	return $html;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_do_copyright_replace( string $html, string $link ): string {
	if ( ! preg_match( '/(<footer[\s\S]*?<\/footer>)/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return $html;
	}
	$footer_html = $m[0][0];
	$offset      = $m[0][1];
	$new_footer  = preg_replace( '/©|&copy;|&#169;|&#xA9;/u', $link, $footer_html, 1, $count );
	if ( ! $count ) {
		return $html;
	}
	return substr_replace( $html, $new_footer, $offset, strlen( $footer_html ) );
}

add_action( 'switch_theme', 'dc_gi_clear_footer_cache' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_clear_footer_cache(): void {
	wp_cache_delete( DC_GI_FOOTER_TRANSIENT, 'dc_gi' );
	delete_transient( DC_GI_FOOTER_TRANSIENT );
}

// =============================================================================
// ACTIVATION / DEACTIVATION
// =============================================================================

register_activation_hook( DC_GI_FILE, 'dc_gi_activate' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_activate(): void {
	if ( ! wp_next_scheduled( DC_GI_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'dc_gi_every5', DC_GI_CRON_HOOK );
	}
	if ( ! wp_next_scheduled( DC_GI_WATCH_HOOK ) ) {
		wp_schedule_event( time() + 300, 'dc_gi_sixhourly', DC_GI_WATCH_HOOK );
	}
	if ( ! wp_next_scheduled( DC_GI_POLL_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_every1', DC_GI_POLL_HOOK );
	}
}

add_action( 'init', 'dc_gi_maybe_reschedule_crons' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_maybe_reschedule_crons(): void {
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
	// Restore the recurring watchlist check-one cron if it was lost but is still needed.
	if ( get_option( 'dc_gi_watch_active', false ) && ! wp_next_scheduled( DC_GI_WATCH_CHECK_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_every1', DC_GI_WATCH_CHECK_HOOK );
	}
}

// =============================================================================
// WATCH CHECK-ONE CRON — drives the live-check loop server-side when JS is gone
// =============================================================================

add_action( DC_GI_WATCH_CHECK_HOOK, 'dc_gi_run_watch_check_one_cron' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_run_watch_check_one_cron(): void {
	// Bail if the user already stopped via JS.
	if ( ! get_option( 'dc_gi_watch_active', false ) ) {
		return;
	}

	$settings = dc_gi_get_settings();
	if ( empty( $settings['service_account_json'] ) ) {
		delete_option( 'dc_gi_watch_active' );
		return;
	}
	$sa = json_decode( $settings['service_account_json'], true );
	if ( ! $sa || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
		delete_option( 'dc_gi_watch_active' );
		return;
	}

	$offset   = (int) get_option( 'dc_gi_watch_offset', 0 );
	$site_url = trailingslashit( get_home_url() );
	$list     = get_option( 'dc_gi_watchlist', [] );
	$keys     = array_keys( $list );
	$total    = count( $keys );

	$done_statuses = [ 'indexed', 'removed' ];

	// Advance past already-done entries.
	while ( $offset < $total && in_array( $list[ $keys[ $offset ] ]['status'] ?? '', $done_statuses, true ) ) {
		$offset++;
	}

	if ( $offset >= $total ) {
		// All done — clean up and remove the recurring cron.
		delete_option( 'dc_gi_watch_active' );
		delete_option( 'dc_gi_watch_offset' );
		wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
		return;
	}

	$key   = $keys[ $offset ];
	$entry = &$list[ $key ];

	$result            = DC_GI_JWT::inspect_url( $sa, $entry['url'], $site_url );
	$entry['last_checked'] = time();

	if ( is_wp_error( $result ) ) {
		$entry['coverage'] = 'error: ' . $result->get_error_message();
		$entry['status']   = 'error';
	} else {
		$coverage          = $result['inspectionResult']['indexStatusResult']['coverageState'] ?? '';
		$entry['coverage'] = $coverage;

		$resubmit_states = [
			'Crawled - currently not indexed',
			'Discovered - currently not indexed',
			'URL is unknown to Google',
			'',
		];

		if ( 'removal_pending' === $entry['status'] ) {
			if ( '' === $coverage || 'URL is unknown to Google' === $coverage
				|| 'Not found (404)' === $coverage || 'Soft 404' === $coverage ) {
				$entry['status'] = 'removed';
			}
		} elseif ( 'Submitted and indexed' === $coverage || 'Indexed, not submitted in sitemap' === $coverage ) {
			$entry['status'] = 'indexed';
		} elseif ( in_array( $coverage, $resubmit_states, true ) ) {
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
				update_option( 'dc_gi_watchlist', $list, false );
				// Recalculate keys/total after removal and continue from same position.
				$keys  = array_keys( $list );
				$total = count( $keys );
				$next  = $offset; // Stay at same position since we removed an entry.
				while ( $next < $total && in_array( $list[ $keys[ $next ] ]['status'] ?? '', $done_statuses, true ) ) {
					$next++;
				}
				if ( $next >= $total ) {
					delete_option( 'dc_gi_watch_active' );
					delete_option( 'dc_gi_watch_offset' );
					wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
				} else {
					update_option( 'dc_gi_watch_offset', $next, false );
				}
				return;
			}
			dc_gi_enqueue_url( $entry['url'], 'URL_UPDATED' );
			$entry['coverage'] = $coverage ?: 'URL is unknown to Google';
			$entry['coverage'] .= ' (re-queued for submission)';
			$entry['status']   = 'pending';
		} else {
			$entry['status'] = 'pending';
		}
	}
	unset( $entry );
	update_option( 'dc_gi_watchlist', $list, false );

	$next = $offset + 1;
	while ( $next < $total && in_array( $list[ $keys[ $next ] ]['status'] ?? '', $done_statuses, true ) ) {
		$next++;
	}

	if ( $next >= $total ) {
		// Cycle complete — clean up and remove the recurring cron.
		delete_option( 'dc_gi_watch_active' );
		delete_option( 'dc_gi_watch_offset' );
		wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
	} else {
		// Advance cursor — recurring 1-minute cron will fire the next check automatically.
		update_option( 'dc_gi_watch_offset', $next, false );
	}
}

register_deactivation_hook( DC_GI_FILE, 'dc_gi_deactivate' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_deactivate(): void {
	wp_clear_scheduled_hook( DC_GI_CRON_HOOK );
	wp_clear_scheduled_hook( DC_GI_WATCH_HOOK );
	wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
	wp_clear_scheduled_hook( DC_GI_POLL_HOOK );
	update_option( 'dc_gi_poll_active', false );
	delete_option( 'dc_gi_watch_active' );
	delete_option( 'dc_gi_watch_offset' );
}

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
define( 'DC_GI_CRON_HOOK',   'dc_gi_process_queue' );
define( 'DC_GI_WATCH_HOOK',  'dc_gi_check_watchlist' );
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
	return $schedules;
}

// =============================================================================
// AUTO-SUBMIT ON PUBLISH / UPDATE
// =============================================================================

add_action( 'transition_post_status', 'dc_gi_on_status_change', 10, 3 );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_on_status_change( string $new_status, string $old_status, WP_Post $post ): void {
	if ( 'publish' !== $new_status ) {
		return;
	}
	$settings = dc_gi_get_settings();
	if ( empty( $settings['auto_submit'] ) ) {
		return;
	}
	$post_types = $settings['post_types'] ?? [ 'post', 'page' ];
	if ( ! in_array( $post->post_type, $post_types, true ) ) {
		return;
	}
	$url = get_permalink( $post->ID );
	if ( $url ) {
		dc_gi_enqueue_url( $url, 'URL_UPDATED' );
	}
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

	// On successful submission, add to watchlist so we can track when Google indexes it.
	if ( ! is_wp_error( $result ) && 'URL_DELETED' !== $type ) {
		dc_gi_watchlist_add( $url );
	}
}

// =============================================================================
// WATCHLIST — track submitted URLs until Google indexes them
// =============================================================================

/**
 * Add a URL to the watchlist (idempotent — won't duplicate).
 * Status: 'pending' → 'indexed' once Google confirms.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_watchlist_add( string $url ): void {
	$list = get_option( 'dc_gi_watchlist', [] );
	foreach ( $list as $entry ) {
		if ( $entry['url'] === $url ) {
			return; // Already watching.
		}
	}
	array_unshift( $list, [
		'url'          => esc_url_raw( $url ),
		'submitted_at' => time(),
		'status'       => 'pending',
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

	$site_url = trailingslashit( get_home_url() );
	$list     = get_option( 'dc_gi_watchlist', [] );
	$updated  = false;
	$checked  = 0;

	foreach ( $list as &$entry ) {
		if ( 'indexed' === $entry['status'] ) {
			continue; // Already done.
		}
		if ( $checked >= 20 ) {
			break; // Quota-safe batch limit per run.
		}

		$result = DC_GI_JWT::inspect_url( $sa, $entry['url'], $site_url );
		$entry['last_checked'] = time();
		$checked++;
		$updated = true;

		if ( is_wp_error( $result ) ) {
			$entry['coverage'] = 'error: ' . $result->get_error_message();
			continue;
		}

		$coverage = $result['inspectionResult']['indexStatusResult']['coverageState'] ?? '';
		$entry['coverage'] = $coverage;

		if ( 'Submitted and indexed' === $coverage
			|| 'Indexed, not submitted in sitemap' === $coverage ) {
			$entry['status'] = 'indexed';
		}
	}
	unset( $entry );

	if ( $updated ) {
		update_option( 'dc_gi_watchlist', $list, false );
	}
}

// =============================================================================
// HELPERS
// =============================================================================

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_get_settings(): array {
	return (array) get_option( 'dc_gi_settings', [] );
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
}

register_deactivation_hook( DC_GI_FILE, 'dc_gi_deactivate' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_deactivate(): void {
	wp_clear_scheduled_hook( DC_GI_CRON_HOOK );
	wp_clear_scheduled_hook( DC_GI_WATCH_HOOK );
}

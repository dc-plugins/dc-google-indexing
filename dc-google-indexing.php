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
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DC_GI_VERSION',     '1.0.0' );
define( 'DC_GI_FILE',        __FILE__ );
define( 'DC_GI_DIR',         plugin_dir_path( __FILE__ ) );
define( 'DC_GI_CRON_HOOK',   'dc_gi_process_queue' );
define( 'DC_GI_DAILY_CAP',   200 );

require_once DC_GI_DIR . 'class-jwt.php';
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

register_activation_hook( DC_GI_FILE, 'dc_gi_activate' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_activate(): void {
	if ( ! wp_next_scheduled( DC_GI_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'dc_gi_every5', DC_GI_CRON_HOOK );
	}
}

register_deactivation_hook( DC_GI_FILE, 'dc_gi_deactivate' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_deactivate(): void {
	wp_clear_scheduled_hook( DC_GI_CRON_HOOK );
}

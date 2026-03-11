<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package dc-google-indexing
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dc_gi_settings' );
delete_option( 'dc_gi_queue' );
delete_option( 'dc_gi_log' );
delete_transient( 'dc_gi_access_token' );
delete_transient( 'dc_gi_inspection_token' );
delete_transient( 'dc_gi_last_poll' );

// Remove daily quota transients
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dc_gi_quota_%' OR option_name LIKE '_transient_timeout_dc_gi_quota_%'"
);

wp_clear_scheduled_hook( 'dc_gi_process_queue' );

// Footer credit cache
delete_transient( 'dc_gi_footer_strategy' );
wp_cache_delete( 'dc_gi_footer_strategy', 'dc_gi' );

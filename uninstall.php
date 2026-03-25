<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package dc-google-indexing
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-url-cache.php';

delete_option( 'dc_gi_settings' );
delete_option( 'dc_gi_queue' );
delete_option( 'dc_gi_log' );
delete_option( 'dc_gi_watchlist' );
delete_option( 'dc_gi_poll_seen' );
delete_option( 'dc_gi_poll_active' );
delete_option( 'dc_gi_watch_active' );
delete_option( 'dc_gi_watch_offset' );
delete_option( 'dc_gi_qa_active' );
delete_option( 'dc_gi_qa_offset' );
delete_option( 'dc_gi_qa_pending' );
delete_option( 'dc_gi_qa_results' );
delete_transient( 'dc_gi_access_token' );
delete_transient( 'dc_gi_inspection_token' );
delete_transient( 'dc_gi_last_poll' );
delete_transient( 'dc_gi_poll_lock' );
delete_transient( 'dc_gi_sitemap_urls_cache' );

// Remove daily quota transients.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dc_gi_quota_%' OR option_name LIKE '_transient_timeout_dc_gi_quota_%'"
);

wp_clear_scheduled_hook( 'dc_gi_process_queue' );
wp_clear_scheduled_hook( 'dc_gi_check_watchlist' );
wp_clear_scheduled_hook( 'dc_gi_watch_check_one_cron' );
wp_clear_scheduled_hook( 'dc_gi_poll_batch' );
wp_clear_scheduled_hook( 'dc_gi_inspect_batch' );

// Drop URL inspection cache table.
DC_GI_URL_Cache::drop_table();

// Footer credit cache.
delete_transient( 'dc_gi_footer_strategy' );
wp_cache_delete( 'dc_gi_footer_strategy', 'dc_gi' );

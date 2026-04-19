<?php
/**
 * Admin interface for DC Google Indexing.
 *
 * @package dc-google-indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// MENU + FOOTER BRANDING
// =============================================================================

add_action( 'admin_menu', 'dc_gi_admin_menu' );
/**
 * Register the plugin top-level admin menu page.
 */
function dc_gi_admin_menu(): void {
	add_menu_page(
		__( 'DC Google Indexing', 'dc-google-indexing' ),
		__( 'Google Indexing', 'dc-google-indexing' ),
		'manage_options',
		'dc-google-indexing',
		'dc_gi_render_page',
		'dashicons-search',
		81
	);
}

add_filter( 'admin_footer_text', 'dc_gi_admin_footer_text' );
/**
 * Replace the default admin footer text with a DC Plugins credit on the plugin's own page.
 *
 * @param string $text Default footer text.
 * @return string Modified footer text.
 */
function dc_gi_admin_footer_text( string $text ): string {
	$screen = get_current_screen();
	if ( $screen && 'toplevel_page_dc-google-indexing' === $screen->id ) {
		return sprintf(
			/* translators: %s: URL to DC Plugins GitHub organisation */
			__( 'More plugins by <a href="%s" target="_blank" rel="noopener">DC Plugins</a>', 'dc-google-indexing' ),
			'https://github.com/dc-plugins'
		);
	}
	return $text;
}

// =============================================================================
// FORM HANDLERS
// =============================================================================

add_action( 'admin_post_dc_gi_save', 'dc_gi_handle_save' );
add_action( 'admin_post_dc_gi_test', 'dc_gi_handle_test' );
add_action( 'admin_post_dc_gi_submit', 'dc_gi_handle_submit' );
add_action( 'admin_post_dc_gi_runnow', 'dc_gi_handle_runnow' );
add_action( 'admin_post_dc_gi_clrqueue', 'dc_gi_handle_clear_queue' );
add_action( 'admin_post_dc_gi_clrlog', 'dc_gi_handle_clear_log' );
add_action( 'admin_post_dc_gi_cache_clear', 'dc_gi_handle_cache_clear' );
add_action( 'admin_post_dc_gi_watch_del', 'dc_gi_handle_watch_delete' );
add_action( 'admin_post_dc_gi_watch_clr', 'dc_gi_handle_watch_clear' );
add_action( 'admin_post_dc_gi_watch_now', 'dc_gi_handle_watch_check_now' );

add_action( 'wp_ajax_dc_gi_watch_check_one', 'dc_gi_ajax_watch_check_one' );
add_action( 'wp_ajax_dc_gi_watch_clr', 'dc_gi_ajax_watch_clear' );
add_action( 'wp_ajax_dc_gi_watch_stop', 'dc_gi_ajax_watch_stop' );
add_action( 'wp_ajax_dc_gi_watch_resubmit_one', 'dc_gi_ajax_watch_resubmit_one' );
add_action( 'wp_ajax_dc_gi_watch_status', 'dc_gi_ajax_watch_status' );
add_action( 'admin_post_dc_gi_watch_fix_cron', 'dc_gi_handle_watch_fix_cron' );
add_action( 'admin_post_dc_gi_watch_clr_indexed', 'dc_gi_handle_watch_clear_indexed' );
add_action( 'wp_ajax_dc_gi_qa_scan_one', 'dc_gi_ajax_qa_scan_one' );
add_action( 'wp_ajax_dc_gi_qa_stop', 'dc_gi_ajax_qa_stop' );
add_action( 'wp_ajax_dc_gi_qa_rescan_one', 'dc_gi_ajax_qa_rescan_one' );
add_action( 'wp_ajax_dc_gi_qa_dismiss_one', 'dc_gi_ajax_qa_dismiss_one' );
add_action( 'admin_post_dc_gi_qa_clear', 'dc_gi_handle_qa_clear' );
add_action( 'wp_ajax_dc_gi_index_status', 'dc_gi_ajax_index_status' );
add_action( 'wp_ajax_dc_gi_is_urls', 'dc_gi_ajax_is_urls' );
add_action( 'wp_ajax_dc_gi_quota_metrics', 'dc_gi_ajax_quota_metrics' );
add_action( 'wp_ajax_dc_gi_fetch_analytics', 'dc_gi_ajax_fetch_analytics' );
add_action( 'admin_enqueue_scripts', 'dc_gi_enqueue_scripts' );
/**
 * Enqueue admin scripts and localized data for the plugin's admin page.
 *
 * @param string $hook Current admin page hook suffix.
 */
function dc_gi_enqueue_scripts( string $hook ): void {
	if ( 'toplevel_page_dc-google-indexing' !== $hook ) {
		return;
	}
	$base     = plugin_dir_url( DC_GI_FILE );
	$settings = dc_gi_get_settings();

	wp_enqueue_style( 'dc-gi-admin', $base . 'assets/dc-gi-admin.css', array(), DC_GI_VERSION );

	wp_enqueue_script( 'dc-gi-admin', $base . 'assets/dc-gi-admin.js', array( 'jquery' ), DC_GI_VERSION, true );
	wp_localize_script(
		'dc-gi-admin',
		'dcGiPoll',
		array(
			'nonce'                => wp_create_nonce( 'dc_gi_ajax' ),
			'ajaxurl'              => admin_url( 'admin-ajax.php' ),
			'inspectBaseUrl'       => add_query_arg(
				array(
					'page' => 'dc-google-indexing',
					'tab'  => 'index_status',
				),
				admin_url( 'admin.php' )
			),
			'analyticsDefaultDays' => max( 1, (int) ( $settings['analytics_days'] ?? 28 ) ),
			'watchActive'          => (bool) get_option( 'dc_gi_watch_active', false ),
			'watchOffset'          => (int) get_option( 'dc_gi_watch_offset', 0 ),
			'watchTotal'           => count( (array) get_option( 'dc_gi_watchlist', array() ) ),
			'qaActive'             => (bool) get_option( 'dc_gi_qa_active', false ),
			'qaOffset'             => (int) get_option( 'dc_gi_qa_offset', 0 ),
			'qaPendingCount'       => count( (array) get_option( 'dc_gi_qa_pending', array() ) ),
			'qaWithIssues'         => count(
				array_filter(
					(array) get_option( 'dc_gi_qa_results', array() ),
					fn( $r ) => ! empty( $r['issues'] )
				)
			),
			'quotaExhausted'       => dc_gi_is_quota_exhausted(),
			'i18n'                 => array(
				// Poll / quota panel.
				'starting'                   => __( 'Starting…', 'dc-google-indexing' ),
				'stopping'                   => __( 'Stopping…', 'dc-google-indexing' ),
				'running'                    => __( 'Running', 'dc-google-indexing' ),
				'stopped'                    => __( '○ Stopped', 'dc-google-indexing' ),
				'done'                       => __( '✅ Cycle complete', 'dc-google-indexing' ),
				'quotaExhausted'             => __( '🚫 Daily quota exhausted', 'dc-google-indexing' ),
				'errComms'                   => __( 'Communication error — retrying…', 'dc-google-indexing' ),
				'watchRunning'               => __( '● Running in background', 'dc-google-indexing' ),
				'watchStopped'               => __( '○ Stopped', 'dc-google-indexing' ),
				'watchDone'                  => __( '✅ Check complete', 'dc-google-indexing' ),
				'qaRunning'                  => __( '● Scanning…', 'dc-google-indexing' ),
				'qaStopped'                  => __( '○ Stopped', 'dc-google-indexing' ),
				'qaDone'                     => __( '✅ Scan complete', 'dc-google-indexing' ),
				'quotaLoading'               => __( 'Loading…', 'dc-google-indexing' ),
				'quotaError'                 => __( 'Error loading quota data.', 'dc-google-indexing' ),
				'quotaMetric'                => __( 'Quota', 'dc-google-indexing' ),
				'quotaLimit'                 => __( 'Limit', 'dc-google-indexing' ),
				'quotaUsed'                  => __( 'Used today', 'dc-google-indexing' ),
				'quotaUsage'                 => __( 'Usage', 'dc-google-indexing' ),
				'quotaFetched'               => __( 'Fetched from API:', 'dc-google-indexing' ),
				'quotaPerDay'                => __( '/day', 'dc-google-indexing' ),
				'quotaPerMin'                => __( '/min', 'dc-google-indexing' ),
				// Index Status tab.
				'isLoading'                  => __( 'Loading…', 'dc-google-indexing' ),
				'isRobotsTxt'                => __( 'Robots.txt', 'dc-google-indexing' ),
				'isIndexing'                 => __( 'Indexing', 'dc-google-indexing' ),
				'isCrawledAs'                => __( 'Crawled as', 'dc-google-indexing' ),
				'isPageFetch'                => __( 'Page fetch', 'dc-google-indexing' ),
				'isGoogleCanonical'          => __( 'Google canonical', 'dc-google-indexing' ),
				'isDiffersFromUserCanonical' => __( 'differs from user canonical', 'dc-google-indexing' ),
				'isUserCanonical'            => __( 'User canonical', 'dc-google-indexing' ),
				'isLastSubmitted'            => __( 'Last submitted', 'dc-google-indexing' ),
				'isSearchAnalytics'          => __( 'Search Analytics', 'dc-google-indexing' ),
				'isClicks'                   => __( 'Clicks', 'dc-google-indexing' ),
				'isImpressions'              => __( 'Impressions', 'dc-google-indexing' ),
				'isCtr'                      => __( 'CTR', 'dc-google-indexing' ),
				'isAvgPosition'              => __( 'Avg. Position', 'dc-google-indexing' ),
				'isUpdated'                  => __( 'Updated:', 'dc-google-indexing' ),
				'isRichResults'              => __( 'Rich Results', 'dc-google-indexing' ),
				'isInspect'                  => __( 'Inspect', 'dc-google-indexing' ),
				'isResubmit'                 => __( 'Re-submit', 'dc-google-indexing' ),
				'isNoUrlsMatch'              => __( 'No URLs match this filter.', 'dc-google-indexing' ),
				'isShowDetails'              => __( 'Show details', 'dc-google-indexing' ),
				'isQueueing'                 => __( 'Queueing…', 'dc-google-indexing' ),
				'isResubmitError'            => __( 'Could not re-submit this URL.', 'dc-google-indexing' ),
				'isQueued'                   => __( 'Queued', 'dc-google-indexing' ),
				'isPage'                     => __( 'Page', 'dc-google-indexing' ),
				'isOf'                       => __( 'of', 'dc-google-indexing' ),
				'isUrls'                     => __( 'URLs', 'dc-google-indexing' ),
				'isStatsUpdated'             => __( 'Stats updated:', 'dc-google-indexing' ),
				'isFetching'                 => __( 'Fetching…', 'dc-google-indexing' ),
				'isFetchAnalytics'           => __( '↻ Fetch Analytics', 'dc-google-indexing' ),
				'isFetchAnalyticsError'      => __( 'Error fetching analytics.', 'dc-google-indexing' ),
				'isLastFetched'              => __( 'Last fetched:', 'dc-google-indexing' ),
				'isRowsUpdated'              => __( 'rows updated', 'dc-google-indexing' ),
				'isFetchComplete'            => __( 'Fetch complete.', 'dc-google-indexing' ),
				'isWarning'                  => __( 'Warning:', 'dc-google-indexing' ),
				'isNoDataReturned'           => __( 'No data returned.', 'dc-google-indexing' ),
				'isRequestFailed'            => __( 'Request failed.', 'dc-google-indexing' ),
			),
		)
	);

	wp_enqueue_script( 'dc-gi-index-status', $base . 'assets/dc-gi-index-status.js', array( 'jquery', 'dc-gi-admin' ), DC_GI_VERSION, true );
}

// Sticky admin notice when daily Indexing API quota is exhausted.
add_action( 'admin_notices', 'dc_gi_quota_exhausted_notice' );
/**
 * Display a sticky admin notice when the daily Indexing API quota is exhausted.
 */
function dc_gi_quota_exhausted_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! dc_gi_is_quota_exhausted() ) {
		return;
	}
	$settings    = dc_gi_get_settings();
	$quota_limit = min( DC_GI_DAILY_CAP, (int) ( $settings['daily_quota'] ?? DC_GI_DAILY_CAP ) );
	$quota_used  = dc_gi_get_quota_used();
	?>
	<div class="notice notice-warning" style="border-left-color:#ff8d72">
		<p>
			<strong><?php esc_html_e( '⚠️ DC Google Indexing — Daily quota exhausted', 'dc-google-indexing' ); ?></strong>
			<?php
			printf(
				/* translators: 1: used count, 2: daily limit */
				esc_html__( '(%1$d / %2$d submissions used today). Queue processing and watchlist re-submissions are paused until the quota resets at midnight Pacific Time.', 'dc-google-indexing' ),
				(int) $quota_used,
				(int) $quota_limit
			);
			?>
		</p>
	</div>
	<?php
}



/**
 * Handle the settings save form submission.
 */
function dc_gi_handle_save(): void {
	check_admin_referer( 'dc_gi_save' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}

	$old      = dc_gi_get_settings();
	$raw_json = isset( $_POST['service_account_json'] )
		? sanitize_textarea_field( wp_unslash( $_POST['service_account_json'] ) )
		: '';
	$property = dc_gi_normalize_search_console_property(
		sanitize_text_field( wp_unslash( $_POST['search_console_property'] ?? '' ) )
	);

	if ( ! empty( $raw_json ) ) {
		$parsed = json_decode( $raw_json, true );
		if ( ! $parsed || empty( $parsed['client_email'] ) || empty( $parsed['private_key'] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => 'dc-google-indexing',
						'notice' => 'invalid_json',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		// Clear cached token when credentials change.
		if ( ( $old['service_account_json'] ?? '' ) !== $raw_json ) {
			delete_transient( 'dc_gi_access_token' );
			delete_transient( 'dc_gi_inspection_token' );
			delete_transient( 'dc_gi_cloud_token' );
			delete_transient( 'dc_gi_quota_limits' );
			delete_option( 'dc_gi_connection_test' );
		}
	} else {
		$raw_json = $old['service_account_json'] ?? '';
	}

	if ( dc_gi_normalize_search_console_property( (string) ( $old['search_console_property'] ?? '' ) ) !== $property ) {
		delete_option( 'dc_gi_connection_test' );
	}

	$post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
		? array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) )
		: array();

	update_option(
		'dc_gi_settings',
		array(
			'service_account_json'    => $raw_json,
			'search_console_property' => $property,
			'auto_submit'             => ! empty( $_POST['auto_submit'] ) ? 1 : 0,
			'auto_delete'             => ! empty( $_POST['auto_delete'] ) ? 1 : 0,
			'post_types'              => $post_types,
			'daily_quota'             => min( 200, max( 1, absint( isset( $_POST['daily_quota'] ) ? wp_unslash( $_POST['daily_quota'] ) : 200 ) ) ),
			'analytics_days'          => min( 90, max( 1, absint( isset( $_POST['analytics_days'] ) ? wp_unslash( $_POST['analytics_days'] ) : 28 ) ) ),
		)
	);

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'notice' => 'saved',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Find the selected Search Console property inside the accessible property list.
 *
 * @param array  $properties Array of Search Console properties from the API.
 * @param string $selected   Normalized property string.
 * @return array|null
 */
function dc_gi_find_matching_property( array $properties, string $selected ): ?array {
	foreach ( $properties as $property ) {
		$site_url = dc_gi_normalize_search_console_property( (string) ( $property['siteUrl'] ?? '' ) );
		if ( '' !== $site_url && $site_url === $selected ) {
			return $property;
		}
	}

	return null;
}

/**
 * Run a multi-step connection diagnostic against Google APIs and Search Console.
 *
 * @param array  $sa                Decoded service account JSON.
 * @param string $selected_property Normalized Search Console property.
 * @return array<string,mixed>
 */
function dc_gi_run_connection_diagnostics( array $sa, string $selected_property ): array {
	delete_transient( 'dc_gi_access_token' );
	delete_transient( 'dc_gi_inspection_token' );
	delete_transient( 'dc_gi_cloud_token' );

	$diag = array(
		'checked_at'        => time(),
		'selected_property' => $selected_property,
		'indexing_api'      => array(
			'ok'      => false,
			'message' => '',
		),
		'inspection_api'    => array(
			'ok'      => false,
			'message' => '',
		),
		'property_access'   => array(
			'ok'               => false,
			'message'          => '',
			'permission_level' => '',
		),
		'properties'        => array(),
	);

	$indexing_token = DC_GI_JWT::get_access_token( $sa );
	if ( is_wp_error( $indexing_token ) ) {
		$diag['indexing_api']['message'] = $indexing_token->get_error_message();
	} else {
		$diag['indexing_api'] = array(
			'ok'      => true,
			'message' => __( 'Indexing API access token acquired successfully.', 'dc-google-indexing' ),
		);
	}

	$inspection_token = DC_GI_JWT::get_inspection_token( $sa );
	if ( is_wp_error( $inspection_token ) ) {
		$diag['inspection_api']['message'] = $inspection_token->get_error_message();
		return $diag;
	}

	$diag['inspection_api'] = array(
		'ok'      => true,
		'message' => __( 'Search Console inspection token acquired successfully.', 'dc-google-indexing' ),
	);

	$properties = DC_GI_JWT::list_search_console_properties( $sa );
	if ( is_wp_error( $properties ) ) {
		$diag['property_access']['message'] = $properties->get_error_message();
		return $diag;
	}

	$diag['properties'] = $properties;
	$matched            = dc_gi_find_matching_property( $properties, $selected_property );

	if ( $matched ) {
		$permission              = (string) ( $matched['permissionLevel'] ?? '' );
		$diag['property_access'] = array(
			'ok'               => true,
			'message'          => sprintf(
				/* translators: %s: permission level returned by Search Console */
				__( 'Selected property is accessible with permission level: %s.', 'dc-google-indexing' ),
				$permission ? $permission : __( 'unknown', 'dc-google-indexing' )
			),
			'permission_level' => $permission,
		);
	} else {
		$diag['property_access']['message'] = __(
			'The selected Search Console property was not found in the service account\'s accessible properties. Add the service account to that property and re-run the test.',
			'dc-google-indexing'
		);
	}

	return $diag;
}

/**
 * Handle the connection-test form submission.
 */
function dc_gi_handle_test(): void {
	check_admin_referer( 'dc_gi_test' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}

	$settings = dc_gi_get_settings();
	if ( empty( $settings['service_account_json'] ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'dc-google-indexing',
					'notice' => 'test_no_sa',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$sa   = json_decode( $settings['service_account_json'], true );
	$diag = dc_gi_run_connection_diagnostics( $sa, dc_gi_get_search_console_property( $settings ) );
	update_option( 'dc_gi_connection_test', $diag, false );

	$notice = ( ! empty( $diag['indexing_api']['ok'] ) && ! empty( $diag['inspection_api']['ok'] ) && ! empty( $diag['property_access']['ok'] ) )
		? 'test_ok'
		: ( ! empty( $diag['indexing_api']['ok'] ) && ! empty( $diag['inspection_api']['ok'] ) ? 'test_warn' : 'test_fail' );
	$msg    = '';
	if ( 'test_fail' === $notice ) {
		$error_message = '';
		if ( ! empty( $diag['indexing_api']['message'] ) ) {
			$error_message = (string) $diag['indexing_api']['message'];
		} elseif ( ! empty( $diag['inspection_api']['message'] ) ) {
			$error_message = (string) $diag['inspection_api']['message'];
		} else {
			$error_message = (string) ( $diag['property_access']['message'] ?? '' );
		}
		$msg = rawurlencode( $error_message );
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'notice' => $notice,
				'errmsg' => $msg,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle the manual URL submission form.
 */
function dc_gi_handle_submit(): void {
	check_admin_referer( 'dc_gi_submit' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}

	$raw   = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';
	$type  = ( isset( $_POST['submit_type'] ) && 'URL_DELETED' === $_POST['submit_type'] ) ? 'URL_DELETED' : 'URL_UPDATED';
	$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
	$count = 0;
	foreach ( $lines as $url ) {
		$clean = esc_url_raw( $url );
		if ( $clean ) {
			dc_gi_enqueue_url( $clean, $type );
			++$count;
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'tab'    => 'submit',
				'notice' => 'queued',
				'count'  => $count,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle the Run Now (process queue immediately) form action.
 */
function dc_gi_handle_runnow(): void {
	check_admin_referer( 'dc_gi_runnow' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	dc_gi_process_queue();
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'tab'    => 'log',
				'notice' => 'processed',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle the clear-queue form action.
 */
function dc_gi_handle_clear_queue(): void {
	check_admin_referer( 'dc_gi_clrqueue' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	update_option( 'dc_gi_queue', array(), false );
	wp_cache_delete( 'dc_gi_queue', 'options' ); // Force-bust Redis persistent object cache.
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'tab'    => 'submit',
				'notice' => 'queue_cleared',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle the clear-log form action.
 */
function dc_gi_handle_clear_log(): void {
	check_admin_referer( 'dc_gi_clrlog' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	update_option( 'dc_gi_log', array(), false );
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'tab'    => 'log',
				'notice' => 'log_cleared',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle the delete-from-watchlist form action.
 */
function dc_gi_handle_watch_delete(): void {
	check_admin_referer( 'dc_gi_watch_del' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	$url = esc_url_raw( isset( $_POST['watch_url'] ) ? wp_unslash( $_POST['watch_url'] ) : '' );
	if ( $url ) {
		dc_gi_watchlist_remove( $url );
	}
	wp_safe_redirect(
		add_query_arg(
			array(
				'page' => 'dc-google-indexing',
				'tab'  => 'watchlist',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle the clear-watchlist form action.
 */
function dc_gi_handle_watch_clear(): void {
	check_admin_referer( 'dc_gi_watch_clr' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	update_option( 'dc_gi_watchlist', array(), false );
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'tab'    => 'watchlist',
				'notice' => 'watch_cleared',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * AJAX: clear the entire watchlist and stop the watch-check cron.
 * JS intercepts the "Clear All" form submit and calls this instead of
 * the admin-post handler so the DOM can be updated without a redirect.
 */
function dc_gi_ajax_watch_clear(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	update_option( 'dc_gi_watchlist', array(), false );
	delete_option( 'dc_gi_watch_active' );
	delete_option( 'dc_gi_watch_offset' );
	wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
	wp_send_json_success( array() );
}

/**
 * Handle the clear-indexed-entries form action (removes 'indexed' entries from watchlist).
 */
function dc_gi_handle_watch_clear_indexed(): void {
	check_admin_referer( 'dc_gi_watch_clr_indexed' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	$list     = get_option( 'dc_gi_watchlist', array() );
	$indexed  = array_values( array_filter( $list, fn( $e ) => 'indexed' === $e['status'] ) );
	$filtered = array_values( array_filter( $list, fn( $e ) => 'indexed' !== $e['status'] ) );
	$removed  = count( $list ) - count( $filtered );
	update_option( 'dc_gi_watchlist', $filtered, false );

	// Sync the URL cache for every cleared entry so the Index Status counters
	// ("N cached · N need submission") reflect the confirmed indexed state
	// without waiting for the background inspection cron to re-visit each URL.
	// mark_indexed() updates only index_verdict and coverage_state and intentionally
	// leaves last_inspected untouched so the TTL-based re-inspection schedule is preserved.
	foreach ( $indexed as $entry ) {
		DC_GI_URL_Cache::mark_indexed(
			$entry['url'],
			! empty( $entry['coverage'] ) ? $entry['coverage'] : 'Submitted and indexed'
		);
	}
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'tab'    => 'watchlist',
				'notice' => 'watch_indexed_cleared',
				'count'  => $removed,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle the watchlist Check Now form action.
 */
function dc_gi_handle_watch_check_now(): void {
	check_admin_referer( 'dc_gi_watch_now' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	// Run the immediate batch check (up to 20 pending URLs).
	dc_gi_run_watchlist_check();
	// Schedule a recurring 1-minute cron so any remaining pending URLs continue
	// to be checked automatically even after this page request ends.
	// Offset is reset to 0 so the cron sweeps the full list; URLs already checked
	// above will be re-evaluated at most once with negligible quota cost.
	update_option( 'dc_gi_watch_active', true, false );
	update_option( 'dc_gi_watch_offset', 0, false );
	if ( ! wp_next_scheduled( DC_GI_WATCH_CHECK_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_every1', DC_GI_WATCH_CHECK_HOOK );
	}
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'tab'    => 'watchlist',
				'notice' => 'watch_checked',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle the fix-watchlist-cron form action.
 */
function dc_gi_handle_watch_fix_cron(): void {
	check_admin_referer( 'dc_gi_watch_fix_cron' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	if ( ! wp_next_scheduled( DC_GI_WATCH_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_sixhourly', DC_GI_WATCH_HOOK );
	}
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'tab'    => 'watchlist',
				'notice' => 'cron_fixed',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * AJAX: check one pending watchlist URL and return progress.
 * Offset is passed from JS; list order is stable (array index).
 */
function dc_gi_ajax_watch_check_one(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$settings = dc_gi_get_settings();
	$sa       = dc_gi_get_validated_sa( $settings );
	if ( is_wp_error( $sa ) ) {
		wp_send_json_error( 'dc_gi_no_sa' === $sa->get_error_code() ? 'no_service_account' : 'invalid_service_account' );
	}

	$offset   = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$site_url = dc_gi_get_search_console_property( $settings );
	$list     = get_option( 'dc_gi_watchlist', array() );

	// Build flat array of all entries (pending + already indexed — we skip indexed in loop).
	$keys  = array_keys( $list );
	$total = count( $keys );

	// Mark active + store cursor so cron can continue if JS disconnects.
	update_option( 'dc_gi_watch_active', true, false );
	update_option( 'dc_gi_watch_offset', $offset, false );

	// Ensure a recurring 1-minute cron is running as a fallback in case JS disconnects.
	if ( ! wp_next_scheduled( DC_GI_WATCH_CHECK_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_every1', DC_GI_WATCH_CHECK_HOOK );
	}

	$done_statuses = array( 'indexed', 'removed' );

	// Advance offset past already-done entries.
	while ( $offset < $total && in_array( $list[ $keys[ $offset ] ]['status'] ?? '', $done_statuses, true ) ) {
		++$offset;
	}

	if ( $offset >= $total ) {
		delete_option( 'dc_gi_watch_active' );
		delete_option( 'dc_gi_watch_offset' );
		wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
		wp_send_json_success(
			array(
				'done'        => true,
				'checked'     => $offset,
				'total'       => $total,
				'queue_count' => count( (array) get_option( 'dc_gi_queue', array() ) ),
			)
		);
	}

	$key       = $keys[ $offset ];
	$entry     = &$list[ $key ];
	$entry_url = $entry['url'];

	$result                = DC_GI_JWT::inspect_url( $sa, $entry_url, $site_url );
	$entry['last_checked'] = time();

	$auto_removed = false;

	if ( is_wp_error( $result ) ) {
		$entry['coverage'] = 'error: ' . $result->get_error_message();
		$entry['status']   = 'error';
	} else {
		$coverage          = $result['inspectionResult']['indexStatusResult']['coverageState'] ?? '';
		$entry['coverage'] = $coverage;

		// Cache the full inspection result so the Index Status tab stays up-to-date
		// and the Inspection Cron has fresh data for its next submission decision.
		$parsed = DC_GI_URL_Cache::parse_api_result( $result );
		DC_GI_URL_Cache::upsert( $entry_url, $parsed );

		if ( 'removal_pending' === $entry['status'] ) {
			if ( '' === $coverage || 'URL is unknown to Google' === $coverage
				|| 'Not found (404)' === $coverage || 'Soft 404' === $coverage ) {
				$entry['status'] = 'removed';
			}
		} elseif ( 'Submitted and indexed' === $coverage
			|| 'Indexed, not submitted in sitemap' === $coverage ) {
			$entry['status'] = 'indexed';
		} elseif ( in_array( $coverage, DC_GI_RESUBMIT_STATES, true ) ) {
			// Before updating status, verify the URL is still in the sitemap.
			// If it has been removed, auto-delete from watchlist and log.
			$sitemap_urls = dc_gi_get_sitemap_urls_cached();
			if ( ! empty( $sitemap_urls ) && ! in_array( $entry_url, $sitemap_urls, true ) ) {
				$auto_removed = true;
				unset( $entry );
				dc_gi_log_info(
					$entry_url,
					'SITEMAP_REMOVED',
					__( 'URL no longer in sitemap — auto-removed from watchlist', 'dc-google-indexing' )
				);
				unset( $list[ $key ] );
				$list  = array_values( $list );
				$keys  = array_keys( $list );
				$total = count( $keys );
				update_option( 'dc_gi_watchlist', $list, false );
			} else {
				// Flag for manual QA when Google has seen the URL but not indexed it yet.
				// Submission is handled by the Inspection Cron, not here.
				if ( in_array( $coverage, DC_GI_QA_STATES, true ) ) {
					dc_gi_qa_pending_add( $entry_url );
				}
				$entry['status'] = 'pending';
			}
		} else {
			$entry['status'] = 'pending';
		}
	}

	if ( ! $auto_removed ) {
		unset( $entry );
		update_option( 'dc_gi_watchlist', $list, false );
	}

	$done_statuses = array( 'indexed', 'removed' );
	// When auto-removed, stay at the same offset (the entry at offset is now the next one).
	$next_offset = $auto_removed ? $offset : $offset + 1;
	// Skip any trailing already-done entries for the next call.
	while ( $next_offset < $total && in_array( $list[ $keys[ $next_offset ] ]['status'] ?? '', $done_statuses, true ) ) {
		++$next_offset;
	}

	$done        = $next_offset >= $total;
	$queue_count = count( (array) get_option( 'dc_gi_queue', array() ) );

	if ( $done ) {
		delete_option( 'dc_gi_watch_active' );
		delete_option( 'dc_gi_watch_offset' );
		wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
	} else {
		// Keep cursor up to date so the recurring cron continues from the right place.
		update_option( 'dc_gi_watch_offset', $next_offset, false );
	}

	wp_send_json_success(
		array(
			'done'         => $done,
			'checked'      => $auto_removed ? $offset : $offset + 1,
			'total'        => $total,
			'next'         => $next_offset,
			'url'          => $entry_url,
			'status'       => $auto_removed ? 'auto_removed' : ( $list[ $keys[ $offset ] ]['status'] ?? '' ),
			'coverage'     => $auto_removed ? '' : ( $list[ $keys[ $offset ] ]['coverage'] ?? '' ),
			'auto_removed' => $auto_removed,
			'queue_count'  => $queue_count,
		)
	);
}

/**
 * AJAX: Stop the watchlist live-check loop.
 */
function dc_gi_ajax_watch_stop(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	delete_option( 'dc_gi_watch_active' );
	delete_option( 'dc_gi_watch_offset' );
	wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
	wp_send_json_success();
}

/**
 * AJAX: Return the current watchlist running state so JS can show the badge.
 */
function dc_gi_ajax_watch_status(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	wp_send_json_success(
		array(
			'active' => (bool) get_option( 'dc_gi_watch_active', false ),
			'offset' => (int) get_option( 'dc_gi_watch_offset', 0 ),
			'total'  => count( (array) get_option( 'dc_gi_watchlist', array() ) ),
		)
	);
}

/**
 * AJAX: Re-submit a single watchlist URL to the Google Indexing API and
 * queue a URL Inspection API signal as a background cron event.
 *
 * The inspection signal runs via wp_schedule_single_event() + spawn_cron()
 * so the Google API call never blocks the AJAX response.  It uses the Search
 * Console quota (2,000/day), independent of the Indexing API quota (200/day).
 */
function dc_gi_ajax_watch_resubmit_one(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$url = esc_url_raw( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '' );
	if ( ! $url ) {
		wp_send_json_error( 'missing_url' );
	}

	$list  = get_option( 'dc_gi_watchlist', array() );
	$found = false;
	foreach ( $list as &$entry ) {
		if ( $entry['url'] === $url ) {
			$found = true;
			// Enqueue for re-submission via Indexing API.
			dc_gi_enqueue_url( $url, 'URL_UPDATED' );
			// Reset status so the watchlist tracks the new submission.
			$entry['status']       = 'pending';
			$entry['coverage']     = '';
			$entry['submitted_at'] = time();
			break;
		}
	}
	unset( $entry );

	if ( ! $found ) {
		// URL not in watchlist — add it and enqueue.
		dc_gi_enqueue_url( $url, 'URL_UPDATED' );
		dc_gi_watchlist_add( $url, 'pending' );
	} else {
		update_option( 'dc_gi_watchlist', $list, false );
	}

	// Schedule a background URL Inspection API signal so Google re-evaluates
	// the page via Search Console without blocking this AJAX response.
	// spawn_cron() triggers the WP-Cron loopback immediately.
	wp_schedule_single_event( time(), DC_GI_INSPECT_SIGNAL_HOOK, array( $url ) );
	spawn_cron();

	wp_send_json_success(
		array(
			'url'               => $url,
			'queue_count'       => count( (array) get_option( 'dc_gi_queue', array() ) ),
			'inspection_queued' => true,
		)
	);
}

// =============================================================================
// QUALITY ASSURANCE — AJAX HANDLERS
// =============================================================================

/**
 * AJAX: Scan one sitemap URL for common on-page SEO issues.
 *
 * Fetches the URL via wp_remote_get(), parses the HTML response for noindex
 * directives, missing title/description/H1 tags, canonical mismatches, and
 * accumulates a content hash for duplicate-content detection on the final URL.
 * Results are stored in the dc_gi_qa_results option.
 *
 * Sources URLs exclusively from dc_gi_qa_pending — the list populated by the
 * Watchlist whenever it finds a URL is "Discovered - currently not indexed".
 * The QA tab does not scan the full sitemap; that is Polling's responsibility.
 */
function dc_gi_ajax_qa_scan_one(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

	// Build or read the scan list.  At offset 0 we merge new pending URLs with
	// existing issue-URLs so a single scan re-checks everything.
	if ( 0 === $offset ) {
		$pending    = array_values( (array) get_option( 'dc_gi_qa_pending', array() ) );
		$issue_urls = array_keys(
			array_filter(
				(array) get_option( 'dc_gi_qa_results', array() ),
				fn( $r ) => ! empty( $r['issues'] )
			)
		);
		$urls       = array_values( array_unique( array_merge( $pending, $issue_urls ) ) );
		update_option( 'dc_gi_qa_scan_list', $urls, false );
	} else {
		$urls = array_values( (array) get_option( 'dc_gi_qa_scan_list', array() ) );
	}

	$total = count( $urls );

	if ( empty( $urls ) ) {
		wp_send_json_error( 'no_pending' );
	}

	if ( $offset >= $total ) {
		delete_option( 'dc_gi_qa_active' );
		delete_option( 'dc_gi_qa_offset' );
		wp_send_json_success(
			array(
				'done'   => true,
				'total'  => $total,
				'offset' => $total,
			)
		);
	}

	$url = $urls[ $offset ];

	// Mark active + store cursor.
	update_option( 'dc_gi_qa_active', true, false );
	update_option( 'dc_gi_qa_offset', $offset, false );

	// Scan the URL using the shared helper.
	$result      = dc_gi_qa_scan_single_url( $url );
	$issues      = $result['issues'];
	$http_status = $result['http_status'];
	$title       = $result['title'];
	$meta_desc   = $result['meta_desc'];
	$word_count  = $result['word_count'];

	// Persist this URL's result.
	$results         = (array) get_option( 'dc_gi_qa_results', array() );
	$results[ $url ] = $result;

	$next = $offset + 1;
	$done = $next >= $total;

	if ( $done ) {
		// Final pass: flag duplicates across all results.
		dc_gi_qa_run_duplicate_detection( $results );

		update_option( 'dc_gi_qa_results', $results, false );
		delete_option( 'dc_gi_qa_active' );
		delete_option( 'dc_gi_qa_offset' );
		delete_option( 'dc_gi_qa_scan_list' );
		delete_option( 'dc_gi_qa_pending' );

		$remaining_issues = count( array_filter( $results, fn( $r ) => ! empty( $r['issues'] ) ) );
	} else {
		update_option( 'dc_gi_qa_results', $results, false );
		update_option( 'dc_gi_qa_offset', $next, false );
	}

	wp_send_json_success(
		array(
			'done'            => $done,
			'offset'          => $offset,
			'next'            => $next,
			'total'           => $total,
			'url'             => $url,
			'http_status'     => $http_status,
			'issues'          => array_values( array_unique( $issues ) ),
			'title'           => $title,
			'meta_desc'       => $meta_desc,
			'word_count'      => $word_count,
			'has_more_issues' => $done ? $remaining_issues > 0 : null,
		)
	);
}

/**
 * AJAX: Stop the QA scan and clear the active flag.
 */
function dc_gi_ajax_qa_stop(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	delete_option( 'dc_gi_qa_active' );
	delete_option( 'dc_gi_qa_offset' );
	delete_option( 'dc_gi_qa_scan_list' );
	wp_send_json_success();
}

/**
 * AJAX: Re-scan a single URL from QA results.
 * If the URL is now clean (no issues), it is removed from results.
 */
function dc_gi_ajax_qa_rescan_one(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$url = esc_url_raw( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '' );
	if ( ! $url ) {
		wp_send_json_error( 'missing_url' );
	}

	$scanned = dc_gi_qa_scan_single_url( $url );
	$results = (array) get_option( 'dc_gi_qa_results', array() );

	if ( empty( $scanned['issues'] ) ) {
		unset( $results[ $url ] );
		update_option( 'dc_gi_qa_results', $results, false );
		wp_send_json_success(
			array(
				'clean' => true,
				'url'   => $url,
			)
		);
	}

	$results[ $url ] = $scanned;
	dc_gi_qa_run_duplicate_detection( $results );
	update_option( 'dc_gi_qa_results', $results, false );

	wp_send_json_success(
		array(
			'clean'       => false,
			'url'         => $url,
			'http_status' => $scanned['http_status'],
			'issues'      => $scanned['issues'],
			'title'       => $scanned['title'],
		)
	);
}

/**
 * AJAX: Dismiss (remove) a single URL from QA results.
 */
function dc_gi_ajax_qa_dismiss_one(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$url = esc_url_raw( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '' );
	if ( ! $url ) {
		wp_send_json_error( 'missing_url' );
	}

	$results = (array) get_option( 'dc_gi_qa_results', array() );
	unset( $results[ $url ] );
	update_option( 'dc_gi_qa_results', $results, false );

	wp_send_json_success( array( 'url' => $url ) );
}

/**
 * Handle the clear-QA-results form action.
 */
function dc_gi_handle_qa_clear(): void {
	check_admin_referer( 'dc_gi_qa_clear' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	delete_option( 'dc_gi_qa_results' );
	delete_option( 'dc_gi_qa_active' );
	delete_option( 'dc_gi_qa_offset' );
	delete_option( 'dc_gi_qa_scan_list' );
	delete_option( 'dc_gi_qa_refresh_offset' );
	delete_option( 'dc_gi_qa_refresh_list' );
	delete_option( 'dc_gi_qa_last_refresh' );
	wp_safe_redirect(
		add_query_arg(
			array(
				'page' => 'dc-google-indexing',
				'tab'  => 'qa',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * AJAX: Return URL cache verdict and coverage breakdown for the index-status widget.
 */
function dc_gi_ajax_index_status(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	wp_send_json_success(
		array(
			'verdicts'       => DC_GI_URL_Cache::get_verdict_counts(),
			'coverage'       => DC_GI_URL_Cache::get_coverage_state_breakdown(),
			'total'          => DC_GI_URL_Cache::count_total(),
			'excluded'       => DC_GI_URL_Cache::count_excluded(),
			'age_days'       => DC_GI_URL_Cache::oldest_entry_age_days(),
			'inspect_errors' => DC_GI_URL_Cache::get_inspect_error_count(),
			'quota_backoff'  => (bool) get_transient( 'dc_gi_inspect_quota_backoff' ),
		)
	);
}

/**
 * AJAX: return a paginated page of individual URL inspection results for the Index Status tab.
 */
function dc_gi_ajax_is_urls(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );    // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$filter   = sanitize_text_field( wp_unslash( $_POST['filter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$order_by = sanitize_text_field( wp_unslash( $_POST['order_by'] ?? 'last_inspected' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$order    = 'ASC' === strtoupper( sanitize_text_field( wp_unslash( $_POST['order'] ?? 'DESC' ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	$allowed = array( '', 'PASS', 'NEUTRAL', 'FAIL', 'VERDICT_UNSPECIFIED', 'EXCLUDED' );
	if ( ! in_array( $filter, $allowed, true ) ) {
		$filter = '';
	}

	$per_page    = 25;
	$rows        = DC_GI_URL_Cache::get_paginated_urls( $page, $per_page, $filter, $order_by, $order );
	$total       = DC_GI_URL_Cache::count_filtered( $filter );
	$total_pages = (int) ceil( max( 1, $total ) / $per_page );

	wp_send_json_success(
		array(
			'rows'        => $rows,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
			'order_by'    => $order_by,
			'order'       => $order,
		)
	);
}

/**
 * AJAX: return real API quota limits from Google Service Usage API.
 *
 * Accepts an optional $_POST['force'] = '1' to bust the 1-hour transient cache.
 *
 * @return void
 */
function dc_gi_ajax_quota_metrics(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$settings = dc_gi_get_settings();
	$sa       = dc_gi_get_validated_sa( $settings );
	if ( is_wp_error( $sa ) ) {
		wp_send_json_error(
			'dc_gi_no_sa' === $sa->get_error_code()
				? __( 'No service account configured.', 'dc-google-indexing' )
				: __( 'Invalid service account JSON.', 'dc-google-indexing' )
		);
	}

	// Allow a forced cache-bust.
	if ( ! empty( $_POST['force'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		delete_transient( 'dc_gi_quota_limits' );
		delete_transient( 'dc_gi_cloud_token' );
	}

	$quotas       = DC_GI_JWT::get_service_quotas( $sa );
	$publish_used = dc_gi_get_quota_used();

	if ( is_wp_error( $quotas ) ) {
		wp_send_json_success(
			array(
				'quotas' => array(),
				'error'  => $quotas->get_error_message(),
				'used'   => $publish_used,
			)
		);
	}

	$payload = array();
	foreach ( $quotas as $q ) {
		$used      = ( false !== strpos( $q['metric'], 'publish_requests' ) ) ? $publish_used : null;
		$payload[] = array(
			'metric'      => $q['metric'],
			'displayName' => $q['displayName'],
			'unit'        => $q['unit'],
			'limit'       => $q['limit'],
			'used'        => $used,
			'pct'         => ( null !== $used && $q['limit'] > 0 ) ? round( $used / $q['limit'] * 100, 1 ) : null,
		);
	}

	wp_send_json_success(
		array(
			'quotas' => $payload,
			'error'  => null,
			'used'   => $publish_used,
		)
	);
}

/**
 * AJAX: Fetch Search Analytics data for the given date range and store in the URL cache.
 *
 * Accepts an optional $_POST['days'] (7, 28, or 90) to override the saved setting.
 *
 * @return void
 */
function dc_gi_ajax_fetch_analytics(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$settings = dc_gi_get_settings();
	$sa       = dc_gi_get_validated_sa( $settings );
	if ( is_wp_error( $sa ) ) {
		wp_send_json_error(
			'dc_gi_no_sa' === $sa->get_error_code()
				? __( 'No service account configured.', 'dc-google-indexing' )
				: __( 'Invalid service account JSON.', 'dc-google-indexing' )
		);
	}

	$allowed_days = array( 7, 28, 90 );
	$days_raw     = isset( $_POST['days'] ) ? (int) wp_unslash( $_POST['days'] ) : 0;
	$days         = in_array( $days_raw, $allowed_days, true )
		? $days_raw
		: max( 1, (int) ( $settings['analytics_days'] ?? 28 ) );

	$site_url = dc_gi_get_search_console_property( $settings );
	$result   = DC_GI_URL_Cache::run_analytics_batch( $sa, $site_url, $days );

	if ( 0 === strpos( $result, 'error:' ) ) {
		wp_send_json_success(
			array(
				'ok'           => false,
				'message'      => substr( $result, 6 ),
				'updated'      => 0,
				'days'         => $days,
				'last_updated' => null,
			)
		);
	}

	$updated = (int) substr( $result, 3 ); // 'ok:N'

	wp_send_json_success(
		array(
			'ok'           => true,
			'updated'      => $updated,
			'days'         => $days,
			'last_updated' => DC_GI_URL_Cache::get_analytics_last_updated(),
		)
	);
}

/**
 * Handle the clear-URL-cache form action.
 */
function dc_gi_handle_cache_clear(): void {
	check_admin_referer( 'dc_gi_cache_clear' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}

	DC_GI_URL_Cache::truncate();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'dc-google-indexing',
				'tab'    => 'index_status',
				'notice' => 'cache_cleared',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

	// =============================================================================
	// RENDER PAGE
	// =============================================================================

	/**
	 * Render the plugin's admin page (all tabs).
	 */
function dc_gi_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings    = dc_gi_get_settings();
	$log         = get_option( 'dc_gi_log', array() );
	$watchlist   = dc_gi_watchlist_get();
	$quota_used  = dc_gi_get_quota_used();
	$quota_limit = min( 200, (int) ( $settings['daily_quota'] ?? 200 ) );
	$has_sa      = ! empty( $settings['service_account_json'] );
	$property    = dc_gi_get_search_console_property( $settings );
	$sa_decoded  = array();
	$sa_email    = '';
	if ( $has_sa ) {
		$sa_decoded = json_decode( $settings['service_account_json'], true );
		$sa_email   = $sa_decoded['client_email'] ?? '';
	}
	$connection_test = (array) get_option( 'dc_gi_connection_test', array() );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ( $has_sa ? 'settings' : 'start' );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice_key = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$errmsg = isset( $_GET['errmsg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['errmsg'] ) ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$queued_count = absint( isset( $_GET['count'] ) ? wp_unslash( $_GET['count'] ) : 0 );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$inspect_url = esc_url_raw( wp_unslash( $_GET['inspect_url'] ?? '' ) );

	$qa_results    = (array) get_option( 'dc_gi_qa_results', array() );
	$qa_pending    = (array) get_option( 'dc_gi_qa_pending', array() );
	$inspect_entry = null;
	$inspect_meta  = null;
	$inspect_error = '';

	if ( 'index_status' === $tab && $inspect_url && class_exists( 'DC_GI_URL_Cache' ) ) {
		$inspect_entry = DC_GI_URL_Cache::get_entry( $inspect_url );
		if ( ! $inspect_entry ) {
			$inspect_error = __( 'That URL is not in the local inspection cache yet.', 'dc-google-indexing' );
		} elseif ( $has_sa && ! empty( $sa_decoded['client_email'] ) && ! empty( $sa_decoded['private_key'] ) ) {
			$inspect_meta = DC_GI_JWT::get_url_notification_metadata( $sa_decoded, $inspect_url );
			if ( is_wp_error( $inspect_meta ) ) {
				$data = $inspect_meta->get_error_data();
				if ( 404 !== ( $data['status'] ?? 0 ) ) {
					// Only surface real errors (auth failure, permission denied, etc.).
					// A 404 simply means the URL has no Indexing API submission history.
					$inspect_error = $inspect_meta->get_error_message();
				}
				$inspect_meta = null;
			}
		}
	}

	$notices = array(
		'saved'                 => array( 'success', __( 'Settings saved.', 'dc-google-indexing' ) ),
		'invalid_json'          => array( 'error', __( 'Invalid JSON — ensure it contains client_email and private_key.', 'dc-google-indexing' ) ),
		'queued'                => array(
			'success',
			sprintf(
								/* translators: %d: number of URLs added to queue */
				__( '%d URL(s) added to queue.', 'dc-google-indexing' ),
				$queued_count
			),
		),
		'processed'             => array( 'success', __( 'Queue processed.', 'dc-google-indexing' ) ),
		'queue_cleared'         => array( 'success', __( 'Queue cleared.', 'dc-google-indexing' ) ),
		'log_cleared'           => array( 'success', __( 'Log cleared.', 'dc-google-indexing' ) ),
		'test_ok'               => array( 'success', __( '&#10003; Connection successful — credentials and Search Console property access look good.', 'dc-google-indexing' ) ),
		'test_warn'             => array( 'warning', __( 'Credentials are valid, but the selected Search Console property still needs attention. Review the connection report below.', 'dc-google-indexing' ) ),
		'test_fail'             => array( 'error', $errmsg ? esc_html( $errmsg ) : __( 'Connection failed.', 'dc-google-indexing' ) ),
		'test_no_sa'            => array( 'error', __( 'No service account saved. Paste your JSON and save first.', 'dc-google-indexing' ) ),
		'watch_cleared'         => array( 'success', __( 'Watchlist cleared.', 'dc-google-indexing' ) ),
		'watch_indexed_cleared' => array(
			'success',
			sprintf(
							/* translators: %d: number of indexed URLs removed from watchlist */
				__( '%d indexed URL(s) removed from watchlist.', 'dc-google-indexing' ),
				absint( isset( $_GET['count'] ) ? wp_unslash( $_GET['count'] ) : 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			),
		),
		'watch_checked'         => array( 'success', __( 'Watchlist check complete.', 'dc-google-indexing' ) ),
		'cron_fixed'            => array( 'success', __( 'Watchlist auto-check schedule restored.', 'dc-google-indexing' ) ),
		'cache_cleared'         => array( 'success', __( 'Inspection cache cleared — the background cron will rebuild it automatically.', 'dc-google-indexing' ) ),
	);

	// Allowlist: WordPress core types (via _builtin) plus known WooCommerce types.
	// This prevents third-party plugin types (GDPR tools, CRMs, etc.) from appearing
	// while still surfacing WooCommerce product pages that benefit from indexing.
	$woo_post_types = array( 'product' );
	$all_post_types = array_filter(
		get_post_types( array( 'public' => true ), 'objects' ),
		static function ( $pt ) use ( $woo_post_types ) {
			return $pt->_builtin || in_array( $pt->name, $woo_post_types, true );
		}
	);
	?>
	<div class="wrap dc-gi-admin">
		<h1 class="dc-gi-page-title">
			<span class="dashicons dashicons-search"></span>
		<?php esc_html_e( 'DC Google Indexing', 'dc-google-indexing' ); ?>
		</h1>

		<?php if ( $notice_key && isset( $notices[ $notice_key ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice_key ][0] ); ?> is-dismissible">
			<p><?php echo wp_kses_post( $notices[ $notice_key ][1] ); ?></p>
		</div>
		<?php endif; ?>

		<!-- Status bar -->
		<div class="dc-gi-statusbar">
		<?php if ( $has_sa ) : ?>
				<span class="dc-gi-statusbar-chip">
					<span><?php esc_html_e( 'Service account', 'dc-google-indexing' ); ?></span>
					<span class="dc-gi-chip-val ok"><code><?php echo esc_html( $sa_email ); ?></code></span>
				</span>
			<?php else : ?>
				<span class="dc-gi-statusbar-chip">
					<span class="dc-gi-chip-val err"><?php esc_html_e( '✗ No service account configured', 'dc-google-indexing' ); ?></span>
				</span>
			<?php endif; ?>
			<span class="dc-gi-statusbar-chip">
				<span><?php esc_html_e( 'Property', 'dc-google-indexing' ); ?></span>
				<span class="dc-gi-chip-val"><code><?php echo esc_html( $property ); ?></code></span>
			</span>
			<span class="dc-gi-statusbar-chip">
				<span><?php esc_html_e( 'Quota today', 'dc-google-indexing' ); ?></span>
				<span class="dc-gi-chip-val"><?php echo esc_html( $quota_used . ' / ' . $quota_limit ); ?></span>
			</span>
		</div>

		<!-- Tabs -->
		<nav class="nav-tab-wrapper">
		<?php
		$tabs = array(
			'start'        => __( '🚀 Getting Started', 'dc-google-indexing' ),
			'settings'     => __( 'Settings', 'dc-google-indexing' ),
			'submit'       => __( 'Submit URLs', 'dc-google-indexing' ),
			'watchlist'    => __( '👁 Watchlist', 'dc-google-indexing' ),
			'log'          => __( 'Log', 'dc-google-indexing' ),
			'qa'           => __( '🔍 Quality Assurance', 'dc-google-indexing' ),
			'index_status' => __( '📊 Index Status', 'dc-google-indexing' ),
		);
		foreach ( $tabs as $t => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page' => 'dc-google-indexing',
							'tab'  => $t,
						),
						admin_url( 'admin.php' )
					)
				),
				$tab === $t ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		?>
		</nav>

		<div class="dc-gi-panel">

		<?php if ( 'start' === $tab ) : ?>

		<!-- ===== GETTING STARTED ===== -->

		<div class="dc-gi-guide">

		<h2><?php esc_html_e( 'Getting Started — Connect Google Indexing API', 'dc-google-indexing' ); ?></h2>
		<p class="dc-gi-intro">
				<?php esc_html_e( 'This guide walks you through connecting your WordPress site to Google\'s Web Search Indexing API. Once set up, Google is notified within seconds every time you publish or update content — no more waiting days for Googlebot to find your pages.', 'dc-google-indexing' ); ?>
			<br><strong><?php esc_html_e( 'Estimated time: 10–15 minutes. No coding required.', 'dc-google-indexing' ); ?></strong>
		</p>

		<!-- Progress bar -->
				<?php
				$property_connected = ! empty( $connection_test['property_access']['ok'] );
				$step_done          = array( false, false, false, false, false );
				if ( $has_sa ) {
					$step_done = array( true, true, true, $property_connected, $property_connected );
				}
				$step_labels = array(
					__( 'Cloud Project', 'dc-google-indexing' ),
					__( 'Enable API', 'dc-google-indexing' ),
					__( 'Service Account', 'dc-google-indexing' ),
					__( 'Search Console', 'dc-google-indexing' ),
					__( 'Connect', 'dc-google-indexing' ),
				);
				?>
		<div class="dc-gi-progress">
				<?php
				foreach ( $step_labels as $i => $slabel ) :
					$class = $step_done[ $i ] ? 'done' : ( ! $has_sa && 0 === $i ? 'active' : '' );
					?>
			<div class="dc-gi-progress-step <?php echo esc_attr( $class ); ?>">
				<div class="dc-gi-progress-dot"><?php echo $step_done[ $i ] ? '✓' : esc_html( (string) ( $i + 1 ) ); ?></div>
				<div class="dc-gi-progress-label"><?php echo esc_html( $slabel ); ?></div>
			</div>
				<?php endforeach; ?>
		</div>

		<!-- ── STEP 1 ── -->
		<div class="dc-gi-step-card <?php echo $has_sa ? 'dc-gi-done' : ''; ?>">
			<div class="dc-gi-step-header">
				<div class="dc-gi-step-icon"><?php echo $has_sa ? '✓' : '1'; ?></div>
				<div class="dc-gi-step-title"><?php esc_html_e( 'Create a Google Cloud project', 'dc-google-indexing' ); ?></div>
				<div class="dc-gi-step-status"><?php echo $has_sa ? esc_html__( 'Complete', 'dc-google-indexing' ) : esc_html__( 'To do', 'dc-google-indexing' ); ?></div>
				<div class="dc-gi-step-toggle">▼</div>
			</div>
			<div class="dc-gi-step-body" <?php echo $has_sa ? 'hidden' : ''; ?>>
				<p style="color:#555;margin-top:0"><?php esc_html_e( 'Google Cloud is a platform where you manage API access. You need a "project" — think of it as a container for your Google services. It\'s free.', 'dc-google-indexing' ); ?></p>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">1</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">console.cloud.google.com ↗</a> and sign in with your Google account.', 'dc-google-indexing' ) ); ?></p>
						<div class="dc-gi-callout info">
							<?php esc_html_e( 'Use the same Google account that owns your Search Console property. If you\'re not sure, check', 'dc-google-indexing' ); ?>
							<a href="https://search.google.com/search-console" target="_blank" rel="noopener"><?php esc_html_e( 'Search Console ↗', 'dc-google-indexing' ); ?></a>.
						</div>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">2</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Click <strong>Select a project</strong> in the top bar, then click <strong>New Project</strong>.', 'dc-google-indexing' ) ); ?></p>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">3</div>
					<div class="dc-gi-substep-content">
						<p><?php esc_html_e( 'Enter a project name — e.g. "My Site Indexing" — and click Create. Wait a few seconds for it to be created.', 'dc-google-indexing' ); ?></p>
						<div class="dc-gi-callout warn">
							<strong><?php esc_html_e( 'No billing needed.', 'dc-google-indexing' ); ?></strong>
							<?php esc_html_e( 'The Indexing API is free within Google\'s default quota (200 URLs/day). Do NOT enable billing unless you specifically need a quota increase.', 'dc-google-indexing' ); ?>
						</div>
					</div>
				</div>

				<div class="dc-gi-btn-row">
					<a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener" class="button button-primary">
						<?php esc_html_e( 'Open Google Cloud Console ↗', 'dc-google-indexing' ); ?>
					</a>
					<span style="color:#888;font-size:13px"><?php esc_html_e( 'Opens in a new tab', 'dc-google-indexing' ); ?></span>
				</div>

				<div class="dc-gi-callout ok" style="margin-top:14px">
					<strong><?php esc_html_e( '✅ Done when:', 'dc-google-indexing' ); ?></strong>
					<?php esc_html_e( 'You can see your project name in the top navigation bar of Google Cloud Console.', 'dc-google-indexing' ); ?>
				</div>
			</div>
		</div>

		<!-- ── STEP 2 ── -->
		<div class="dc-gi-step-card <?php echo $has_sa ? 'dc-gi-done' : ''; ?>">
			<div class="dc-gi-step-header">
				<div class="dc-gi-step-icon"><?php echo $has_sa ? '✓' : '2'; ?></div>
				<div class="dc-gi-step-title"><?php esc_html_e( 'Enable the Web Search Indexing API', 'dc-google-indexing' ); ?></div>
				<div class="dc-gi-step-status"><?php echo $has_sa ? esc_html__( 'Complete', 'dc-google-indexing' ) : esc_html__( 'To do', 'dc-google-indexing' ); ?></div>
				<div class="dc-gi-step-toggle">▼</div>
			</div>
			<div class="dc-gi-step-body" <?php echo $has_sa ? 'hidden' : ''; ?>>
				<p style="color:#555;margin-top:0"><?php esc_html_e( 'By default, Google Cloud projects have most APIs disabled. You need to switch on the Indexing API.', 'dc-google-indexing' ); ?></p>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">1</div>
					<div class="dc-gi-substep-content">
						<p><?php esc_html_e( 'Click the button below to open the Indexing API page in Google Cloud (your project must be selected first).', 'dc-google-indexing' ); ?></p>
						<div class="dc-gi-btn-row" style="margin-top:8px">
							<a href="https://console.cloud.google.com/apis/library/indexing.googleapis.com" target="_blank" rel="noopener" class="button button-primary">
								<?php esc_html_e( 'Open Indexing API page ↗', 'dc-google-indexing' ); ?>
							</a>
						</div>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">2</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Click the blue <strong>Enable</strong> button. After a few seconds the button should change to <strong>Manage</strong>.', 'dc-google-indexing' ) ); ?></p>
						<div class="dc-gi-callout warn">
							<?php esc_html_e( 'Before clicking Enable, verify the correct project is shown in the top navigation. Enabling it on the wrong project is a common mistake.', 'dc-google-indexing' ); ?>
						</div>
					</div>
				</div>

				<div class="dc-gi-callout ok">
					<strong><?php esc_html_e( '✅ Done when:', 'dc-google-indexing' ); ?></strong>
					<?php esc_html_e( 'The button on the Indexing API page says "Manage" instead of "Enable".', 'dc-google-indexing' ); ?>
				</div>
			</div>
		</div>

		<!-- ── STEP 3 ── -->
		<div class="dc-gi-step-card <?php echo $has_sa ? 'dc-gi-done' : ''; ?>">
			<div class="dc-gi-step-header">
				<div class="dc-gi-step-icon"><?php echo $has_sa ? '✓' : '3'; ?></div>
				<div class="dc-gi-step-title"><?php esc_html_e( 'Create a Service Account and download the JSON key', 'dc-google-indexing' ); ?></div>
				<div class="dc-gi-step-status"><?php echo $has_sa ? esc_html__( 'Complete', 'dc-google-indexing' ) : esc_html__( 'To do', 'dc-google-indexing' ); ?></div>
				<div class="dc-gi-step-toggle">▼</div>
			</div>
			<div class="dc-gi-step-body" <?php echo $has_sa ? 'hidden' : ''; ?>>
				<p style="color:#555;margin-top:0">
					<?php esc_html_e( 'A Service Account is like a robot user. It has its own email address and credentials that this plugin uses to talk to Google — completely separate from your own login.', 'dc-google-indexing' ); ?>
				</p>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">1</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Open <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">Service Accounts in Cloud Console ↗</a>.', 'dc-google-indexing' ) ); ?></p>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">2</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Click <strong>+ Create Service Account</strong> at the top.', 'dc-google-indexing' ) ); ?></p>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">3</div>
					<div class="dc-gi-substep-content">
						<p><?php esc_html_e( 'Enter a name like "indexing-bot", an optional description, then click Create and Continue.', 'dc-google-indexing' ); ?></p>
						<div class="dc-gi-callout info">
							<?php esc_html_e( 'When it asks for a role, skip it — click Continue and then Done. No IAM role is needed for this service account.', 'dc-google-indexing' ); ?>
						</div>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">4</div>
					<div class="dc-gi-substep-content">
						<p><?php esc_html_e( 'You are now back on the Service Accounts list. Click on the email address of the account you just created.', 'dc-google-indexing' ); ?></p>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">5</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Click the <strong>Keys</strong> tab at the top of the page, then click <strong>Add Key → Create new key</strong>.', 'dc-google-indexing' ) ); ?></p>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">6</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Select <strong>JSON</strong> (not P12) and click <strong>Create</strong>. A <code>.json</code> file downloads to your computer automatically — keep it safe!', 'dc-google-indexing' ) ); ?></p>
						<div class="dc-gi-callout warn">
							<strong><?php esc_html_e( '🔒 Security notice:', 'dc-google-indexing' ); ?></strong>
							<?php esc_html_e( 'This JSON file contains a private key. Do NOT share it, commit it to version control, or leave it in a public folder. Once pasted here, you can delete the local copy.', 'dc-google-indexing' ); ?>
						</div>
					</div>
				</div>

				<div class="dc-gi-callout ok">
					<strong><?php esc_html_e( '✅ Done when:', 'dc-google-indexing' ); ?></strong>
					<?php esc_html_e( 'A .json file (about 2 KB) has downloaded to your computer. Open it in a text editor — you should see fields like "client_email", "private_key", and "project_id".', 'dc-google-indexing' ); ?>
				</div>
			</div>
		</div>

		<!-- ── STEP 4 ── -->
		<div class="dc-gi-step-card <?php echo $has_sa ? 'dc-gi-done' : ''; ?>">
			<div class="dc-gi-step-header">
				<div class="dc-gi-step-icon"><?php echo $has_sa ? '✓' : '4'; ?></div>
				<div class="dc-gi-step-title"><?php esc_html_e( 'Add the service account to Google Search Console', 'dc-google-indexing' ); ?></div>
				<div class="dc-gi-step-status"><?php echo $has_sa ? esc_html__( 'Complete', 'dc-google-indexing' ) : esc_html__( 'To do', 'dc-google-indexing' ); ?></div>
				<div class="dc-gi-step-toggle">▼</div>
			</div>
			<div class="dc-gi-step-body" <?php echo $has_sa ? 'hidden' : ''; ?>>
				<p style="color:#555;margin-top:0">
					<?php esc_html_e( 'Google requires that the service account is verified as an owner of your Search Console property. This is what gives it permission to submit URLs for your specific site.', 'dc-google-indexing' ); ?>
				</p>

				<div class="dc-gi-callout info" style="margin-bottom:16px">
					<?php esc_html_e( 'You need the service account email for this step. Open the JSON file you downloaded and find the "client_email" value. It looks like this:', 'dc-google-indexing' ); ?>
					<br><code>indexing-bot@your-project-id.iam.gserviceaccount.com</code>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">1</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Open <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console ↗</a> and select your property (website).', 'dc-google-indexing' ) ); ?></p>
						<div class="dc-gi-callout warn">
							<?php esc_html_e( 'Your site must already be verified in Search Console. If it isn\'t, add and verify it first — verification can take a few minutes.', 'dc-google-indexing' ); ?>
						</div>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">2</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'In the left sidebar, scroll to the bottom and click <strong>Settings</strong>.', 'dc-google-indexing' ) ); ?></p>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">3</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Click <strong>Users and permissions</strong>, then click <strong>Add user</strong>.', 'dc-google-indexing' ) ); ?></p>
					</div>
				</div>

				<div class="dc-gi-substep">
					<div class="dc-gi-substep-num">4</div>
					<div class="dc-gi-substep-content">
						<p><?php echo wp_kses_post( __( 'Paste the <code>client_email</code> from your JSON file into the email field and set Permission to <strong>Owner</strong>. Click Add.', 'dc-google-indexing' ) ); ?></p>
						<div class="dc-gi-callout warn">
							<strong><?php esc_html_e( 'Must be "Owner" — not "Full" or "Restricted".', 'dc-google-indexing' ); ?></strong>
							<?php esc_html_e( 'The Indexing API requires property ownership, not just user access. "Full" user permission will still result in a 403 Permission denied error.', 'dc-google-indexing' ); ?>
						</div>
					</div>
				</div>

				<div class="dc-gi-callout ok">
					<strong><?php esc_html_e( '✅ Done when:', 'dc-google-indexing' ); ?></strong>
					<?php esc_html_e( 'The service account email appears in the Users and permissions list with "Owner" next to it.', 'dc-google-indexing' ); ?>
				</div>
			</div>
		</div>

		<!-- ── STEP 5 ── -->
		<div class="dc-gi-step-card <?php echo $has_sa ? 'dc-gi-done' : ''; ?>">
			<div class="dc-gi-step-header">
				<div class="dc-gi-step-icon"><?php echo $has_sa ? '✓' : '5'; ?></div>
				<div class="dc-gi-step-title">
					<?php esc_html_e( 'Paste your JSON key and connect', 'dc-google-indexing' ); ?>
				</div>
				<div class="dc-gi-step-status">
					<?php echo $has_sa ? esc_html__( 'Complete', 'dc-google-indexing' ) : esc_html__( 'Action needed', 'dc-google-indexing' ); ?>
				</div>
				<div class="dc-gi-step-toggle"><?php echo $has_sa ? '▼' : '▲'; ?></div>
			</div>
			<div class="dc-gi-step-body">
				<?php if ( $has_sa ) : ?>

				<div class="dc-gi-check-row">
					<span class="dc-gi-check-icon" style="color:#46b450">✅</span>
					<span class="dc-gi-check-label"><strong><?php esc_html_e( 'Service account connected', 'dc-google-indexing' ); ?></strong></span>
					<span class="dc-gi-check-value"><code><?php echo esc_html( $sa_email ); ?></code></span>
				</div>
				<div class="dc-gi-check-row" style="margin-top:8px">
					<span class="dc-gi-check-icon" style="color:#46b450">🔎</span>
					<span class="dc-gi-check-label"><strong><?php esc_html_e( 'Search Console property', 'dc-google-indexing' ); ?></strong></span>
					<span class="dc-gi-check-value"><code><?php echo esc_html( $property ); ?></code></span>
				</div>

				<div class="dc-gi-callout ok" style="margin-top:14px">
					<strong><?php esc_html_e( '🎉 You\'re all set!', 'dc-google-indexing' ); ?></strong>
					<?php esc_html_e( 'Your site is connected. Next, run the connection test from Settings to verify property access and inspect quota visibility.', 'dc-google-indexing' ); ?>
				</div>
					<?php if ( empty( $connection_test['checked_at'] ) || empty( $connection_test['property_access']['ok'] ) ) : ?>
				<div class="dc-gi-callout warn" style="margin-top:14px">
					<strong><?php esc_html_e( 'One last step remains.', 'dc-google-indexing' ); ?></strong>
						<?php esc_html_e( 'Open Settings and run the connection test to confirm that this exact Search Console property is visible to the service account.', 'dc-google-indexing' ); ?>
				</div>
				<?php endif; ?>

				<div class="dc-gi-btn-row">
					<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => 'dc-google-indexing',
								'tab'  => 'submit',
							),
							admin_url( 'admin.php' )
						)
					);
					?>
								" class="button button-primary">
						<?php esc_html_e( '→ Submit URLs now', 'dc-google-indexing' ); ?>
					</a>
					<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => 'dc-google-indexing',
								'tab'  => 'settings',
							),
							admin_url( 'admin.php' )
						)
					);
					?>
								" class="button">
						<?php esc_html_e( '→ Review Settings', 'dc-google-indexing' ); ?>
					</a>
				</div>

				<?php else : ?>

				<p style="margin-top:0;color:#555">
					<?php esc_html_e( 'Open the .json file from Step 3 in a text editor (Notepad, TextEdit, etc.), select all the text, copy it, and paste it into the box below.', 'dc-google-indexing' ); ?>
				</p>

				<div class="dc-gi-callout info">
					<?php esc_html_e( 'The file looks like this (the values below are placeholders):', 'dc-google-indexing' ); ?>
					<div class="dc-gi-json-preview">
{<br>
&nbsp;&nbsp;<span class="key">"type"</span>: <span class="val">"service_account"</span>,<br>
&nbsp;&nbsp;<span class="key">"project_id"</span>: <span class="val">"your-project-123"</span>,<br>
&nbsp;&nbsp;<span class="key">"private_key_id"</span>: <span class="val">"a1b2c3..."</span>,<br>
&nbsp;&nbsp;<span class="key">"private_key"</span>: <span class="val">"-----BEGIN PRIVATE KEY-----\n..."</span>,<br>
&nbsp;&nbsp;<span class="key">"client_email"</span>: <span class="val">"<span class="type">indexing-bot@your-project.iam.gserviceaccount.com</span>"</span>,<br>
&nbsp;&nbsp;<span class="key">"client_id"</span>: <span class="val">"123456..."</span>,<br>
&nbsp;&nbsp;...<br>
}
					</div>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dc_gi_save' ); ?>
					<input type="hidden" name="action" value="dc_gi_save">
					<input type="hidden" name="auto_submit" value="1">
					<input type="hidden" name="auto_delete" value="1">
					<input type="hidden" name="daily_quota" value="200">
					<input type="hidden" name="post_types[]" value="post">
					<input type="hidden" name="post_types[]" value="page">
					<label for="dc-gi-search-console-property" style="font-weight:600;display:block;margin-bottom:6px">
						<?php esc_html_e( 'Search Console property', 'dc-google-indexing' ); ?>
					</label>
					<input
						type="text"
						id="dc-gi-search-console-property"
						name="search_console_property"
						class="regular-text code"
						value="<?php echo esc_attr( $property ); ?>"
						placeholder="https://example.com/ or sc-domain:example.com"
						style="margin-bottom:12px;max-width:560px"
					>
					<p class="description" style="margin-top:0;margin-bottom:12px">
						<?php esc_html_e( 'Use the exact property from Search Console. URL-prefix properties should end with a slash. Domain properties should use the sc-domain:example.com format.', 'dc-google-indexing' ); ?>
					</p>
					<label for="dc-gi-json-input" style="font-weight:600;display:block;margin-bottom:6px">
						<?php esc_html_e( 'Paste your JSON key file contents here:', 'dc-google-indexing' ); ?>
					</label>
					<textarea
						id="dc-gi-json-input"
						name="service_account_json"
						rows="9"
						class="large-text code"
						placeholder='{"type":"service_account","project_id":"...","private_key":"-----BEGIN PRIVATE KEY-----\n...","client_email":"name@project.iam.gserviceaccount.com",...}'
					></textarea>
					<div id="dc-gi-json-feedback"></div>
					<div class="dc-gi-btn-row">
						<button type="submit" class="button button-primary button-large">
							<?php esc_html_e( 'Save Settings', 'dc-google-indexing' ); ?>
						</button>
						<span style="color:#888;font-size:13px"><?php esc_html_e( 'The plugin validates the JSON on save. Run the connection test right after saving to verify property access.', 'dc-google-indexing' ); ?></span>
					</div>
				</form>

				<div class="dc-gi-callout warn" style="margin-top:16px">
					<strong><?php esc_html_e( 'Getting a 403 Permission denied error?', 'dc-google-indexing' ); ?></strong>
					<?php esc_html_e( 'Go to Search Console → Settings → Users and permissions and check the service account entry. It must show "Owner" — not "Full" or "Restricted". If it shows "Full", remove it and re-add with Owner permission.', 'dc-google-indexing' ); ?>
				</div>

				<?php endif; ?>
			</div>
		</div>

		</div><!-- .dc-gi-guide -->

		<?php elseif ( 'settings' === $tab ) : ?>

		<!-- ===== SETTINGS ===== -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dc_gi_save' ); ?>
			<input type="hidden" name="action" value="dc_gi_save">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="service_account_json"><?php esc_html_e( 'Service Account JSON', 'dc-google-indexing' ); ?></label>
					</th>
					<td>
						<?php if ( $has_sa ) : ?>
						<p style="margin-bottom:6px">
							<span style="color:#46b450">&#10003;</span>
							<?php
							printf(
								/* translators: %s: service account email address */
								esc_html__( 'Configured: %s', 'dc-google-indexing' ),
								'<code>' . esc_html( $sa_email ) . '</code>'
							);
							?>
						</p>
						<?php endif; ?>
						<textarea
							id="service_account_json"
							name="service_account_json"
							rows="7"
							class="large-text code"
							placeholder='{"type":"service_account","project_id":"...","private_key":"-----BEGIN PRIVATE KEY-----\n...","client_email":"name@project.iam.gserviceaccount.com",...}'
						></textarea>
						<p class="description">
							<?php if ( $has_sa ) : ?>
								<?php esc_html_e( 'Leave empty to keep current credentials. Paste a new JSON file to replace.', 'dc-google-indexing' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Paste the full contents of your Google Cloud service account JSON key file.', 'dc-google-indexing' ); ?>
							<?php endif; ?>
							&nbsp;<a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener"><?php esc_html_e( 'Open Google Cloud Console ↗', 'dc-google-indexing' ); ?></a>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="search_console_property"><?php esc_html_e( 'Search Console Property', 'dc-google-indexing' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="search_console_property"
							name="search_console_property"
							class="regular-text code"
							value="<?php echo esc_attr( $property ); ?>"
							placeholder="https://example.com/ or sc-domain:example.com"
						>
						<p class="description">
							<?php esc_html_e( 'Use the exact property string from Search Console. This is the property the URL Inspection API will use for coverage checks.', 'dc-google-indexing' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-submit on Publish', 'dc-google-indexing' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_submit" value="1" <?php checked( ! empty( $settings['auto_submit'] ) ); ?>>
							<?php esc_html_e( 'Automatically queue URLs when a post is published or updated', 'dc-google-indexing' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-delete on Remove', 'dc-google-indexing' ); ?></th>
					<td>
						<label>
							<?php
							// Default to checked (1) for sites upgrading from a version without this setting.
							$auto_delete_val = isset( $settings['auto_delete'] ) ? $settings['auto_delete'] : 1;
							?>
							<input type="checkbox" name="auto_delete" value="1" <?php checked( ! empty( $auto_delete_val ) ); ?>>
							<?php esc_html_e( 'Automatically notify Google to de-index URLs when a post is trashed, unpublished, or password-protected', 'dc-google-indexing' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Sends a URL_DELETED notification for posts that are moved to Trash, switched to Draft/Private/Pending, or have a password added. Disable this if you manage de-indexing separately.', 'dc-google-indexing' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types', 'dc-google-indexing' ); ?></th>
					<td>
						<?php
						$saved_types = $settings['post_types'] ?? array( 'post', 'page' );
						foreach ( $all_post_types as $pt ) :
							?>
						<label style="display:block;margin-bottom:4px">
							<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $saved_types, true ) ); ?>>
							<?php echo esc_html( $pt->label . ' (' . $pt->name . ')' ); ?>
						</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="daily_quota"><?php esc_html_e( 'Daily Quota Limit', 'dc-google-indexing' ); ?></label>
					</th>
					<td>
						<input type="number" id="daily_quota" name="daily_quota"
							value="<?php echo esc_attr( (string) ( $settings['daily_quota'] ?? 200 ) ); ?>"
							min="1" max="200" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Maximum submissions per day. Google default is 200 — request a quota increase in Cloud Console if needed.', 'dc-google-indexing' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="analytics_days"><?php esc_html_e( 'Analytics Date Range (days)', 'dc-google-indexing' ); ?></label>
					</th>
					<td>
						<select id="analytics_days" name="analytics_days">
							<?php
							$saved_days = (int) ( $settings['analytics_days'] ?? 28 );
							foreach ( array( 7, 28, 90 ) as $opt ) {
								printf(
									'<option value="%1$d"%2$s>%3$s</option>',
									(int) $opt,
									selected( $saved_days, $opt, false ),
									/* translators: %d: number of days for analytics date range */
									esc_html( sprintf( __( 'Last %d days', 'dc-google-indexing' ), $opt ) )
								);
							}
							?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Date range used when the background cron fetches Search Analytics data. This also sets the default range on the Index Status page.', 'dc-google-indexing' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'dc-google-indexing' ) ); ?>
		</form>

		<hr style="margin:20px 0">

		<h3><?php esc_html_e( 'Test Connection', 'dc-google-indexing' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dc_gi_test' ); ?>
			<input type="hidden" name="action" value="dc_gi_test">
			<p>
				<button type="submit" class="button">
					<?php esc_html_e( 'Run connection test', 'dc-google-indexing' ); ?>
				</button>
				<span class="description" style="margin-left:8px">
					<?php esc_html_e( 'Checks Indexing API auth, Search Console auth, and whether the selected property is actually accessible to the service account.', 'dc-google-indexing' ); ?>
				</span>
			</p>
		</form>

			<?php if ( ! empty( $connection_test['checked_at'] ) ) : ?>
		<div class="dc-gi-live-panel" style="max-width:860px;margin-top:18px">
			<h3 style="margin-top:0"><?php esc_html_e( 'Latest Connection Report', 'dc-google-indexing' ); ?></h3>
			<p style="font-size:12px;color:#7a8499;margin-top:-6px">
				<?php
				printf(
					/* translators: %s: localized date and time */
					esc_html__( 'Last checked: %s', 'dc-google-indexing' ),
					esc_html( wp_date( 'Y-m-d H:i:s', (int) $connection_test['checked_at'] ) )
				);
				?>
			</p>
			<div class="dc-gi-grid-3" style="grid-template-columns:repeat(3,minmax(0,1fr))">
				<div class="dc-gi-callout <?php echo ! empty( $connection_test['indexing_api']['ok'] ) ? 'ok' : 'err'; ?>" style="margin:0">
					<strong><?php esc_html_e( 'Indexing API', 'dc-google-indexing' ); ?></strong><br>
					<?php echo esc_html( (string) ( $connection_test['indexing_api']['message'] ?? '' ) ); ?>
				</div>
				<div class="dc-gi-callout <?php echo ! empty( $connection_test['inspection_api']['ok'] ) ? 'ok' : 'err'; ?>" style="margin:0">
					<strong><?php esc_html_e( 'Search Console API', 'dc-google-indexing' ); ?></strong><br>
					<?php echo esc_html( (string) ( $connection_test['inspection_api']['message'] ?? '' ) ); ?>
				</div>
				<div class="dc-gi-callout <?php echo ! empty( $connection_test['property_access']['ok'] ) ? 'ok' : 'warn'; ?>" style="margin:0">
					<strong><?php esc_html_e( 'Property Access', 'dc-google-indexing' ); ?></strong><br>
					<?php echo esc_html( (string) ( $connection_test['property_access']['message'] ?? '' ) ); ?>
				</div>
			</div>

				<?php if ( ! empty( $connection_test['properties'] ) ) : ?>
			<h4 style="margin-bottom:8px"><?php esc_html_e( 'Accessible Search Console Properties', 'dc-google-indexing' ); ?></h4>
			<div style="max-height:220px;overflow:auto;border:1px solid #2d3555;border-radius:6px">
				<table class="widefat striped" style="margin:0">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Property', 'dc-google-indexing' ); ?></th>
							<th style="width:170px"><?php esc_html_e( 'Permission', 'dc-google-indexing' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( (array) $connection_test['properties'] as $api_property ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $api_property['siteUrl'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $api_property['permissionLevel'] ?? '' ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<hr style="margin:20px 0">

		<p>
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => 'dc-google-indexing',
						'tab'  => 'start',
					),
					admin_url( 'admin.php' )
				)
			);
			?>
						">
				<?php esc_html_e( '← View the Getting Started guide', 'dc-google-indexing' ); ?>
			</a>
		</p>

		<?php elseif ( 'submit' === $tab ) : ?>

		<!-- ===== SUBMIT URLs ===== -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dc_gi_submit' ); ?>
			<input type="hidden" name="action" value="dc_gi_submit">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="urls"><?php esc_html_e( 'URLs', 'dc-google-indexing' ); ?></label>
					</th>
					<td>
						<textarea id="urls" name="urls" rows="12" class="large-text code"
							placeholder="https://example.com/product-1&#10;https://example.com/product-2&#10;https://example.com/blog-post"></textarea>
						<p class="description"><?php esc_html_e( 'One URL per line. Added to queue and processed within 5 minutes.', 'dc-google-indexing' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Notification Type', 'dc-google-indexing' ); ?></th>
					<td>
						<label style="margin-right:20px">
							<input type="radio" name="submit_type" value="URL_UPDATED" checked>
							<?php esc_html_e( 'URL Updated — new or changed content', 'dc-google-indexing' ); ?>
						</label>
						<label>
							<input type="radio" name="submit_type" value="URL_DELETED">
							<?php esc_html_e( 'URL Deleted — remove from index', 'dc-google-indexing' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Add to Queue', 'dc-google-indexing' ) ); ?>
		</form>

		<?php elseif ( 'watchlist' === $tab ) : ?>

		<!-- ===== WATCHLIST ===== -->

		<h2 style="margin-top:0"><?php esc_html_e( 'Watchlist — Index Status Tracker', 'dc-google-indexing' ); ?></h2>
		<p style="color:#555;max-width:700px">
			<?php esc_html_e( 'Every URL successfully submitted to Google is tracked here. Status is checked automatically every 6 hours via WP-Cron and updated when Google reports the page as indexed. URLs already in the Watchlist are skipped during Polling to avoid wasting inspection quota.', 'dc-google-indexing' ); ?>
		</p>

			<?php
			$watch_pending         = array_filter( $watchlist, fn( $e ) => 'pending' === $e['status'] );
			$watch_indexed         = array_filter( $watchlist, fn( $e ) => 'indexed' === $e['status'] );
			$watch_removal_pending = array_filter( $watchlist, fn( $e ) => 'removal_pending' === $e['status'] );
			$watch_removed         = array_filter( $watchlist, fn( $e ) => 'removed' === $e['status'] );
			$next_watch            = wp_next_scheduled( DC_GI_WATCH_HOOK );
			?>

		<div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
			<!-- Running/Stopped badge — updated live by JS -->
			<span id="dc-gi-watch-badge" class="dc-gi-poll-badge stopped" style="margin-right:4px">
				<span class="dc-gi-badge-text"><?php esc_html_e( '○ Stopped', 'dc-google-indexing' ); ?></span>
			</span>
			<button id="dc-gi-watch-check-btn" class="button dc-gi-btn-start"><?php esc_html_e( '🔄 Check Now', 'dc-google-indexing' ); ?></button>
			<button id="dc-gi-watch-stop-btn2" class="button dc-gi-btn-stop" disabled><?php esc_html_e( '■ Stop', 'dc-google-indexing' ); ?></button>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php esc_attr_e( 'Clear the entire watchlist?', 'dc-google-indexing' ); ?>')">
				<?php wp_nonce_field( 'dc_gi_watch_clr' ); ?>
				<input type="hidden" name="action" value="dc_gi_watch_clr">
				<button type="submit" class="button dc-gi-btn-secondary"><?php esc_html_e( 'Clear All', 'dc-google-indexing' ); ?></button>
			</form>
			<?php if ( count( $watch_indexed ) > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php esc_attr_e( 'Remove all indexed URLs from the watchlist?', 'dc-google-indexing' ); ?>')">
				<?php wp_nonce_field( 'dc_gi_watch_clr_indexed' ); ?>
				<input type="hidden" name="action" value="dc_gi_watch_clr_indexed">
				<button type="submit" class="button dc-gi-btn-secondary">
					<?php
					printf(
						/* translators: %d: number of indexed URLs */
						esc_html__( '✅ Clear All Indexed (%d)', 'dc-google-indexing' ),
						count( $watch_indexed )
					);
					?>
				</button>
			</form>
			<?php endif; ?>
			<span class="dc-gi-wl-next">
				<?php if ( $next_watch ) : ?>
					<?php
					printf(
						/* translators: %s: human-readable time until next auto-check */
						esc_html__( 'Next auto-check in %s', 'dc-google-indexing' ),
						esc_html( human_time_diff( time(), $next_watch ) )
					);
					?>
				<?php else : ?>
					<span style="color:#fd5d93"><?php esc_html_e( '⚠️ Auto-check not scheduled', 'dc-google-indexing' ); ?></span>
				<?php endif; ?>
			</span>
			<?php if ( ! $next_watch ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
					<?php wp_nonce_field( 'dc_gi_watch_fix_cron' ); ?>
					<input type="hidden" name="action" value="dc_gi_watch_fix_cron">
					<button type="submit" class="button button-small" style="color:#ff8d72;border-color:#ff8d72"><?php esc_html_e( '↺ Fix Schedule', 'dc-google-indexing' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<!-- Live check progress panel -->
		<div id="dc-gi-watch-progress" style="display:none;max-width:740px;margin-bottom:20px">
			<div class="dc-gi-live-panel" style="padding:18px 22px">
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
					<span id="dc-gi-wcp-label" style="font-size:13px;font-weight:600;color:#c8d0e0"><?php esc_html_e( 'Checking…', 'dc-google-indexing' ); ?></span>
					<button id="dc-gi-watch-stop-btn" class="button dc-gi-btn-stop" style="font-size:11px;padding:3px 12px" disabled><?php esc_html_e( '■ Stop', 'dc-google-indexing' ); ?></button>
				</div>
				<div style="display:flex;justify-content:space-between;font-size:12px;color:#7a8499;margin-bottom:4px">
					<span><?php esc_html_e( 'Progress', 'dc-google-indexing' ); ?></span>
					<span id="dc-gi-wcp-count">0 / 0</span>
				</div>
				<div style="background:rgba(255,255,255,.07);border-radius:6px;height:8px;overflow:hidden">
					<div id="dc-gi-wcp-bar" style="height:100%;width:0;background:linear-gradient(90deg,#1d8cf8,#00f2c3);border-radius:6px;transition:width .3s"></div>
				</div>
				<p id="dc-gi-wcp-url" style="font-size:11px;color:#7a8499;margin:8px 0 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></p>
			</div>
		</div>

		<div style="display:flex;gap:20px;margin-bottom:20px;flex-wrap:wrap">
			<div class="dc-gi-stat" style="min-width:110px">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) count( $watchlist ) ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Total', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat" style="min-width:110px">
				<div class="dc-gi-stat-num" style="color:#ff8d72"><?php echo esc_html( (string) count( $watch_pending ) ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Pending', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat green" style="min-width:110px">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) count( $watch_indexed ) ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Indexed', 'dc-google-indexing' ); ?></div>
			</div>
			<?php if ( count( $watch_removal_pending ) > 0 || count( $watch_removed ) > 0 ) : ?>
			<div class="dc-gi-stat" style="min-width:110px">
				<div class="dc-gi-stat-num" style="color:#1d8cf8"><?php echo esc_html( (string) count( $watch_removal_pending ) ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Removal Pending', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat" style="min-width:110px">
				<div class="dc-gi-stat-num" style="color:#8892a4"><?php echo esc_html( (string) count( $watch_removed ) ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Removed', 'dc-google-indexing' ); ?></div>
			</div>
			<?php endif; ?>
		</div>

			<?php if ( empty( $watchlist ) ) : ?>
		<p style="color:#777"><?php esc_html_e( 'No URLs tracked yet. Submit URLs to Google and they will appear here automatically.', 'dc-google-indexing' ); ?></p>
		<?php else : ?>
		<table class="widefat striped" style="margin-top:0">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL', 'dc-google-indexing' ); ?></th>
					<th style="width:100px"><?php esc_html_e( 'Status', 'dc-google-indexing' ); ?></th>
					<th style="width:200px"><?php esc_html_e( 'Coverage State', 'dc-google-indexing' ); ?></th>
					<th style="width:140px"><?php esc_html_e( 'Submitted', 'dc-google-indexing' ); ?></th>
					<th style="width:140px"><?php esc_html_e( 'Last Checked', 'dc-google-indexing' ); ?></th>
					<th style="width:50px"></th>
				</tr>
			</thead>
			<tbody id="dc-gi-wl-tbody">
				<?php
				foreach ( $watchlist as $entry ) :
					$badge_class = in_array( $entry['status'], array( 'pending', 'indexed', 'error', 'removal_pending', 'removed' ), true )
						? $entry['status'] : 'pending';
					$badge_label = 'removal_pending' === $entry['status']
						? __( 'Removal Pending', 'dc-google-indexing' )
						: ucfirst( $entry['status'] );
					?>
				<tr data-wl-url="<?php echo esc_attr( $entry['url'] ); ?>">
					<td>
						<a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $entry['url'] ); ?>
						</a>
					</td>
					<td><span class="dc-gi-wl-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span></td>
					<td><?php echo esc_html( ( ! empty( $entry['coverage'] ) ) ? $entry['coverage'] : '—' ); ?></td>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $entry['submitted_at'] ) ); ?></td>
					<td><?php echo $entry['last_checked'] ? esc_html( wp_date( 'Y-m-d H:i', $entry['last_checked'] ) ) : '—'; ?></td>
					<td>
						<div style="display:flex;gap:4px;align-items:center">
							<button type="button"
								class="button dc-gi-watch-resubmit-btn"
								style="font-size:11px;padding:2px 8px"
								data-url="<?php echo esc_attr( $entry['url'] ); ?>"
								title="<?php esc_attr_e( 'Re-submit to Indexing API + signal URL Inspection API', 'dc-google-indexing' ); ?>"
							>↻</button>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'dc_gi_watch_del' ); ?>
								<input type="hidden" name="action" value="dc_gi_watch_del">
								<input type="hidden" name="watch_url" value="<?php echo esc_attr( $entry['url'] ); ?>">
								<button type="submit" class="button button-link-delete" style="font-size:11px"
									onclick="return confirm('<?php esc_attr_e( 'Remove from watchlist?', 'dc-google-indexing' ); ?>')"
								>&times;</button>
							</form>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php elseif ( 'log' === $tab ) : ?>

		<!-- ===== LOG ===== -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			style="margin-bottom:12px"
			onsubmit="return confirm('<?php esc_attr_e( 'Clear the submission log?', 'dc-google-indexing' ); ?>')">
			<?php wp_nonce_field( 'dc_gi_clrlog' ); ?>
			<input type="hidden" name="action" value="dc_gi_clrlog">
			<button type="submit" class="button"><?php esc_html_e( 'Clear Log', 'dc-google-indexing' ); ?></button>
		</form>

			<?php if ( empty( $log ) ) : ?>
			<p><?php esc_html_e( 'No submissions logged yet.', 'dc-google-indexing' ); ?></p>
		<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'dc-google-indexing' ); ?></th>
					<th><?php esc_html_e( 'URL', 'dc-google-indexing' ); ?></th>
					<th><?php esc_html_e( 'Type', 'dc-google-indexing' ); ?></th>
					<th><?php esc_html_e( 'Status', 'dc-google-indexing' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'dc-google-indexing' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $log as $entry ) :
					$is_ownership_err = ( 'error' === $entry['status'] )
						&& false !== stripos( $entry['detail'], 'URL ownership' );
					?>
				<tr>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $entry['time'] ) ); ?></td>
					<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr( $entry['url'] ); ?>">
						<?php echo esc_html( $entry['url'] ); ?>
					</td>
					<td><code><?php echo esc_html( $entry['type'] ); ?></code></td>
					<td>
						<?php if ( 'ok' === $entry['status'] ) : ?>
							<span style="color:#46b450;font-weight:600">&#10003; OK</span>
						<?php elseif ( 'info' === $entry['status'] ) : ?>
							<span style="color:#1d8cf8;font-weight:600">&#x2139; Info</span>
						<?php else : ?>
							<span style="color:#dc3232;font-weight:600">&#10007; Error</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $is_ownership_err ) : ?>
							<span style="color:#dc3232"><?php esc_html_e( 'Permission denied: service account not verified as property owner.', 'dc-google-indexing' ); ?></span>
							<a href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'page' => 'dc-google-indexing',
										'tab'  => 'start',
									),
									admin_url( 'admin.php' )
								)
							);
							?>
										#step-4" style="margin-left:6px;white-space:nowrap">
								<?php esc_html_e( '→ Fix: Step 4', 'dc-google-indexing' ); ?>
							</a>
						<?php else : ?>
							<?php echo esc_html( $entry['detail'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php elseif ( 'qa' === $tab ) : ?>

		<!-- ===== QUALITY ASSURANCE ===== -->

		<h2 style="margin-top:0"><?php esc_html_e( 'Quality Assurance — On-Page SEO Checker', 'dc-google-indexing' ); ?></h2>
		<p style="color:#555;max-width:740px">
			<?php esc_html_e( 'Inspects URLs flagged by the Watchlist (Crawled, Discovered, or unknown to Google) and checks for common on-page issues: 404 errors, noindex directives, missing or short meta description (≤80 chars), missing title or H1, non-canonical URLs, duplicate full-page content, duplicate WooCommerce short descriptions, thin content (<150 words), SEO title mismatch, and duplicate SEO titles.', 'dc-google-indexing' ); ?>
		</p>

		<div class="dc-gi-info-grid" style="max-width:860px">
			<div class="dc-gi-callout info">
				<strong><?php esc_html_e( 'Watchlist-driven', 'dc-google-indexing' ); ?></strong><br>
				<?php esc_html_e( 'URLs are added here automatically by the Watchlist when Google reports them as "Crawled — not indexed", "Discovered — not indexed", or "URL is unknown to Google". QA does not scan the full sitemap — that is Polling\'s job.', 'dc-google-indexing' ); ?>
			</div>
			<div class="dc-gi-callout info">
				<strong><?php esc_html_e( 'No quota used', 'dc-google-indexing' ); ?></strong><br>
				<?php esc_html_e( 'Each URL is fetched directly — no Google Inspection API calls are made, so no daily quota is consumed. Results are saved after each URL so you can stop and resume at any time.', 'dc-google-indexing' ); ?>
			</div>
		</div>

			<?php
			// Compute summary stats from stored results.
			$qa_total        = count( $qa_results );
			$qa_with_issues  = 0;
			$qa_clean        = 0;
			$qa_issue_counts = array();
			foreach ( $qa_results as $qa_entry ) {
				if ( ! empty( $qa_entry['issues'] ) ) {
					++$qa_with_issues;
					foreach ( $qa_entry['issues'] as $issue_type ) {
						$qa_issue_counts[ $issue_type ] = ( $qa_issue_counts[ $issue_type ] ?? 0 ) + 1;
					}
				} else {
					++$qa_clean;
				}
			}

			$qa_issue_labels = array(
				'fetch_error'          => __( 'Fetch Error', 'dc-google-indexing' ),
				'not_found'            => __( '404 Not Found', 'dc-google-indexing' ),
				'http_error'           => __( 'HTTP Error', 'dc-google-indexing' ),
				'redirect'             => __( 'Redirect', 'dc-google-indexing' ),
				'noindex'              => __( 'Noindex', 'dc-google-indexing' ),
				'missing_title'        => __( 'Missing Title', 'dc-google-indexing' ),
				'missing_meta_desc'    => __( 'Missing Meta Desc', 'dc-google-indexing' ),
				'short_meta_desc'      => __( 'Short Meta Desc (≤80)', 'dc-google-indexing' ),
				'missing_h1'           => __( 'Missing H1', 'dc-google-indexing' ),
				'non_canonical'        => __( 'Non-Canonical', 'dc-google-indexing' ),
				'duplicate_content'    => __( 'Duplicate Content', 'dc-google-indexing' ),
				'duplicate_short_desc' => __( 'Duplicate Short Desc', 'dc-google-indexing' ),
				'thin_content'         => __( 'Thin Content (<150 words)', 'dc-google-indexing' ),
				'title_mismatch'       => __( 'Title Mismatch', 'dc-google-indexing' ),
				'duplicate_title'      => __( 'Duplicate Title', 'dc-google-indexing' ),
			);

			$qa_issue_colors = array(
				'fetch_error'          => '#fd5d93',
				'not_found'            => '#fd5d93',
				'http_error'           => '#fd5d93',
				'redirect'             => '#ff8d72',
				'noindex'              => '#ff8d72',
				'missing_title'        => '#ff8d72',
				'missing_meta_desc'    => '#ff8d72',
				'short_meta_desc'      => '#ff8d72',
				'missing_h1'           => '#7a8499',
				'non_canonical'        => '#ff8d72',
				'duplicate_content'    => '#ff8d72',
				'duplicate_short_desc' => '#ff8d72',
				'thin_content'         => '#ff8d72',
				'title_mismatch'       => '#ff8d72',
				'duplicate_title'      => '#ff8d72',
			);
			?>

			<?php if ( ! empty( $qa_pending ) ) : ?>
		<div class="dc-gi-callout warn" style="margin-bottom:20px">
			<strong>
				<?php
				printf(
				/* translators: %d: number of URLs flagged by Watchlist */
					esc_html__( '🔍 %d URL(s) flagged by Watchlist for manual QA scan', 'dc-google-indexing' ),
					count( $qa_pending )
				);
				?>
			</strong><br>
			<span style="font-size:12px;opacity:.8"><?php esc_html_e( 'These URLs were flagged by the Watchlist (Crawled/Discovered not indexed, or unknown to Google) and re-queued for submission. Run a scan to investigate on-page issues preventing indexing.', 'dc-google-indexing' ); ?></span>
			<ul style="margin:8px 0 4px;padding-left:20px">
				<?php foreach ( $qa_pending as $qa_pending_url ) : ?>
				<li style="font-size:12px"><a href="<?php echo esc_url( $qa_pending_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $qa_pending_url ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- Stats row -->
		<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
			<div class="dc-gi-stat" style="min-width:110px">
				<div class="dc-gi-stat-num" id="dc-gi-qa-stat-total"><?php echo esc_html( (string) $qa_total ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Scanned', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat red" style="min-width:110px">
				<div class="dc-gi-stat-num" id="dc-gi-qa-stat-issues"><?php echo esc_html( (string) $qa_with_issues ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'With Issues', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat green" style="min-width:110px">
				<div class="dc-gi-stat-num" id="dc-gi-qa-stat-clean"><?php echo esc_html( (string) $qa_clean ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Clean', 'dc-google-indexing' ); ?></div>
			</div>
		</div>

		<!-- Controls -->
		<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
			<span id="dc-gi-qa-badge" class="dc-gi-poll-badge stopped">
				<span><?php esc_html_e( '○ Stopped', 'dc-google-indexing' ); ?></span>
			</span>
			<button id="dc-gi-qa-start-btn" class="button dc-gi-btn-start" <?php disabled( empty( $qa_pending ) && 0 === $qa_with_issues ); ?>><?php esc_html_e( '▶ Start Scan', 'dc-google-indexing' ); ?></button>
			<button id="dc-gi-qa-stop-btn" class="button dc-gi-btn-stop" disabled><?php esc_html_e( '■ Stop', 'dc-google-indexing' ); ?></button>
			<?php
			$qa_last_refresh = (int) get_option( 'dc_gi_qa_last_refresh', 0 );
			if ( $qa_last_refresh > 0 ) :
				?>
			<span style="font-size:12px;color:#7a8499;align-self:center">
				<?php
				printf(
					/* translators: %s: human-readable time difference, e.g. "3 days" */
					esc_html__( 'Last auto-scan: %s ago', 'dc-google-indexing' ),
					esc_html( human_time_diff( $qa_last_refresh ) )
				);
				?>
			</span>
			<?php endif; ?>
			<?php if ( ! empty( $qa_results ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php esc_attr_e( 'Clear all QA scan results?', 'dc-google-indexing' ); ?>')">
				<?php wp_nonce_field( 'dc_gi_qa_clear' ); ?>
				<input type="hidden" name="action" value="dc_gi_qa_clear">
				<button type="submit" class="button dc-gi-btn-secondary"><?php esc_html_e( '✕ Clear Results', 'dc-google-indexing' ); ?></button>
			</form>
			<?php endif; ?>
		</div>

		<!-- Progress panel (hidden until scan starts) -->
		<div id="dc-gi-qa-progress" style="display:none;max-width:740px;margin-bottom:20px">
			<div class="dc-gi-live-panel" style="padding:18px 22px">
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
					<span id="dc-gi-qa-prog-label" style="font-size:13px;font-weight:600;color:#c8d0e0"><?php esc_html_e( 'Scanning…', 'dc-google-indexing' ); ?></span>
					<span id="dc-gi-qa-prog-count" style="font-size:12px;color:#7a8499">0 / 0</span>
				</div>
				<div style="background:rgba(255,255,255,.07);border-radius:6px;height:8px;overflow:hidden">
					<div id="dc-gi-qa-prog-bar" style="height:100%;width:0;background:linear-gradient(90deg,#1d8cf8,#00f2c3);border-radius:6px;transition:width .3s"></div>
				</div>
				<p id="dc-gi-qa-prog-url" style="font-size:11px;color:#7a8499;margin:8px 0 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></p>
			</div>
		</div>

			<?php if ( ! empty( $qa_results ) || true ) : ?>
		<!-- Filter -->
		<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
			<label for="dc-gi-qa-filter" style="font-size:13px;color:#c8d0e0"><?php esc_html_e( 'Filter by issue:', 'dc-google-indexing' ); ?></label>
			<select id="dc-gi-qa-filter" style="min-width:180px">
				<option value="all"><?php esc_html_e( 'All URLs', 'dc-google-indexing' ); ?></option>
				<option value="clean"><?php esc_html_e( '✓ No issues', 'dc-google-indexing' ); ?></option>
				<?php foreach ( $qa_issue_labels as $issue_key => $issue_label ) : ?>
					<?php if ( ! empty( $qa_issue_counts[ $issue_key ] ) || true ) : ?>
					<option value="<?php echo esc_attr( $issue_key ); ?>">
						<?php echo esc_html( $issue_label ); ?>
						<?php if ( ! empty( $qa_issue_counts[ $issue_key ] ) ) : ?>
							(<?php echo esc_html( (string) $qa_issue_counts[ $issue_key ] ); ?>)
						<?php endif; ?>
					</option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
		</div>

		<!-- Results table -->
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL', 'dc-google-indexing' ); ?></th>
					<th style="width:70px"><?php esc_html_e( 'Status', 'dc-google-indexing' ); ?></th>
					<th><?php esc_html_e( 'Issues', 'dc-google-indexing' ); ?></th>
					<th style="width:200px"><?php esc_html_e( 'Title', 'dc-google-indexing' ); ?></th>
					<th style="width:72px"></th>
				</tr>
			</thead>
			<tbody id="dc-gi-qa-tbody">
				<?php
				foreach ( array_reverse( $qa_results ) as $qa_entry ) :
					$q_issues       = $qa_entry['issues'] ?? array();
					$q_status       = (int) ( $qa_entry['http_status'] ?? 0 );
					$q_status_color = 200 === $q_status ? '#00f2c3' : ( $q_status >= 400 ? '#fd5d93' : '#ff8d72' );
					?>
				<tr data-qa-url="<?php echo esc_attr( $qa_entry['url'] ); ?>">
					<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr( $qa_entry['url'] ); ?>">
						<a href="<?php echo esc_url( $qa_entry['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $qa_entry['url'] ); ?>
						</a>
					</td>
					<td style="color:<?php echo esc_attr( $q_status_color ); ?>;font-weight:600">
						<?php echo $q_status > 0 ? esc_html( (string) $q_status ) : '—'; ?>
					</td>
					<td>
						<?php if ( empty( $q_issues ) ) : ?>
							<span style="color:#00f2c3;font-size:12px">✓ <?php esc_html_e( 'No issues', 'dc-google-indexing' ); ?></span>
						<?php else : ?>
							<?php foreach ( $q_issues as $q_issue ) : ?>
								<?php
								$issue_label = $qa_issue_labels[ $q_issue ] ?? $q_issue;
								$issue_color = $qa_issue_colors[ $q_issue ] ?? '#7a8499';
								$tooltip     = '';
								if ( 'non_canonical' === $q_issue && ! empty( $qa_entry['canonical'] ) ) {
									$tooltip = esc_attr(
										sprintf(
										/* translators: %s: canonical URL */
											__( 'Canonical: %s', 'dc-google-indexing' ),
											$qa_entry['canonical']
										)
									);
								} elseif ( 'duplicate_content' === $q_issue && ! empty( $qa_entry['duplicate_urls'] ) ) {
									$tooltip = esc_attr(
										sprintf(
										/* translators: %s: comma-separated list of duplicate URLs */
											__( 'Matches: %s', 'dc-google-indexing' ),
											implode( ', ', (array) $qa_entry['duplicate_urls'] )
										)
									);
								} elseif ( 'duplicate_short_desc' === $q_issue && ! empty( $qa_entry['duplicate_short_desc_urls'] ) ) {
									$tooltip = esc_attr(
										sprintf(
										/* translators: %s: comma-separated list of URLs sharing the same short description */
											__( 'Same short desc: %s', 'dc-google-indexing' ),
											implode( ', ', (array) $qa_entry['duplicate_short_desc_urls'] )
										)
									);
								} elseif ( 'short_meta_desc' === $q_issue && ! empty( $qa_entry['meta_desc'] ) ) {
									$tooltip = esc_attr(
										sprintf(
										/* translators: 1: character count, 2: meta description text */
											__( '%1$d chars: "%2$s"', 'dc-google-indexing' ),
											mb_strlen( html_entity_decode( $qa_entry['meta_desc'], ENT_QUOTES, 'UTF-8' ), 'UTF-8' ),
											$qa_entry['meta_desc']
										)
									);
								} elseif ( 'thin_content' === $q_issue && isset( $qa_entry['word_count'] ) ) {
									$tooltip = esc_attr(
										sprintf(
										/* translators: %d: word count */
											__( '%d words in body', 'dc-google-indexing' ),
											(int) $qa_entry['word_count']
										)
									);
								} elseif ( 'title_mismatch' === $q_issue && isset( $qa_entry['word_count'] ) ) {
									$tooltip = esc_attr(
										sprintf(
										/* translators: %d: word count */
											__( 'Title words not found in body (%d words)', 'dc-google-indexing' ),
											(int) $qa_entry['word_count']
										)
									);
								} elseif ( 'duplicate_title' === $q_issue && ! empty( $qa_entry['duplicate_title_urls'] ) ) {
									$tooltip = esc_attr(
										sprintf(
										/* translators: %s: comma-separated list of URLs sharing the same SEO title */
											__( 'Same title: %s', 'dc-google-indexing' ),
											implode( ', ', (array) $qa_entry['duplicate_title_urls'] )
										)
									);
								}
								?>
								<span style="display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;font-weight:600;background:rgba(255,255,255,.08);color:<?php echo esc_attr( $issue_color ); ?>;margin:1px 2px"
									<?php
									if ( $tooltip ) :
										?>
										title="<?php echo $tooltip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr'd above ?>"<?php endif; ?>>
									<?php echo esc_html( $issue_label ); ?>
								</span>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
					<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#7a8499"
						title="<?php echo esc_attr( $qa_entry['title'] ?? '' ); ?>">
						<?php echo esc_html( ( ! empty( $qa_entry['title'] ) ) ? $qa_entry['title'] : '—' ); ?>
					</td>
					<td style="white-space:nowrap;text-align:right">
						<button class="button button-small dc-gi-qa-rescan-btn"
							data-url="<?php echo esc_attr( $qa_entry['url'] ); ?>"
							title="<?php esc_attr_e( 'Re-scan this URL', 'dc-google-indexing' ); ?>">&#x21BA;</button>
						<button class="button button-small dc-gi-qa-dismiss-btn"
							data-url="<?php echo esc_attr( $qa_entry['url'] ); ?>"
							title="<?php esc_attr_e( 'Dismiss', 'dc-google-indexing' ); ?>"
							style="margin-left:4px">&#x2715;</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<!-- Common causes explanation -->
		<hr style="margin:28px 0 20px">
		<h3 style="margin-bottom:8px"><?php esc_html_e( 'Common Causes of "Crawled — Currently Not Indexed"', 'dc-google-indexing' ); ?></h3>
		<div class="dc-gi-info-grid" style="max-width:860px">
			<div>
				<ul style="list-style:none;margin:0;padding:0">
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#fd5d93">✗</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( '404 / HTTP error', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'page returns an error; Google cannot index it.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Noindex directive', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'meta robots or X-Robots-Tag header explicitly blocks indexing.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Missing title tag', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'Google may deprioritise pages with no document title.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Missing meta description', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'thin metadata signals low-quality content to Googlebot.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Short meta description (≤80 chars)', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'Google recommends 120–160 characters; very short descriptions look thin and may be auto-rewritten unfavourably.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#7a8499">–</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Missing H1 heading', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'indicates a lack of clear page structure.', 'dc-google-indexing' ); ?>
					</li>
				</ul>
			</div>
			<div>
				<ul style="list-style:none;margin:0;padding:0">
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Non-canonical URL', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'the page points its canonical tag to a different URL; Google indexes the canonical instead.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Duplicate content', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'multiple URLs serve identical content; Google picks one to index and ignores the others.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Duplicate WooCommerce short description', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'the same product short description is reused across multiple products; Google may treat them as near-duplicate thin content and decline to index all of them.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Thin content', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'body text under 150 words; Google considers the page low-value and may skip it even if all other tags are correct.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Title mismatch', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'fewer than 2 meaningful words from the SEO title appear in the page body; typically caused by a hardcoded Rank Math title that is unrelated to the actual product content.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Duplicate title', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'the same SEO title is used across multiple pages; Google treats them as duplicates and may only index one.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#ff8d72">!</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Redirect', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'URL in sitemap redirects to another page; only the final destination is indexed.', 'dc-google-indexing' ); ?>
					</li>
					<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:#8892a4">
						<span style="position:absolute;left:0;color:#7a8499">–</span>
						<strong style="color:#c8d0e0"><?php esc_html_e( 'Other low-quality signals', 'dc-google-indexing' ); ?></strong> — <?php esc_html_e( 'even with correct tags, pages with very little unique value may be skipped by Google.', 'dc-google-indexing' ); ?>
					</li>
				</ul>
			</div>
		</div>


		<?php elseif ( 'index_status' === $tab ) : ?>

		<!-- ===== INDEX STATUS ===== -->

			<?php if ( $inspect_url ) : ?>
		<div class="dc-gi-live-panel" style="max-width:980px;margin-bottom:22px">
			<h2 style="margin-top:0"><?php esc_html_e( 'URL Inspection Detail', 'dc-google-indexing' ); ?></h2>
			<p style="font-size:12px;color:#7a8499;word-break:break-all"><code><?php echo esc_html( $inspect_url ); ?></code></p>

				<?php if ( ! $inspect_entry ) : ?>
			<div class="dc-gi-callout warn">
					<?php echo esc_html( $inspect_error ); ?>
			</div>
			<?php else : ?>
			<div class="dc-gi-grid-3" style="grid-template-columns:repeat(3,minmax(0,1fr))">
				<div class="dc-gi-callout info" style="margin:0">
					<strong><?php esc_html_e( 'Index Verdict', 'dc-google-indexing' ); ?></strong><br>
					<?php echo esc_html( (string) ( $inspect_entry['index_verdict'] ?? '—' ) ); ?>
				</div>
				<div class="dc-gi-callout info" style="margin:0">
					<strong><?php esc_html_e( 'Coverage State', 'dc-google-indexing' ); ?></strong><br>
					<?php echo esc_html( (string) ( $inspect_entry['coverage_state'] ?? '—' ) ); ?>
				</div>
				<div class="dc-gi-callout info" style="margin:0">
					<strong><?php esc_html_e( 'Search Console Property', 'dc-google-indexing' ); ?></strong><br>
					<code><?php echo esc_html( $property ); ?></code>
				</div>
			</div>

				<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 24px;margin-top:14px;font-size:13px">
					<div><strong><?php esc_html_e( 'Page Fetch', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( ! empty( $inspect_entry['page_fetch_state'] ) ? (string) $inspect_entry['page_fetch_state'] : '—' ); ?></div>
					<div><strong><?php esc_html_e( 'Indexing State', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( ! empty( $inspect_entry['indexing_state'] ) ? (string) $inspect_entry['indexing_state'] : '—' ); ?></div>
					<div><strong><?php esc_html_e( 'Robots.txt', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( ! empty( $inspect_entry['robots_txt_state'] ) ? (string) $inspect_entry['robots_txt_state'] : '—' ); ?></div>
					<div><strong><?php esc_html_e( 'Crawled As', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( ! empty( $inspect_entry['crawled_as'] ) ? (string) $inspect_entry['crawled_as'] : '—' ); ?></div>
				<div><strong><?php esc_html_e( 'Last Crawl', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( ! empty( $inspect_entry['last_crawl_time'] ) ? (string) $inspect_entry['last_crawl_time'] : '—' ); ?></div>
				<div><strong><?php esc_html_e( 'Last Inspected', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( ! empty( $inspect_entry['last_inspected'] ) ? (string) $inspect_entry['last_inspected'] : '—' ); ?></div>
					<div><strong><?php esc_html_e( 'Google Canonical', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( ! empty( $inspect_entry['google_canonical'] ) ? (string) $inspect_entry['google_canonical'] : '—' ); ?></div>
					<div><strong><?php esc_html_e( 'User Canonical', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( ! empty( $inspect_entry['user_canonical'] ) ? (string) $inspect_entry['user_canonical'] : '—' ); ?></div>
				<div><strong><?php esc_html_e( 'Last Submitted', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( ! empty( $inspect_entry['last_submitted'] ) ? (string) $inspect_entry['last_submitted'] : '—' ); ?></div>
			</div>

				<?php if ( ! empty( $inspect_entry['rich_results'] ) ) : ?>
					<?php $rich_results = json_decode( (string) $inspect_entry['rich_results'], true ); ?>
					<?php if ( is_array( $rich_results ) && ! empty( $rich_results ) ) : ?>
				<h3 style="margin-bottom:8px"><?php esc_html_e( 'Rich Results', 'dc-google-indexing' ); ?></h3>
				<div class="dc-gi-callout info">
						<?php foreach ( $rich_results as $rich_item ) : ?>
					<div style="margin-bottom:10px">
						<strong><?php echo esc_html( (string) ( $rich_item['t'] ?? '' ) ); ?></strong>
							<?php foreach ( (array) ( $rich_item['i'] ?? array() ) as $rich_child ) : ?>
						<div style="margin-top:4px">
								<?php echo esc_html( (string) ( $rich_child['n'] ?? '' ) ); ?>
								<?php foreach ( (array) ( $rich_child['i'] ?? array() ) as $rich_issue ) : ?>
							<div style="font-size:12px;color:#ff8d72">
									<?php echo esc_html( (string) ( $rich_issue['m'] ?? '' ) ); ?>
									<?php if ( ! empty( $rich_issue['s'] ) ) : ?>
									(<?php echo esc_html( (string) $rich_issue['s'] ); ?>)
								<?php endif; ?>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endforeach; ?>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			<?php endif; ?>

			<h3 style="margin-bottom:8px"><?php esc_html_e( 'Indexing API Metadata', 'dc-google-indexing' ); ?></h3>
				<?php if ( $inspect_meta ) : ?>
			<div class="dc-gi-callout ok">
				<div><strong><?php esc_html_e( 'Latest update notification', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( (string) ( $inspect_meta['latestUpdate']['notifyTime'] ?? '—' ) ); ?></div>
				<div><strong><?php esc_html_e( 'Latest update type', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( (string) ( $inspect_meta['latestUpdate']['type'] ?? '—' ) ); ?></div>
				<div><strong><?php esc_html_e( 'Latest removal notification', 'dc-google-indexing' ); ?>:</strong> <?php echo esc_html( (string) ( $inspect_meta['latestRemove']['notifyTime'] ?? '—' ) ); ?></div>
			</div>
			<?php elseif ( $inspect_error ) : ?>
			<div class="dc-gi-callout warn">
				<?php echo esc_html( $inspect_error ); ?>
			</div>
			<?php else : ?>
			<div class="dc-gi-callout info">
				<?php esc_html_e( 'No Indexing API metadata is available for this URL yet.', 'dc-google-indexing' ); ?>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<h2 style="margin-top:0"><?php esc_html_e( 'Index Status Overview', 'dc-google-indexing' ); ?></h2>
		<p style="color:#8892a4;max-width:720px;font-size:13px;margin-bottom:20px"><?php esc_html_e( 'Live snapshot of all URLs in the inspection cache, grouped by coverage state and index verdict. The stat cards auto-refresh every 30 seconds.', 'dc-google-indexing' ); ?></p>

		<div class="dc-gi-callout warn" id="dc-gi-is-quota-backoff" style="max-width:700px;margin-bottom:18px<?php echo get_transient( 'dc_gi_inspect_quota_backoff' ) ? '' : ';display:none'; ?>">
			<?php esc_html_e( 'URL Inspection API quota temporarily exhausted — background inspection is paused for up to 1 hour. The cache will resume building automatically once the quota window resets. Data shown below reflects what was cached before the quota was hit.', 'dc-google-indexing' ); ?>
		</div>

			<?php
			$is_counts   = class_exists( 'DC_GI_URL_Cache' ) ? DC_GI_URL_Cache::get_verdict_counts() : array();
			$is_coverage = class_exists( 'DC_GI_URL_Cache' ) ? DC_GI_URL_Cache::get_coverage_state_breakdown() : array();
			$is_total    = class_exists( 'DC_GI_URL_Cache' ) ? DC_GI_URL_Cache::count_total() : 0;
			$is_pass     = (int) ( $is_counts['PASS'] ?? 0 );
			$is_fail     = (int) ( $is_counts['FAIL'] ?? 0 );
			$is_excl     = class_exists( 'DC_GI_URL_Cache' ) ? DC_GI_URL_Cache::count_excluded() : 0;
			$is_errors   = class_exists( 'DC_GI_URL_Cache' ) ? DC_GI_URL_Cache::get_inspect_error_count() : 0;
			$is_age      = class_exists( 'DC_GI_URL_Cache' ) ? DC_GI_URL_Cache::oldest_entry_age_days() : null;
			$is_ccolors  = array(
				'Submitted and indexed'                    => '#00f2c3',
				'Indexed, though not submitted in sitemap' => '#00c9a7',
				'URL is unknown to Google'                 => '#1d8cf8',
				'Discovered - currently not indexed'       => '#9b5fe0',
				'Crawled - currently not indexed'          => '#ff8d72',
				'Page with redirect'                       => '#ffa500',
				'Blocked by robots.txt'                    => '#fd5d93',
				'Not found (404)'                          => '#fd5d93',
				'Soft 404'                                 => '#fd5d93',
				'Duplicate without user-selected canonical' => '#ff8d72',
				'Canonical: other'                         => '#ff8d72',
				'Alternate page with proper canonical tag' => '#7a8499',
				'Duplicate, Google chose different canonical than user' => '#7a8499',
			);
			?>

		<!-- Stat cards -->
		<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px">
			<div class="dc-gi-stat" id="is-stat-total">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) $is_total ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Total Cached', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat green" id="is-stat-pass">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) $is_pass ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Pass / Indexed', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat amber" id="is-stat-excluded">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) $is_excl ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Need Submission', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat red" id="is-stat-fail">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) $is_fail ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Fail', 'dc-google-indexing' ); ?></div>
			</div>
			<?php if ( $is_errors > 0 ) : ?>
			<div class="dc-gi-stat amber" id="is-stat-errors">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) $is_errors ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Insp. Errors', 'dc-google-indexing' ); ?></div>
			</div>
			<?php endif; ?>
			<div class="dc-gi-stat" id="is-stat-age">
				<div class="dc-gi-stat-num"><?php echo null !== $is_age ? esc_html( (string) $is_age ) : '&mdash;'; ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Oldest Entry (days)', 'dc-google-indexing' ); ?></div>
			</div>
		</div><!-- /.stat cards -->

		<!-- Search Analytics panel -->
			<?php
			$is_analytics_last = class_exists( 'DC_GI_URL_Cache' ) ? DC_GI_URL_Cache::get_analytics_last_updated() : null;
			$is_analytics_days = max( 1, (int) ( $settings['analytics_days'] ?? 28 ) );
			?>
		<div class="dc-gi-live-panel" style="max-width:740px;padding:18px 22px;margin-bottom:24px">
			<h3 style="margin:0 0 12px;font-size:14px;color:#c8d0e0"><?php esc_html_e( 'Search Analytics', 'dc-google-indexing' ); ?></h3>
			<p style="font-size:13px;color:#8892a4;margin:0 0 14px">
				<?php esc_html_e( 'Fetch clicks, impressions, CTR, and position from the Google Search Console Search Analytics API. Results are stored per URL and shown in the detail rows below.', 'dc-google-indexing' ); ?>
			</p>
			<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
				<label for="dc-gi-analytics-days" style="font-size:13px;color:#c8d0e0"><?php esc_html_e( 'Date range:', 'dc-google-indexing' ); ?></label>
				<select id="dc-gi-analytics-days" style="background:#252a45;border:1px solid #2d3555;color:#c8d0e0;border-radius:4px;padding:4px 8px;font-size:13px">
					<?php foreach ( array( 7, 28, 90 ) as $opt ) : ?>
					<option value="<?php echo esc_attr( (string) $opt ); ?>"<?php echo selected( $is_analytics_days, $opt, false ); ?>>
						<?php
						printf(
							/* translators: %d: number of days */
							esc_html__( 'Last %d days', 'dc-google-indexing' ),
							(int) $opt
						);
						?>
					</option>
					<?php endforeach; ?>
				</select>
				<button id="dc-gi-analytics-fetch-btn" class="button dc-gi-btn-start" style="padding:5px 16px!important">
					<?php esc_html_e( '↻ Fetch Analytics', 'dc-google-indexing' ); ?>
				</button>
				<span id="dc-gi-analytics-status" style="font-size:12px;color:#7a8499">
					<?php
					if ( $is_analytics_last ) {
						printf(
							/* translators: %s: UTC datetime of last analytics fetch */
							esc_html__( 'Last fetched: %s UTC', 'dc-google-indexing' ),
							esc_html( $is_analytics_last )
						);
					} else {
						esc_html_e( 'No analytics data yet.', 'dc-google-indexing' );
					}
					?>
				</span>
			</div>
		</div><!-- /.analytics panel -->

			<?php if ( $is_total > 0 ) : ?>
		<!-- Two-column: coverage bars + verdict donut -->
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:940px;margin-bottom:28px">

			<!-- LEFT: Coverage state bars -->
			<div class="dc-gi-live-panel" style="padding:20px 22px">
				<h3 style="margin:0 0 16px;font-size:14px;color:#c8d0e0"><?php esc_html_e( 'Coverage States', 'dc-google-indexing' ); ?></h3>
				<?php
				foreach ( $is_coverage as $is_row ) :
					$is_state = (string) $is_row['coverage_state'];
					$is_cnt   = (int) $is_row['count'];
					$is_pct   = $is_total > 0 ? (int) round( $is_cnt / $is_total * 100 ) : 0;
					$is_col   = $is_ccolors[ $is_state ] ?? '#7a8499';
					?>
				<div style="margin-bottom:12px">
					<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
						<span style="font-size:12px;color:#c8d0e0;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr( $is_state ); ?>"><?php echo esc_html( $is_state ); ?></span>
						<span style="font-size:12px;color:#7a8499;white-space:nowrap;margin-left:8px"><?php echo esc_html( (string) $is_cnt . ' (' . (string) $is_pct . '%)' ); ?></span>
					</div>
					<div style="background:rgba(255,255,255,.07);border-radius:4px;height:7px;overflow:hidden">
						<div style="height:100%;width:<?php echo esc_attr( (string) min( 100, $is_pct ) ); ?>%;background:<?php echo esc_attr( $is_col ); ?>;border-radius:4px;transition:width .4s"></div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- RIGHT: Verdict donut chart (pure SVG) -->
			<div class="dc-gi-live-panel" style="padding:20px 22px">
				<h3 style="margin:0 0 16px;font-size:14px;color:#c8d0e0"><?php esc_html_e( 'Index Verdict', 'dc-google-indexing' ); ?></h3>
				<?php
				$is_segs    = array(
					'PASS'                => array(
						'label' => __( 'Pass', 'dc-google-indexing' ),
						'color' => '#00f2c3',
					),
					'NEUTRAL'             => array(
						'label' => __( 'Excluded/Neutral', 'dc-google-indexing' ),
						'color' => '#ff8d72',
					),
					'FAIL'                => array(
						'label' => __( 'Fail', 'dc-google-indexing' ),
						'color' => '#fd5d93',
					),
					'VERDICT_UNSPECIFIED' => array(
						'label' => __( 'Unspecified', 'dc-google-indexing' ),
						'color' => '#6e7a90',
					),
				);
				$is_dtotal  = max( 1, (int) array_sum( $is_counts ) );
				$is_r       = 70;
				$is_sw      = 28;
				$is_circ    = 2.0 * M_PI * $is_r;
				$is_off_acc = $is_circ / 4.0; // Start at 12 o'clock.
				$is_paths   = array();
				foreach ( $is_segs as $is_v => $is_seg ) {
					$is_vcnt     = (int) ( $is_counts[ $is_v ] ?? 0 );
					$is_dash     = ( $is_vcnt / $is_dtotal ) * $is_circ;
					$is_paths[]  = array(
						'color'  => $is_seg['color'],
						'label'  => $is_seg['label'],
						'count'  => $is_vcnt,
						'dash'   => $is_dash,
						'gap'    => $is_circ - $is_dash,
						'offset' => $is_off_acc,
					);
					$is_off_acc -= $is_dash;
				}
				?>
				<div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
					<svg width="200" height="200" viewBox="0 0 200 200" style="flex-shrink:0" aria-hidden="true">
						<circle cx="100" cy="100" r="<?php echo esc_attr( (string) $is_r ); ?>"
							fill="none" stroke="rgba(255,255,255,.06)" stroke-width="<?php echo esc_attr( (string) $is_sw ); ?>"/>
						<?php
						foreach ( $is_paths as $is_path ) :
							if ( 0 === $is_path['count'] ) {
								continue;
							}
							?>
						<circle cx="100" cy="100" r="<?php echo esc_attr( (string) $is_r ); ?>"
							fill="none"
							stroke="<?php echo esc_attr( $is_path['color'] ); ?>"
							stroke-width="<?php echo esc_attr( (string) $is_sw ); ?>"
							stroke-dasharray="<?php echo esc_attr( number_format( $is_path['dash'], 3, '.', '' ) . ' ' . number_format( $is_path['gap'], 3, '.', '' ) ); ?>"
							stroke-dashoffset="<?php echo esc_attr( number_format( $is_path['offset'], 3, '.', '' ) ); ?>"/>
						<?php endforeach; ?>
						<text x="100" y="96" text-anchor="middle" fill="#c8d0e0" font-size="28" font-weight="700" font-family="sans-serif"><?php echo esc_html( (string) $is_total ); ?></text>
						<text x="100" y="114" text-anchor="middle" fill="#7a8499" font-size="11" font-family="sans-serif"><?php esc_html_e( 'URLs', 'dc-google-indexing' ); ?></text>
					</svg>
					<div>
						<?php foreach ( $is_segs as $is_v => $is_seg ) : ?>
						<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
							<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr( $is_seg['color'] ); ?>;flex-shrink:0"></span>
							<span style="font-size:13px;color:#c8d0e0"><?php echo esc_html( $is_seg['label'] ); ?></span>
							<span style="font-size:13px;color:#7a8499;margin-left:4px"><?php echo esc_html( (string) (int) ( $is_counts[ $is_v ] ?? 0 ) ); ?></span>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

		</div><!-- /two-column grid -->

		<!-- URL table — filter tabs -->
		<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center">
			<span style="font-size:13px;font-weight:600;color:#c8d0e0;margin-right:6px"><?php esc_html_e( 'URL Status Table:', 'dc-google-indexing' ); ?></span>
				<?php
				$is_ftabs = array(
					''         => array(
						'label' => __( 'All', 'dc-google-indexing' ),
						'n'     => $is_total,
						'color' => '#c8d0e0',
					),
					'PASS'     => array(
						'label' => __( '✓ Indexed', 'dc-google-indexing' ),
						'n'     => $is_pass,
						'color' => '#00f2c3',
					),
					'EXCLUDED' => array(
						'label' => __( '⚠ Not Indexed', 'dc-google-indexing' ),
						'n'     => $is_excl,
						'color' => '#ff8d72',
					),
					'FAIL'     => array(
						'label' => __( '✗ Fail', 'dc-google-indexing' ),
						'n'     => $is_fail,
						'color' => '#fd5d93',
					),
				);
				foreach ( $is_ftabs as $is_fkey => $is_ftab ) :
					?>
			<button class="dc-gi-is-filter-btn button<?php echo '' === $is_fkey ? ' dc-gi-is-filter-active' : ''; ?>"
				data-filter="<?php echo esc_attr( $is_fkey ); ?>"
				style="font-size:12px;padding:4px 12px;color:<?php echo esc_attr( $is_ftab['color'] ); ?>;border-color:<?php echo esc_attr( $is_ftab['color'] ); ?>;background:rgba(0,0,0,.2)">
					<?php echo esc_html( $is_ftab['label'] ); ?> <span class="dc-gi-is-fcount"><?php echo esc_html( (string) $is_ftab['n'] ); ?></span>
			</button>
				<?php endforeach; ?>
		</div>

		<!-- URL table -->
		<div style="overflow-x:auto;max-width:100%;margin-bottom:8px">
			<table class="widefat striped" id="dc-gi-is-url-tbl" style="min-width:880px;table-layout:fixed">
				<thead>
					<tr>
						<th style="width:36px;color:#7a8499">#</th>
						<th style="width:auto;min-width:240px;color:#c8d0e0;cursor:pointer" data-col="url">
							<?php esc_html_e( 'URL', 'dc-google-indexing' ); ?> <span class="dc-gi-sort-icon" data-col="url"></span>
						</th>
						<th style="width:100px;color:#c8d0e0;cursor:pointer" data-col="index_verdict">
							<?php esc_html_e( 'Verdict', 'dc-google-indexing' ); ?> <span class="dc-gi-sort-icon" data-col="index_verdict"></span>
						</th>
						<th style="width:220px;color:#c8d0e0;cursor:pointer" data-col="coverage_state">
							<?php esc_html_e( 'Coverage State', 'dc-google-indexing' ); ?> <span class="dc-gi-sort-icon" data-col="coverage_state"></span>
						</th>
						<th style="width:120px;color:#c8d0e0;cursor:pointer" data-col="last_crawl_time">
							<?php esc_html_e( 'Last Crawl', 'dc-google-indexing' ); ?> <span class="dc-gi-sort-icon" data-col="last_crawl_time">↕</span>
						</th>
						<th style="width:120px;color:#c8d0e0;cursor:pointer" data-col="last_inspected">
							<?php esc_html_e( 'Inspected', 'dc-google-indexing' ); ?> <span class="dc-gi-sort-icon" data-col="last_inspected"></span>
						</th>
						<th style="width:32px;color:#7a8499"></th>
					</tr>
				</thead>
				<tbody id="dc-gi-is-url-tbody">
					<tr><td colspan="7" style="text-align:center;color:#7a8499;padding:24px"><?php esc_html_e( 'Loading…', 'dc-google-indexing' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<!-- Pagination row -->
		<div id="dc-gi-is-pager" style="display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap">
			<button id="dc-gi-is-prev" class="button dc-gi-btn-secondary" disabled><?php esc_html_e( '← Prev', 'dc-google-indexing' ); ?></button>
			<span id="dc-gi-is-page-info" style="font-size:12px;color:#7a8499"></span>
			<button id="dc-gi-is-next" class="button dc-gi-btn-secondary" disabled><?php esc_html_e( 'Next →', 'dc-google-indexing' ); ?></button>
			<span id="dc-gi-is-tbl-ts" style="font-size:12px;color:#7a8499;margin-left:auto"></span>
		</div>

		<?php endif; /* $is_total > 0 */ ?>

			<?php if ( 0 === $is_total ) : ?>
		<div class="dc-gi-callout info" style="max-width:740px">
			<strong><?php esc_html_e( 'Cache is empty', 'dc-google-indexing' ); ?></strong><br>
				<?php esc_html_e( 'The inspection cache has no data yet. The background cron inspects URLs from the sitemap at a rate of 3 URLs per minute. Check back in a few minutes.', 'dc-google-indexing' ); ?>
		</div>
		<?php endif; ?>

		<!-- Auto-refresh summary stats every 30 s -->
		<div style="display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap">
			<span id="dc-gi-is-ts" style="font-size:12px;color:#7a8499"></span>
			<label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#7a8499;cursor:pointer">
				<input type="checkbox" id="dc-gi-is-auto" checked style="cursor:pointer">
				<?php esc_html_e( 'Auto-refresh every 30 s', 'dc-google-indexing' ); ?>
			</label>
		</div>



		<?php endif; ?>

		</div><!-- tab content -->
	</div><!-- .wrap -->
		<?php
}

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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_admin_footer_text( string $text ): string {
	$screen = get_current_screen();
	if ( $screen && $screen->id === 'toplevel_page_dc-google-indexing' ) {
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

add_action( 'admin_post_dc_gi_save',       'dc_gi_handle_save' );
add_action( 'admin_post_dc_gi_test',       'dc_gi_handle_test' );
add_action( 'admin_post_dc_gi_submit',     'dc_gi_handle_submit' );
add_action( 'admin_post_dc_gi_runnow',     'dc_gi_handle_runnow' );
add_action( 'admin_post_dc_gi_clrqueue',   'dc_gi_handle_clear_queue' );
add_action( 'admin_post_dc_gi_clrlog',     'dc_gi_handle_clear_log' );
add_action( 'admin_post_dc_gi_poll',       'dc_gi_handle_poll' );
add_action( 'admin_post_dc_gi_watch_del',  'dc_gi_handle_watch_delete' );
add_action( 'admin_post_dc_gi_watch_clr',  'dc_gi_handle_watch_clear' );
add_action( 'admin_post_dc_gi_watch_now',  'dc_gi_handle_watch_check_now' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_save(): void {
	check_admin_referer( 'dc_gi_save' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}

	$old      = dc_gi_get_settings();
	$raw_json = isset( $_POST['service_account_json'] )
		? sanitize_textarea_field( wp_unslash( $_POST['service_account_json'] ) )
		: '';

	if ( ! empty( $raw_json ) ) {
		$parsed = json_decode( $raw_json, true );
		if ( ! $parsed || empty( $parsed['client_email'] ) || empty( $parsed['private_key'] ) ) {
			wp_safe_redirect( add_query_arg(
				[ 'page' => 'dc-google-indexing', 'notice' => 'invalid_json' ],
				admin_url( 'admin.php' )
			) );
			exit;
		}
		// Clear cached token when credentials change
		if ( ( $old['service_account_json'] ?? '' ) !== $raw_json ) {
			delete_transient( 'dc_gi_access_token' );
		}
	} else {
		$raw_json = $old['service_account_json'] ?? '';
	}

	$post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
		? array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) )
		: [];

	update_option( 'dc_gi_settings', [
		'service_account_json' => $raw_json,
		'auto_submit'          => ! empty( $_POST['auto_submit'] ) ? 1 : 0,
		'post_types'           => $post_types,
		'daily_quota'          => min( 200, max( 1, absint( isset( $_POST['daily_quota'] ) ? wp_unslash( $_POST['daily_quota'] ) : 200 ) ) ),
		'footer_credit'        => ! empty( $_POST['footer_credit'] ) ? 1 : 0,
	] );

	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'notice' => 'saved' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_test(): void {
	check_admin_referer( 'dc_gi_test' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}

	$settings = dc_gi_get_settings();
	if ( empty( $settings['service_account_json'] ) ) {
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'dc-google-indexing', 'notice' => 'test_no_sa' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	$sa     = json_decode( $settings['service_account_json'], true );
	$result = DC_GI_JWT::test_connection( $sa );
	$notice = is_wp_error( $result ) ? 'test_fail' : 'test_ok';
	$msg    = is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : '';

	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'notice' => $notice, 'errmsg' => $msg ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
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
			$count++;
		}
	}

	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'submit', 'notice' => 'queued', 'count' => $count ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_runnow(): void {
	check_admin_referer( 'dc_gi_runnow' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	dc_gi_process_queue();
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'queue', 'notice' => 'processed' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_clear_queue(): void {
	check_admin_referer( 'dc_gi_clrqueue' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	update_option( 'dc_gi_queue', [] );
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'queue', 'notice' => 'queue_cleared' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_clear_log(): void {
	check_admin_referer( 'dc_gi_clrlog' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	update_option( 'dc_gi_log', [] );
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'log', 'notice' => 'log_cleared' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_watch_delete(): void {
	check_admin_referer( 'dc_gi_watch_del' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	$url = esc_url_raw( isset( $_POST['watch_url'] ) ? wp_unslash( $_POST['watch_url'] ) : '' );
	if ( $url ) {
		dc_gi_watchlist_remove( $url );
	}
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'watchlist' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_watch_clear(): void {
	check_admin_referer( 'dc_gi_watch_clr' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	update_option( 'dc_gi_watchlist', [] );
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'watchlist', 'notice' => 'watch_cleared' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_watch_check_now(): void {
	check_admin_referer( 'dc_gi_watch_now' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	dc_gi_run_watchlist_check();
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'watchlist', 'notice' => 'watch_checked' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_poll(): void {
	check_admin_referer( 'dc_gi_poll' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}

	$settings = dc_gi_get_settings();
	if ( empty( $settings['service_account_json'] ) ) {
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'dc-google-indexing', 'tab' => 'polling', 'notice' => 'test_no_sa' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	$sa         = json_decode( $settings['service_account_json'], true );
	$site_url   = trailingslashit( get_home_url() );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$poll_limit = min( 200, max( 1, absint( isset( $_POST['poll_limit'] ) ? wp_unslash( $_POST['poll_limit'] ) : 50 ) ) );

	// Fetch more URLs than we'll inspect so we have enough candidates after filtering.
	$all_urls = DC_GI_Sitemap::get_urls( $poll_limit * 4 );
	if ( is_wp_error( $all_urls ) ) {
		wp_safe_redirect( add_query_arg(
			[
				'page'   => 'dc-google-indexing',
				'tab'    => 'polling',
				'notice' => 'poll_no_sitemap',
				'errmsg' => rawurlencode( $all_urls->get_error_message() ),
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// Exclude URLs already tracked in the watchlist — no need to re-inspect them.
	$watched_urls = array_column( dc_gi_watchlist_get(), 'url' );
	$all_urls     = array_values( array_diff( $all_urls, $watched_urls ) );

	/**
	 * Coverage states that Google knows the URL but hasn't indexed it yet.
	 * These are the only states worth re-submitting.
	 * All error/exclusion states (404, redirect, noindex, robots, canonical, 5xx, etc.) are skipped.
	 */
	$submittable = [
		'Crawled - currently not indexed',
		'Discovered - currently not indexed',
	];

	$inspected = 0;
	$queued    = 0;
	$skipped   = 0;
	$errors    = 0;

	foreach ( array_slice( $all_urls, 0, $poll_limit ) as $url ) {
		$result = DC_GI_JWT::inspect_url( $sa, $url, $site_url );
		$inspected++;

		if ( is_wp_error( $result ) ) {
			$errors++;
			dc_gi_add_log( $url, 'INSPECT', $result );
			continue;
		}

		$coverage_state = $result['inspectionResult']['indexStatusResult']['coverageState'] ?? '';

		if ( in_array( $coverage_state, $submittable, true ) ) {
			dc_gi_enqueue_url( $url, 'URL_UPDATED' );
			$queued++;
		} else {
			$skipped++;
		}
	}

	set_transient( 'dc_gi_last_poll', [
		'time'      => time(),
		'inspected' => $inspected,
		'queued'    => $queued,
		'skipped'   => $skipped,
		'errors'    => $errors,
	], DAY_IN_SECONDS );

	wp_safe_redirect( add_query_arg(
		[
			'page'      => 'dc-google-indexing',
			'tab'       => 'polling',
			'notice'    => 'poll_done',
			'inspected' => $inspected,
			'pqueued'   => $queued,
		],
		admin_url( 'admin.php' )
	) );
	exit;
}

// =============================================================================
// RENDER PAGE
// =============================================================================

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings    = dc_gi_get_settings();
	$queue       = get_option( 'dc_gi_queue', [] );
	$log         = get_option( 'dc_gi_log', [] );
	$watchlist   = dc_gi_watchlist_get();
	$quota_used  = dc_gi_get_quota_used();
	$quota_limit = min( 200, (int) ( $settings['daily_quota'] ?? 200 ) );
	$has_sa      = ! empty( $settings['service_account_json'] );
	$sa_email    = '';
	if ( $has_sa ) {
		$sa_decoded = json_decode( $settings['service_account_json'], true );
		$sa_email   = $sa_decoded['client_email'] ?? '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ( $has_sa ? 'settings' : 'start' );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice_key = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$errmsg = isset( $_GET['errmsg'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['errmsg'] ) ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$queued_count      = absint( isset( $_GET['count'] ) ? wp_unslash( $_GET['count'] ) : 0 );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$poll_inspected    = absint( isset( $_GET['inspected'] ) ? wp_unslash( $_GET['inspected'] ) : 0 );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$poll_queued       = absint( isset( $_GET['pqueued'] ) ? wp_unslash( $_GET['pqueued'] ) : 0 );

	$last_poll = get_transient( 'dc_gi_last_poll' );

	$notices = [
		'saved'           => [ 'success', __( 'Settings saved.', 'dc-google-indexing' ) ],
		'invalid_json'    => [ 'error',   __( 'Invalid JSON — ensure it contains client_email and private_key.', 'dc-google-indexing' ) ],
		'queued'          => [ 'success', sprintf(
			/* translators: %d: number of URLs added to queue */
			__( '%d URL(s) added to queue.', 'dc-google-indexing' ),
			$queued_count
		) ],
		'processed'       => [ 'success', __( 'Queue processed.', 'dc-google-indexing' ) ],
		'queue_cleared'   => [ 'success', __( 'Queue cleared.', 'dc-google-indexing' ) ],
		'log_cleared'     => [ 'success', __( 'Log cleared.', 'dc-google-indexing' ) ],
		'test_ok'         => [ 'success', __( '&#10003; Connection successful — credentials are valid.', 'dc-google-indexing' ) ],
		'test_fail'       => [ 'error',   esc_html( $errmsg ) ?: __( 'Connection failed.', 'dc-google-indexing' ) ],
		'test_no_sa'      => [ 'error',   __( 'No service account saved. Paste your JSON and save first.', 'dc-google-indexing' ) ],
		'poll_done'       => [ 'success', sprintf(
			/* translators: 1: number of URLs inspected 2: number queued */
			__( 'Polling complete: %1$d URL(s) inspected, %2$d added to queue.', 'dc-google-indexing' ),
			$poll_inspected,
			$poll_queued
		) ],
		'poll_no_sitemap' => [ 'error',   esc_html( $errmsg ) ?: __( 'No sitemap found. Ensure your site has a public XML sitemap.', 'dc-google-indexing' ) ],
		'poll_error'      => [ 'error',   esc_html( $errmsg ) ?: __( 'Polling failed.', 'dc-google-indexing' ) ],
		'watch_cleared'   => [ 'success', __( 'Watchlist cleared.', 'dc-google-indexing' ) ],
		'watch_checked'   => [ 'success', __( 'Watchlist check complete.', 'dc-google-indexing' ) ],
	];

	$all_post_types = get_post_types( [ 'public' => true ], 'objects' );
	?>
	<div class="wrap">
		<h1 style="display:flex;align-items:center;gap:10px">
			<span class="dashicons dashicons-search" style="font-size:28px;color:#4285f4"></span>
			<?php esc_html_e( 'DC Google Indexing', 'dc-google-indexing' ); ?>
		</h1>

		<?php if ( $notice_key && isset( $notices[ $notice_key ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice_key ][0] ); ?> is-dismissible">
			<p><?php echo wp_kses_post( $notices[ $notice_key ][1] ); ?></p>
		</div>
		<?php endif; ?>

		<!-- Status bar -->
		<div style="background:#fff;border:1px solid #ccd0d4;padding:12px 18px;margin:12px 0 18px;border-radius:3px;display:flex;flex-wrap:wrap;gap:24px;align-items:center">
			<?php if ( $has_sa ) : ?>
				<span style="color:#46b450;font-weight:600">&#10003; <?php esc_html_e( 'Service account:', 'dc-google-indexing' ); ?> <code><?php echo esc_html( $sa_email ); ?></code></span>
			<?php else : ?>
				<span style="color:#dc3232;font-weight:600">&#10007; <?php esc_html_e( 'No service account configured', 'dc-google-indexing' ); ?></span>
			<?php endif; ?>
			<span><?php printf(
				/* translators: 1: URLs used today 2: daily limit */
				esc_html__( 'Quota today: %1$d / %2$d', 'dc-google-indexing' ),
				esc_html( (string) $quota_used ),
				esc_html( (string) $quota_limit )
			); ?></span>
			<span><?php printf(
				/* translators: %d: number of URLs pending in queue */
				esc_html__( 'Queue: %d pending', 'dc-google-indexing' ),
				count( $queue )
			); ?></span>
		</div>

		<!-- Tabs -->
		<nav class="nav-tab-wrapper" style="margin-bottom:0">
			<?php
			$tabs = [
				'start'     => __( '🚀 Getting Started', 'dc-google-indexing' ),
				'settings'  => __( 'Settings', 'dc-google-indexing' ),
				'submit'    => __( 'Submit URLs', 'dc-google-indexing' ),
				'queue'     => __( 'Queue', 'dc-google-indexing' ),
				'watchlist' => __( '👁 Watchlist', 'dc-google-indexing' ),
				'polling'   => __( '📡 Polling', 'dc-google-indexing' ),
				'log'       => __( 'Log', 'dc-google-indexing' ),
			];
			foreach ( $tabs as $t => $label ) {
				printf(
					'<a href="%s" class="nav-tab %s">%s</a>',
					esc_url( add_query_arg( [ 'page' => 'dc-google-indexing', 'tab' => $t ], admin_url( 'admin.php' ) ) ),
					$tab === $t ? 'nav-tab-active' : '',
					esc_html( $label )
				);
			}
			?>
		</nav>

		<div style="background:#fff;border:1px solid #ccd0d4;border-top:none;padding:20px 24px">

		<?php if ( 'start' === $tab ) : ?>

		<!-- ===== GETTING STARTED ===== -->
		<style>
		/* Layout */
		.dc-gi-guide { max-width: 780px; }
		.dc-gi-guide h2 { margin-top: 0; font-size: 22px; }
		.dc-gi-guide .dc-gi-intro { font-size: 14px; color: #555; margin-bottom: 28px; line-height: 1.7; }

		/* Progress bar */
		.dc-gi-progress { display: flex; align-items: center; margin-bottom: 32px; }
		.dc-gi-progress-step { display: flex; flex-direction: column; align-items: center; gap: 5px; flex: 1; position: relative; }
		.dc-gi-progress-step:not(:last-child)::after {
			content: ''; position: absolute; top: 15px; left: 55%; width: 90%; height: 2px;
			background: #ddd; z-index: 0;
		}
		.dc-gi-progress-step.done:not(:last-child)::after { background: #46b450; }
		.dc-gi-progress-dot {
			width: 30px; height: 30px; border-radius: 50%; background: #ddd; color: #999;
			display: flex; align-items: center; justify-content: center; font-size: 13px;
			font-weight: 700; position: relative; z-index: 1;
		}
		.dc-gi-progress-step.done .dc-gi-progress-dot { background: #46b450; color: #fff; }
		.dc-gi-progress-step.active .dc-gi-progress-dot { background: #4285f4; color: #fff; box-shadow: 0 0 0 3px #e8f0fe; }
		.dc-gi-progress-label { font-size: 11px; color: #777; text-align: center; max-width: 80px; line-height: 1.3; }
		.dc-gi-progress-step.done .dc-gi-progress-label { color: #46b450; }
		.dc-gi-progress-step.active .dc-gi-progress-label { color: #4285f4; font-weight: 600; }

		/* Steps */
		.dc-gi-step-card {
			border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 16px; overflow: hidden;
		}
		.dc-gi-step-card.dc-gi-done { border-color: #b7e1b8; }
		.dc-gi-step-header {
			display: flex; align-items: center; gap: 14px; padding: 14px 18px;
			background: #fafafa; cursor: pointer; user-select: none;
		}
		.dc-gi-step-card.dc-gi-done .dc-gi-step-header { background: #f0fbf0; }
		.dc-gi-step-icon {
			flex-shrink: 0; width: 34px; height: 34px; border-radius: 50%;
			background: #e8f0fe; color: #4285f4;
			display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700;
		}
		.dc-gi-step-card.dc-gi-done .dc-gi-step-icon { background: #edfaee; color: #46b450; }
		.dc-gi-step-title { font-size: 15px; font-weight: 600; margin: 0; flex: 1; }
		.dc-gi-step-status {
			font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 12px;
			background: #fff3cd; color: #856404;
		}
		.dc-gi-step-card.dc-gi-done .dc-gi-step-status { background: #edfaee; color: #2d7a31; }
		.dc-gi-step-toggle { color: #999; font-size: 18px; flex-shrink: 0; transition: transform .2s; }
		.dc-gi-step-body { padding: 18px 22px; border-top: 1px solid #e0e0e0; }
		.dc-gi-step-card.dc-gi-done .dc-gi-step-body { border-color: #b7e1b8; }

		/* Content helpers */
		.dc-gi-substep {
			display: flex; gap: 12px; margin-bottom: 18px; align-items: flex-start;
		}
		.dc-gi-substep-num {
			flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%;
			background: #4285f4; color: #fff;
			display: flex; align-items: center; justify-content: center;
			font-size: 12px; font-weight: 700; margin-top: 1px;
		}
		.dc-gi-substep-content { flex: 1; }
		.dc-gi-substep-content p { margin: 0 0 6px; }
		.dc-gi-substep-content strong { color: #1d2327; }
		.dc-gi-callout {
			border-radius: 4px; padding: 10px 14px; margin: 12px 0; font-size: 13px; line-height: 1.6;
		}
		.dc-gi-callout.info { background: #e8f4fd; border-left: 3px solid #4285f4; }
		.dc-gi-callout.warn { background: #fff8e0; border-left: 3px solid #f0b429; }
		.dc-gi-callout.ok   { background: #edfaee; border-left: 3px solid #46b450; }
		.dc-gi-callout.err  { background: #fdf0ef; border-left: 3px solid #dc3232; }
		.dc-gi-callout code { background: rgba(0,0,0,.06); padding: 1px 5px; border-radius: 3px; font-size: 12px; }
		.dc-gi-check-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
		.dc-gi-check-row:last-child { border-bottom: none; }
		.dc-gi-check-icon { font-size: 17px; flex-shrink: 0; }
		.dc-gi-check-label { flex: 1; font-size: 13px; }
		.dc-gi-check-value { font-size: 12px; color: #777; }
		.dc-gi-btn-row { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
		.dc-gi-json-preview {
			background: #1e1e1e; color: #9cdcfe; font-size: 12px; padding: 12px 16px;
			border-radius: 4px; margin: 10px 0; overflow-x: auto; line-height: 1.6;
		}
		.dc-gi-json-preview .key { color: #9cdcfe; }
		.dc-gi-json-preview .val { color: #ce9178; }
		.dc-gi-json-preview .type { color: #4ec9b0; }

		/* Accordion JS toggle */
		.dc-gi-step-body[hidden] { display: none; }
		</style>

		<script>
		document.addEventListener('DOMContentLoaded', function () {
			// Accordion
			document.querySelectorAll('.dc-gi-step-header').forEach(function (header) {
				header.addEventListener('click', function () {
					var body   = header.nextElementSibling;
					var toggle = header.querySelector('.dc-gi-step-toggle');
					var hidden = body.hasAttribute('hidden');
					body.toggleAttribute('hidden', !hidden);
					toggle.textContent = hidden ? '▲' : '▼';
				});
			});

			// Live JSON validator
			var textarea = document.getElementById('dc-gi-json-input');
			var feedback = document.getElementById('dc-gi-json-feedback');
			if (textarea) {
				textarea.addEventListener('input', function () {
					var val = textarea.value.trim();
					if (!val) { feedback.innerHTML = ''; return; }
					try {
						var obj = JSON.parse(val);
						var errors = [];
						if (obj.type !== 'service_account') errors.push('❌ "type" must be "service_account"');
						if (!obj.client_email) errors.push('❌ Missing "client_email"');
						if (!obj.private_key) errors.push('❌ Missing "private_key"');
						if (!obj.project_id) errors.push('❌ Missing "project_id"');
						if (errors.length) {
							feedback.innerHTML = '<div class="dc-gi-callout err">' + errors.join('<br>') + '</div>';
						} else {
							feedback.innerHTML =
								'<div class="dc-gi-callout ok">' +
								'✅ <strong>Valid JSON key file detected!</strong><br>' +
								'<span style="color:#555">Account: <code>' + obj.client_email + '</code></span>' +
								'</div>';
						}
					} catch (e) {
						feedback.innerHTML = '<div class="dc-gi-callout err">❌ Invalid JSON — check that you copied the entire file contents.</div>';
					}
				});
			}
		});
		</script>

		<div class="dc-gi-guide">

		<h2><?php esc_html_e( 'Getting Started — Connect Google Indexing API', 'dc-google-indexing' ); ?></h2>
		<p class="dc-gi-intro">
			<?php esc_html_e( 'This guide walks you through connecting your WordPress site to Google\'s Web Search Indexing API. Once set up, Google is notified within seconds every time you publish or update content — no more waiting days for Googlebot to find your pages.', 'dc-google-indexing' ); ?>
			<br><strong><?php esc_html_e( 'Estimated time: 10–15 minutes. No coding required.', 'dc-google-indexing' ); ?></strong>
		</p>

		<!-- Progress bar -->
		<?php
		$step_done = [ false, false, false, false, false ];
		if ( $has_sa ) {
			$step_done = [ true, true, true, true, true ];
		}
		$step_labels = [
			__( 'Cloud Project', 'dc-google-indexing' ),
			__( 'Enable API', 'dc-google-indexing' ),
			__( 'Service Account', 'dc-google-indexing' ),
			__( 'Search Console', 'dc-google-indexing' ),
			__( 'Connect', 'dc-google-indexing' ),
		];
		?>
		<div class="dc-gi-progress">
			<?php foreach ( $step_labels as $i => $slabel ) :
				$class = $step_done[ $i ] ? 'done' : ( ! $has_sa && $i === 0 ? 'active' : '' );
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

				<div class="dc-gi-callout ok" style="margin-top:14px">
					<strong><?php esc_html_e( '🎉 You\'re all set!', 'dc-google-indexing' ); ?></strong>
					<?php esc_html_e( 'Your site is connected. Google will be notified as soon as you publish or update content.', 'dc-google-indexing' ); ?>
				</div>

				<div class="dc-gi-btn-row">
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'dc-google-indexing', 'tab' => 'submit' ], admin_url( 'admin.php' ) ) ); ?>" class="button button-primary">
						<?php esc_html_e( '→ Submit URLs now', 'dc-google-indexing' ); ?>
					</a>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'dc-google-indexing', 'tab' => 'settings' ], admin_url( 'admin.php' ) ) ); ?>" class="button">
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
					<input type="hidden" name="daily_quota" value="200">
					<input type="hidden" name="post_types[]" value="post">
					<input type="hidden" name="post_types[]" value="page">
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
							<?php esc_html_e( '🔗 Save & Connect', 'dc-google-indexing' ); ?>
						</button>
						<span style="color:#888;font-size:13px"><?php esc_html_e( 'The plugin will validate your JSON and verify the connection.', 'dc-google-indexing' ); ?></span>
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
					<th scope="row"><?php esc_html_e( 'Auto-submit on Publish', 'dc-google-indexing' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_submit" value="1" <?php checked( ! empty( $settings['auto_submit'] ) ); ?>>
							<?php esc_html_e( 'Automatically queue URLs when a post is published or updated', 'dc-google-indexing' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types', 'dc-google-indexing' ); ?></th>
					<td>
						<?php
						$saved_types = $settings['post_types'] ?? [ 'post', 'page' ];
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
					<th scope="row"><?php esc_html_e( 'Footer Credit', 'dc-google-indexing' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="footer_credit" value="1" <?php checked( ! empty( $settings['footer_credit'] ) ); ?>>
							<?php esc_html_e( 'Show some love and support development by adding a small link in the footer', 'dc-google-indexing' ); ?>
						</label>
						<p class="description">
							<?php echo wp_kses_post( __( 'Inserts a discreet <a href="https://www.dampcig.dk" target="_blank" rel="noopener">Dampcig.dk</a> link in the footer by linking the copyright symbol &copy;. Does nothing if your theme has no &copy; in the footer.', 'dc-google-indexing' ) ); ?>
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
					<?php esc_html_e( 'Test credentials', 'dc-google-indexing' ); ?>
				</button>
				<span class="description" style="margin-left:8px">
					<?php esc_html_e( 'Attempts to obtain a Google access token — no URL is submitted.', 'dc-google-indexing' ); ?>
				</span>
			</p>
		</form>

		<hr style="margin:20px 0">

		<p>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'dc-google-indexing', 'tab' => 'start' ], admin_url( 'admin.php' ) ) ); ?>">
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

		<?php elseif ( 'queue' === $tab ) : ?>

		<!-- ===== QUEUE ===== -->
		<p>
			<strong><?php printf(
				/* translators: %d: number of URLs in queue */
				esc_html__( '%d URL(s) pending.', 'dc-google-indexing' ),
				count( $queue )
			); ?></strong>
			<?php esc_html_e( 'Processed automatically every 5 minutes via WP-Cron.', 'dc-google-indexing' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<?php wp_nonce_field( 'dc_gi_runnow' ); ?>
			<input type="hidden" name="action" value="dc_gi_runnow">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Process Now', 'dc-google-indexing' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block"
			onsubmit="return confirm('<?php esc_attr_e( 'Clear the entire queue?', 'dc-google-indexing' ); ?>')">
			<?php wp_nonce_field( 'dc_gi_clrqueue' ); ?>
			<input type="hidden" name="action" value="dc_gi_clrqueue">
			<button type="submit" class="button"><?php esc_html_e( 'Clear Queue', 'dc-google-indexing' ); ?></button>
		</form>

		<?php if ( ! empty( $queue ) ) : ?>
		<table class="widefat striped" style="margin-top:16px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL', 'dc-google-indexing' ); ?></th>
					<th><?php esc_html_e( 'Type', 'dc-google-indexing' ); ?></th>
					<th><?php esc_html_e( 'Added', 'dc-google-indexing' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $queue as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item['url'] ); ?></td>
					<td><code><?php echo esc_html( $item['type'] ); ?></code></td>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $item['added'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php elseif ( 'watchlist' === $tab ) : ?>

		<!-- ===== WATCHLIST ===== -->
		<style>
		.dc-gi-wl-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
		.dc-gi-wl-badge.pending  { background:#fff3cd; color:#856404; }
		.dc-gi-wl-badge.indexed  { background:#d1e7dd; color:#0f5132; }
		.dc-gi-wl-badge.error    { background:#f8d7da; color:#842029; }
		.dc-gi-wl-next { color:#777; font-size:12px; }
		</style>

		<h2 style="margin-top:0"><?php esc_html_e( 'Watchlist — Index Status Tracker', 'dc-google-indexing' ); ?></h2>
		<p style="color:#555;max-width:700px">
			<?php esc_html_e( 'Every URL successfully submitted to Google is tracked here. Status is checked automatically every 6 hours via WP-Cron and updated when Google reports the page as indexed. URLs already in the Watchlist are skipped during Polling to avoid wasting inspection quota.', 'dc-google-indexing' ); ?>
		</p>

		<?php
		$watch_pending = array_filter( $watchlist, fn( $e ) => 'pending' === $e['status'] );
		$watch_indexed = array_filter( $watchlist, fn( $e ) => 'indexed' === $e['status'] );
		$next_watch    = wp_next_scheduled( DC_GI_WATCH_HOOK );
		?>

		<div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dc_gi_watch_now' ); ?>
				<input type="hidden" name="action" value="dc_gi_watch_now">
				<button type="submit" class="button button-primary"><?php esc_html_e( '🔄 Check Now', 'dc-google-indexing' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php esc_attr_e( 'Clear the entire watchlist?', 'dc-google-indexing' ); ?>')">
				<?php wp_nonce_field( 'dc_gi_watch_clr' ); ?>
				<input type="hidden" name="action" value="dc_gi_watch_clr">
				<button type="submit" class="button"><?php esc_html_e( 'Clear All', 'dc-google-indexing' ); ?></button>
			</form>
			<span class="dc-gi-wl-next">
				<?php if ( $next_watch ) : ?>
					<?php printf(
						/* translators: %s: human-readable time until next auto-check */
						esc_html__( 'Next auto-check in %s', 'dc-google-indexing' ),
						esc_html( human_time_diff( time(), $next_watch ) )
					); ?>
				<?php else : ?>
					<?php esc_html_e( 'Auto-check not scheduled — re-save settings to reload cron.', 'dc-google-indexing' ); ?>
				<?php endif; ?>
			</span>
		</div>

		<div style="display:flex;gap:20px;margin-bottom:20px">
			<div class="dc-gi-stat" style="min-width:120px">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) count( $watchlist ) ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Total', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat" style="min-width:120px">
				<div class="dc-gi-stat-num" style="color:#856404"><?php echo esc_html( (string) count( $watch_pending ) ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Pending', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat green" style="min-width:120px">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) count( $watch_indexed ) ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Indexed', 'dc-google-indexing' ); ?></div>
			</div>
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
			<tbody>
				<?php foreach ( $watchlist as $entry ) :
					$badge_class = in_array( $entry['status'], [ 'pending', 'indexed', 'error' ], true )
						? $entry['status'] : 'pending';
				?>
				<tr>
					<td>
						<a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $entry['url'] ); ?>
						</a>
					</td>
					<td><span class="dc-gi-wl-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( ucfirst( $entry['status'] ) ); ?></span></td>
					<td><?php echo esc_html( $entry['coverage'] ?: '—' ); ?></td>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $entry['submitted_at'] ) ); ?></td>
					<td><?php echo $entry['last_checked'] ? esc_html( wp_date( 'Y-m-d H:i', $entry['last_checked'] ) ) : '—'; ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'dc_gi_watch_del' ); ?>
							<input type="hidden" name="action" value="dc_gi_watch_del">
							<input type="hidden" name="watch_url" value="<?php echo esc_attr( $entry['url'] ); ?>">
							<button type="submit" class="button button-link-delete" style="font-size:11px"
								onclick="return confirm('<?php esc_attr_e( 'Remove from watchlist?', 'dc-google-indexing' ); ?>')"
							>&times;</button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php elseif ( 'polling' === $tab ) : ?>

		<!-- ===== POLLING ===== -->
		<style>
		.dc-gi-grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin:16px 0; }
		.dc-gi-stat { background:#f6f7f7; border:1px solid #e0e0e0; border-radius:6px; padding:14px 18px; text-align:center; }
		.dc-gi-stat-num { font-size:28px; font-weight:700; color:#1d2327; line-height:1.2; }
		.dc-gi-stat-label { font-size:12px; color:#777; margin-top:4px; }
		.dc-gi-stat.green .dc-gi-stat-num { color:#46b450; }
		.dc-gi-stat.red .dc-gi-stat-num { color:#dc3232; }
		.dc-gi-filter-list { list-style:none; margin:0; padding:0; columns:2; }
		.dc-gi-filter-list li { padding:4px 0 4px 20px; position:relative; font-size:13px; color:#555; }
		.dc-gi-filter-list li::before { content:'❌'; position:absolute; left:0; font-size:11px; top:5px; }
		.dc-gi-filter-list li.ok::before { content:'✅'; }
		</style>

		<h2 style="margin-top:0"><?php esc_html_e( 'URL Polling — Discover Unindexed Pages', 'dc-google-indexing' ); ?></h2>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:900px">
			<div class="dc-gi-callout info" style="margin:0">
				<strong><?php esc_html_e( 'How it works', 'dc-google-indexing' ); ?></strong><br>
				<?php esc_html_e( 'Polling fetches your XML sitemap, then calls the Google URL Inspection API for each URL to check its current index status. URLs that are known to Google but not yet indexed are automatically added to the submission queue.', 'dc-google-indexing' ); ?>
			</div>
			<div class="dc-gi-callout warn" style="margin:0">
				<strong><?php esc_html_e( '⚠️ Use sparingly', 'dc-google-indexing' ); ?></strong><br>
				<?php esc_html_e( 'Each URL consumes 1 inspection (quota: 2,000/day). Qualifying URLs are then submitted against the Indexing API quota (200/day). Large sitemaps can exhaust both limits quickly.', 'dc-google-indexing' ); ?>
			</div>
		</div>

		<?php if ( $last_poll ) : ?>
		<h3><?php esc_html_e( 'Last Polling Run', 'dc-google-indexing' ); ?></h3>
		<p style="color:#777;font-size:13px;margin-top:-8px">
			<?php echo esc_html( wp_date( 'Y-m-d H:i:s', $last_poll['time'] ) ); ?>
		</p>
		<div class="dc-gi-grid-3" style="max-width:600px">
			<div class="dc-gi-stat">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) $last_poll['inspected'] ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Inspected', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat green">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) $last_poll['queued'] ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Added to queue', 'dc-google-indexing' ); ?></div>
			</div>
			<div class="dc-gi-stat">
				<div class="dc-gi-stat-num"><?php echo esc_html( (string) $last_poll['skipped'] ); ?></div>
				<div class="dc-gi-stat-label"><?php esc_html_e( 'Filtered out', 'dc-google-indexing' ); ?></div>
			</div>
		</div>
		<?php if ( $last_poll['errors'] > 0 ) : ?>
		<p style="color:#dc3232;font-size:13px">
			<?php printf(
				/* translators: %d: number of errors */
				esc_html__( '%d inspection error(s) — see Log tab for details.', 'dc-google-indexing' ),
				(int) $last_poll['errors']
			); ?>
		</p>
		<?php endif; ?>
		<?php endif; ?>

		<hr style="margin:24px 0 20px">

		<h3 style="margin-bottom:4px"><?php esc_html_e( 'Run Polling', 'dc-google-indexing' ); ?></h3>
		<p style="color:#555;font-size:13px;margin-top:0">
			<?php echo wp_kses_post( sprintf(
				/* translators: %s: URL to site home */
				__( 'Site URL detected: <code>%s</code>. This must match your Search Console property.', 'dc-google-indexing' ),
				esc_html( trailingslashit( get_home_url() ) )
			) ); ?>
		</p>

		<?php if ( ! $has_sa ) : ?>
		<div class="dc-gi-callout err">
			<?php echo wp_kses_post( sprintf(
				/* translators: %s: link to settings tab */
				__( 'No service account configured. <a href="%s">Go to Settings</a> to connect your Google Cloud credentials first.', 'dc-google-indexing' ),
				esc_url( add_query_arg( [ 'page' => 'dc-google-indexing', 'tab' => 'settings' ], admin_url( 'admin.php' ) ) )
			) ); ?>
		</div>
		<?php else : ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:500px">
			<?php wp_nonce_field( 'dc_gi_poll' ); ?>
			<input type="hidden" name="action" value="dc_gi_poll">
			<table class="form-table" role="presentation" style="margin-top:0">
				<tr>
					<th scope="row" style="width:180px">
						<label for="poll_limit"><?php esc_html_e( 'URLs to inspect', 'dc-google-indexing' ); ?></label>
					</th>
					<td>
						<input type="number" id="poll_limit" name="poll_limit" value="50" min="1" max="200" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Max 200. Each URL costs 1 inspection from your 2,000/day quota. Start small (10–50) to see how many unindexed pages you have before running larger batches.', 'dc-google-indexing' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( '🔍 Start Polling', 'dc-google-indexing' ); ?>
				</button>
				<span class="description" style="margin-left:10px">
					<?php esc_html_e( 'This may take 30–60 seconds for large batches.', 'dc-google-indexing' ); ?>
				</span>
			</p>
		</form>

		<?php endif; ?>

		<hr style="margin:24px 0 20px">

		<h3 style="margin-bottom:8px"><?php esc_html_e( 'Filter Logic — What Gets Submitted vs. Skipped', 'dc-google-indexing' ); ?></h3>
		<p style="color:#555;font-size:13px;margin-top:0"><?php esc_html_e( 'Only URLs with these Google coverage states are queued for submission:', 'dc-google-indexing' ); ?></p>
		<ul class="dc-gi-filter-list">
			<li class="ok"><?php esc_html_e( 'Crawled — currently not indexed', 'dc-google-indexing' ); ?></li>
			<li class="ok"><?php esc_html_e( 'Discovered — currently not indexed', 'dc-google-indexing' ); ?></li>
		</ul>
		<p style="color:#555;font-size:13px;margin-top:16px"><?php esc_html_e( 'These states are automatically filtered out (noise):', 'dc-google-indexing' ); ?></p>
		<ul class="dc-gi-filter-list">
			<li><?php esc_html_e( 'Submitted and indexed (already done)', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Indexed, not submitted in sitemap', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Not found (404)', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Page with redirect (301/302)', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Redirect error', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Server error (5xx)', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Soft 404', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Excluded by \'noindex\' tag', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Blocked by robots.txt', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Alternate page with canonical tag', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Duplicate — Google chose different canonical', 'dc-google-indexing' ); ?></li>
			<li><?php esc_html_e( 'Blocked (401 unauthorized)', 'dc-google-indexing' ); ?></li>
		</ul>

		<div class="dc-gi-callout info" style="max-width:700px;margin-top:16px">
			<strong><?php esc_html_e( 'Tip: Fix root causes, not symptoms', 'dc-google-indexing' ); ?></strong><br>
			<?php esc_html_e( 'If polling consistently finds many filtered-out URLs (redirects, canonicals, noindex), those pages likely have underlying SEO issues. Submitting them won\'t help — fix the issues first (correct canonicals, remove noindex, fix redirects) then run polling again.', 'dc-google-indexing' ); ?>
		</div>

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
				<?php foreach ( $log as $entry ) :
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
						<?php else : ?>
							<span style="color:#dc3232;font-weight:600">&#10007; Error</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $is_ownership_err ) : ?>
							<span style="color:#dc3232"><?php esc_html_e( 'Permission denied: service account not verified as property owner.', 'dc-google-indexing' ); ?></span>
							<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'dc-google-indexing', 'tab' => 'start' ], admin_url( 'admin.php' ) ) ); ?>#step-4" style="margin-left:6px;white-space:nowrap">
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

		<?php endif; ?>

		</div><!-- tab content -->
	</div><!-- .wrap -->
	<?php
}

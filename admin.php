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
add_action( 'admin_post_dc_gi_poll_reset', 'dc_gi_handle_poll_reset' );
add_action( 'admin_post_dc_gi_watch_del',  'dc_gi_handle_watch_delete' );
add_action( 'admin_post_dc_gi_watch_clr',  'dc_gi_handle_watch_clear' );
add_action( 'admin_post_dc_gi_watch_now',  'dc_gi_handle_watch_check_now' );

add_action( 'wp_ajax_dc_gi_poll_start',  'dc_gi_ajax_poll_start' );
add_action( 'wp_ajax_dc_gi_poll_stop',   'dc_gi_ajax_poll_stop' );
add_action( 'wp_ajax_dc_gi_poll_status', 'dc_gi_ajax_poll_status' );
add_action( 'wp_ajax_dc_gi_poll_wait',   'dc_gi_ajax_poll_wait' );
add_action( 'wp_ajax_dc_gi_poll_debug',    'dc_gi_ajax_poll_debug' );
add_action( 'wp_ajax_dc_gi_poll_fix_cron', 'dc_gi_ajax_poll_fix_cron' );
add_action( 'wp_ajax_dc_gi_poll_trigger',  'dc_gi_ajax_poll_trigger' );
add_action( 'wp_ajax_dc_gi_watch_check_one',  'dc_gi_ajax_watch_check_one' );
add_action( 'wp_ajax_dc_gi_watch_stop',        'dc_gi_ajax_watch_stop' );
add_action( 'admin_post_dc_gi_watch_fix_cron', 'dc_gi_handle_watch_fix_cron' );
add_action( 'admin_enqueue_scripts', 'dc_gi_enqueue_scripts' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_enqueue_scripts( string $hook ): void {
	if ( 'toplevel_page_dc-google-indexing' !== $hook ) {
		return;
	}
	wp_register_script( 'dc-gi-admin', false, [ 'jquery' ], DC_GI_VERSION, true );
	wp_enqueue_script( 'dc-gi-admin' );
	wp_localize_script( 'dc-gi-admin', 'dcGiPoll', [
		'nonce'   => wp_create_nonce( 'dc_gi_ajax' ),
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'active'  => (bool) get_option( 'dc_gi_poll_active', false ),
		'i18n'    => [
			'starting'  => __( 'Starting…', 'dc-google-indexing' ),
			'stopping'  => __( 'Stopping…', 'dc-google-indexing' ),
			'running'   => __( 'Running', 'dc-google-indexing' ),
			'stopped'   => __( '○ Stopped', 'dc-google-indexing' ),
			'done'      => __( '✅ Cycle complete', 'dc-google-indexing' ),
			'errComms'  => __( 'Communication error — retrying…', 'dc-google-indexing' ),
		],
	] );
	wp_add_inline_script( 'dc-gi-admin', dc_gi_poll_js() );
	wp_add_inline_script( 'dc-gi-admin', dc_gi_watch_check_js() );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_poll_js(): string {
	return <<<'JS'
(function($){
	var lpXhr   = null;
	var stopped = false;
	var lastTs  = 0;

	function pct(seen, total) {
		return total > 0 ? Math.round(seen / total * 100) : 0;
	}

	function updateUI(data) {
		var active = data.active;
		var lp     = data.last_poll || {};
		var seen   = data.cycle_seen  || (lp.cycle_seen  || 0);
		var total  = data.cycle_total || (lp.cycle_total || 0);
		var done   = lp.cycle_done || false;
		var p      = pct(seen, total);

		if (lp.time) lastTs = lp.time;

		var badge  = done ? dcGiPoll.i18n.done : (active ? dcGiPoll.i18n.running : dcGiPoll.i18n.stopped);
		var $badge = $('#dc-gi-status-badge');
		$badge.attr('class', 'dc-gi-poll-badge ' + (done ? 'done' : (active ? 'running' : 'stopped')));
		$badge.find('.dc-gi-spinner').remove();
		if (active && !done) {
			$badge.prepend('<span class="dc-gi-spinner"></span>');
		}
		$badge.find('.dc-gi-badge-text').text(badge);

		$('#dc-gi-prog-bar').css('width', p + '%').css('background', done ? '#46b450' : '#2271b1');
		$('#dc-gi-prog-label').text(total > 0 ? seen + ' / ' + total + ' (' + p + '%)' : '—');

		if (lp.time) {
			var d  = new Date(lp.time * 1000);
			var ts = d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'});
			$('#dc-gi-batch-time').text(ts);
			// Show cycle-wide cumulative totals, falling back to per-batch for first batch
			$('#dc-gi-batch-inspected').text(lp.cycle_inspected != null ? lp.cycle_inspected : (lp.inspected || 0));
			$('#dc-gi-batch-queued').text(lp.cycle_queued != null ? lp.cycle_queued : (lp.queued || 0));
			$('#dc-gi-batch-skipped').text(lp.cycle_skipped != null ? lp.cycle_skipped : (lp.skipped || 0));
			var errEl = $('#dc-gi-batch-errors');
			var totalErrors = lp.cycle_errors != null ? lp.cycle_errors : (lp.errors || 0);
			errEl.text(totalErrors);
			errEl.css('color', totalErrors > 0 ? '#fd5d93' : '#e4e8f3');
			$('#dc-gi-last-batch').show();
		}

		$('#dc-gi-queue-count').text(data.queue_count || 0);
		$('#dc-gi-start-btn').prop('disabled', active || done).text('\u25b6 Start Polling');
		$('#dc-gi-stop-btn').prop('disabled', !active);
	}

	function setBadgeRunning() {
		var $badge = $('#dc-gi-status-badge');
		$badge.attr('class', 'dc-gi-poll-badge running');
		$badge.find('.dc-gi-spinner').remove();
		$badge.prepend('<span class="dc-gi-spinner"></span>');
		$badge.find('.dc-gi-badge-text').text(dcGiPoll.i18n.running);
		$('#dc-gi-start-btn').prop('disabled', true);
		$('#dc-gi-stop-btn').prop('disabled', false);
	}

	function setBadgeStopped() {
		var $badge = $('#dc-gi-status-badge');
		$badge.attr('class', 'dc-gi-poll-badge stopped');
		$badge.find('.dc-gi-spinner').remove();
		$badge.find('.dc-gi-badge-text').text(dcGiPoll.i18n.stopped);
		$('#dc-gi-stop-btn').prop('disabled', true);
		$('#dc-gi-start-btn').prop('disabled', false);
	}

	function longPoll() {
		if (stopped) return;
		console.log('[dcGi] longPoll fired');
		lpXhr = $.post(dcGiPoll.ajaxurl, {action:'dc_gi_poll_wait', nonce:dcGiPoll.nonce})
		.done(function(r) {
			// If user stopped while this request was in-flight, ignore the response.
			if (stopped) { setBadgeStopped(); return; }
			console.log('[dcGi] poll_wait response:', JSON.stringify(r));
			if (r.success) {
				updateUI(r.data);
				if (r.data.active && !stopped) {
					longPoll();
				} else {
					setBadgeStopped();
					console.log('[dcGi] longPoll stopping: active=' + r.data.active + ' stopped=' + stopped);
				}
			}
		})
		.fail(function(xhr) {
			if (xhr.statusText === 'abort') return;
			console.warn('[dcGi] poll_wait failed, retrying in 2s', xhr.status, xhr.statusText);
			setTimeout(longPoll, 2000);
		});
	}

	$(function(){

		$('#dc-gi-start-btn').on('click', function(e){
			e.preventDefault();
			stopped = false;
			setBadgeRunning();
			$.post(dcGiPoll.ajaxurl, {action:'dc_gi_poll_start', nonce:dcGiPoll.nonce})
				.done(function(r){
					console.log('[dcGi] poll_start response:', JSON.stringify(r));
					if (r.success) longPoll();
				});
		});

		$('#dc-gi-stop-btn').on('click', function(e){
			e.preventDefault();
			stopped = true;
			if (lpXhr) { lpXhr.abort(); lpXhr = null; }
			setBadgeStopped();
			// Tell the server to clear the active flag — no UI update, badge already correct.
			$.post(dcGiPoll.ajaxurl, {action:'dc_gi_poll_stop', nonce:dcGiPoll.nonce});
		});

		$('#dc-gi-debug-btn').on('click', function(){
			$('#dc-gi-debug-out').text('Loading\u2026');
			$.post(dcGiPoll.ajaxurl, {action:'dc_gi_poll_debug', nonce:dcGiPoll.nonce})
				.done(function(r){
					console.log('[dcGi] debug:', JSON.stringify(r, null, 2));
					if (r.success) $('#dc-gi-debug-out').text(JSON.stringify(r.data, null, 2));
				});
		});

		$('#dc-gi-fix-cron-btn').on('click', function(){
			var $btn = $(this).prop('disabled', true).text('Scheduling\u2026');
			$.post(dcGiPoll.ajaxurl, {action:'dc_gi_poll_fix_cron', nonce:dcGiPoll.nonce})
				.done(function(r){
					console.log('[dcGi] fix_cron:', JSON.stringify(r));
					$btn.prop('disabled', false).text('\u21ba Fix Cron Schedule');
					$('#dc-gi-debug-out').text(r.success ? '\u2705 ' + r.data : '\u274c ' + JSON.stringify(r));
				});
		});

		$('#dc-gi-trigger-btn').on('click', function(){
			var $btn = $(this).prop('disabled', true).text('Running batch\u2026');
			$('#dc-gi-debug-out').text('Triggering batch \u2014 this may take 5\u201310s\u2026');
			$.post(dcGiPoll.ajaxurl, {action:'dc_gi_poll_trigger', nonce:dcGiPoll.nonce})
				.done(function(r){
					console.log('[dcGi] trigger:', JSON.stringify(r, null, 2));
					$btn.prop('disabled', false).text('\u25b6 Run One Batch Now');
					if (r.success) {
						updateUI({
							active:      true,
							last_poll:   r.data.result,
							cycle_seen:  r.data.result ? (r.data.result.cycle_seen  || 0) : 0,
							cycle_total: r.data.result ? (r.data.result.cycle_total || 0) : 0,
							queue_count: 0
						});
						$('#dc-gi-debug-out').text(
							r.data.batch_ran
								? '\u2705 Batch ran! status=' + r.data.batch_status + '\n' + JSON.stringify(r.data.result, null, 2)
								: '\u26a0\ufe0f Batch did NOT run.\nbatch_status: ' + r.data.batch_status + '\nlock_was_stuck: ' + r.data.lock_was_stuck + '\n\n' + JSON.stringify(r.data, null, 2)
						);
					}
				})
				.fail(function(xhr){
					$btn.prop('disabled', false).text('\u25b6 Run One Batch Now');
					$('#dc-gi-debug-out').text('Error: ' + xhr.status + ' ' + xhr.responseText.substring(0, 300));
				});
		});

		// Initial status on page load.
		$.post(dcGiPoll.ajaxurl, {action:'dc_gi_poll_status', nonce:dcGiPoll.nonce})
			.done(function(r){
				console.log('[dcGi] initial status:', JSON.stringify(r));
				if (r.success) {
					updateUI(r.data);
					if (r.data.active) longPoll();
				}
			});

	});
}(jQuery));
JS;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_watch_check_js(): string {
	return <<<'JS'
(function($){
	$(function(){
		var wcStopped = true;
		var wcXhr     = null;

		// Format a unix timestamp (seconds) as 'YYYY-MM-DD HH:MM'.
		function fmtNow() {
			var n = new Date();
			var p = function(v){ return String(v).padStart(2,'0'); };
			return n.getFullYear()+'-'+p(n.getMonth()+1)+'-'+p(n.getDate())+' '+p(n.getHours())+':'+p(n.getMinutes());
		}

		// FLIP: move $row to top of its tbody with a spring-like CSS transition.
		function flipToTop($row, newStatus, newCoverage) {
			var tbody = document.getElementById('dc-gi-wl-tbody');
			if (!tbody) return;

			// FIRST — record current position.
			var first = $row[0].getBoundingClientRect().top;

			// LAST — move to top of list instantly.
			tbody.insertBefore($row[0], tbody.firstChild);

			// Compute delta: how far we visually displaced the row.
			var last  = $row[0].getBoundingClientRect().top;
			var delta = first - last;

			// INVERT — offset back to old visual position with no transition.
			$row[0].style.transition = 'none';
			$row[0].style.transform  = 'translateY(' + delta + 'px)';

			// Force reflow so the browser registers the starting position.
			$row[0].offsetHeight; // eslint-disable-line no-unused-expressions

			// PLAY — animate to natural (zero) position.
			$row[0].style.transition = 'transform 420ms cubic-bezier(0.34,1.56,0.64,1)';
			$row[0].style.transform  = 'translateY(0)';

			// Clean up transform after animation so layout stays normal.
			setTimeout(function(){ $row[0].style.transition = ''; $row[0].style.transform = ''; }, 450);

			// Update status badge (2nd td).
			var label = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
			$row.find('td').eq(1).html('<span class="dc-gi-wl-badge ' + newStatus + '">' + label + '</span>');

			// Update coverage (3rd td).
			$row.find('td').eq(2).text(newCoverage || '—');

			// Update last-checked (5th td).
			$row.find('td').eq(4).text(fmtNow());

			// Flash highlight.
			$row.removeClass('dc-gi-wl-flash');
			$row[0].offsetHeight;
			$row.addClass('dc-gi-wl-flash');
		}

		function wcStart() {
			wcStopped = false;
			$('#dc-gi-watch-check-btn').prop('disabled', true);
			$('#dc-gi-watch-progress').show();
			$('#dc-gi-wcp-label').text('Checking…');
			$('#dc-gi-wcp-bar').css('width','0%').css('background','linear-gradient(90deg,#1d8cf8,#00f2c3)');
			wcCheckOne(0);
		}

		function wcStop() {
			wcStopped = true;
			if (wcXhr) { wcXhr.abort(); wcXhr = null; }
			$('#dc-gi-watch-check-btn').prop('disabled', false);
			$('#dc-gi-wcp-label').text('Stopped.');
			// Tell server to clear the active flag + cron safety-net.
			$.post(dcGiPoll.ajaxurl, { action: 'dc_gi_watch_stop', nonce: dcGiPoll.nonce });
		}

		function wcCheckOne(offset) {
			if (wcStopped) return;
			wcXhr = $.post(dcGiPoll.ajaxurl, {
					action: 'dc_gi_watch_check_one',
					nonce:  dcGiPoll.nonce,
					offset: offset
				})
				.done(function(r) {
					if (wcStopped) return;
					if (!r.success) {
						$('#dc-gi-wcp-label').text('Error: ' + JSON.stringify(r));
						$('#dc-gi-watch-check-btn').prop('disabled', false);
						return;
					}
					var d     = r.data;
					var total = d.total || 1;
					var pct   = Math.round(d.checked / total * 100);
					$('#dc-gi-wcp-count').text(d.checked + ' / ' + total + ' (' + pct + '%)');
					$('#dc-gi-wcp-bar').css('width', pct + '%');
					if (d.url) $('#dc-gi-wcp-url').text(d.url + ' → ' + (d.coverage || d.status));
					// Live-update the header queue count.
					if (typeof d.queue_count !== 'undefined') {
						$('#dc-gi-header-queue').text(d.queue_count);
						$('#dc-gi-queue-count').text(d.queue_count); // polling tab counter too
					}

					// FLIP the processed row to the top of the table.
					if (d.url) {
						var $row = $('[data-wl-url="' + d.url.replace(/"/g,'&quot;') + '"]');
						if ($row.length) flipToTop($row, d.status, d.coverage);
					}

					if (d.done) {
						$('#dc-gi-wcp-label').text('✅ Done — ' + d.checked + ' URLs checked.');
						$('#dc-gi-wcp-bar').css('background','#00f2c3');
						$('#dc-gi-watch-check-btn').prop('disabled', false);
					} else {
						wcCheckOne(d.next);
					}
				})
				.fail(function(xhr) {
					if (xhr.statusText === 'abort') return;
					if (wcStopped) return;
					// Retry same offset after 2s on transient errors.
					setTimeout(function(){ wcCheckOne(offset); }, 2000);
				});
		}

		$('#dc-gi-watch-check-btn').on('click', wcStart);
		$('#dc-gi-watch-stop-btn').on('click', wcStop);
	});
}(jQuery));
JS;
}

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
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'watchlist', 'notice' => 'watch_checked' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_watch_fix_cron(): void {
	check_admin_referer( 'dc_gi_watch_fix_cron' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}
	if ( ! wp_next_scheduled( DC_GI_WATCH_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_sixhourly', DC_GI_WATCH_HOOK );
	}
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'watchlist', 'notice' => 'cron_fixed' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

/**
 * AJAX: check one pending watchlist URL and return progress.
 * Offset is passed from JS; list order is stable (array index).
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_ajax_watch_check_one(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$settings = dc_gi_get_settings();
	if ( empty( $settings['service_account_json'] ) ) {
		wp_send_json_error( 'no_service_account' );
	}
	$sa = json_decode( $settings['service_account_json'], true );
	if ( ! $sa || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
		wp_send_json_error( 'invalid_service_account' );
	}

	$offset   = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$site_url = trailingslashit( get_home_url() );
	$list     = get_option( 'dc_gi_watchlist', [] );

	// Build flat array of all entries (pending + already indexed — we skip indexed in loop).
	$keys    = array_keys( $list );
	$total   = count( $keys );

	// Mark active + store cursor so cron can continue if JS disconnects.
	update_option( 'dc_gi_watch_active', true, false );
	update_option( 'dc_gi_watch_offset', $offset, false );

	// Ensure a recurring 1-minute cron is running as a fallback in case JS disconnects.
	if ( ! wp_next_scheduled( DC_GI_WATCH_CHECK_HOOK ) ) {
		wp_schedule_event( time() + 60, 'dc_gi_every1', DC_GI_WATCH_CHECK_HOOK );
	}

	$done_statuses = [ 'indexed', 'removed' ];

	// Advance offset past already-done entries.
	while ( $offset < $total && in_array( $list[ $keys[ $offset ] ]['status'] ?? '', $done_statuses, true ) ) {
		$offset++;
	}

	if ( $offset >= $total ) {
		delete_option( 'dc_gi_watch_active' );
		delete_option( 'dc_gi_watch_offset' );
		wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
		wp_send_json_success( [ 'done' => true, 'checked' => $offset, 'total' => $total, 'queue_count' => count( (array) get_option( 'dc_gi_queue', [] ) ) ] );
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
		if ( 'removal_pending' === $entry['status'] ) {
			if ( '' === $coverage || 'URL is unknown to Google' === $coverage
				|| 'Not found (404)' === $coverage || 'Soft 404' === $coverage ) {
				$entry['status'] = 'removed';
			}
		} elseif ( 'Submitted and indexed' === $coverage
			|| 'Indexed, not submitted in sitemap' === $coverage ) {
			$entry['status'] = 'indexed';
		} elseif ( '' === $coverage || 'URL is unknown to Google' === $coverage ) {
			// Google has never seen this URL — re-submit via Indexing API.
			dc_gi_enqueue_url( $entry['url'], 'URL_UPDATED' );
			$entry['coverage'] = 'URL is unknown to Google (re-queued for submission)';
			$entry['status']   = 'pending';
		} else {
			$entry['status'] = 'pending';
		}
	}
	unset( $entry );
	update_option( 'dc_gi_watchlist', $list, false );

	$done_statuses = [ 'indexed', 'removed' ];
	$next_offset   = $offset + 1;
	// Skip any trailing already-done entries for the next call.
	while ( $next_offset < $total && in_array( $list[ $keys[ $next_offset ] ]['status'] ?? '', $done_statuses, true ) ) {
		$next_offset++;
	}

	$done        = $next_offset >= $total;
	$queue_count = count( (array) get_option( 'dc_gi_queue', [] ) );

	if ( $done ) {
		delete_option( 'dc_gi_watch_active' );
		delete_option( 'dc_gi_watch_offset' );
		wp_clear_scheduled_hook( DC_GI_WATCH_CHECK_HOOK );
	} else {
		// Keep cursor up to date so the recurring cron continues from the right place.
		update_option( 'dc_gi_watch_offset', $next_offset, false );
	}

	wp_send_json_success( [
		'done'        => $done,
		'checked'     => $offset + 1,
		'total'       => $total,
		'next'        => $next_offset,
		'url'         => $list[ $keys[ $offset ] ]['url'],
		'status'      => $list[ $keys[ $offset ] ]['status'],
		'coverage'    => $list[ $keys[ $offset ] ]['coverage'] ?? '',
		'queue_count' => $queue_count,
	] );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
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

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_ajax_poll_start(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	dc_gi_set_poll_active( true );
	// Ensure the recurring cron is scheduled so polling continues without the browser.
	if ( ! wp_next_scheduled( DC_GI_POLL_HOOK ) ) {
		wp_schedule_event( time(), 'dc_gi_every1', DC_GI_POLL_HOOK );
	}
	wp_send_json_success( dc_gi_poll_status_data() );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_ajax_poll_stop(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	dc_gi_set_poll_active( false );
	wp_send_json_success( dc_gi_poll_status_data() );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_ajax_poll_status(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	wp_send_json_success( dc_gi_poll_status_data() );
}

/**
 * Long-poll endpoint: runs one batch directly and returns the result.
 * The browser reconnects immediately, driving the loop — no cron needed
 * for the interactive case. Cron remains as a background fallback.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_ajax_poll_wait(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	global $wpdb;

	// Check active state directly from DB (bypass object cache).
	$row_active = $wpdb->get_var( $wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
		'dc_gi_poll_active'
	) );
	$active = ! empty( $row_active );

	if ( ! $active ) {
		wp_send_json_success( array_merge( dc_gi_poll_status_data(), [ 'active' => false ] ) );
	}

	if ( function_exists( 'session_write_close' ) ) {
		session_write_close();
	}

	// Run one batch now — this is the actual work, response returns when done.
	delete_transient( 'dc_gi_poll_lock' );
	wp_cache_delete( '_transient_dc_gi_poll_lock', 'options' );
	$batch_status = dc_gi_run_poll_batch( true );

	// Build fresh response directly from DB.
	$row_poll    = $wpdb->get_var( $wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
		'_transient_dc_gi_last_poll'
	) );
	$last_poll   = $row_poll ? maybe_unserialize( $row_poll ) : [];

	$row_seen    = $wpdb->get_var( $wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
		'dc_gi_poll_seen'
	) );
	$row_active2 = $wpdb->get_var( $wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
		'dc_gi_poll_active'
	) );
	$row_queue   = $wpdb->get_var( $wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
		'dc_gi_queue'
	) );

	$poll_seen   = $row_seen  ? (array) maybe_unserialize( $row_seen )  : [];
	$is_active   = ! empty( $row_active2 );
	$queue       = $row_queue ? (array) maybe_unserialize( $row_queue ) : [];

	wp_send_json_success( [
		'active'       => $is_active,
		'last_poll'    => $last_poll ?: null,
		'cycle_seen'   => count( $poll_seen ),
		'cycle_total'  => $last_poll['cycle_total'] ?? 0,
		'queue_count'  => count( $queue ),
		'batch_status' => $batch_status,
	] );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_poll_status_data(): array {
	$last_poll   = get_transient( 'dc_gi_last_poll' );
	$poll_seen   = (array) get_option( 'dc_gi_poll_seen', [] );
	$active      = (bool) get_option( 'dc_gi_poll_active', false );
	return [
		'active'       => $active,
		'last_poll'    => $last_poll ?: null,
		'cycle_seen'   => count( $poll_seen ),
		'cycle_total'  => $last_poll['cycle_total'] ?? 0,
		'queue_count'  => count( (array) get_option( 'dc_gi_queue', [] ) ),
	];
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_ajax_poll_debug(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	global $wpdb;

	$read = function( string $key ) use ( $wpdb ): mixed {
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$key
		) );
		return $raw !== null ? maybe_unserialize( $raw ) : '(not found)';
	};

	$next_poll = wp_next_scheduled( DC_GI_POLL_HOOK );
	$next_queue = wp_next_scheduled( DC_GI_CRON_HOOK );
	$next_watch = wp_next_scheduled( DC_GI_WATCH_HOOK );
	$last_poll_raw = $read( '_transient_dc_gi_last_poll' );
	$last_poll_age = ( is_array( $last_poll_raw ) && isset( $last_poll_raw['time'] ) )
		? ( time() - $last_poll_raw['time'] ) . 's ago'
		: 'never';
	wp_send_json_success( [
		'── Poll state ──'    => '──────────────────────',
		'poll_active'         => $read( 'dc_gi_poll_active' ),
		'poll_lock_set'       => $read( '_transient_dc_gi_poll_lock' ) !== '(not found)',
		'poll_seen_count'     => count( (array) $read( 'dc_gi_poll_seen' ) ),
		'── Last batch ──'    => '──────────────────────',
		'last_poll_age'       => $last_poll_age,
		'last_poll'           => $last_poll_raw,
		'── Cron schedule ──' => '──────────────────────',
		'poll_hook'           => DC_GI_POLL_HOOK,
		'poll_next_in_sec'    => $next_poll ? ( $next_poll - time() ) : '⚠ NOT SCHEDULED — click Fix Schedule',
		'queue_hook_next_sec' => $next_queue ? ( $next_queue - time() ) : 'not scheduled',
		'watch_hook_next_sec' => $next_watch ? ( $next_watch - time() ) : 'not scheduled',
		'── Queue ──'         => '──────────────────────',
		'queue_count'         => count( (array) $read( 'dc_gi_queue' ) ),
		'── Server ──'        => '──────────────────────',
		'server_time'         => gmdate( 'Y-m-d H:i:s', time() ) . ' UTC',
		'php_max_execution'   => ini_get( 'max_execution_time' ),
	] );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_ajax_poll_fix_cron(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	if ( ! wp_next_scheduled( DC_GI_POLL_HOOK ) ) {
		wp_schedule_event( time(), 'dc_gi_every1', DC_GI_POLL_HOOK );
		wp_send_json_success( 'Cron scheduled — next run in ~1 minute.' );
	} else {
		wp_send_json_success( 'Cron was already scheduled.' );
	}
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_ajax_poll_trigger(): void {
	check_ajax_referer( 'dc_gi_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}
	// Force-clear the lock so a stuck transient can't block the manual trigger.
	$lock_was_set = (bool) get_transient( 'dc_gi_poll_lock' );
	delete_transient( 'dc_gi_poll_lock' );
	wp_cache_delete( '_transient_dc_gi_poll_lock', 'options' );
	// Ensure active flag is written to DB.
	dc_gi_set_poll_active( true );

	$before       = get_transient( 'dc_gi_last_poll' );
	$before_time  = is_array( $before ) ? ( $before['time'] ?? 0 ) : 0;
	$batch_status = dc_gi_run_poll_batch( true );
	$after        = get_transient( 'dc_gi_last_poll' );
	$after_time   = is_array( $after ) ? ( $after['time'] ?? 0 ) : 0;
	wp_send_json_success( [
		'batch_status'   => $batch_status,
		'lock_was_stuck' => $lock_was_set,
		'batch_ran'      => $after_time > $before_time,
		'before_time'    => $before_time,
		'after_time'     => $after_time,
		'result'         => $after,
	] );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_gi_handle_poll_reset(): void {
	check_admin_referer( 'dc_gi_poll_reset' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Forbidden', 'dc-google-indexing' ) );
	}

	// Clear the seen-URL cursor so the next batch starts from URL 0.
	delete_option( 'dc_gi_poll_seen' );
	wp_cache_delete( 'dc_gi_poll_seen', 'options' );

	// Clear the last-poll transient so the UI resets to 0/N and old
	// cumulative counts (cycle_inspected, cycle_done, etc.) don't carry over.
	delete_transient( 'dc_gi_last_poll' );
	// Also nuke the underlying option row that WP uses for transients on some hosts.
	delete_option( '_transient_dc_gi_last_poll' );
	delete_option( '_transient_timeout_dc_gi_last_poll' );
	wp_cache_delete( 'dc_gi_last_poll', 'transient' );

	wp_safe_redirect( add_query_arg(
		[ 'page' => 'dc-google-indexing', 'tab' => 'polling', 'notice' => 'poll_reset' ],
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
	$queued_count = absint( isset( $_GET['count'] ) ? wp_unslash( $_GET['count'] ) : 0 );

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
		'poll_no_sitemap' => [ 'error',   esc_html( $errmsg ) ?: __( 'No sitemap found. Ensure your site has a public XML sitemap.', 'dc-google-indexing' ) ],
		'poll_error'      => [ 'error',   esc_html( $errmsg ) ?: __( 'Polling failed.', 'dc-google-indexing' ) ],
		'watch_cleared'   => [ 'success', __( 'Watchlist cleared.', 'dc-google-indexing' ) ],
		'watch_checked'   => [ 'success', __( 'Watchlist check complete.', 'dc-google-indexing' ) ],
		'cron_fixed'      => [ 'success', __( 'Watchlist auto-check schedule restored.', 'dc-google-indexing' ) ],
		'poll_reset'      => [ 'success', __( 'Poll cycle reset — next run will start from the beginning of the sitemap.', 'dc-google-indexing' ) ],
	];

	$all_post_types = get_post_types( [ 'public' => true ], 'objects' );
	?>
	<style id="dc-gi-theme">
	/* ===================================================
	   DC Google Indexing — Dark Dashboard Theme
	   =================================================== */
	.dc-gi-admin{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
	.dc-gi-page-title{display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,#161825 0%,#1e2236 100%);color:#e4e8f3;padding:18px 26px;margin:8px 0 0;border:1px solid #2d3555;border-bottom:none;border-radius:6px 6px 0 0;font-size:20px;font-weight:700;letter-spacing:-.2px}
	.dc-gi-page-title .dashicons{color:#1d8cf8;font-size:26px;line-height:1;width:26px;height:26px}
	/* Status bar */
	.dc-gi-statusbar{background:#1a1c2a;border:1px solid #2d3555;border-top:none;padding:0 24px;display:flex;flex-wrap:wrap;align-items:stretch;margin-bottom:0}
	.dc-gi-statusbar-chip{display:flex;align-items:center;gap:7px;padding:9px 20px 9px 0;margin-right:20px;font-size:12px;color:#7a8499;border-right:1px solid rgba(45,53,85,.7)}
	.dc-gi-statusbar-chip:last-child{border-right:none;padding-right:0;margin-right:0}
	.dc-gi-chip-val{color:#c8d0e0;font-weight:600}.dc-gi-chip-val.ok{color:#00f2c3}.dc-gi-chip-val.err{color:#fd5d93}
	.dc-gi-chip-val code{background:rgba(255,255,255,.08);padding:1px 6px;border-radius:3px;font-size:11px;color:#c8d0e0;border:none}
	/* Nav tabs */
	.dc-gi-admin .nav-tab-wrapper{background:#161825;border:1px solid #2d3555;border-top:none;border-bottom:2px solid #2d3555;padding:0 12px;display:flex;gap:2px;margin-bottom:0!important}
	.dc-gi-admin .nav-tab{background:transparent!important;border:none!important;box-shadow:none!important;color:#6e7a90!important;font-size:13px;font-weight:500;padding:10px 16px!important;margin:0!important;border-radius:0!important;border-bottom:2px solid transparent!important;position:relative;top:2px;transition:color .15s;outline:none!important}
	.dc-gi-admin .nav-tab:hover{color:#c8d0e0!important;background:rgba(255,255,255,.04)!important;text-decoration:none!important}
	.dc-gi-admin .nav-tab-active,.dc-gi-admin .nav-tab-active:hover{color:#1d8cf8!important;background:transparent!important;border-bottom:2px solid #1d8cf8!important;font-weight:600}
	/* Content panel */
	.dc-gi-panel{background:#1a1c2a;border:1px solid #2d3555;border-top:none;padding:24px 28px;min-height:440px}
	.dc-gi-panel h2{color:#e4e8f3;font-size:20px;font-weight:700;margin-top:0}
	.dc-gi-panel h3{color:#c8d0e0;font-size:15px;font-weight:600}
	.dc-gi-panel p{color:#8892a4;font-size:13px}
	.dc-gi-panel hr{border:none;border-top:1px solid #2d3555;margin:22px 0}
	.dc-gi-panel code{background:rgba(255,255,255,.08);padding:2px 6px;border-radius:3px;color:#9cdcfe;font-size:12px;border:none}
	.dc-gi-panel label{color:#c8d0e0!important}.dc-gi-panel .description{color:#7a8499!important}
	.dc-gi-panel a{color:#1d8cf8}
	/* Form fields */
	.dc-gi-panel input[type=text],.dc-gi-panel input[type=url],.dc-gi-panel input[type=number],.dc-gi-panel textarea,.dc-gi-panel select{background:#252a45!important;border-color:#2d3555!important;color:#c8d0e0!important}
	.dc-gi-panel input:focus,.dc-gi-panel textarea:focus{border-color:#1d8cf8!important;box-shadow:0 0 0 1px #1d8cf8!important;outline:none!important}
	/* Callouts */
	.dc-gi-callout{border-radius:6px;padding:12px 16px;margin:12px 0;font-size:13px;line-height:1.65;border-left:3px solid transparent}
	.dc-gi-callout.info{background:rgba(29,140,248,.1);border-left-color:#1d8cf8;color:#9ab8da}
	.dc-gi-callout.warn{background:rgba(255,141,114,.1);border-left-color:#ff8d72;color:#d4a898}
	.dc-gi-callout.ok{background:rgba(0,242,195,.1);border-left-color:#00f2c3;color:#7dcfb8}
	.dc-gi-callout.err{background:rgba(253,93,147,.1);border-left-color:#fd5d93;color:#e89ab0}
	.dc-gi-callout strong{color:#e4e8f3}.dc-gi-callout code{background:rgba(255,255,255,.08);padding:1px 5px;border-radius:3px;color:#c8d0e0}.dc-gi-callout a{color:#1d8cf8}
	/* Stat cards */
	.dc-gi-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:16px 0}
	.dc-gi-stat{background:#1e2236;border:1px solid #2d3555;border-radius:8px;padding:18px 20px;text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.3);transition:transform .15s,box-shadow .15s}
	.dc-gi-stat:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(0,0,0,.4)}
	.dc-gi-stat-num{font-size:32px;font-weight:700;color:#e4e8f3;line-height:1.1}
	.dc-gi-stat-label{font-size:11px;color:#7a8499;margin-top:6px;text-transform:uppercase;letter-spacing:.5px}
	.dc-gi-stat.green .dc-gi-stat-num{color:#00f2c3}.dc-gi-stat.red .dc-gi-stat-num{color:#fd5d93}.dc-gi-stat.blue .dc-gi-stat-num{color:#1d8cf8}.dc-gi-stat.amber .dc-gi-stat-num{color:#ff8d72}
	/* Badges */
	.dc-gi-wl-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600}
	.dc-gi-wl-badge.pending{background:rgba(255,141,114,.15);color:#ff8d72}.dc-gi-wl-badge.indexed{background:rgba(0,242,195,.15);color:#00f2c3}.dc-gi-wl-badge.error{background:rgba(253,93,147,.15);color:#fd5d93}
	.dc-gi-wl-badge.removal_pending{background:rgba(29,140,248,.15);color:#1d8cf8}.dc-gi-wl-badge.removed{background:rgba(200,208,224,.12);color:#8892a4}
	/* Tables */
	.dc-gi-panel .widefat{border-color:#2d3555!important;background:#1e2236!important}
	.dc-gi-panel .widefat thead th{background:#252a45!important;color:#6e7a90!important;border-bottom:1px solid #2d3555!important;font-size:11px;text-transform:uppercase;letter-spacing:.4px}
	.dc-gi-panel .widefat tbody tr{border-bottom:1px solid rgba(45,53,85,.6)!important}
	.dc-gi-panel .widefat tbody tr:hover{background:rgba(29,140,248,.05)!important}
	.dc-gi-panel .widefat tbody td{color:#c8d0e0!important;background:transparent!important;border:none!important}
	.dc-gi-panel .widefat.striped>tbody>:nth-child(odd){background:rgba(255,255,255,.025)!important}
	.dc-gi-panel .widefat a{color:#1d8cf8}
	/* Polling live panel */
	@keyframes dcGiSpin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
	.dc-gi-poll-badge{display:inline-flex;align-items:center;gap:7px;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:600}
	.dc-gi-poll-badge.running{background:rgba(0,242,195,.12);color:#00f2c3;border:1px solid rgba(0,242,195,.35)}
	.dc-gi-poll-badge.stopped{background:rgba(253,93,147,.1);color:#fd5d93;border:1px solid rgba(253,93,147,.3)}
	.dc-gi-poll-badge.done{background:rgba(0,242,195,.12);color:#00f2c3;border:1px solid rgba(0,242,195,.35)}
	.dc-gi-spinner{display:inline-block;width:10px;height:10px;border:2px solid #00f2c3;border-top-color:transparent;border-radius:50%;animation:dcGiSpin .7s linear infinite;flex-shrink:0}
	.dc-gi-live-panel{background:#1e2236;border:1px solid #2d3555;border-radius:10px;padding:22px 24px;max-width:740px;margin-bottom:20px;box-shadow:0 8px 32px rgba(0,0,0,.3)}
	.dc-gi-live-btn-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px}
	.dc-gi-btn-start{background:linear-gradient(135deg,#1d8cf8,#1263c1)!important;border-color:transparent!important;color:#fff!important;border-radius:6px!important;font-weight:600!important;padding:6px 20px!important;box-shadow:0 4px 12px rgba(29,140,248,.4)!important;transition:box-shadow .15s!important}
	.dc-gi-btn-start:hover{box-shadow:0 6px 18px rgba(29,140,248,.55)!important}
	.dc-gi-btn-stop{background:rgba(253,93,147,.1)!important;border:1px solid rgba(253,93,147,.4)!important;color:#fd5d93!important;border-radius:6px!important;font-weight:600!important;padding:6px 18px!important}
	.dc-gi-btn-secondary{background:rgba(255,255,255,.05)!important;border:1px solid #2d3555!important;color:#6e7a90!important;border-radius:6px!important;padding:5px 14px!important;font-size:12px!important}
	.dc-gi-btn-secondary:hover{color:#c8d0e0!important;background:rgba(255,255,255,.09)!important}
	#dc-gi-prog-track{background:rgba(255,255,255,.07);border-radius:6px;height:8px;margin:6px 0 4px;overflow:hidden}
	#dc-gi-prog-bar{height:100%;width:0;background:linear-gradient(90deg,#1d8cf8,#00f2c3);border-radius:6px;transition:width .5s cubic-bezier(.4,0,.2,1)}
	/* Live stats grid */
	.dc-gi-live-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px}
	.dc-gi-live-stat{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:7px;padding:14px;text-align:center}
	.dc-gi-live-stat-num{font-size:24px;font-weight:700;color:#e4e8f3;line-height:1}
	.dc-gi-live-stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#7a8499;margin-top:5px}
	.dc-gi-live-stat.green .dc-gi-live-stat-num{color:#00f2c3}.dc-gi-live-stat.amber .dc-gi-live-stat-num{color:#ff8d72}.dc-gi-live-stat.red .dc-gi-live-stat-num{color:#fd5d93}
	/* Info grid */
	.dc-gi-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:860px;margin:0 0 24px}
	/* Filter list */
	.dc-gi-filter-list{list-style:none;margin:0;padding:0;columns:2}
	.dc-gi-filter-list li{padding:4px 0 4px 20px;position:relative;font-size:13px;color:#8892a4}
	.dc-gi-filter-list li::before{content:'\2717';position:absolute;left:0;color:#fd5d93;font-size:12px;top:5px}
	.dc-gi-filter-list li.ok::before{content:'\2713';color:#00f2c3}
	/* Getting Started */
	.dc-gi-guide{max-width:780px}.dc-gi-guide .dc-gi-intro{font-size:14px;color:#8892a4;margin-bottom:28px;line-height:1.7}
	.dc-gi-progress{display:flex;align-items:center;margin-bottom:32px}
	.dc-gi-progress-step{display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;position:relative}
	.dc-gi-progress-step:not(:last-child)::after{content:'';position:absolute;top:15px;left:55%;width:90%;height:2px;background:#2d3555;z-index:0}
	.dc-gi-progress-step.done:not(:last-child)::after{background:#00f2c3}
	.dc-gi-progress-dot{width:30px;height:30px;border-radius:50%;background:#252a45;color:#6e7a90;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;position:relative;z-index:1}
	.dc-gi-progress-step.done .dc-gi-progress-dot{background:#00f2c3;color:#1a1c2a}
	.dc-gi-progress-step.active .dc-gi-progress-dot{background:#1d8cf8;color:#fff;box-shadow:0 0 0 3px rgba(29,140,248,.25)}
	.dc-gi-progress-label{font-size:11px;color:#6e7a90;text-align:center;max-width:80px;line-height:1.3}
	.dc-gi-progress-step.done .dc-gi-progress-label{color:#00f2c3}.dc-gi-progress-step.active .dc-gi-progress-label{color:#1d8cf8;font-weight:600}
	.dc-gi-step-card{border:1px solid #2d3555;border-radius:8px;margin-bottom:14px;overflow:hidden}
	.dc-gi-step-card.dc-gi-done{border-color:rgba(0,242,195,.35)}
	.dc-gi-step-header{display:flex;align-items:center;gap:14px;padding:14px 18px;background:rgba(255,255,255,.03);cursor:pointer;user-select:none}
	.dc-gi-step-card.dc-gi-done .dc-gi-step-header{background:rgba(0,242,195,.04)}
	.dc-gi-step-icon{flex-shrink:0;width:34px;height:34px;border-radius:50%;background:rgba(29,140,248,.15);color:#1d8cf8;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700}
	.dc-gi-step-card.dc-gi-done .dc-gi-step-icon{background:rgba(0,242,195,.15);color:#00f2c3}
	.dc-gi-step-title{font-size:15px;font-weight:600;margin:0;flex:1;color:#c8d0e0}
	.dc-gi-step-status{font-size:12px;font-weight:600;padding:3px 10px;border-radius:12px;background:rgba(255,141,114,.15);color:#ff8d72}
	.dc-gi-step-card.dc-gi-done .dc-gi-step-status{background:rgba(0,242,195,.15);color:#00f2c3}
	.dc-gi-step-toggle{color:#6e7a90;font-size:18px;flex-shrink:0}
	.dc-gi-step-body{padding:18px 22px;border-top:1px solid #2d3555;background:rgba(255,255,255,.01)}
	.dc-gi-step-body[hidden]{display:none}
	.dc-gi-substep{display:flex;gap:12px;margin-bottom:18px;align-items:flex-start}
	.dc-gi-substep-num{flex-shrink:0;width:24px;height:24px;border-radius:50%;background:#1d8cf8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;margin-top:1px}
	.dc-gi-substep-content p{margin:0 0 6px;color:#8892a4}.dc-gi-substep-content strong{color:#c8d0e0}
	.dc-gi-check-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(45,53,85,.5)}
	.dc-gi-check-row:last-child{border-bottom:none}
	.dc-gi-check-label{flex:1;font-size:13px;color:#c8d0e0}.dc-gi-check-value{font-size:12px;color:#7a8499}
	.dc-gi-btn-row{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
	.dc-gi-json-preview{background:#0d1117;color:#9cdcfe;font-size:12px;padding:12px 16px;border-radius:6px;margin:10px 0;overflow-x:auto;line-height:1.6;border:1px solid #2d3555}
	.dc-gi-json-preview .key{color:#9cdcfe}.dc-gi-json-preview .val{color:#ce9178}.dc-gi-json-preview .type{color:#4ec9b0}
	/* WP notices */
	.dc-gi-panel .notice{background:rgba(29,140,248,.08)!important;border-left-color:#1d8cf8!important;color:#c8d0e0!important}
	.dc-gi-panel .notice p{color:#c8d0e0!important}
	/* Watchlist row FLIP animation */
	#dc-gi-wl-tbody tr{will-change:transform}
	.dc-gi-wl-flash{animation:dcGiWlFlash .9s ease-out}
	@keyframes dcGiWlFlash{0%{background:rgba(29,140,248,.22)}100%{background:transparent}}
	/* ===================================================
	   Responsive — constrain to viewport on small screens
	   =================================================== */
	.dc-gi-admin,.dc-gi-admin *,.dc-gi-admin *::before,.dc-gi-admin *::after{box-sizing:border-box}
	@media screen and (max-width:782px){
	.dc-gi-page-title{padding:14px 16px;font-size:17px}
	.dc-gi-statusbar{overflow-x:auto;flex-wrap:nowrap;padding:0 12px}
	.dc-gi-statusbar-chip{white-space:nowrap;flex-shrink:0}
	.dc-gi-admin .nav-tab-wrapper{overflow-x:auto;flex-wrap:nowrap}
	.dc-gi-admin .nav-tab{white-space:nowrap;padding:10px 12px!important;font-size:12px}
	.dc-gi-panel{padding:18px 14px}
	.dc-gi-grid-3{grid-template-columns:repeat(2,1fr)}
	.dc-gi-live-stats{grid-template-columns:repeat(2,1fr)}
	.dc-gi-info-grid{grid-template-columns:1fr}
	.dc-gi-filter-list{columns:1}
	.dc-gi-live-panel{max-width:100%!important}
	.dc-gi-progress{overflow-x:auto;padding-bottom:6px}
	.dc-gi-panel .widefat{display:block;overflow-x:auto}
	}
	@media screen and (max-width:600px){
	.dc-gi-page-title{padding:11px 12px;font-size:15px;gap:8px}
	.dc-gi-page-title .dashicons{font-size:22px;width:22px;height:22px}
	.dc-gi-panel{padding:14px 10px;min-height:unset}
	.dc-gi-grid-3{grid-template-columns:1fr}
	.dc-gi-live-stats{gap:8px}
	.dc-gi-stat-num{font-size:26px}
	.dc-gi-live-stat-num{font-size:20px}
	.dc-gi-step-header{flex-wrap:wrap}
	.dc-gi-substep{flex-direction:column;gap:8px}
	}
	</style>
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
				<span><?php esc_html_e( 'Quota today', 'dc-google-indexing' ); ?></span>
				<span class="dc-gi-chip-val"><?php echo esc_html( $quota_used . ' / ' . $quota_limit ); ?></span>
			</span>
			<span class="dc-gi-statusbar-chip">
				<span><?php esc_html_e( 'Queue', 'dc-google-indexing' ); ?></span>
				<span class="dc-gi-chip-val"><span id="dc-gi-header-queue"><?php echo esc_html( (string) count( $queue ) ); ?></span> <?php esc_html_e( 'pending', 'dc-google-indexing' ); ?></span>
			</span>
		</div>

		<!-- Tabs -->
		<nav class="nav-tab-wrapper">
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

		<div class="dc-gi-panel">

		<?php if ( 'start' === $tab ) : ?>

		<!-- ===== GETTING STARTED ===== -->

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

		<h2 style="margin-top:0"><?php esc_html_e( 'Watchlist — Index Status Tracker', 'dc-google-indexing' ); ?></h2>
		<p style="color:#555;max-width:700px">
			<?php esc_html_e( 'Every URL successfully submitted to Google is tracked here. Status is checked automatically every 6 hours via WP-Cron and updated when Google reports the page as indexed. URLs already in the Watchlist are skipped during Polling to avoid wasting inspection quota.', 'dc-google-indexing' ); ?>
		</p>

		<?php
		$watch_pending         = array_filter( $watchlist, fn( $e ) => 'pending' === $e['status'] );
		$watch_indexed         = array_filter( $watchlist, fn( $e ) => 'indexed' === $e['status'] );
		$watch_removal_pending = array_filter( $watchlist, fn( $e ) => 'removal_pending' === $e['status'] );
		$watch_removed         = array_filter( $watchlist, fn( $e ) => 'removed' === $e['status'] );
		$next_watch    = wp_next_scheduled( DC_GI_WATCH_HOOK );
		?>

		<div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
			<button id="dc-gi-watch-check-btn" class="button dc-gi-btn-start"><?php esc_html_e( '🔄 Check Now', 'dc-google-indexing' ); ?></button>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php esc_attr_e( 'Clear the entire watchlist?', 'dc-google-indexing' ); ?>')">
				<?php wp_nonce_field( 'dc_gi_watch_clr' ); ?>
				<input type="hidden" name="action" value="dc_gi_watch_clr">
				<button type="submit" class="button dc-gi-btn-secondary"><?php esc_html_e( 'Clear All', 'dc-google-indexing' ); ?></button>
			</form>
			<span class="dc-gi-wl-next">
				<?php if ( $next_watch ) : ?>
					<?php printf(
						/* translators: %s: human-readable time until next auto-check */
						esc_html__( 'Next auto-check in %s', 'dc-google-indexing' ),
						esc_html( human_time_diff( time(), $next_watch ) )
					); ?>
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
					<button id="dc-gi-watch-stop-btn" class="button dc-gi-btn-stop" style="font-size:11px;padding:3px 12px"><?php esc_html_e( '■ Stop', 'dc-google-indexing' ); ?></button>
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
				<?php foreach ( $watchlist as $entry ) :
					$badge_class = in_array( $entry['status'], [ 'pending', 'indexed', 'error', 'removal_pending', 'removed' ], true )
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

		<h2 style="margin-top:0"><?php esc_html_e( 'URL Polling — Discover Unindexed Pages', 'dc-google-indexing' ); ?></h2>

		<div class="dc-gi-info-grid">
			<div class="dc-gi-callout info">
				<strong><?php esc_html_e( 'How it works', 'dc-google-indexing' ); ?></strong><br>
				<?php esc_html_e( 'Polling fetches your XML sitemap, then calls the Google URL Inspection API for each URL to check its current index status. URLs known to Google but not yet indexed are automatically added to the submission queue.', 'dc-google-indexing' ); ?>
			</div>
			<div class="dc-gi-callout warn">
				<strong><?php esc_html_e( '⚠️ Use sparingly', 'dc-google-indexing' ); ?></strong><br>
				<?php esc_html_e( 'Each URL consumes 1 inspection (quota: 2,000/day). Qualifying URLs are then submitted against the Indexing API quota (200/day). Large sitemaps can exhaust both limits quickly.', 'dc-google-indexing' ); ?>
			</div>
		</div>

		<?php
		$php_inspected = $last_poll ? (int) ( $last_poll['cycle_inspected'] ?? $last_poll['inspected'] ?? 0 ) : 0;
		$php_queued    = $last_poll ? (int) ( $last_poll['cycle_queued']    ?? $last_poll['queued']    ?? 0 ) : 0;
		$php_skipped   = $last_poll ? (int) ( $last_poll['cycle_skipped']   ?? $last_poll['skipped']   ?? 0 ) : 0;
		$php_errors    = $last_poll ? (int) ( $last_poll['cycle_errors']    ?? $last_poll['errors']    ?? 0 ) : 0;
		$php_seen      = $last_poll ? (int) ( $last_poll['cycle_seen']  ?? 0 ) : 0;
		$php_total     = $last_poll ? (int) ( $last_poll['cycle_total'] ?? 0 ) : 0;
		$php_pct       = $php_total > 0 ? round( $php_seen / $php_total * 100 ) : 0;
		$php_done      = $last_poll && ! empty( $last_poll['cycle_done'] );
		$php_time      = $last_poll ? wp_date( 'H:i:s', $last_poll['time'] ) : '';
		?>

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



		<div class="dc-gi-live-panel">
			<div class="dc-gi-live-btn-row">
				<span id="dc-gi-status-badge" class="dc-gi-poll-badge stopped"><span class="dc-gi-badge-text"><?php esc_html_e( '○ Stopped', 'dc-google-indexing' ); ?></span></span>
				<button id="dc-gi-start-btn" class="button dc-gi-btn-start"><?php esc_html_e( '▶ Start Polling', 'dc-google-indexing' ); ?></button>
				<button id="dc-gi-stop-btn" class="button dc-gi-btn-stop" disabled><?php esc_html_e( '■ Stop', 'dc-google-indexing' ); ?></button>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
					<?php wp_nonce_field( 'dc_gi_poll_reset' ); ?>
					<input type="hidden" name="action" value="dc_gi_poll_reset">
					<button type="submit" class="button dc-gi-btn-secondary"
						onclick="return confirm('<?php esc_attr_e( 'Reset the poll cursor? Next run will start from the beginning of the sitemap.', 'dc-google-indexing' ); ?>')"
					><?php esc_html_e( '↺ Reset Cycle', 'dc-google-indexing' ); ?></button>
				</form>
			</div>

			<div style="margin-bottom:16px">
				<div style="display:flex;justify-content:space-between;font-size:12px;color:#7a8499;margin-bottom:4px">
					<span><?php esc_html_e( 'Cycle progress', 'dc-google-indexing' ); ?></span>
					<span id="dc-gi-prog-label"><?php echo $php_total > 0 ? esc_html( $php_seen . ' / ' . $php_total . ' (' . $php_pct . '%)' ) : '&mdash;'; ?></span>
				</div>
				<div id="dc-gi-prog-track"><div id="dc-gi-prog-bar" style="width:<?php echo esc_attr( $php_pct ); ?>%<?php echo $php_done ? ';background:#00f2c3' : ''; ?>"></div></div>
			</div>

			<div id="dc-gi-last-batch"<?php echo $last_poll ? '' : ' style="display:none"'; ?>>
				<p style="font-size:11px;color:#7a8499;margin:0 0 12px">
					<?php esc_html_e( 'This cycle &mdash; last updated:', 'dc-google-indexing' ); ?>
					<strong style="color:#c8d0e0" id="dc-gi-batch-time"><?php echo esc_html( $php_time ); ?></strong>
				</p>
				<div class="dc-gi-live-stats">
					<div class="dc-gi-live-stat">
						<div class="dc-gi-live-stat-num" id="dc-gi-batch-inspected"><?php echo esc_html( (string) $php_inspected ); ?></div>
						<div class="dc-gi-live-stat-label"><?php esc_html_e( 'Inspected', 'dc-google-indexing' ); ?></div>
					</div>
					<div class="dc-gi-live-stat green">
						<div class="dc-gi-live-stat-num" id="dc-gi-batch-queued"><?php echo esc_html( (string) $php_queued ); ?></div>
						<div class="dc-gi-live-stat-label"><?php esc_html_e( 'Added to queue', 'dc-google-indexing' ); ?></div>
					</div>
					<div class="dc-gi-live-stat amber">
						<div class="dc-gi-live-stat-num" id="dc-gi-batch-skipped"><?php echo esc_html( (string) $php_skipped ); ?></div>
						<div class="dc-gi-live-stat-label"><?php esc_html_e( 'Filtered out', 'dc-google-indexing' ); ?></div>
					</div>
					<div class="dc-gi-live-stat<?php echo $php_errors > 0 ? ' red' : ''; ?>">
						<div class="dc-gi-live-stat-num" id="dc-gi-batch-errors"<?php echo $php_errors > 0 ? ' style="color:#fd5d93"' : ''; ?>><?php echo esc_html( (string) $php_errors ); ?></div>
						<div class="dc-gi-live-stat-label"><?php esc_html_e( 'Errors', 'dc-google-indexing' ); ?></div>
					</div>
				</div>
			</div>

			<p style="font-size:11px;color:#7a8499;margin:16px 0 0;padding-top:14px;border-top:1px solid rgba(45,53,85,.5)">
				<?php esc_html_e( '1 URL per batch · runs every 1 minute via WP-Cron · continues if you leave this page', 'dc-google-indexing' ); ?>&ensp;|&ensp;<?php esc_html_e( 'Queue:', 'dc-google-indexing' ); ?> <strong style="color:#c8d0e0" id="dc-gi-queue-count">—</strong>
			</p>
		</div>

		<?php /* DEBUG SECTION — uncomment to re-enable
		<details style="max-width:680px;margin-bottom:20px" open>
			<summary style="cursor:pointer;font-size:12px;color:#888;font-weight:600"><?php esc_html_e( '🛠 Debug / Diagnostics', 'dc-google-indexing' ); ?><\/summary>
			<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
				<button id="dc-gi-debug-btn" class="button button-small"><?php esc_html_e( '↻ Refresh DB snapshot', 'dc-google-indexing' ); ?><\/button>
				<button id="dc-gi-fix-cron-btn" class="button button-small" style="color:#856404;border-color:#856404"><?php esc_html_e( '↺ Fix Cron Schedule', 'dc-google-indexing' ); ?><\/button>
				<button id="dc-gi-trigger-btn" class="button button-small" style="color:#0a3622;border-color:#0a3622"><?php esc_html_e( '🧪 Test One Batch Now', 'dc-google-indexing' ); ?><\/button>
			<\/div>
			<p style="font-size:11px;color:#888;margin:6px 0 4px">If <code>poll_next_in_sec<\/code> says <strong>NOT SCHEDULED<\/strong>, click <em>Fix Cron Schedule<\/em>. Use <em>Test One Batch Now<\/em> to verify a single batch runs correctly without starting the full polling loop.<\/p>
			<pre id="dc-gi-debug-out" style="background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;font-size:11px;margin-top:4px;max-height:320px;overflow:auto">Click Refresh to load…<\/pre>
		<\/details>
		DEBUG SECTION END */ ?>

		<?php endif; ?>

		<hr style="margin:24px 0 20px">

		<h3 style="margin-bottom:8px"><?php esc_html_e( 'Filter Logic — What Gets Submitted vs. Skipped', 'dc-google-indexing' ); ?></h3>
		<p style="color:#555;font-size:13px;margin-top:0"><?php esc_html_e( 'Only URLs with these Google coverage states are queued for submission:', 'dc-google-indexing' ); ?></p>
		<ul class="dc-gi-filter-list">
			<li class="ok"><?php esc_html_e( 'Crawled — currently not indexed', 'dc-google-indexing' ); ?></li>
			<li class="ok"><?php esc_html_e( 'Discovered — currently not indexed', 'dc-google-indexing' ); ?></li>
			<li class="ok"><?php esc_html_e( 'URL is unknown to Google (never seen — submission triggers discovery)', 'dc-google-indexing' ); ?></li>
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

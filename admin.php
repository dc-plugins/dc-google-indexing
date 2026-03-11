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

add_action( 'admin_post_dc_gi_save',      'dc_gi_handle_save' );
add_action( 'admin_post_dc_gi_test',      'dc_gi_handle_test' );
add_action( 'admin_post_dc_gi_submit',    'dc_gi_handle_submit' );
add_action( 'admin_post_dc_gi_runnow',    'dc_gi_handle_runnow' );
add_action( 'admin_post_dc_gi_clrqueue',  'dc_gi_handle_clear_queue' );
add_action( 'admin_post_dc_gi_clrlog',    'dc_gi_handle_clear_log' );

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
	$quota_used  = dc_gi_get_quota_used();
	$quota_limit = min( 200, (int) ( $settings['daily_quota'] ?? 200 ) );
	$has_sa      = ! empty( $settings['service_account_json'] );
	$sa_email    = '';
	if ( $has_sa ) {
		$sa_decoded = json_decode( $settings['service_account_json'], true );
		$sa_email   = $sa_decoded['client_email'] ?? '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice_key = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$errmsg = isset( $_GET['errmsg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['errmsg'] ) ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$queued_count = absint( isset( $_GET['count'] ) ? wp_unslash( $_GET['count'] ) : 0 );

	$notices = [
		'saved'         => [ 'success', __( 'Settings saved.', 'dc-google-indexing' ) ],
		'invalid_json'  => [ 'error',   __( 'Invalid JSON — ensure it contains client_email and private_key.', 'dc-google-indexing' ) ],
		'queued'        => [ 'success', sprintf(
			/* translators: %d: number of URLs added to queue */
			__( '%d URL(s) added to queue.', 'dc-google-indexing' ),
			$queued_count
		) ],
		'processed'     => [ 'success', __( 'Queue processed.', 'dc-google-indexing' ) ],
		'queue_cleared' => [ 'success', __( 'Queue cleared.', 'dc-google-indexing' ) ],
		'log_cleared'   => [ 'success', __( 'Log cleared.', 'dc-google-indexing' ) ],
		'test_ok'       => [ 'success', __( '&#10003; Connection successful — credentials are valid.', 'dc-google-indexing' ) ],
		'test_fail'     => [ 'error',   esc_html( $errmsg ) ?: __( 'Connection failed.', 'dc-google-indexing' ) ],
		'test_no_sa'    => [ 'error',   __( 'No service account saved. Paste your JSON and save first.', 'dc-google-indexing' ) ],
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
				'settings' => __( 'Settings', 'dc-google-indexing' ),
				'submit'   => __( 'Submit URLs', 'dc-google-indexing' ),
				'queue'    => __( 'Queue', 'dc-google-indexing' ),
				'log'      => __( 'Log', 'dc-google-indexing' ),
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

		<?php if ( 'settings' === $tab ) : ?>

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

		<h3><?php esc_html_e( 'Setup Guide', 'dc-google-indexing' ); ?></h3>
		<ol style="max-width:600px;line-height:1.8">
			<li><?php echo wp_kses_post( __( 'Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> and create or select a project.', 'dc-google-indexing' ) ); ?></li>
			<li><?php echo wp_kses_post( __( 'Enable the <strong>Web Search Indexing API</strong> for the project.', 'dc-google-indexing' ) ); ?></li>
			<li><?php esc_html_e( 'Create a Service Account, then generate a JSON key. Download it.', 'dc-google-indexing' ); ?></li>
			<li><?php echo wp_kses_post( __( 'In <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a>, add the service account email as a <strong>Full user</strong> for your property.', 'dc-google-indexing' ) ); ?></li>
			<li><?php esc_html_e( 'Paste the JSON file contents above and save.', 'dc-google-indexing' ); ?></li>
		</ol>

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
				<?php foreach ( $log as $entry ) : ?>
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
					<td><?php echo esc_html( $entry['detail'] ); ?></td>
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

<?php
/**
 * WP Admin UI for WooSafe MCP.
 *
 * Provides:
 * - 30-second Scoped Token Generator & 1-Click Copy Claude/Cursor Config JSON
 * - Safety Mode Toggle (Read-Only Default vs Guarded Write)
 * - Hard Caps (Max Price Delta %, Max Batch Items, Coupon Limits) & Per-Tool Matrix
 * - Pre-Write Snapshots Table with 1-Click Undo / Rollback
 * - Live MCP Audit Log
 *
 * Strict capability checks (manage_woocommerce) and WP nonces on every action.
 *
 * @package WooSafe_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooSafe_MCP_Admin_Page {

	private const PAGE_SLUG = 'woosafe-mcp';

	/**
	 * Register WP Admin menu and form handlers.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_woosafe_generate_token', array( __CLASS__, 'handle_generate_token' ) );
		add_action( 'admin_post_woosafe_revoke_token', array( __CLASS__, 'handle_revoke_token' ) );
		add_action( 'admin_post_woosafe_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_woosafe_rollback_snapshot', array( __CLASS__, 'handle_rollback_snapshot' ) );
	}

	/**
	 * Enqueue scoped admin CSS and JS on WooSafe MCP screen only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'woosafe-mcp-admin',
			WOOSAFE_MCP_URL . 'assets/admin.css',
			array(),
			WOOSAFE_MCP_VERSION
		);
		wp_enqueue_script(
			'woosafe-mcp-admin',
			WOOSAFE_MCP_URL . 'assets/admin.js',
			array(),
			WOOSAFE_MCP_VERSION,
			true
		);
	}

	/**
	 * Add submenu under WooCommerce.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'WooSafe MCP', 'woosafe-mcp' ),
			__( 'WooSafe MCP', 'woosafe-mcp' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle scoped Bearer token creation.
	 */
	public static function handle_generate_token(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'woosafe-mcp' ), 403 );
		}
		check_admin_referer( 'woosafe_generate_token' );

		$label = isset( $_POST['token_label'] ) ? sanitize_text_field( wp_unslash( $_POST['token_label'] ) ) : '';
		$scope = isset( $_POST['token_scope'] ) ? sanitize_key( wp_unslash( $_POST['token_scope'] ) ) : WooSafe_MCP_Auth::SCOPE_READ_ONLY;

		$created = WooSafe_MCP_Auth::generate_token( $label, $scope );
		set_transient(
			'woosafe_new_token_' . get_current_user_id(),
			array(
				'token' => $created['token'],
				'scope' => $created['record']['scope'],
				'label' => $created['record']['label'],
			),
			120
		);

		self::redirect_with_notice( 'token_created' );
	}

	/**
	 * Handle token revocation.
	 */
	public static function handle_revoke_token(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'woosafe-mcp' ), 403 );
		}
		check_admin_referer( 'woosafe_revoke_token' );

		$token_id = isset( $_POST['token_id'] ) ? sanitize_key( wp_unslash( $_POST['token_id'] ) ) : '';
		WooSafe_MCP_Auth::revoke_token( $token_id );
		self::redirect_with_notice( 'token_revoked' );
	}

	/**
	 * Handle safety mode, hard caps, and per-tool matrix save.
	 */
	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'woosafe-mcp' ), 403 );
		}
		check_admin_referer( 'woosafe_save_settings' );

		$raw_mode  = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : WooSafe_MCP_Guardrails::MODE_READ_ONLY;
		$raw_tools = isset( $_POST['allowed_write_tools'] ) && is_array( $_POST['allowed_write_tools'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['allowed_write_tools'] ) )
			: array();

		$allowed_map = array();
		foreach ( WooSafe_MCP_Guardrails::get_registered_write_tools() as $tool_name ) {
			$allowed_map[ $tool_name ] = isset( $raw_tools[ $tool_name ] ) && '1' === (string) $raw_tools[ $tool_name ];
		}

		WooSafe_MCP_Guardrails::update_settings(
			array(
				'mode'                => $raw_mode,
				'max_price_delta_pct' => isset( $_POST['max_price_delta_pct'] ) ? (float) wp_unslash( $_POST['max_price_delta_pct'] ) : 25.0,
				'max_batch_items'     => isset( $_POST['max_batch_items'] ) ? absint( wp_unslash( $_POST['max_batch_items'] ) ) : 20,
				'max_coupon_percent'  => isset( $_POST['max_coupon_percent'] ) ? (float) wp_unslash( $_POST['max_coupon_percent'] ) : 50.0,
				'max_coupon_fixed'    => isset( $_POST['max_coupon_fixed'] ) ? (float) wp_unslash( $_POST['max_coupon_fixed'] ) : 500.0,
				'allowed_write_tools' => $allowed_map,
			)
		);

		self::redirect_with_notice( 'settings_saved' );
	}

	/**
	 * Handle 1-click snapshot rollback from WP Admin.
	 */
	public static function handle_rollback_snapshot(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'woosafe-mcp' ), 403 );
		}
		check_admin_referer( 'woosafe_rollback_snapshot' );

		$uuid   = isset( $_POST['snapshot_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot_uuid'] ) ) : '';
		$result = WooSafe_MCP_Snapshots::rollback_snapshot( $uuid, get_current_user_id() );

		if ( ! empty( $result['ok'] ) ) {
			self::redirect_with_notice( 'rollback_ok' );
		}
		self::redirect_with_notice( 'rollback_err' );
	}

	/**
	 * Render WooSafe MCP Admin dashboard.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'woosafe-mcp' ), 403 );
		}

		$settings     = WooSafe_MCP_Guardrails::get_settings();
		$tokens       = WooSafe_MCP_Auth::get_tokens();
		$snapshots    = WooSafe_MCP_Snapshots::get_recent_snapshots( 20 );
		$audit_logs   = WooSafe_MCP_Snapshots::get_recent_audit_logs( 30 );
		$endpoint_url = rest_url( WooSafe_MCP_Endpoint::REST_NAMESPACE . WooSafe_MCP_Endpoint::REST_ROUTE );
		$is_read_only = ( WooSafe_MCP_Guardrails::MODE_READ_ONLY === $settings['mode'] );
		$hpos_active  = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$transient_key = 'woosafe_new_token_' . get_current_user_id();
		$new_token_box = get_transient( $transient_key );
		if ( is_array( $new_token_box ) ) {
			delete_transient( $transient_key );
		} else {
			$new_token_box = null;
		}

		$notice_key   = 'woosafe_mcp_notice_' . get_current_user_id();
		$notice_raw   = get_transient( $notice_key );
		$notice       = is_string( $notice_raw ) ? sanitize_key( $notice_raw ) : '';
		if ( '' !== $notice ) {
			delete_transient( $notice_key );
		}
		$sample_token = $new_token_box ? (string) $new_token_box['token'] : 'YOUR_WOOSAFE_BEARER_TOKEN';

		$cursor_config = wp_json_encode(
			array(
				'mcpServers' => array(
					'woosafe-woocommerce' => array(
						'url'     => $endpoint_url,
						'headers' => array(
							'Authorization' => 'Bearer ' . $sample_token,
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		$claude_config = wp_json_encode(
			array(
				'mcpServers' => array(
					'woosafe-woocommerce' => array(
						'command' => 'npx',
						'args'    => array(
							'-y',
							'mcp-remote',
							$endpoint_url,
							'--header',
							'Authorization: Bearer ${WOOSAFE_MCP_TOKEN}',
						),
						'env'     => array(
							'WOOSAFE_MCP_TOKEN' => $sample_token,
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		?>
		<div class="wrap woosafe-wrap">
			<?php if ( 'token_created' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Scoped Bearer token generated. Copy your MCP config below — the raw token is shown once.', 'woosafe-mcp' ); ?></p></div>
			<?php elseif ( 'token_revoked' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Token revoked immediately.', 'woosafe-mcp' ); ?></p></div>
			<?php elseif ( 'settings_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'WooSafe MCP guardrail settings saved.', 'woosafe-mcp' ); ?></p></div>
			<?php elseif ( 'rollback_ok' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Snapshot rolled back cleanly. Pre-write WooCommerce state restored.', 'woosafe-mcp' ); ?></p></div>
			<?php elseif ( 'rollback_err' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not roll back snapshot. Check the audit log for details.', 'woosafe-mcp' ); ?></p></div>
			<?php endif; ?>

			<div class="woosafe-hero">
				<div>
					<h1><?php esc_html_e( 'WooSafe MCP — Guardrailed WooCommerce MCP Server', 'woosafe-mcp' ); ?></h1>
					<p>
						<?php esc_html_e( 'Streamable HTTP JSON-RPC 2.0 Endpoint:', 'woosafe-mcp' ); ?>
						<code><?php echo esc_html( $endpoint_url ); ?></code>
					</p>
				</div>
				<div class="woosafe-badges">
					<span class="woosafe-pill <?php echo $is_read_only ? 'woosafe-pill-ro' : 'woosafe-pill-rw'; ?>">
						<?php echo $is_read_only ? esc_html__( 'READ-ONLY MODE LOCKED (SAFE DEFAULT)', 'woosafe-mcp' ) : esc_html__( 'GUARDED WRITE MODE (DRY-RUN + SNAPSHOT)', 'woosafe-mcp' ); ?>
					</span>
					<span class="woosafe-pill woosafe-pill-hpos">
						<?php echo $hpos_active ? esc_html__( 'HPOS Active & Compatible', 'woosafe-mcp' ) : esc_html__( 'HPOS Declared Compatible', 'woosafe-mcp' ); ?>
					</span>
				</div>
			</div>

			<div class="woosafe-grid">
				<div class="woosafe-card">
					<h2><?php esc_html_e( '1. 30-Second Setup — Scoped Bearer Tokens', 'woosafe-mcp' ); ?></h2>

					<?php if ( $new_token_box ) : ?>
						<div class="woosafe-token-reveal">
							<strong><?php esc_html_e( 'Copy this token now (it will not be displayed again):', 'woosafe-mcp' ); ?></strong>
							<code><?php echo esc_html( (string) $new_token_box['token'] ); ?></code>
						</div>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px;">
						<input type="hidden" name="action" value="woosafe_generate_token" />
						<?php wp_nonce_field( 'woosafe_generate_token' ); ?>
						<div>
							<label for="woosafe-token-label"><strong><?php esc_html_e( 'Client Label', 'woosafe-mcp' ); ?></strong></label><br />
							<input id="woosafe-token-label" type="text" name="token_label" class="regular-text" placeholder="Claude Desktop — Main Store" style="width:220px;" />
						</div>
						<button type="submit" name="token_scope" value="read_only" class="button button-primary">
							<?php esc_html_e( 'Generate Read-Only Token', 'woosafe-mcp' ); ?>
						</button>
						<button type="submit" name="token_scope" value="read_write" class="button button-secondary">
							<?php esc_html_e( 'Generate Read-Write Token', 'woosafe-mcp' ); ?>
						</button>
					</form>

					<div class="woosafe-tabs">
						<button type="button" class="button woosafe-tab-btn is-active" data-config-target="cursor"><?php esc_html_e( 'Cursor (mcp.json)', 'woosafe-mcp' ); ?></button>
						<button type="button" class="button woosafe-tab-btn" data-config-target="claude"><?php esc_html_e( 'Claude Desktop', 'woosafe-mcp' ); ?></button>
						<button type="button" class="button button-primary" id="woosafe-copy-config"><?php esc_html_e( 'Copy Claude / Cursor Config JSON', 'woosafe-mcp' ); ?></button>
					</div>

					<pre class="woosafe-code-box" id="woosafe-config-cursor"><?php echo esc_html( $cursor_config ); ?></pre>
					<pre class="woosafe-code-box" id="woosafe-config-claude" style="display:none;"><?php echo esc_html( $claude_config ); ?></pre>
					<span id="woosafe-copy-feedback" class="description" style="color:#059669; font-weight:600;"></span>

					<h3 style="margin-top:18px;"><?php esc_html_e( 'Issued Tokens', 'woosafe-mcp' ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Label', 'woosafe-mcp' ); ?></th>
								<th><?php esc_html_e( 'Scope', 'woosafe-mcp' ); ?></th>
								<th><?php esc_html_e( 'Token Mask', 'woosafe-mcp' ); ?></th>
								<th><?php esc_html_e( 'Action', 'woosafe-mcp' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $tokens ) ) : ?>
								<tr><td colspan="4"><?php esc_html_e( 'No tokens issued yet. Click "Generate Read-Only Token" above.', 'woosafe-mcp' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( array_reverse( $tokens ) as $tok ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $tok['label'] ?? '' ) ); ?></td>
										<td><code><?php echo esc_html( (string) ( $tok['scope'] ?? '' ) ); ?></code></td>
										<td><code><?php echo esc_html( (string) ( $tok['masked'] ?? '' ) ); ?></code></td>
										<td>
											<?php if ( ! empty( $tok['revoked'] ) ) : ?>
												<span class="woosafe-status-muted"><?php esc_html_e( 'Revoked', 'woosafe-mcp' ); ?></span>
											<?php else : ?>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
													<input type="hidden" name="action" value="woosafe_revoke_token" />
													<input type="hidden" name="token_id" value="<?php echo esc_attr( (string) $tok['id'] ); ?>" />
													<?php wp_nonce_field( 'woosafe_revoke_token' ); ?>
													<button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'woosafe-mcp' ); ?></button>
												</form>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<div class="woosafe-card">
					<h2><?php esc_html_e( '2. Safety Mode, Hard Caps & Permission Matrix', 'woosafe-mcp' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="woosafe_save_settings" />
						<?php wp_nonce_field( 'woosafe_save_settings' ); ?>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Safety Mode', 'woosafe-mcp' ); ?></th>
								<td>
									<label style="display:block; margin-bottom:6px;">
										<input type="radio" name="mode" value="read_only" <?php checked( $settings['mode'], 'read_only' ); ?> />
										<strong><?php esc_html_e( 'Read-Only Audit Mode (Default)', 'woosafe-mcp' ); ?></strong> — <?php esc_html_e( 'All write tools hard-blocked.', 'woosafe-mcp' ); ?>
									</label>
									<label style="display:block;">
										<input type="radio" name="mode" value="guarded_write" <?php checked( $settings['mode'], 'guarded_write' ); ?> />
										<strong><?php esc_html_e( 'Guarded Write Mode', 'woosafe-mcp' ); ?></strong> — <?php esc_html_e( 'Allows checked write tools below (dry_run: true default + snapshot).', 'woosafe-mcp' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="max_price_delta_pct"><?php esc_html_e( 'Max Price Delta (%)', 'woosafe-mcp' ); ?></label></th>
								<td>
									<input id="max_price_delta_pct" type="number" step="1" min="1" max="100" name="max_price_delta_pct" value="<?php echo esc_attr( (string) $settings['max_price_delta_pct'] ); ?>" class="small-text" /> %
									<p class="description"><?php esc_html_e( 'Hard cap rejecting any single AI price change larger than +/- this percentage (default 25%).', 'woosafe-mcp' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="max_batch_items"><?php esc_html_e( 'Max Products Per Batch', 'woosafe-mcp' ); ?></label></th>
								<td>
									<input id="max_batch_items" type="number" step="1" min="1" max="100" name="max_batch_items" value="<?php echo esc_attr( (string) $settings['max_batch_items'] ); ?>" class="small-text" />
									<p class="description"><?php esc_html_e( 'Hard cap limiting how many products a single AI call may touch (default 20).', 'woosafe-mcp' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Coupon Ceilings', 'woosafe-mcp' ); ?></th>
								<td>
									<label><?php esc_html_e( 'Max %:', 'woosafe-mcp' ); ?> <input type="number" min="1" max="100" name="max_coupon_percent" value="<?php echo esc_attr( (string) $settings['max_coupon_percent'] ); ?>" class="small-text" /></label>
									&nbsp;
									<label><?php esc_html_e( 'Max Fixed ($):', 'woosafe-mcp' ); ?> <input type="number" min="1" max="5000" name="max_coupon_fixed" value="<?php echo esc_attr( (string) $settings['max_coupon_fixed'] ); ?>" class="small-text" /></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Guarded Write Matrix', 'woosafe-mcp' ); ?></th>
								<td>
									<?php $registered_write_tools = WooSafe_MCP_Guardrails::get_registered_write_tools(); ?>
									<?php if ( empty( $registered_write_tools ) ) : ?>
										<p><strong><?php esc_html_e( 'Read-Only Core Active', 'woosafe-mcp' ); ?></strong> — <?php esc_html_e( 'No write tools are installed. Your store is 100% read-only for MCP clients.', 'woosafe-mcp' ); ?></p>
										<p class="description">
											<?php esc_html_e( 'Need guarded writes with Dry-Run Diff previews and 1-Click Rollback snapshots?', 'woosafe-mcp' ); ?>
											<a href="https://woosafe-mcp.kittenlabs-hq.workers.dev/#pricing" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Explore WooSafe MCP Pro', 'woosafe-mcp' ); ?> &rarr;</a>
										</p>
									<?php else : ?>
										<?php foreach ( $registered_write_tools as $wtool ) : ?>
											<label style="display:block; margin-bottom:6px;">
												<input type="checkbox" name="allowed_write_tools[<?php echo esc_attr( $wtool ); ?>]" value="1" <?php checked( ! empty( $settings['allowed_write_tools'][ $wtool ] ) ); ?> />
												<code><?php echo esc_html( $wtool ); ?></code>
											</label>
										<?php endforeach; ?>
									<?php endif; ?>
									<p class="description"><?php esc_html_e( 'All 6 Free Read-Only Audit tools (store_health_audit, low_stock_report, order_summary, coupon_audit, broken_sku_finder, product_seo_audit) are always available.', 'woosafe-mcp' ); ?></p>
								</td>
							</tr>
						</table>

						<?php submit_button( __( 'Save Guardrail Settings', 'woosafe-mcp' ) ); ?>
					</form>
				</div>
			</div>

			<div class="woosafe-card">
				<h2><?php esc_html_e( '3. Pre-Write Snapshots & 1-Click Rollback', 'woosafe-mcp' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Timestamp (UTC)', 'woosafe-mcp' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'woosafe-mcp' ); ?></th>
							<th><?php esc_html_e( 'Summary', 'woosafe-mcp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'woosafe-mcp' ); ?></th>
							<th><?php esc_html_e( 'Rollback', 'woosafe-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $snapshots ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No committed write snapshots yet.', 'woosafe-mcp' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $snapshots as $snap ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $snap->created_at ); ?></code></td>
									<td><code><?php echo esc_html( (string) $snap->tool_name ); ?></code></td>
									<td><?php echo esc_html( (string) $snap->summary ); ?></td>
									<td><code><?php echo esc_html( (string) $snap->status ); ?></code></td>
									<td>
										<?php if ( 'committed' === $snap->status ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Restore WooCommerce items in this snapshot to their exact pre-write state?');">
												<input type="hidden" name="action" value="woosafe_rollback_snapshot" />
												<input type="hidden" name="snapshot_uuid" value="<?php echo esc_attr( (string) $snap->snapshot_uuid ); ?>" />
												<?php wp_nonce_field( 'woosafe_rollback_snapshot' ); ?>
												<button type="submit" class="button woosafe-btn-rollback"><?php esc_html_e( 'Undo / Rollback', 'woosafe-mcp' ); ?></button>
											</form>
										<?php else : ?>
											<span class="woosafe-status-muted"><?php echo esc_html( (string) ( $snap->rolled_back_at ?: $snap->status ) ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="woosafe-card">
				<h2><?php esc_html_e( '4. Live MCP Audit Log', 'woosafe-mcp' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Timestamp (UTC)', 'woosafe-mcp' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'woosafe-mcp' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'woosafe-mcp' ); ?></th>
							<th><?php esc_html_e( 'Decision', 'woosafe-mcp' ); ?></th>
							<th><?php esc_html_e( 'Reason / Summary', 'woosafe-mcp' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'woosafe-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $audit_logs ) ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'No MCP requests recorded yet.', 'woosafe-mcp' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $audit_logs as $log ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $log->created_at ); ?></code></td>
									<td><code><?php echo esc_html( (string) $log->tool_name ); ?></code></td>
									<td><code><?php echo esc_html( (string) $log->mode ); ?></code></td>
									<td class="<?php echo 'allowed' === $log->decision ? 'woosafe-status-ok' : 'woosafe-status-denied'; ?>">
										<?php echo esc_html( strtoupper( (string) $log->decision ) ); ?>
									</td>
									<td>
										<strong><?php echo esc_html( (string) $log->reason ); ?></strong>
										<?php if ( '' !== (string) $log->summary ) : ?>
											— <?php echo esc_html( (string) $log->summary ); ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( (string) $log->duration_ms ); ?> ms</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Redirect back to WooSafe MCP page with status notice query param.
	 */
	private static function redirect_with_notice( string $notice ): void {
		set_transient( 'woosafe_mcp_notice_' . get_current_user_id(), sanitize_key( $notice ), 60 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

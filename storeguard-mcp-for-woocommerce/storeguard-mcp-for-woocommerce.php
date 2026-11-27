<?php
/**
 * Plugin Name: StoreGuard MCP for WooCommerce
 * Plugin URI: https://storeguard-mcp.kittenlabs-hq.workers.dev/
 * Description: Safety-first Read-Only MCP Server for WooCommerce HPOS. Connects Claude Desktop & Cursor in 30 seconds with 6 free audit tools and extensible write guardrails.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 * Requires Plugins: woocommerce
 * Author: KittenLabs
 * Author URI: https://github.com/yefanTao
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: storeguard-mcp-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STOREGUARD_MCP_VERSION', '1.0.0' );
define( 'STOREGUARD_MCP_PROTOCOL_VERSION', '2025-03-26' );
define( 'STOREGUARD_MCP_FILE', __FILE__ );
define( 'STOREGUARD_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'STOREGUARD_MCP_URL', plugin_dir_url( __FILE__ ) );

require_once STOREGUARD_MCP_DIR . 'includes/class-auth.php';
require_once STOREGUARD_MCP_DIR . 'includes/class-snapshots.php';
require_once STOREGUARD_MCP_DIR . 'includes/class-guardrails.php';
require_once STOREGUARD_MCP_DIR . 'includes/class-tools-read.php';
require_once STOREGUARD_MCP_DIR . 'includes/class-mcp-endpoint.php';
require_once STOREGUARD_MCP_DIR . 'admin/class-admin-page.php';

/**
 * Declare WooCommerce High-Performance Order Storage (HPOS) compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				STOREGUARD_MCP_FILE,
				true
			);
		}
	}
);

/**
 * Main plugin bootstrap.
 */
final class StoreGuard_MCP_Plugin {

	/**
	 * Plugin activation hook: create DB tables and initialize safe Read-Only defaults.
	 */
	public static function activate(): void {
		StoreGuard_MCP_Snapshots::create_tables();
		StoreGuard_MCP_Guardrails::ensure_defaults();
		if ( false === get_option( StoreGuard_MCP_Auth::OPTION_TOKENS, false ) ) {
			add_option( StoreGuard_MCP_Auth::OPTION_TOKENS, array(), '', false );
		}
	}

	/**
	 * Initialize runtime hooks once plugins have loaded.
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_woocommerce_missing_notice' ) );
			return;
		}

		add_action( 'rest_api_init', array( StoreGuard_MCP_Endpoint::class, 'register_routes' ) );

		if ( is_admin() ) {
			StoreGuard_MCP_Admin_Page::init();
		}

		/**
		 * Fires after StoreGuard MCP Core is initialized.
		 */
		do_action( 'storeguard_mcp_loaded' );
	}

	/**
	 * Show non-fatal admin notice if WooCommerce is inactive.
	 */
	public static function render_woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'StoreGuard MCP requires WooCommerce 8.0+ to be installed and active.', 'storeguard-mcp-for-woocommerce' );
		echo '</p></div>';
	}
}

register_activation_hook( STOREGUARD_MCP_FILE, array( StoreGuard_MCP_Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( StoreGuard_MCP_Plugin::class, 'init' ), 10 );

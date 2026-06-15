<?php
/**
 * Plugin Name:       KaliCart MCP
 * Plugin URI:        https://mcp.kalicart.com
 * Description:       Agent-callable WordPress content and tools.
 * Version:           0.2.1
 * Author:            KaliCart
 * Author URI:        https://kalicart.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kalicart-mcp
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'KALICART_MCP_VERSION', '0.2.1' );
define( 'KALICART_MCP_FILE',    __FILE__ );
define( 'KALICART_MCP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'KALICART_MCP_URL',     plugin_dir_url( __FILE__ ) );
define( 'KALICART_MCP_API_NS',  'kalicart-mcp/v1' );

require_once KALICART_MCP_DIR . 'includes/class-markdown.php';
require_once KALICART_MCP_DIR . 'includes/class-content.php';
require_once KALICART_MCP_DIR . 'includes/class-mcp.php';
require_once KALICART_MCP_DIR . 'includes/class-presence.php';
require_once KALICART_MCP_DIR . 'includes/class-bridge-hint.php';
require_once KALICART_MCP_DIR . 'includes/class-meta-box.php';

// Admin class is loaded unconditionally: besides the admin screen (gated by its own
// admin_menu/admin_enqueue hooks), it registers admin-only REST routes that must be
// available during REST requests too (which are not is_admin()).
require_once KALICART_MCP_DIR . 'includes/class-admin.php';

add_action( 'plugins_loaded', function () {
	// Load translations (.mo from /languages). Hooked on 'init' per WP 6.7+ guidance.
	add_action( 'init', function () {
		load_plugin_textdomain( 'kalicart-mcp', false, dirname( plugin_basename( KALICART_MCP_FILE ) ) . '/languages' );
	} );

	KaliCart_MCP_Server::init();
	KaliCart_MCP_Presence::init();
	KaliCart_MCP_Bridge_Hint::init();
	KaliCart_MCP_Meta_Box::init();

	KaliCart_MCP_Admin::init();

	// Version-gated: (re)write the physical .well-known mirror and flush rewrites
	// once per plugin version, so discovery works on every install/update without
	// a manual re-activation.
	add_action( 'init', function () {
		if ( get_option( 'kalicart_mcp_wk_version' ) === KALICART_MCP_VERSION ) {
			return;
		}
		KaliCart_MCP_Presence::write_well_known_files();
		flush_rewrite_rules();
		update_option( 'kalicart_mcp_wk_version', KALICART_MCP_VERSION );
	}, 20 );
} );

register_activation_hook( __FILE__, function () {
	KaliCart_MCP_Presence::write_well_known_files();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

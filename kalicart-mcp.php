<?php
/**
 * Plugin Name:       KaliCart MCP
 * Plugin URI:        https://mcp.kalicart.com
 * Description:       Agent-callable WordPress content and tools.
 * Version:           0.2.10
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

define( 'KCMCP_VERSION', '0.2.10' );
define( 'KCMCP_FILE',    __FILE__ );
define( 'KCMCP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'KCMCP_URL',     plugin_dir_url( __FILE__ ) );
define( 'KCMCP_API_NS',  'kalicart-mcp/v1' );

require_once KCMCP_DIR . 'includes/class-markdown.php';
require_once KCMCP_DIR . 'includes/class-content.php';
require_once KCMCP_DIR . 'includes/class-mcp.php';
require_once KCMCP_DIR . 'includes/class-presence.php';
require_once KCMCP_DIR . 'includes/class-bridge-hint.php';
require_once KCMCP_DIR . 'includes/class-meta-box.php';

// Admin class is loaded unconditionally: besides the admin screen (gated by its own
// admin_menu/admin_enqueue hooks), it registers admin-only REST routes that must be
// available during REST requests too (which are not is_admin()).
require_once KCMCP_DIR . 'includes/class-admin.php';

add_action( 'plugins_loaded', function () {
	Kcmcp_Server::init();
	Kcmcp_Presence::init();
	Kcmcp_Bridge_Hint::init();
	Kcmcp_Meta_Box::init();

	Kcmcp_Admin::init();

	// Version-gated: (re)write the physical .well-known mirror and flush rewrites
	// once per plugin version, so discovery works on every install/update without
	// a manual re-activation.
	add_action( 'init', function () {
		if ( get_option( 'kcmcp_wk_version' ) === KCMCP_VERSION ) {
			return;
		}
		Kcmcp_Presence::write_well_known_files();
		flush_rewrite_rules();
		update_option( 'kcmcp_wk_version', KCMCP_VERSION );
	}, 20 );
} );

register_activation_hook( __FILE__, function () {
	Kcmcp_Presence::write_well_known_files();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

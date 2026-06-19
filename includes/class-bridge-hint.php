<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kcmcp_Bridge_Hint
 *
 * The "road to the Bridge". KaliCart MCP serves WordPress *content* (pages, posts).
 * On a WooCommerce site the computable *catalog* (prices, variants, stock, semantic
 * filters) is out of MCP's scope — that is KaliCart Bridge's job. This class detects
 * WooCommerce and points operators (admin notice) and agents (discovery document) to
 * the Bridge, explaining why.
 *
 * SEPARATION: detection only. No import, no call, no code dependency on the Bridge.
 * WooCommerce is detected with class_exists('WooCommerce') (same guard the Bridge
 * uses); the Bridge is detected by its plugin slug in the active-plugins list —
 * never by referencing a Bridge symbol.
 */
class Kcmcp_Bridge_Hint {

	const BRIDGE_URL = 'https://bridge.kalicart.com';
	const META       = 'kcmcp_bridge_hint_dismissed';

	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss' ) );
	}

	/** WooCommerce present? Standard signal, identical to the Bridge's own guard. */
	public static function woo_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/** KaliCart Bridge active? Detected by plugin slug only — zero code coupling. */
	public static function bridge_active(): bool {
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			if ( 0 === strpos( (string) $p, 'kalicart-bridge/' ) ) {
				return true;
			}
		}
		if ( is_multisite() ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $p ) {
				if ( 0 === strpos( (string) $p, 'kalicart-bridge/' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Show the recommendation only when there is a shop and the Bridge is not handling it. */
	public static function maybe_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! self::woo_active() || self::bridge_active() ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::META, true ) ) {
			return;
		}

		$dismiss = wp_nonce_url(
			add_query_arg( 'kcmcp_dismiss_bridge_hint', '1' ),
			'kcmcp_dismiss_bridge_hint'
		);

		echo '<div class="notice notice-info">';
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: %s: link to KaliCart Bridge */
				__( '<strong>KaliCart MCP</strong> makes this site\'s content callable by AI agents — but it detected <strong>WooCommerce</strong>. MCP exposes pages and posts only; it does <em>not</em> serve a computable product catalog (prices, variants, stock, semantic filters). For that, install %s: it publishes your catalog in an agent-readable form. The two are complementary — MCP for content, Bridge for commerce.', 'kalicart-mcp' ),
				'<a href="' . esc_url( self::BRIDGE_URL ) . '" target="_blank" rel="noopener noreferrer">KaliCart Bridge</a>'
			),
			array(
				'strong' => array(),
				'em'     => array(),
				'a'      => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</p>';
		echo '<p><a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'kalicart-mcp' ) . '</a></p>';
		echo '</div>';
	}

	/** Persist dismissal per user. */
	public static function maybe_dismiss(): void {
		if ( empty( $_GET['kcmcp_dismiss_bridge_hint'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		check_admin_referer( 'kcmcp_dismiss_bridge_hint' );
		update_user_meta( get_current_user_id(), self::META, 1 );
		wp_safe_redirect( remove_query_arg( array( 'kcmcp_dismiss_bridge_hint', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Commerce pointer for the discovery document (agent-facing). Null when no shop.
	 * URL reference only; never a code call into the Bridge.
	 */
	public static function commerce_hint(): ?array {
		if ( ! self::woo_active() ) {
			return null;
		}
		$hint = array(
			'platform'      => 'woocommerce',
			'served_by_mcp' => false,
			'note'          => 'This site runs WooCommerce. KaliCart MCP serves editorial content (pages, posts) only. For the computable product catalog — prices, variants, stock, semantic filters — use KaliCart Bridge.',
			'recommended'   => 'KaliCart Bridge',
			'more'          => self::BRIDGE_URL,
		);
		if ( self::bridge_active() ) {
			// The Bridge is installed on this origin: point agents straight at its
			// public discovery document (a URL on the same site — not a code call).
			$hint['bridge_discovery'] = home_url( '/.well-known/kalicart-bridge' );
		}
		return $hint;
	}
}

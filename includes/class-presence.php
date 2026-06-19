<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kcmcp_Presence
 *
 * Presence / discovery layer. Advertises that this WordPress site is callable
 * as an MCP server, so an agent can find the endpoint without manual config:
 *
 *     HTML <head> link  ->  discovery document  ->  MCP endpoint (JSON-RPC 2.0)
 *
 * Discovery is served two ways (the nginx lesson): a WP rewrite -> serve_well_known()
 * (correct Content-Type on any stack) AND a physical .json mirror (served statically
 * with the right MIME where /.well-known/ is a static location and the rewrite never
 * runs). Read-only, no external calls.
 *
 * Note: this plugin deliberately does NOT publish /.well-known/api-catalog, to avoid
 * colliding with KaliCart Bridge (which owns that shared path) on sites running both.
 */
class Kcmcp_Presence {

	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'inject_head_link' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 10, 2 );

		add_action( 'init', array( __CLASS__, 'register_well_known_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_well_known_query_var' ) );
		add_action( 'parse_request', array( __CLASS__, 'serve_well_known' ) );

		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_content_signal_header' ), 10, 3 );
	}

	// ── HEAD LINK ────────────────────────────────────────────────────────────────

	public static function inject_head_link(): void {
		$discovery = home_url( '/.well-known/kalicart-mcp' );
		printf(
			"\n" . '<link rel="kalicart-mcp" type="application/json" href="%s"' .
			' title="Callable MCP server for AI agents — KaliCart MCP" />' . "\n",
			esc_url( $discovery )
		);
	}

	// ── CONTENT-SIGNAL ─────────────────────────────────────────────────────────────

	/**
	 * Content-Signal (draft-romm-aipref-contentsignals): AI usage preferences.
	 *   search=yes    agents may use this content to answer
	 *   ai-input=yes  live agent reads are the whole point
	 *   ai-train=no   default: do not train models on this content
	 */
	public static function content_signal_value(): string {
		return 'search=yes, ai-input=yes, ai-train=yes';
	}

	public static function add_content_signal_header( $response, $server, $request ) {
		if (
			$response instanceof WP_REST_Response
			&& $request instanceof WP_REST_Request
			&& strpos( (string) $request->get_route(), '/' . KCMCP_API_NS ) === 0
		) {
			$response->header( 'Content-Signal', self::content_signal_value() );
		}
		return $response;
	}

	// ── ROBOTS ─────────────────────────────────────────────────────────────────────

	public static function filter_robots_txt( string $output, bool $public ): string {
		if ( ! $public ) {
			return $output;
		}
		$output .= "\n# KaliCart MCP — agent discovery\n";
		$output .= "Allow: /.well-known/kalicart-mcp\n";
		$output .= 'Content-Signal: ' . self::content_signal_value() . "\n";
		return $output;
	}

	// ── .well-known (rewrite-served, correct Content-Type) ───────────────────────

	public static function register_well_known_rewrite(): void {
		add_rewrite_rule( '^\.well-known/kalicart-mcp(?:\.json)?$', 'index.php?kcmcp_wk=1', 'top' );
	}

	public static function add_well_known_query_var( array $vars ): array {
		$vars[] = 'kcmcp_wk';
		return $vars;
	}

	public static function serve_well_known( $wp = null ): void {
		$hit = false;
		if ( $wp instanceof WP && ! empty( $wp->query_vars['kcmcp_wk'] ) ) {
			$hit = true;
		} elseif ( ! empty( $_GET['kcmcp_wk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$hit = true;
		}
		if ( ! $hit ) {
			return;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo self::discovery_payload(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload
		exit;
	}

	// ── discovery payload ────────────────────────────────────────────────────────

	private static function discovery_payload(): string {
		$base = rest_url( KCMCP_API_NS );
		$doc = array(
			'type'          => 'kalicart-mcp-v1',
			'version'       => KCMCP_VERSION,
			'name'          => get_bloginfo( 'name' ),
			'description'   => get_bloginfo( 'description' ),
			'url'           => home_url( '/' ),
			'kind'          => 'content',
			'access'        => 'read-only',
			'mcp'           => array(
				'endpoint'        => $base . '/mcp',
				'transport'       => 'http',
				'protocol'        => 'Model Context Protocol',
				'protocolVersion' => Kcmcp_Server::PROTOCOL_VERSION,
				'jsonrpc'         => '2.0',
				'tools'           => array_values( Kcmcp_Server::TOOLS ),
			),
			'agent_note'    => 'This WordPress site is callable as an MCP server. Connect an MCP client to mcp.endpoint (JSON-RPC 2.0 over HTTP POST) and call site_info first.',
			'generator'     => 'KaliCart MCP ' . KCMCP_VERSION,
			'documentation' => 'https://mcp.kalicart.com/docs/',
		);

		// Agent-facing pointer to the Bridge on WooCommerce sites (detection only,
		// no dependency): MCP serves content; the computable catalog is the Bridge's.
		$commerce = Kcmcp_Bridge_Hint::commerce_hint();
		if ( null !== $commerce ) {
			$doc['commerce'] = $commerce;
		}

		return wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	// ── physical .json mirror (nginx static fallback) ────────────────────────────

	public static function cleanup_well_known_static_files(): void {
		$dir = rtrim( ABSPATH, '/' ) . '/.well-known/';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$path = $dir . 'kalicart-mcp';
		if ( file_exists( $path ) ) {
			$body = (string) @file_get_contents( $path );
			if ( strpos( $body, 'kalicart-mcp' ) !== false ) {
				wp_delete_file( $path );
			}
		}
	}

	public static function write_well_known_files(): void {
		$dir = rtrim( ABSPATH, '/' ) . '/.well-known/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Extension-less path is served by the rewrite -> serve_well_known() handler
		// (correct Content-Type on every stack); a physical extension-less file would
		// be served as text/plain, so remove any of ours.
		self::cleanup_well_known_static_files();

		$path     = $dir . 'kalicart-mcp.json';
		$existing = file_exists( $path ) ? (string) @file_get_contents( $path ) : '';
		// Only (over)write a file that is ours or absent — never clobber a file the
		// host/merchant placed there (ACME, autoconfig, etc.).
		if ( '' === $existing || strpos( $existing, 'kalicart-mcp' ) !== false ) {
			@file_put_contents( $path, self::discovery_payload() ); // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- /.well-known/ files must reside in web root
		}
	}
}

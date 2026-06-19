<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kcmcp_Server
 *
 * Model Context Protocol (MCP) server — JSON-RPC 2.0 over HTTP POST.
 * Exposes this WordPress site's content as callable tools for AI agents.
 * No external calls, no LLM, no cloud, no API key — local content only.
 *
 * Endpoint: POST /wp-json/kalicart-mcp/v1/mcp
 */
class Kcmcp_Server {

	const PROTOCOL_VERSION = '2025-06-18';
	const TOOLS = array( 'site_info', 'site_map', 'list_content', 'get_content', 'search_content' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			KCMCP_API_NS,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'no_sse' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = json_decode( $request->get_body(), true );
		}
		if ( ! is_array( $body ) ) {
			return self::respond( self::rpc_error( null, -32700, 'Parse error: body is not valid JSON' ) );
		}

		if ( isset( $body[0] ) && is_array( $body[0] ) ) {
			$out = array();
			foreach ( $body as $msg ) {
				$r = self::dispatch( is_array( $msg ) ? $msg : array() );
				if ( null !== $r ) {
					$out[] = $r;
				}
			}
			return empty( $out ) ? new WP_REST_Response( null, 202 ) : new WP_REST_Response( $out, 200 );
		}

		$r = self::dispatch( $body );
		if ( null === $r ) {
			return new WP_REST_Response( null, 202 );
		}
		return self::respond( $r );
	}

	private static function respond( array $rpc ): WP_REST_Response {
		$resp = new WP_REST_Response( $rpc, 200 );
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	private static function dispatch( array $msg ) {
		$is_notification = ! array_key_exists( 'id', $msg );
		$id              = $msg['id'] ?? null;

		if ( empty( $msg['method'] ) || ! is_string( $msg['method'] ) ) {
			return $is_notification ? null : self::rpc_error( $id, -32600, 'Invalid Request: missing method' );
		}

		$method = $msg['method'];
		$params = ( isset( $msg['params'] ) && is_array( $msg['params'] ) ) ? $msg['params'] : array();

		switch ( $method ) {
			case 'initialize':
				$result = self::r_initialize( $params );
				break;
			case 'tools/list':
				$result = array( 'tools' => self::tool_definitions() );
				break;
			case 'tools/call':
				return self::r_tools_call( $id, $params );
			case 'ping':
				$result = (object) array();
				break;
			default:
				if ( $is_notification || 0 === strpos( $method, 'notifications/' ) ) {
					return null;
				}
				return self::rpc_error( $id, -32601, 'Method not found: ' . $method );
		}

		if ( $is_notification ) {
			return null;
		}
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	private static function r_initialize( array $params ): array {
		$client_pv = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$name      = get_bloginfo( 'name' );

		return array(
			'protocolVersion' => '' !== $client_pv ? $client_pv : self::PROTOCOL_VERSION,
			'capabilities'    => array( 'tools' => (object) array() ),
			'serverInfo'      => array(
				'name'    => 'kalicart-mcp',
				'title'   => 'KaliCart MCP — ' . $name,
				'version' => KCMCP_VERSION,
			),
			'instructions'    => implode( ' ', array_filter( array(
				'Read-only navigation of the WordPress site "' . $name . '".',
				'Call site_info first to learn what the site is and which post types and taxonomies exist.',
				'Use site_map for menus and the page hierarchy, search_content to find content by keyword, list_content to browse a post type, and get_content to read a single item as clean Markdown.',
				Kcmcp_Bridge_Hint::woo_active()
					? 'This site runs WooCommerce, but this server exposes editorial content (pages, posts) only: products, product categories, cart, checkout, account and shop surfaces are deliberately excluded. For the product catalog (prices, variants, stock) use KaliCart Bridge; do not infer commerce data from editorial content here.'
					: '',
			) ) ),
		);
	}

	private static function r_tools_call( $id, array $params ): array {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = ( isset( $params['arguments'] ) && is_array( $params['arguments'] ) ) ? $params['arguments'] : array();

		if ( ! in_array( $name, self::TOOLS, true ) ) {
			return self::rpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}

		try {
			$data = self::run_tool( $name, $args );
		} catch ( \Throwable $e ) {
			return self::tool_result( $id, array( 'error' => 'Tool execution failed: ' . $e->getMessage() ), true );
		}

		$is_error = ( isset( $data['success'] ) && false === $data['success'] );
		return self::tool_result( $id, $data, $is_error );
	}

	private static function tool_result( $id, array $data, bool $is_error ): array {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'content'           => array( array( 'type' => 'text', 'text' => is_string( $json ) ? $json : '{}' ) ),
				'structuredContent' => $data,
				'isError'           => $is_error,
			),
		);
	}

	private static function run_tool( string $name, array $args ): array {
		switch ( $name ) {
			case 'site_info':      return Kcmcp_Content::site_info();
			case 'site_map':       return Kcmcp_Content::site_map();
			case 'list_content':   return Kcmcp_Content::list_content( $args );
			case 'search_content': return Kcmcp_Content::search_content( $args );
			case 'get_content':    return Kcmcp_Content::get_content( $args );
		}
		return array();
	}

	private static function tool_definitions(): array {
		$empty = array( 'type' => 'object', 'properties' => (object) array() );

		return array(
			array(
				'name'        => 'site_info',
				'title'       => 'Site info',
				'description' => 'What this site is: name, description, language, front page, available post types and taxonomies. Call this first to ground navigation.',
				'inputSchema' => $empty,
			),
			array(
				'name'        => 'site_map',
				'title'       => 'Site map',
				'description' => 'The navigable structure of the site: registered navigation menus (by location) and the full page hierarchy with titles and URLs.',
				'inputSchema' => $empty,
			),
			array(
				'name'        => 'search_content',
				'title'       => 'Search content',
				'description' => 'Full-text search across the site content. Returns matching items (title, URL, excerpt). Use get_content to read a result in full.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(
						'q'         => array( 'type' => 'string', 'description' => 'Search keywords.' ),
						'post_type' => array( 'type' => 'string', 'description' => 'Optional post type slug to restrict the search (e.g. "post", "page"). Omit to search all public types.' ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					),
					'required'   => array( 'q' ),
				),
			),
			array(
				'name'        => 'list_content',
				'title'       => 'List content',
				'description' => 'Browse content of one post type (paginated). Optionally filter posts by category or tag slug.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(
						'post_type' => array( 'type' => 'string', 'default' => 'post', 'description' => 'Post type slug (e.g. "post", "page"). Enumerate via site_info.' ),
						'category'  => array( 'type' => 'string', 'description' => 'Category slug (posts only).' ),
						'tag'       => array( 'type' => 'string', 'description' => 'Tag slug (posts only).' ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'orderby'   => array( 'type' => 'string', 'enum' => array( 'date', 'title', 'menu_order' ), 'default' => 'date' ),
						'order'     => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'default' => 'DESC' ),
					),
				),
			),
			array(
				'name'        => 'get_content',
				'title'       => 'Get content',
				'description' => 'Read a single item in full as clean Markdown, with title, URL, date, taxonomy terms and word count. Identify it by numeric id (preferred) or by slug + post_type.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(
						'id'        => array( 'type' => 'integer', 'description' => 'Post/page ID (from a search or list result).' ),
						'slug'      => array( 'type' => 'string', 'description' => 'Slug, used when id is not provided.' ),
						'post_type' => array( 'type' => 'string', 'default' => 'post', 'description' => 'Post type for slug lookup.' ),
					),
				),
			),
		);
	}

	public static function no_sse( WP_REST_Request $req ): WP_REST_Response {
		$resp = new WP_REST_Response( array( 'error' => 'GET not supported. This MCP endpoint accepts JSON-RPC 2.0 over HTTP POST only.' ), 405 );
		$resp->header( 'Allow', 'POST' );
		return $resp;
	}

	private static function rpc_error( $id, int $code, string $message ): array {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
	}
}

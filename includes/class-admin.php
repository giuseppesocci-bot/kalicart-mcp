<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kcmcp_Admin
 *
 * One admin page (top-level menu with the KaliCart mark): MCP server status, the
 * content-exposure control, the agent connect-string, a ready-to-paste Claude
 * Desktop config, the tools exposed, and WooCommerce coexistence.
 */
class Kcmcp_Admin {

	const SLUG = 'kalicart-mcp';

	const HOOK_SUFFIX = 'toplevel_page_kalicart-mcp';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_admin_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the admin page CSS/JS — only on this plugin's settings screen.
	 * Localizes the REST base, nonce, and UI strings consumed by assets/admin.js.
	 */
	public static function enqueue( string $hook ): void {
		if ( self::HOOK_SUFFIX !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'kcmcp-admin',
			KCMCP_URL . 'assets/admin.css',
			array(),
			KCMCP_VERSION
		);
		wp_enqueue_script(
			'kcmcp-admin',
			KCMCP_URL . 'assets/admin.js',
			array(),
			KCMCP_VERSION,
			true
		);
		wp_localize_script(
			'kcmcp-admin',
			'KCMCP',
			array(
				'base'  => esc_url_raw( rest_url( KCMCP_API_NS ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				't'     => array(
					'noResults'   => __( 'No matching content.', 'kalicart-mcp' ),
					'searching'   => __( 'Searching…', 'kalicart-mcp' ),
					'error'       => __( 'Search failed. Try again.', 'kalicart-mcp' ),
					'toggleError' => __( 'Could not save. Reload the page and try again.', 'kalicart-mcp' ),
					'hiddenLabel' => __( 'Currently hidden:', 'kalicart-mcp' ),
				),
			)
		);
	}

	/**
	 * Admin-only REST routes (manage_options). These are NOT part of the public MCP
	 * surface (the five tools); they back the exclusions UI only.
	 */
	public static function register_admin_routes(): void {
		$perm = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			KCMCP_API_NS,
			'/admin/search-posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_search_posts' ),
				'permission_callback' => $perm,
				'args'                => array(
					'q' => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
		register_rest_route(
			KCMCP_API_NS,
			'/admin/toggle-exclude',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_toggle_exclude' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id'     => array( 'type' => 'integer', 'required' => true ),
					'hidden' => array( 'type' => 'boolean', 'required' => true ),
				),
			)
		);
	}

	/** Search exposed posts (NOT pages) by title. Returns up to 20 matches. */
	public static function rest_search_posts( WP_REST_Request $request ) {
		$q     = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$types = array_keys( Kcmcp_Content::public_post_types() );
		if ( empty( $types ) || '' === $q ) {
			return rest_ensure_response( array( 'items' => array() ) );
		}
		$posts = get_posts( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			's'              => $q,
			'orderby'        => 'relevance',
			'exclude'        => Kcmcp_Content::woo_reserved_page_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- small bounded set of WooCommerce functional pages, admin-only search.
		) );
		$items = array();
		foreach ( $posts as $p ) {
			$type_obj = get_post_type_object( $p->post_type );
			$items[]  = array(
				'id'     => (int) $p->ID,
				'title'  => $p->post_title ? $p->post_title : __( '(no title)', 'kalicart-mcp' ),
				'type'   => $type_obj ? $type_obj->labels->singular_name : $p->post_type,
				'hidden' => ( '1' === get_post_meta( $p->ID, '_kcmcp_exclude', true ) ),
			);
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	/** Hide/show a single post or page from agents by toggling its _kcmcp_exclude meta. */
	public static function rest_toggle_exclude( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$hidden = rest_sanitize_boolean( $request->get_param( 'hidden' ) );
		$post   = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'kcmcp_not_found', __( 'Item not found.', 'kalicart-mcp' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'kcmcp_forbidden', __( 'Not allowed.', 'kalicart-mcp' ), array( 'status' => 403 ) );
		}
		// Only allow toggling content types we actually expose.
		if ( ! array_key_exists( $post->post_type, Kcmcp_Content::public_post_types() ) ) {
			return new WP_Error( 'kcmcp_not_exposed', __( 'This content type is not exposed.', 'kalicart-mcp' ), array( 'status' => 400 ) );
		}
		if ( $hidden ) {
			update_post_meta( $id, '_kcmcp_exclude', '1' );
		} else {
			delete_post_meta( $id, '_kcmcp_exclude' );
		}
		return rest_ensure_response( array( 'id' => $id, 'hidden' => $hidden ) );
	}

	public static function menu(): void {
		add_menu_page(
			'KaliCart MCP',
			'KaliCart MCP',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			self::icon_data_uri(),
			80
		);
	}

	private static function icon_data_uri(): string {
		// Base64-encoded inline SVG for the menu icon (same scheme as KaliCart Bridge).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 1196" fill="#f3f1f1">'
			. '<rect x="280" y="184" width="212" height="824"/>'
			. '<path d="M677 184H900V411L720 504Z"/>'
			. '<path d="M575 691L780 568L1018 1008H790Z"/>'
			. '<path d="M900 411L780 568L575 691L720 504Z"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}


	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save the content-exposure choice.
		if ( isset( $_POST['kcmcp_save_exposure'] ) && check_admin_referer( 'kcmcp_exposure' ) ) {
			$eligible = array_keys( Kcmcp_Content::eligible_post_types() );
			$chosen   = isset( $_POST['kcmcp_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['kcmcp_types'] ) ) : array();
			$chosen   = array_values( array_intersect( $eligible, $chosen ) );
			update_option( 'kcmcp_exposed_types', $chosen );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Exposure settings saved.', 'kalicart-mcp' ) . '</p></div>';
		}

		// Save the excluded categories (batch). Individual posts/pages toggle live via REST.
		if ( isset( $_POST['kcmcp_save_categories'] ) && check_admin_referer( 'kcmcp_categories' ) ) {
			$terms = isset( $_POST['kcmcp_terms'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['kcmcp_terms'] ) ) : array();
			$valid = array();
			foreach ( $terms as $tid ) {
				if ( $tid > 0 && get_term( $tid, 'category' ) instanceof WP_Term ) {
					$valid[] = $tid;
				}
			}
			update_option( 'kcmcp_excluded_terms', array_values( array_unique( $valid ) ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Excluded categories saved.', 'kalicart-mcp' ) . '</p></div>';
		}

		$endpoint  = rest_url( KCMCP_API_NS . '/mcp' );
		$discovery = home_url( '/.well-known/kalicart-mcp' );
		$logo      = KCMCP_URL . 'assets/logo.svg';
		$tools     = array_values( Kcmcp_Server::TOOLS );
		$exposed   = array_keys( Kcmcp_Content::public_post_types() );
		$key       = sanitize_title( get_bloginfo( 'name' ) );
		$key       = '' !== $key ? $key : 'my-site';

		$snippet  = "{\n  \"mcpServers\": {\n    \"" . $key . "\": {\n";
		$snippet .= "      \"command\": \"npx\",\n";
		$snippet .= "      \"args\": [\"-y\", \"mcp-remote\", \"" . $endpoint . "\", \"--transport\", \"http-only\"]\n";
		$snippet .= "    }\n  }\n}";
		?>

		<div class="wrap kcmcp-wrap">
			<div class="kcmcp-hd">
				<img src="<?php echo esc_url( $logo ); ?>" alt="KaliCart MCP" />
				<div>
					<h1>KaliCart MCP</h1>
					<div class="tag"><?php esc_html_e( 'Agent-callable WordPress content and tools', 'kalicart-mcp' ); ?></div>
				</div>
				<span class="kcmcp-ver">v<?php echo esc_html( KCMCP_VERSION ); ?></span>
			</div>

			<div class="kcmcp-card">
				<h2><?php esc_html_e( 'Status', 'kalicart-mcp' ); ?></h2>
				<div class="kcmcp-row"><span class="kcmcp-dot"></span> <?php esc_html_e( 'MCP server active — this site is callable by AI agents.', 'kalicart-mcp' ); ?></div>
				<p class="kcmcp-muted"><?php echo esc_html( sprintf( /* translators: %s: protocol version */ __( 'Model Context Protocol · JSON-RPC 2.0 · protocol %s', 'kalicart-mcp' ), Kcmcp_Server::PROTOCOL_VERSION ) ); ?></p>
			</div>

			<div class="kcmcp-card">
				<h2><?php esc_html_e( 'Content exposure', 'kalicart-mcp' ); ?></h2>
				<p class="kcmcp-muted"><?php esc_html_e( 'Choose which content types AI agents can read. Products (WooCommerce) are handled by KaliCart Bridge, not here.', 'kalicart-mcp' ); ?></p>
				<form method="post" style="margin-top:10px;">
					<?php wp_nonce_field( 'kcmcp_exposure' ); ?>
					<?php
					foreach ( Kcmcp_Content::eligible_post_types() as $slug => $obj ) :
						$cnt      = wp_count_posts( $slug );
						$pub      = isset( $cnt->publish ) ? (int) $cnt->publish : 0;
						$reserved = ( 'page' === $slug ) ? count( Kcmcp_Content::woo_reserved_page_ids() ) : 0;
						$shown    = max( 0, $pub - $reserved );
						?>
						<div class="kcmcp-toggle">
							<label class="kcmcp-switch">
								<input type="checkbox" name="kcmcp_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $exposed, true ) ); ?> />
								<span class="kcmcp-tog-track"><span class="kcmcp-tog-thumb"></span></span>
							</label>
							<span class="kcmcp-tog-label">
								<strong><?php echo esc_html( $obj->labels->name ?? $slug ); ?></strong>
								<?php if ( $reserved > 0 ) : ?>
								<span class="kcmcp-muted">(<?php echo (int) $shown; ?> of <?php echo (int) $pub; ?>)</span>
								<div class="kcmcp-tog-note"><?php echo (int) $reserved; ?> WooCommerce pages (cart, checkout, my account&hellip;) excluded automatically</div>
								<?php else : ?>
								<span class="kcmcp-muted">(<?php echo (int) $pub; ?>)</span>
								<?php endif; ?>
							</span>
						</div>
					<?php endforeach; ?>
					<div style="margin-top:12px;"><button type="submit" name="kcmcp_save_exposure" value="1" class="kcmcp-copy" style="padding:7px 18px;"><?php esc_html_e( 'Save', 'kalicart-mcp' ); ?></button></div>
				</form>
			</div>

			<?php
			$excl_terms = Kcmcp_Content::excluded_term_ids();
			// Show only the primary-language categories: the MCP serves one language,
			// so listing every translation (e.g. Academy IT/EN/DE/FR) would be ambiguous.
			$cat_args = array( 'hide_empty' => false );
			$primary_lang = Kcmcp_Content::default_language();
			if ( null !== $primary_lang && function_exists( 'pll_default_language' ) ) {
				$cat_args['lang'] = $primary_lang;
			}
			$categories = get_categories( $cat_args );
			$exposed_types = array_keys( Kcmcp_Content::public_post_types() );

			// Content (posts, pages, public CPTs): potentially thousands — never preload.
			// Show only the already-hidden items; everything else is reachable via search.
			$hidden_items = array();
			if ( ! empty( $exposed_types ) ) {
				$hidden_items = get_posts( array(
					'post_type'      => $exposed_types,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'meta_key'       => '_kcmcp_exclude', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-only listing of already-hidden items.
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- admin-only listing of already-hidden items.
					'exclude'        => Kcmcp_Content::woo_reserved_page_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- small bounded set of WooCommerce functional pages.
				) );
			}
			?>
			<div class="kcmcp-card">
				<h2><?php esc_html_e( 'Content exclusions', 'kalicart-mcp' ); ?></h2>
				<p class="kcmcp-muted"><?php esc_html_e( 'Hide content from AI agents. Hidden items disappear from listings, search, and direct retrieval. You can also hide any single item from its editor sidebar.', 'kalicart-mcp' ); ?></p>
				<?php
				$kcmcp_primary_lang = Kcmcp_Content::default_language();
				if ( null !== $kcmcp_primary_lang ) : ?>
					<p class="kcmcp-notice" style="margin-top:10px;padding:10px 14px;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:6px;font-size:13px;color:#1d3a5f;">
						<?php
						echo wp_kses_post( sprintf(
							/* translators: %s: primary language code, e.g. "IT" */
							__( 'This site is multilingual. To keep the data agents receive clean and free of duplicates, KaliCart MCP serves content in your primary language only (%s). The categories below are shown in that language.', 'kalicart-mcp' ),
							'<strong>' . esc_html( strtoupper( $kcmcp_primary_lang ) ) . '</strong>'
						) );
						?>
					</p>
				<?php endif; ?>

				<?php
				// --- Categories (batch form) ---
				?>
				<form method="post" style="margin-top:14px;">
					<?php wp_nonce_field( 'kcmcp_categories' ); ?>
					<h3 class="kcmcp-sub"><?php esc_html_e( 'Categories', 'kalicart-mcp' ); ?></h3>
					<p class="kcmcp-muted"><?php esc_html_e( 'Hide every post in a category — handy for test or staging content.', 'kalicart-mcp' ); ?></p>
					<?php if ( empty( $categories ) ) : ?>
						<p class="kcmcp-muted"><?php esc_html_e( 'No categories found.', 'kalicart-mcp' ); ?></p>
					<?php else : ?>
					<div class="kcmcp-checklist" style="margin-top:8px;">
						<?php foreach ( $categories as $cat ) : ?>
						<label class="kcmcp-check">
							<input type="checkbox" name="kcmcp_terms[]" value="<?php echo (int) $cat->term_id; ?>" <?php checked( in_array( (int) $cat->term_id, $excl_terms, true ) ); ?> />
							<span><?php echo esc_html( $cat->name ); ?> <span class="kcmcp-muted">(<?php echo (int) $cat->count; ?>)</span></span>
						</label>
						<?php endforeach; ?>
					</div>
					<div style="margin-top:12px;"><button type="submit" name="kcmcp_save_categories" value="1" class="kcmcp-copy" style="padding:7px 18px;"><?php esc_html_e( 'Save categories', 'kalicart-mcp' ); ?></button></div>
					<?php endif; ?>
				</form>

				<?php if ( ! empty( $exposed_types ) ) : ?>
				<h3 class="kcmcp-sub" style="margin-top:22px;"><?php esc_html_e( 'Posts and pages', 'kalicart-mcp' ); ?></h3>
				<p class="kcmcp-muted"><?php esc_html_e( 'Search by title, then toggle an item to hide it from agents.', 'kalicart-mcp' ); ?></p>
				<input type="search" id="kcmcp-post-search" class="kcmcp-search" placeholder="<?php esc_attr_e( 'Type a title…', 'kalicart-mcp' ); ?>" autocomplete="off" />
				<div id="kcmcp-search-results" class="kcmcp-togglelist" style="margin-top:8px;"></div>

				<?php if ( ! empty( $hidden_items ) ) : ?>
				<p class="kcmcp-muted" style="margin-top:16px;"><?php esc_html_e( 'Currently hidden:', 'kalicart-mcp' ); ?></p>
				<div id="kcmcp-hidden-posts" class="kcmcp-togglelist">
					<?php foreach ( $hidden_items as $hp ) :
						$type_obj = get_post_type_object( $hp->post_type );
						$type_lbl = $type_obj ? $type_obj->labels->singular_name : $hp->post_type;
						?>
					<div class="kcmcp-trow">
						<label class="kcmcp-switch">
							<input type="checkbox" class="kcmcp-xtoggle" data-id="<?php echo (int) $hp->ID; ?>" checked />
							<span class="kcmcp-tog-track"><span class="kcmcp-tog-thumb"></span></span>
						</label>
						<span class="kcmcp-trow-title"><?php echo esc_html( $hp->post_title ? $hp->post_title : __( '(no title)', 'kalicart-mcp' ) ); ?> <span class="kcmcp-muted">&middot; <?php echo esc_html( $type_lbl ); ?></span></span>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<?php endif; ?>
			</div>


			<div class="kcmcp-card">
				<h2><?php esc_html_e( 'Endpoint', 'kalicart-mcp' ); ?></h2>
				<p class="kcmcp-muted"><?php esc_html_e( 'Point any MCP client at this URL (JSON-RPC 2.0 over HTTP POST):', 'kalicart-mcp' ); ?></p>
				<div class="kcmcp-code">
					<code id="kcmcp-endpoint"><?php echo esc_html( $endpoint ); ?></code>
					<button type="button" class="kcmcp-copy" data-copy="#kcmcp-endpoint"><?php esc_html_e( 'Copy', 'kalicart-mcp' ); ?></button>
				</div>
				<p class="kcmcp-muted" style="margin-top:12px;">
					<?php esc_html_e( 'Discovery document:', 'kalicart-mcp' ); ?>
					<a class="kcmcp-link" href="<?php echo esc_url( $discovery ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $discovery ); ?></a>
				</p>
			</div>

			<div class="kcmcp-card">
				<h2><?php esc_html_e( 'Tools exposed', 'kalicart-mcp' ); ?></h2>
				<div class="kcmcp-pills">
					<?php foreach ( $tools as $t ) : ?>
						<span class="kcmcp-pill"><?php echo esc_html( $t ); ?></span>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="kcmcp-card">
				<h2><?php esc_html_e( 'Connect from Claude Desktop', 'kalicart-mcp' ); ?></h2>
				<p class="kcmcp-muted"><?php esc_html_e( 'Add this to claude_desktop_config.json (uses mcp-remote):', 'kalicart-mcp' ); ?></p>
				<pre class="kcmcp-pre" id="kcmcp-snippet"><?php echo esc_html( $snippet ); ?></pre>
				<div style="margin-top:8px;"><button type="button" class="kcmcp-copy" data-copy="#kcmcp-snippet"><?php esc_html_e( 'Copy config', 'kalicart-mcp' ); ?></button></div>
			</div>

			<?php if ( Kcmcp_Bridge_Hint::woo_active() ) : ?>
				<div class="kcmcp-card">
					<h2><?php esc_html_e( 'WooCommerce', 'kalicart-mcp' ); ?></h2>
					<?php if ( Kcmcp_Bridge_Hint::bridge_active() ) : ?>
						<div class="kcmcp-row"><span class="kcmcp-dot"></span> <?php esc_html_e( 'KaliCart Bridge is active — your product catalog is served to agents by the Bridge. MCP handles content, Bridge handles commerce.', 'kalicart-mcp' ); ?></div>
					<?php else : ?>
						<p class="kcmcp-muted">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: link to KaliCart Bridge */
									__( 'MCP serves your pages and posts. For an agent-readable <em>product catalog</em> (prices, variants, stock, semantic filters), install %s — MCP for content, Bridge for commerce.', 'kalicart-mcp' ),
									'<a class="kcmcp-link" href="' . esc_url( Kcmcp_Bridge_Hint::BRIDGE_URL ) . '" target="_blank" rel="noopener noreferrer">KaliCart Bridge</a>'
								),
								array(
									'em' => array(),
									'a'  => array( 'href' => array(), 'target' => array(), 'rel' => array(), 'class' => array() ),
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="kcmcp-foot">
				KaliCart MCP · <?php esc_html_e( 'ideatore / creator', 'kalicart-mcp' ); ?> <b>Giuseppe Socci</b> · <a class="kcmcp-link" href="https://mcp.kalicart.com" target="_blank" rel="noopener">mcp.kalicart.com</a>
			</div>
		</div>

		<?php
	}
}

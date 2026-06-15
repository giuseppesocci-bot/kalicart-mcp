<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_MCP_Admin
 *
 * One admin page (top-level menu with the KaliCart mark): MCP server status, the
 * content-exposure control, the agent connect-string, a ready-to-paste Claude
 * Desktop config, the tools exposed, and WooCommerce coexistence.
 */
class KaliCart_MCP_Admin {

	const SLUG = 'kalicart-mcp';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'menu_icon_css' ) );
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
		$file = KALICART_MCP_DIR . 'assets/icon.svg';
		if ( is_readable( $file ) ) {
			return 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $file ) ); // phpcs:ignore
		}
		return 'dashicons-rest-api';
	}

	/** Recolor the menu icon to the admin scheme on every theme (mask + currentColor). */
	public static function menu_icon_css(): void {
		$file = KALICART_MCP_DIR . 'assets/icon.svg';
		if ( ! is_readable( $file ) ) {
			return;
		}
		$svg = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled asset
		if ( false === $svg ) {
			return;
		}
		// URL-encode the SVG (no base64): safely embeddable in a CSS url() value.
		$uri  = 'data:image/svg+xml,' . rawurlencode( $svg );
		$sel  = '#adminmenu #toplevel_page_' . self::SLUG;
		$css  = $sel . ' .wp-menu-image{background:none!important;}';
		$css .= $sel . ' .wp-menu-image::before{content:"";display:block;width:20px;height:20px;margin:7px auto;background-color:currentColor;';
		$css .= '-webkit-mask:url("' . $uri . '") no-repeat center;-webkit-mask-size:contain;';
		$css .= 'mask:url("' . $uri . '") no-repeat center;mask-size:contain;}';
		wp_register_style( 'kalicart-mcp-menu', false, array(), KALICART_MCP_VERSION );
		wp_enqueue_style( 'kalicart-mcp-menu' );
		wp_add_inline_style( 'kalicart-mcp-menu', $css );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save the content-exposure choice.
		if ( isset( $_POST['kcmcp_save_exposure'] ) && check_admin_referer( 'kcmcp_exposure' ) ) {
			$eligible = array_keys( KaliCart_MCP_Content::eligible_post_types() );
			$chosen   = isset( $_POST['kcmcp_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['kcmcp_types'] ) ) : array();
			$chosen   = array_values( array_intersect( $eligible, $chosen ) );
			update_option( 'kcmcp_exposed_types', $chosen );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Exposure settings saved.', 'kalicart-mcp' ) . '</p></div>';
		}

		// Save the content-exclusion choices (hidden categories + hidden individual items).
		if ( isset( $_POST['kcmcp_save_exclusions'] ) && check_admin_referer( 'kcmcp_exclusions' ) ) {
			// Hidden categories.
			$terms = isset( $_POST['kcmcp_terms'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['kcmcp_terms'] ) ) : array();
			$valid = array();
			foreach ( $terms as $tid ) {
				if ( $tid > 0 && get_term( $tid, 'category' ) instanceof WP_Term ) {
					$valid[] = $tid;
				}
			}
			update_option( 'kcmcp_excluded_terms', array_values( array_unique( $valid ) ) );

			// Hidden individual items: reconcile the candidate list against the checked set.
			$candidates = isset( $_POST['kcmcp_item_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['kcmcp_item_ids'] ) ) : array();
			$checked    = isset( $_POST['kcmcp_items'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['kcmcp_items'] ) ) : array();
			foreach ( $candidates as $pid ) {
				if ( $pid <= 0 || ! current_user_can( 'edit_post', $pid ) ) {
					continue;
				}
				if ( in_array( $pid, $checked, true ) ) {
					update_post_meta( $pid, '_kcmcp_exclude', '1' );
				} else {
					delete_post_meta( $pid, '_kcmcp_exclude' );
				}
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Exclusion settings saved.', 'kalicart-mcp' ) . '</p></div>';
		}

		$endpoint  = rest_url( KALICART_MCP_API_NS . '/mcp' );
		$discovery = home_url( '/.well-known/kalicart-mcp' );
		$logo      = KALICART_MCP_URL . 'assets/logo.svg';
		$tools     = array_values( KaliCart_MCP_Server::TOOLS );
		$exposed   = array_keys( KaliCart_MCP_Content::public_post_types() );
		$key       = sanitize_title( get_bloginfo( 'name' ) );
		$key       = '' !== $key ? $key : 'my-site';

		$snippet  = "{\n  \"mcpServers\": {\n    \"" . $key . "\": {\n";
		$snippet .= "      \"command\": \"npx\",\n";
		$snippet .= "      \"args\": [\"-y\", \"mcp-remote\", \"" . $endpoint . "\", \"--transport\", \"http-only\"]\n";
		$snippet .= "    }\n  }\n}";
		?>
		<style>
			.wrap.kcmcp-wrap{width:96%;margin:24px 10px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:var(--kb-text,#1d2327);font-size:13px;max-width:1480px;}
			.kcmcp-hd{display:flex;align-items:center;gap:16px;margin:4px 0 6px;}
			.kcmcp-hd img{width:56px;height:56px;border-radius:13px;display:block;box-shadow:0 1px 4px rgba(0,0,0,.12);}
			.kcmcp-hd h1{margin:0;padding:0;font-size:23px;line-height:1.15;}
			.kcmcp-hd .tag{color:#646970;font-size:13px;margin-top:3px;}
			.kcmcp-ver{margin-left:auto;font-size:14px;color:#596067;padding:3px 10px;border-radius:999px;font-weight:600;letter-spacing:0.5px;}
			.kcmcp-card{background:#fff;border:1px solid #dcdcde;border-radius:11px;padding:18px 20px;margin:14px 0;}
			.kcmcp-card h2{margin:0 0 10px;padding:0;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:#50575e;}
			.kcmcp-row{display:flex;align-items:center;gap:9px;margin:7px 0;font-size:13px;color:#1d2327;}
			.kcmcp-dot{width:9px;height:9px;border-radius:50%;background:#46b450;flex:0 0 auto;box-shadow:0 0 0 3px rgba(70,180,80,.15);}
			.kcmcp-muted{color:#646970;font-size:13px;margin:0;}
			.kcmcp-code{display:flex;gap:8px;align-items:stretch;margin-top:8px;}
			.kcmcp-code code{flex:1;background:#1d2327;color:#f3f1f1;padding:11px 13px;border-radius:8px;font-size:12.5px;overflow:auto;white-space:nowrap;}
			.kcmcp-copy{background:#f80;border:none;color:#fff;border-radius:8px;padding:8px 16px;cursor:pointer;font-weight:600;font-size:12.5px;white-space:nowrap;}
			.kcmcp-copy:hover{background:#f90;}
			.kcmcp-pills{display:flex;flex-wrap:wrap;gap:7px;}
			.kcmcp-pill{background:#fff;color:#646a71;border:1px solid #f0f0f0;border-radius:8px;padding:4px 12px;font-size:12px;font-family:ui-monospace,Menlo,Consolas,monospace;}
			.kcmcp-sub{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin:16px 0 8px;font-weight:600;}
			.kcmcp-checklist{display:flex;flex-wrap:wrap;gap:8px 18px;}
			.kcmcp-check{display:flex;align-items:center;gap:7px;font-size:13px;color:#1d2327;cursor:pointer;}
			.kcmcp-check input{margin:0;}
			.kcmcp-itemlist{flex-direction:column;flex-wrap:nowrap;gap:6px;max-height:300px;overflow:auto;border:1px solid #f0f0f0;border-radius:8px;padding:10px 12px;background:#fafafa;}
			.kcmcp-pre{background:#1d2327;color:#f3f1f1;border-radius:8px;padding:13px;font-size:12.5px;overflow:auto;margin:8px 0 0;line-height:1.5;}
			.kcmcp-foot{color:#646970;font-size:12.5px;margin-top:20px;padding-top:13px;border-top:1px solid #e6e6e6;}
			.kcmcp-foot b{color:#f80;}
			.kcmcp-link{color:#d97600;text-decoration:none;font-weight:500;}
			.kcmcp-link:hover{color:#f80;text-decoration:underline;}
			.kcmcp-toggle{display:flex;align-items:center;gap:12px;margin:12px 0;cursor:pointer;user-select:none;}
			.kcmcp-toggle input[type=checkbox]{position:absolute;opacity:0;width:0;height:0;pointer-events:none;}
			.kcmcp-tog-track{position:relative;width:44px;height:24px;background:#c3c4c7;border-radius:12px;transition:background .18s;flex:0 0 44px;}
			.kcmcp-tog-thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:left .18s;box-shadow:0 1px 3px rgba(0,0,0,.28);}
			.kcmcp-toggle input:checked + .kcmcp-tog-track{background:#f80;}
			.kcmcp-toggle input:checked + .kcmcp-tog-track .kcmcp-tog-thumb{left:23px;}
			.kcmcp-tog-label{font-size:13px;color:#1d2327;line-height:1.4;}
			.kcmcp-tog-note{font-size:11.5px;color:#646970;margin-top:1px;}
		</style>

		<div class="wrap kcmcp-wrap">
			<div class="kcmcp-hd">
				<img src="<?php echo esc_url( $logo ); ?>" alt="KaliCart MCP" />
				<div>
					<h1>KaliCart MCP</h1>
					<div class="tag"><?php esc_html_e( 'Agent-callable WordPress content and tools', 'kalicart-mcp' ); ?></div>
				</div>
				<span class="kcmcp-ver">v<?php echo esc_html( KALICART_MCP_VERSION ); ?></span>
			</div>

			<div class="kcmcp-card">
				<h2><?php esc_html_e( 'Status', 'kalicart-mcp' ); ?></h2>
				<div class="kcmcp-row"><span class="kcmcp-dot"></span> <?php esc_html_e( 'MCP server active — this site is callable by AI agents.', 'kalicart-mcp' ); ?></div>
				<p class="kcmcp-muted"><?php echo esc_html( sprintf( /* translators: %s: protocol version */ __( 'Model Context Protocol · JSON-RPC 2.0 · protocol %s', 'kalicart-mcp' ), KaliCart_MCP_Server::PROTOCOL_VERSION ) ); ?></p>
			</div>

			<div class="kcmcp-card">
				<h2><?php esc_html_e( 'Content exposure', 'kalicart-mcp' ); ?></h2>
				<p class="kcmcp-muted"><?php esc_html_e( 'Choose which content types AI agents can read. Products (WooCommerce) are handled by KaliCart Bridge, not here.', 'kalicart-mcp' ); ?></p>
				<form method="post" style="margin-top:10px;">
					<?php wp_nonce_field( 'kcmcp_exposure' ); ?>
					<?php
					foreach ( KaliCart_MCP_Content::eligible_post_types() as $slug => $obj ) :
						$cnt      = wp_count_posts( $slug );
						$pub      = isset( $cnt->publish ) ? (int) $cnt->publish : 0;
						$reserved = ( 'page' === $slug ) ? count( KaliCart_MCP_Content::woo_reserved_page_ids() ) : 0;
						$shown    = max( 0, $pub - $reserved );
						?>
						<label class="kcmcp-toggle">
							<input type="checkbox" name="kcmcp_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $exposed, true ) ); ?> />
							<span class="kcmcp-tog-track"><span class="kcmcp-tog-thumb"></span></span>
							<span class="kcmcp-tog-label">
								<strong><?php echo esc_html( $obj->labels->name ?? $slug ); ?></strong>
								<?php if ( $reserved > 0 ) : ?>
								<span class="kcmcp-muted">(<?php echo (int) $shown; ?> of <?php echo (int) $pub; ?>)</span>
								<div class="kcmcp-tog-note"><?php echo (int) $reserved; ?> WooCommerce pages (cart, checkout, my account&hellip;) excluded automatically</div>
								<?php else : ?>
								<span class="kcmcp-muted">(<?php echo (int) $pub; ?>)</span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
					<div style="margin-top:12px;"><button type="submit" name="kcmcp_save_exposure" value="1" class="kcmcp-copy" style="padding:7px 18px;"><?php esc_html_e( 'Save', 'kalicart-mcp' ); ?></button></div>
				</form>
			</div>

			<?php
			$excl_terms = KaliCart_MCP_Content::excluded_term_ids();
			$categories = get_categories( array( 'hide_empty' => false ) );
			$reserved_ids = KaliCart_MCP_Content::woo_reserved_page_ids();
			$exposed_types = array_keys( KaliCart_MCP_Content::public_post_types() );
			$items = array();
			if ( ! empty( $exposed_types ) ) {
				$items = get_posts( array(
					'post_type'      => $exposed_types,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'exclude'        => $reserved_ids, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- small bounded set of WooCommerce functional pages in an admin-only listing.
				) );
			}
			?>
			<div class="kcmcp-card">
				<h2><?php esc_html_e( 'Content exclusions', 'kalicart-mcp' ); ?></h2>
				<p class="kcmcp-muted"><?php esc_html_e( 'Hide whole categories (handy for test or staging content) or individual posts and pages from AI agents. Hidden items disappear from listings, search, and direct retrieval.', 'kalicart-mcp' ); ?></p>
				<form method="post" style="margin-top:12px;">
					<?php wp_nonce_field( 'kcmcp_exclusions' ); ?>

					<h3 class="kcmcp-sub"><?php esc_html_e( 'Categories', 'kalicart-mcp' ); ?></h3>
					<?php if ( empty( $categories ) ) : ?>
						<p class="kcmcp-muted"><?php esc_html_e( 'No categories found.', 'kalicart-mcp' ); ?></p>
					<?php else : ?>
					<div class="kcmcp-checklist">
						<?php foreach ( $categories as $cat ) : ?>
						<label class="kcmcp-check">
							<input type="checkbox" name="kcmcp_terms[]" value="<?php echo (int) $cat->term_id; ?>" <?php checked( in_array( (int) $cat->term_id, $excl_terms, true ) ); ?> />
							<span><?php echo esc_html( $cat->name ); ?> <span class="kcmcp-muted">(<?php echo (int) $cat->count; ?>)</span></span>
						</label>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>

					<h3 class="kcmcp-sub"><?php esc_html_e( 'Individual posts and pages', 'kalicart-mcp' ); ?></h3>
					<?php if ( empty( $items ) ) : ?>
						<p class="kcmcp-muted"><?php esc_html_e( 'No exposed content yet.', 'kalicart-mcp' ); ?></p>
					<?php else : ?>
					<p class="kcmcp-muted"><?php echo esc_html( sprintf( /* translators: %d: number of items shown */ __( 'Showing the %d most recently updated items. Check an item to hide it.', 'kalicart-mcp' ), count( $items ) ) ); ?></p>
					<div class="kcmcp-checklist kcmcp-itemlist">
						<?php foreach ( $items as $it ) :
							$is_hidden = ( '1' === get_post_meta( $it->ID, '_kcmcp_exclude', true ) );
							$type_obj  = get_post_type_object( $it->post_type );
							$type_lbl  = $type_obj ? $type_obj->labels->singular_name : $it->post_type;
							?>
						<label class="kcmcp-check">
							<input type="hidden" name="kcmcp_item_ids[]" value="<?php echo (int) $it->ID; ?>" />
							<input type="checkbox" name="kcmcp_items[]" value="<?php echo (int) $it->ID; ?>" <?php checked( $is_hidden ); ?> />
							<span><?php echo esc_html( $it->post_title ? $it->post_title : __( '(no title)', 'kalicart-mcp' ) ); ?> <span class="kcmcp-muted">&middot; <?php echo esc_html( $type_lbl ); ?></span></span>
						</label>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>

					<div style="margin-top:14px;"><button type="submit" name="kcmcp_save_exclusions" value="1" class="kcmcp-copy" style="padding:7px 18px;"><?php esc_html_e( 'Save exclusions', 'kalicart-mcp' ); ?></button></div>
				</form>
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

			<?php if ( KaliCart_MCP_Bridge_Hint::woo_active() ) : ?>
				<div class="kcmcp-card">
					<h2><?php esc_html_e( 'WooCommerce', 'kalicart-mcp' ); ?></h2>
					<?php if ( KaliCart_MCP_Bridge_Hint::bridge_active() ) : ?>
						<div class="kcmcp-row"><span class="kcmcp-dot"></span> <?php esc_html_e( 'KaliCart Bridge is active — your product catalog is served to agents by the Bridge. MCP handles content, Bridge handles commerce.', 'kalicart-mcp' ); ?></div>
					<?php else : ?>
						<p class="kcmcp-muted">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: link to KaliCart Bridge */
									__( 'MCP serves your pages and posts. For an agent-readable <em>product catalog</em> (prices, variants, stock, semantic filters), install %s — MCP for content, Bridge for commerce.', 'kalicart-mcp' ),
									'<a class="kcmcp-link" href="' . esc_url( KaliCart_MCP_Bridge_Hint::BRIDGE_URL ) . '" target="_blank" rel="noopener noreferrer">KaliCart Bridge</a>'
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

		<script>
		(function(){
			document.querySelectorAll('.kcmcp-copy[data-copy]').forEach(function(btn){
				btn.addEventListener('click', function(){
					var el = document.querySelector(btn.getAttribute('data-copy'));
					if(!el){ return; }
					navigator.clipboard.writeText(el.innerText).then(function(){
						var o = btn.innerText; btn.innerText = '\u2713 ' + o; setTimeout(function(){ btn.innerText = o; }, 1200);
					});
				});
			});
		})();
		</script>
		<?php
	}
}

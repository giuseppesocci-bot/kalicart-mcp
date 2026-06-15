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
		add_action( 'admin_head', array( __CLASS__, 'menu_icon_css' ) );
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
		$uri = 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $file ) ); // phpcs:ignore
		echo '<style>'
			. '#adminmenu #toplevel_page_' . esc_attr( self::SLUG ) . ' .wp-menu-image{background:none!important;}'
			. '#adminmenu #toplevel_page_' . esc_attr( self::SLUG ) . ' .wp-menu-image::before{content:"";display:block;width:20px;height:20px;margin:7px auto;background-color:currentColor;'
			. '-webkit-mask:url(\'' . $uri . '\') no-repeat center;-webkit-mask-size:contain;'
			. 'mask:url(\'' . $uri . '\') no-repeat center;mask-size:contain;}'
			. '</style>'; // phpcs:ignore
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
			.kcmcp-ver{margin-left:auto;font-size:12px;color:#8a5a00;background:#fff4e6;border:1px solid #ffd9a8;padding:3px 10px;border-radius:999px;font-weight:600;}
			.kcmcp-card{background:#fff;border:1px solid #dcdcde;border-radius:11px;padding:18px 20px;margin:14px 0;}
			.kcmcp-card h2{margin:0 0 10px;padding:0;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:#50575e;}
			.kcmcp-row{display:flex;align-items:center;gap:9px;margin:7px 0;font-size:13px;color:#1d2327;}
			.kcmcp-dot{width:9px;height:9px;border-radius:50%;background:#46b450;flex:0 0 auto;box-shadow:0 0 0 3px rgba(70,180,80,.15);}
			.kcmcp-muted{color:#646970;font-size:13px;margin:0;}
			.kcmcp-code{display:flex;gap:8px;align-items:stretch;margin-top:8px;}
			.kcmcp-code code{flex:1;background:#1d2327;color:#f3f1f1;padding:11px 13px;border-radius:8px;font-size:12.5px;overflow:auto;white-space:nowrap;}
			.kcmcp-copy{background:#f80;border:none;color:#fff;border-radius:8px;padding:0 16px;cursor:pointer;font-weight:600;font-size:12.5px;white-space:nowrap;}
			.kcmcp-copy:hover{background:#f90;}
			.kcmcp-pills{display:flex;flex-wrap:wrap;gap:7px;}
			.kcmcp-pill{background:#fff4e6;color:#9a5400;border:1px solid #ffd9a8;border-radius:999px;padding:4px 12px;font-size:12px;font-family:ui-monospace,Menlo,Consolas,monospace;}
			.kcmcp-pre{background:#1d2327;color:#f3f1f1;border-radius:8px;padding:13px;font-size:12.5px;overflow:auto;margin:8px 0 0;line-height:1.5;}
			.kcmcp-foot{color:#646970;font-size:12.5px;margin-top:20px;padding-top:13px;border-top:1px solid #e6e6e6;}
			.kcmcp-foot b{color:#f80;}
			.kcmcp-link{color:#d97600;text-decoration:none;font-weight:500;}
			.kcmcp-link:hover{color:#f80;text-decoration:underline;}
			.kcmcp-check{display:flex;align-items:center;gap:9px;margin:8px 0;font-size:13px;}
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
						$cnt = wp_count_posts( $slug );
						$pub = isset( $cnt->publish ) ? (int) $cnt->publish : 0;
						?>
						<label class="kcmcp-check">
							<input type="checkbox" name="kcmcp_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $exposed, true ) ); ?> />
							<span><strong><?php echo esc_html( $obj->labels->name ?? $slug ); ?></strong> <span class="kcmcp-muted">(<?php echo (int) $pub; ?>)</span></span>
						</label>
					<?php endforeach; ?>
					<div style="margin-top:12px;"><button type="submit" name="kcmcp_save_exposure" value="1" class="kcmcp-copy" style="padding:7px 18px;"><?php esc_html_e( 'Save', 'kalicart-mcp' ); ?></button></div>
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

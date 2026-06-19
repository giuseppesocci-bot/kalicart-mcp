<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kcmcp_Meta_Box
 *
 * Adds a "KaliCart MCP" sidebar box to every eligible content type (post, page,
 * and any public navigable CPT that is not commerce). A single checkbox lets the
 * owner exclude one piece of content from all agent queries without touching its
 * published status on the site.
 *
 * Works in the Classic Editor, the Block Editor (via register_post_meta /
 * show_in_rest), and Elementor (which uses the Classic editor sidebar).
 */
class Kcmcp_Meta_Box {

	const META_KEY = '_kcmcp_exclude';

	public static function init(): void {
		add_action( 'init',          array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_boxes' ) );
		add_action( 'save_post',      array( __CLASS__, 'save' ), 10, 2 );
	}

	/** Register the meta key so the Block Editor can read/write it via REST. */
	public static function register_meta(): void {
		foreach ( array_keys( Kcmcp_Content::eligible_post_types() ) as $pt ) {
			register_post_meta(
				$pt,
				self::META_KEY,
				array(
					'type'          => 'boolean',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/** Add the sidebar meta box to every eligible post type. */
	public static function add_boxes(): void {
		foreach ( array_keys( Kcmcp_Content::eligible_post_types() ) as $pt ) {
			add_meta_box(
				'kcmcp-visibility',
				'KaliCart MCP',
				array( __CLASS__, 'render' ),
				$pt,
				'side',
				'low'
			);
		}
	}

	/** Render the meta box HTML. */
	public static function render( WP_Post $post ): void {
		wp_nonce_field( 'kcmcp_exclude_' . $post->ID, 'kcmcp_exclude_nonce' );
		$excluded = '1' === get_post_meta( $post->ID, self::META_KEY, true );
		?>
		<label style="display:flex;align-items:flex-start;gap:7px;margin:6px 0 4px;font-size:13px;cursor:pointer;">
			<input type="checkbox" name="kcmcp_exclude" value="1" style="margin-top:2px;" <?php checked( $excluded ); ?> />
			<span><?php esc_html_e( 'Hide from AI agents', 'kalicart-mcp' ); ?></span>
		</label>
		<p style="color:#646970;font-size:12px;margin:4px 0 0;line-height:1.5;">
			<?php
			if ( $excluded ) {
				esc_html_e( 'This content is currently hidden from all AI agents.', 'kalicart-mcp' );
			} else {
				esc_html_e( 'This content is visible to AI agents via KaliCart MCP.', 'kalicart-mcp' );
			}
			?>
		</p>
		<?php
	}

	/** Persist the checkbox on Classic Editor / Elementor save. */
	public static function save( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['kcmcp_exclude_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['kcmcp_exclude_nonce'] ) ), 'kcmcp_exclude_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['kcmcp_exclude'] ) && '1' === $_POST['kcmcp_exclude'] ) {
			update_post_meta( $post_id, self::META_KEY, '1' );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}
}


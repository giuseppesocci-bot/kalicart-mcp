<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_MCP_Content
 *
 * Read-only navigation + retrieval over WordPress core content
 * (posts, pages, public custom post types, taxonomies, menus).
 * No external calls. Returns plain arrays consumed by the MCP server.
 */
class KaliCart_MCP_Content {

	/** Candidate content types: public + navigable, minus media and commerce. */
	public static function eligible_post_types(): array {
		// Public AND navigable (show_in_nav_menus=true): keeps post/page and genuine
		// content CPTs; drops elementor_library / e-floating-buttons (public but not
		// navigable destinations). Structural rule, not a per-plugin blocklist.
		$types = get_post_types( array( 'public' => true, 'show_in_nav_menus' => true ), 'objects' );

		// Commerce is the Bridge's domain — MCP serves editorial content only.
		foreach ( self::excluded_types() as $slug ) {
			unset( $types[ $slug ] );
		}
		return $types;
	}

	/** Types actually exposed to agents: eligible types filtered by the owner's choice. */
	public static function public_post_types(): array {
		$types  = self::eligible_post_types();
		$chosen = get_option( 'kcmcp_exposed_types', null );
		if ( is_array( $chosen ) ) {
			foreach ( array_keys( $types ) as $slug ) {
				if ( ! in_array( $slug, $chosen, true ) ) {
					unset( $types[ $slug ] );
				}
			}
		}
		return $types;
	}

	/** Types MCP never treats as content: media + commerce (the Bridge owns products). */
	private static function excluded_types(): array {
		return array( 'attachment', 'product', 'product_variation' );
	}

	public static function site_info(): array {
		$front_id = (int) get_option( 'page_on_front' );
		$blog_id  = (int) get_option( 'page_for_posts' );

		$types = array();
		foreach ( self::public_post_types() as $slug => $obj ) {
			$count   = wp_count_posts( $slug );
			$types[] = array(
				'slug'      => $slug,
				'label'     => $obj->labels->name ?? $slug,
				'published' => isset( $count->publish ) ? (int) $count->publish : 0,
			);
		}

		// Only taxonomies attached to the exposed content types (drops Woo product
		// taxonomies once products are excluded).
		$tax_slugs = array();
		foreach ( array_keys( self::public_post_types() ) as $pt ) {
			foreach ( get_object_taxonomies( $pt ) as $tx ) {
				$tax_slugs[ $tx ] = true;
			}
		}
		$taxes = array();
		foreach ( array_keys( $tax_slugs ) as $slug ) {
			$obj = get_taxonomy( $slug );
			if ( ! $obj || empty( $obj->public ) ) {
				continue;
			}
			$taxes[] = array(
				'slug'  => $slug,
				'label' => $obj->labels->name ?? $slug,
				'terms' => (int) wp_count_terms( array( 'taxonomy' => $slug, 'hide_empty' => false ) ),
			);
		}

		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url( '/' ),
			'language'    => get_bloginfo( 'language' ),
			'front_page'  => $front_id ? self::ref( $front_id ) : array( 'type' => 'posts', 'note' => 'Front page shows the latest posts.' ),
			'posts_page'  => $blog_id ? self::ref( $blog_id ) : null,
			'post_types'  => $types,
			'taxonomies'  => $taxes,
			'generator'   => 'KaliCart MCP ' . KALICART_MCP_VERSION,
		);
	}

	public static function site_map(): array {
		$menus = array();
		foreach ( (array) get_nav_menu_locations() as $location => $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );
			if ( ! $items ) {
				continue;
			}
			$links = array();
			foreach ( $items as $it ) {
				$links[] = array(
					'title'  => $it->title,
					'url'    => $it->url,
					'parent' => (int) $it->menu_item_parent,
				);
			}
			$menus[] = array( 'location' => $location, 'items' => $links );
		}

		$pages = array();
		foreach ( get_pages( array( 'sort_column' => 'menu_order,post_title', 'number' => 200 ) ) as $p ) {
			if ( '1' === get_post_meta( $p->ID, '_kcmcp_exclude', true ) ) {
				continue;
			}
			$pages[] = array(
				'id'     => (int) $p->ID,
				'title'  => $p->post_title,
				'url'    => get_permalink( $p ),
				'parent' => (int) $p->post_parent,
			);
		}

		return array( 'menus' => $menus, 'pages' => $pages );
	}

	public static function list_content( array $args ): array {
		$type     = self::sanitize_type( $args['post_type'] ?? 'post' );
		$per_page = self::clamp( (int) ( $args['per_page'] ?? 20 ), 1, 100 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$orderby  = in_array( ( $args['orderby'] ?? 'date' ), array( 'date', 'title', 'menu_order' ), true ) ? $args['orderby'] : 'date';

		$q_args = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => ( strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ) ? 'ASC' : 'DESC',
		);
		if ( ! empty( $args['category'] ) ) {
			$q_args['category_name'] = sanitize_title( (string) $args['category'] );
		}
		if ( ! empty( $args['tag'] ) ) {
			$q_args['tag'] = sanitize_title( (string) $args['tag'] );
		}

		// Exclude items the owner has hidden from agents.
		$q_args['meta_query'] = array(
			'relation' => 'OR',
			array( 'key' => '_kcmcp_exclude', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_kcmcp_exclude', 'value' => '1', 'compare' => '!=' ),
		);
		$query = new WP_Query( $q_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::summary( $post );
		}

		return array(
			'post_type'   => $type,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'items'       => $items,
		);
	}

	public static function search_content( array $args ): array {
		$term     = trim( (string) ( $args['q'] ?? '' ) );
		$per_page = self::clamp( (int) ( $args['per_page'] ?? 20 ), 1, 50 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		if ( '' === $term ) {
			return array( 'success' => false, 'error' => 'Missing required argument: q' );
		}

		$types = ! empty( $args['post_type'] )
			? self::sanitize_type( $args['post_type'] )
			: array_keys( self::public_post_types() );

		$query = new WP_Query( array(
			's'              => $term,
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_kcmcp_exclude', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_kcmcp_exclude', 'value' => '1', 'compare' => '!=' ),
			),
		) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::summary( $post );
		}

		return array(
			'query'       => $term,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'items'       => $items,
		);
	}

	public static function get_content( array $args ): array {
		$id = isset( $args['id'] ) ? absint( $args['id'] ) : 0;

		if ( ! $id && ! empty( $args['slug'] ) ) {
			$type = self::sanitize_type( $args['post_type'] ?? 'post' );
			$post = get_page_by_path( sanitize_title( (string) $args['slug'] ), OBJECT, is_array( $type ) ? 'post' : $type );
			$id   = $post ? (int) $post->ID : 0;
		}

		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'publish' !== $post->post_status ) {
			return array( 'success' => false, 'error' => 'Content not found or not public.' );
		}
		if ( ! array_key_exists( $post->post_type, self::public_post_types() ) ) {
			return array( 'success' => false, 'error' => 'Content not found or not public.' );
		}
		if ( '1' === get_post_meta( $post->ID, '_kcmcp_exclude', true ) ) {
			return array( 'success' => false, 'error' => 'Content not found or not public.' );
		}

		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content );
		wp_reset_postdata();

		return array(
			'id'         => (int) $post->ID,
			'type'       => $post->post_type,
			'title'      => get_the_title( $post ),
			'url'        => get_permalink( $post ),
			'date'       => get_post_time( 'c', true, $post ),
			'modified'   => get_post_modified_time( 'c', true, $post ),
			'excerpt'    => self::excerpt( $post ),
			'terms'      => self::terms( $post ),
			'markdown'   => KaliCart_MCP_Markdown::from_html( (string) $html ),
			'word_count' => str_word_count( wp_strip_all_tags( (string) $html ) ),
		);
	}

	private static function summary( \WP_Post $post ): array {
		return array(
			'id'      => (int) $post->ID,
			'type'    => $post->post_type,
			'title'   => get_the_title( $post ),
			'url'     => get_permalink( $post ),
			'date'    => get_post_time( 'c', true, $post ),
			'excerpt' => self::excerpt( $post ),
		);
	}

	private static function ref( int $id ): array {
		return array( 'id' => $id, 'title' => get_the_title( $id ), 'url' => get_permalink( $id ) );
	}

	private static function excerpt( \WP_Post $post ): string {
		$ex = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '…' );
		return trim( (string) $ex );
	}

	private static function terms( \WP_Post $post ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$tobj = get_taxonomy( $tax );
			if ( ! $tobj || empty( $tobj->public ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax );
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}
			$names = array();
			foreach ( $terms as $t ) {
				$names[] = array( 'name' => $t->name, 'slug' => $t->slug );
			}
			$out[ $tax ] = $names;
		}
		return $out;
	}

	private static function sanitize_type( $type ) {
		$valid    = array_keys( self::public_post_types() );
		$fallback = $valid ? $valid[0] : '__kcmcp_none__';
		if ( is_array( $type ) ) {
			$clean = array_values( array_intersect( array_map( 'sanitize_key', $type ), $valid ) );
			return ! empty( $clean ) ? $clean : $fallback;
		}
		$type = sanitize_key( (string) $type );
		return in_array( $type, $valid, true ) ? $type : $fallback;
	}

	private static function clamp( int $v, int $min, int $max ): int {
		return max( $min, min( $max, $v ) );
	}
}

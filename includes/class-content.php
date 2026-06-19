<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kcmcp_Content
 *
 * Read-only navigation + retrieval over WordPress core content
 * (posts, pages, public custom post types, taxonomies, menus).
 * No external calls. Returns plain arrays consumed by the MCP server.
 */
class Kcmcp_Content {

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

	/** Category term IDs the owner has chosen to hide from agents (taxonomy: category). */
	public static function excluded_term_ids(): array {
		$ids = get_option( 'kcmcp_excluded_terms', array() );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * The site's primary content language, or null on monolingual sites.
	 *
	 * When a multilingual plugin (Polylang) is active, the MCP serves content in
	 * the site's DEFAULT language only. Rationale: agents translate on demand far
	 * better than per-site translation pipelines, and exposing every language would
	 * return the same content duplicated once per language with no way for the agent
	 * to know they are the same resource. Serving one declared language keeps the
	 * JSON regular and coherent. On sites without a multilingual plugin this returns
	 * null and nothing changes.
	 */
	public static function default_language() {
		// Polylang
		if ( function_exists( 'pll_default_language' ) ) {
			$lang = pll_default_language();
			return $lang ? (string) $lang : null;
		}
		// WPML (and compatible plugins exposing the wpml_default_language filter)
		if ( has_filter( 'wpml_default_language' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook owned by WPML; must be called by its documented name to integrate.
			$lang = apply_filters( 'wpml_default_language', null );
			return $lang ? (string) $lang : null;
		}
		return null;
	}

	/**
	 * Restrict a WP_Query args array to the primary language, when a multilingual
	 * plugin is active. Polylang understands the \'lang\' query var directly; WPML
	 * switches the global language context via the \'wpml_switch_language\' action
	 * before the query runs. Monolingual sites: no-op. Returns the (possibly
	 * modified) args; WPML side-effects are applied as a side effect.
	 */
	public static function apply_primary_language( array $q_args ) {
		$lang = self::default_language();
		if ( null === $lang ) {
			return $q_args;
		}
		if ( function_exists( 'pll_default_language' ) ) {
			$q_args['lang'] = $lang;            // Polylang query var
		} elseif ( has_action( 'wpml_switch_language' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook owned by WPML; must be called by its documented name to integrate.
			do_action( 'wpml_switch_language', $lang ); // WPML global context
		}
		return $q_args;
	}

	/** IDs of WooCommerce functional pages — excluded as application UI, not editorial content. */
	public static function woo_reserved_page_ids(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}
		$ids = array();
		foreach ( array(
			'woocommerce_shop_page_id',
			'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_terms_page_id',
			'woocommerce_privacy_policy_page_id',
			'woocommerce_refund_returns_page_id',
		) as $key ) {
			$id = (int) get_option( $key );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_unique( $ids );
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
		$primary_lang = self::default_language();
		$taxes = array();
		foreach ( array_keys( $tax_slugs ) as $slug ) {
			$obj = get_taxonomy( $slug );
			if ( ! $obj || empty( $obj->public ) ) {
				continue;
			}
			// Count terms in the primary language only, so multilingual sites do not
			// report the same category once per language (Polylang via 'lang',
			// WPML via global context). No-op when monolingual.
			$count_args = self::apply_primary_language( array( 'taxonomy' => $slug, 'hide_empty' => false ) );
			$taxes[] = array(
				'slug'  => $slug,
				'label' => $obj->labels->name ?? $slug,
				'terms' => (int) wp_count_terms( $count_args ),
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
			'served_language' => $primary_lang,
			'multilingual'    => ( null !== $primary_lang ),
			'generator'   => 'KaliCart MCP ' . KCMCP_VERSION,
		);
	}

	/**
	 * A nav-menu item must be hidden from the agent map when it points to a
	 * commerce surface: a Woo object (product), a Woo taxonomy term
	 * (product_cat / product_tag / product_shipping_class), a WooCommerce
	 * functional page, or an item the owner flagged with _kcmcp_exclude.
	 * MCP is content-only: navigation into the shop is not content.
	 */
	private static function menu_item_is_excluded( $it ): bool {
		$object    = isset( $it->object ) ? (string) $it->object : '';
		$object_id = isset( $it->object_id ) ? (int) $it->object_id : 0;

		// Post-type objects that are structurally excluded (product, attachment, ...).
		if ( 'post_type' === $it->type && in_array( $object, self::excluded_types(), true ) ) {
			return true;
		}

		// Woo taxonomy terms surfaced as menu items.
		if ( 'taxonomy' === $it->type && in_array( $object, array( 'product_cat', 'product_tag', 'product_shipping_class' ), true ) ) {
			return true;
		}

		// WooCommerce functional pages (shop/cart/checkout/account/policies).
		if ( 'post_type' === $it->type && 'page' === $object && in_array( $object_id, self::woo_reserved_page_ids(), true ) ) {
			return true;
		}

		// Per-item owner opt-out on the linked object.
		if ( $object_id > 0 && '1' === get_post_meta( $object_id, '_kcmcp_exclude', true ) ) {
			return true;
		}

		return false;
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
				if ( self::menu_item_is_excluded( $it ) ) {
					continue;
				}
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
			if ( in_array( $p->ID, self::woo_reserved_page_ids(), true ) ) {
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
		// Explicit post_type that is not an exposed type -> honest error, never a silent fallback.
		if ( isset( $args['post_type'] ) && '' !== $args['post_type'] ) {
			$requested = sanitize_key( (string) $args['post_type'] );
			if ( ! in_array( $requested, array_keys( self::public_post_types() ), true ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'post_type "%s" is not exposed by this server. Allowed: %s', $requested, implode( ', ', array_keys( self::public_post_types() ) ) ),
				);
			}
		}
		$type     = self::sanitize_type( $args['post_type'] ?? 'post' );
		$per_page = self::clamp( (int) ( $args['per_page'] ?? 20 ), 1, 100 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$orderby_in = (string) ( $args['orderby'] ?? 'date' );
		$orderby    = in_array( $orderby_in, array( 'date', 'title', 'menu_order' ), true ) ? $orderby_in : 'date';

		$q_args = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => ( strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ) ? 'ASC' : 'DESC',
		);
		// Multilingual: serve the site's primary language only (Polylang or WPML).
		$primary_lang = self::default_language();
		$q_args = self::apply_primary_language( $q_args );
		if ( ! empty( $args['category'] ) ) {
			$q_args['category_name'] = sanitize_title( (string) $args['category'] );
		}
		if ( ! empty( $args['tag'] ) ) {
			$q_args['tag'] = sanitize_title( (string) $args['tag'] );
		}

		// Exclude WooCommerce functional pages (cart, checkout, my account, shop, etc.).
		$not_in = self::woo_reserved_page_ids();
		if ( ! empty( $not_in ) ) {
			$q_args['post__not_in'] = $not_in; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- small bounded set of WooCommerce functional pages, required to keep commerce UI out of editorial content.
		}

		// Exclude items the owner has hidden from agents.
		$q_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- owner opt-out flag, bounded by pagination.
			'relation' => 'OR',
			array( 'key' => '_kcmcp_exclude', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_kcmcp_exclude', 'value' => '1', 'compare' => '!=' ),
		);

		// Exclude items in owner-hidden categories.
		$excl_terms = self::excluded_term_ids();
		if ( ! empty( $excl_terms ) && is_object_in_taxonomy( $type, 'category' ) ) {
			$q_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded owner-selected category set.
				array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => $excl_terms,
					'operator' => 'NOT IN',
				),
			);
		}
		$query = new WP_Query( $q_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::summary( $post );
		}

		$out = array(
			'post_type'   => $type,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'items'       => $items,
		);
		// Declare the served language so the agent never has to guess (multilingual sites).
		if ( null !== $primary_lang ) {
			$out['language'] = $primary_lang;
		}
		return $out;
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

		$s_not_in = self::woo_reserved_page_ids();
		$primary_lang = self::default_language();
		$s_args   = array(
			's'              => $term,
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post__not_in'   => $s_not_in, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- small bounded set of WooCommerce functional pages.
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- owner opt-out flag, bounded by pagination.
				'relation' => 'OR',
				array( 'key' => '_kcmcp_exclude', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_kcmcp_exclude', 'value' => '1', 'compare' => '!=' ),
			),
		);

		// Exclude items in owner-hidden categories.
		$excl_terms = self::excluded_term_ids();
		if ( ! empty( $excl_terms ) ) {
			$s_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded owner-selected category set.
				array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => $excl_terms,
					'operator' => 'NOT IN',
				),
			);
		}
		$s_args = self::apply_primary_language( $s_args );
		$query  = new WP_Query( $s_args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::summary( $post );
		}

		$out = array(
			'query'       => $term,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'items'       => $items,
		);
		if ( null !== $primary_lang ) {
			$out['language'] = $primary_lang;
		}
		return $out;
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
		if ( in_array( $post->ID, self::woo_reserved_page_ids(), true ) ) {
			return array( 'success' => false, 'error' => 'Content not found or not public.' );
		}
		$excl_terms = self::excluded_term_ids();
		if ( ! empty( $excl_terms ) && has_category( $excl_terms, $post ) ) {
			return array( 'success' => false, 'error' => 'Content not found or not public.' );
		}

		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying WordPress core filter, not registering a custom hook.
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
			'markdown'   => Kcmcp_Markdown::from_html( (string) $html ),
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
			// Taxonomy terms (categories, tags) so list/search items are self-contained:
			// an agent sees each item's category without a follow-up get_content call.
			// Empty for content types without public taxonomies (e.g. pages).
			'terms'   => self::terms( $post ),
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

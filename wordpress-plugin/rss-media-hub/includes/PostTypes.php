<?php
/**
 * Custom post type and taxonomies for imported media items.
 *
 * @package RSSMediaHub
 */

namespace RMH;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `rmh_item` (permalinks under /media/) and its taxonomies.
 */
class PostTypes {

	const POST_TYPE    = 'rmh_item';
	const TAX_CATEGORY = 'rmh_category';
	const TAX_TAG      = 'rmh_tag';
	const TAX_TYPE     = 'rmh_media_type';
	const TAX_SOURCE   = 'rmh_source';

	/**
	 * Hook registration.
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ), 5 );
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_value' ), 10, 2 );
	}

	/**
	 * Flush rewrites once after an upgrade (never on every request).
	 */
	public function maybe_flush() {
		if ( get_option( 'rmh_flush_rewrites' ) ) {
			delete_option( 'rmh_flush_rewrites' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Media type labels.
	 *
	 * @return array<string,string>
	 */
	public static function media_type_labels() {
		return array(
			'video'   => __( 'Video', 'rss-media-hub' ),
			'audio'   => __( 'Audio', 'rss-media-hub' ),
			'image'   => __( 'Image', 'rss-media-hub' ),
			'gallery' => __( 'Gallery', 'rss-media-hub' ),
			'embed'   => __( 'External video', 'rss-media-hub' ),
			'text'    => __( 'Article', 'rss-media-hub' ),
		);
	}

	/**
	 * Register post type and taxonomies.
	 */
	public function register() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		$slug = rmh_permalink_base();

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Media Posts', 'rss-media-hub' ),
					'singular_name'      => __( 'Media Post', 'rss-media-hub' ),
					'add_new_item'       => __( 'Add Media Post', 'rss-media-hub' ),
					'edit_item'          => __( 'Edit Media Post', 'rss-media-hub' ),
					'view_item'          => __( 'View Media Post', 'rss-media-hub' ),
					'search_items'       => __( 'Search Media Posts', 'rss-media-hub' ),
					'not_found'          => __( 'No media posts found.', 'rss-media-hub' ),
					'not_found_in_trash' => __( 'No media posts found in Trash.', 'rss-media-hub' ),
					'all_items'          => __( 'Media Posts', 'rss-media-hub' ),
				),
				'public'          => true,
				'has_archive'     => $slug,
				'rewrite'         => array(
					'slug'       => $slug,
					'with_front' => false,
				),
				'show_in_menu'    => 'rmh-dashboard',
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-format-video',
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		register_taxonomy(
			self::TAX_CATEGORY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Media Categories', 'rss-media-hub' ),
					'singular_name' => __( 'Media Category', 'rss-media-hub' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => $slug . '/category',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			self::TAX_TAG,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Media Tags', 'rss-media-hub' ),
					'singular_name' => __( 'Media Tag', 'rss-media-hub' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => $slug . '/tag',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			self::TAX_TYPE,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Media Types', 'rss-media-hub' ),
					'singular_name' => __( 'Media Type', 'rss-media-hub' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => false,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => $slug . '/type',
					'with_front' => false,
				),
				'capabilities'      => array(
					'manage_terms' => 'rmh_manage_feeds',
					'edit_terms'   => 'rmh_manage_feeds',
					'delete_terms' => 'rmh_manage_feeds',
					'assign_terms' => 'edit_posts',
				),
			)
		);

		register_taxonomy(
			self::TAX_SOURCE,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Sources', 'rss-media-hub' ),
					'singular_name' => __( 'Source', 'rss-media-hub' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => false,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => $slug . '/source',
					'with_front' => false,
				),
				'capabilities'      => array(
					'manage_terms' => 'rmh_manage_feeds',
					'edit_terms'   => 'rmh_manage_feeds',
					'delete_terms' => 'rmh_manage_feeds',
					'assign_terms' => 'edit_posts',
				),
			)
		);

		foreach ( self::meta_keys() as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Post meta keys written by the importer.
	 *
	 * @return array<string,string>
	 */
	public static function meta_keys() {
		return array(
			'_rmh_item_id'          => 'integer',
			'_rmh_feed_id'          => 'integer',
			'_rmh_source_url'       => 'string',
			'_rmh_guid'             => 'string',
			'_rmh_source_published' => 'integer',
			'_rmh_media_type'       => 'string',
			'_rmh_author'           => 'string',
			'_rmh_content_rating'   => 'string',
			'_rmh_thumbnail_url'    => 'string',
			'_rmh_synced'           => 'string',
			'_rmh_hidden_reason'    => 'string',
			'_rmh_views'            => 'integer',
		);
	}

	/**
	 * Ensure fixed media-type terms exist.
	 */
	public static function ensure_media_type_terms() {
		foreach ( self::media_type_labels() as $slug => $label ) {
			if ( ! term_exists( $slug, self::TAX_TYPE ) ) {
				wp_insert_term( $label, self::TAX_TYPE, array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * Admin list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$columns['rmh_rating'] = __( 'Rating', 'rss-media-hub' );
		return $columns;
	}

	/**
	 * Admin column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 */
	public function column_value( $column, $post_id ) {
		if ( 'rmh_rating' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, '_rmh_content_rating', true ) );
		}
	}
}

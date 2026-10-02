<?php
/**
 * Public site integration: routing, templates, assets, thumbnails, headers.
 *
 * @package RSSMediaHub
 */

namespace RMH\Frontend;

use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the reader into the active theme (classic or block theme).
 */
class Frontend {

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( __CLASS__, 'add_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_filter( 'template_include', array( $this, 'template_include' ), 99 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'post_thumbnail_html', array( $this, 'remote_thumbnail_html' ), 10, 5 );
		add_filter( 'has_post_thumbnail', array( $this, 'has_remote_thumbnail' ), 10, 3 );
		add_action( 'wp_head', array( $this, 'head_meta' ), 1 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_action( 'template_redirect', array( $this, 'security_headers' ), 5 );
		add_shortcode( 'rmh_reader', array( Reader::class, 'shortcode' ) );
		add_shortcode( 'rmh_feed_directory', array( $this, 'directory_shortcode' ) );
	}

	/**
	 * Rewrite for the feed directory.
	 */
	public static function add_rewrites() {
		add_rewrite_rule( '^' . rmh_permalink_base() . '/sources/?$', 'index.php?rmh_directory=1', 'top' );
	}

	/**
	 * Register query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'rmh_directory';
		return $vars;
	}

	/**
	 * Is the current request a plugin page?
	 *
	 * @return bool
	 */
	public static function is_rmh_request() {
		if ( is_admin() ) {
			return false;
		}
		return is_singular( PostTypes::POST_TYPE )
			|| is_post_type_archive( PostTypes::POST_TYPE )
			|| is_tax( array( PostTypes::TAX_CATEGORY, PostTypes::TAX_TAG, PostTypes::TAX_TYPE, PostTypes::TAX_SOURCE ) )
			|| (bool) get_query_var( 'rmh_directory' )
			|| self::home_is_reader();
	}

	/**
	 * Is the reader configured as the blog home?
	 *
	 * @return bool
	 */
	public static function home_is_reader() {
		return is_home() && is_front_page() && (bool) rmh()->get( 'settings' )->get( 'show_on_home' );
	}

	/**
	 * Apply reader filters to the main query.
	 *
	 * @param \WP_Query $q Query.
	 */
	public function pre_get_posts( $q ) {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}
		if ( $q->get( 'rmh_directory' ) ) {
			$q->set( 'post_type', PostTypes::POST_TYPE );
			$q->set( 'posts_per_page', 1 );
			$q->is_home    = false;
			$q->is_404     = false;
			$q->is_archive = true;
			return;
		}
		$home = $q->is_home() && $q->is_front_page() && rmh()->get( 'settings' )->get( 'show_on_home' ) && 'posts' === get_option( 'show_on_front' );
		if ( $home || $q->is_post_type_archive( PostTypes::POST_TYPE ) || $q->is_tax( array( PostTypes::TAX_CATEGORY, PostTypes::TAX_TAG, PostTypes::TAX_TYPE, PostTypes::TAX_SOURCE ) ) ) {
			Reader::apply( $q, Reader::current_filters() );
		}
	}

	/**
	 * Use plugin (or theme-overridden) templates.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public function template_include( $template ) {
		if ( get_query_var( 'rmh_directory' ) ) {
			status_header( 200 );
			return Template::locate( 'directory' );
		}
		if ( is_singular( PostTypes::POST_TYPE ) ) {
			return Template::locate( 'single' );
		}
		if ( is_post_type_archive( PostTypes::POST_TYPE ) || is_tax( array( PostTypes::TAX_CATEGORY, PostTypes::TAX_TAG, PostTypes::TAX_TYPE, PostTypes::TAX_SOURCE ) ) || self::home_is_reader() ) {
			return Template::locate( 'archive' );
		}
		return $template;
	}

	/**
	 * Open the page using the theme's header (classic) or header template part (block theme).
	 */
	public static function open_page() {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
			<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
			<?php wp_body_open(); ?>
<div class="wp-site-blocks">
			<?php
			block_template_part( 'header' );
			echo '<div class="rmh-wrap">';
			return;
		}
		get_header();
		echo '<div class="rmh-wrap">';
	}

	/**
	 * Close the page.
	 */
	public static function close_page() {
		echo '</div>';
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			block_template_part( 'footer' );
			echo '</div>';
			wp_footer();
			echo "</body>\n</html>\n";
			return;
		}
		get_footer();
	}

	/**
	 * Enqueue assets only where needed.
	 */
	public function assets() {
		global $post;
		$needed = self::is_rmh_request() || ( is_singular() && $post && ( has_shortcode( $post->post_content, 'rmh_reader' ) || has_shortcode( $post->post_content, 'rmh_feed_directory' ) ) );
		if ( ! $needed ) {
			return;
		}
		wp_enqueue_style( 'rmh-frontend', RMH_URL . 'assets/css/frontend.css', array(), RMH_VERSION );
		wp_enqueue_script( 'rmh-frontend', RMH_URL . 'assets/js/frontend.js', array(), RMH_VERSION, true );
		$settings = rmh()->get( 'settings' );
		wp_localize_script(
			'rmh-frontend',
			'RMH',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'rmh/v1/' ) ),
				'analytics' => (bool) $settings->get( 'analytics_enabled' ),
				'ageGate'   => $settings->get( 'age_gate_enabled' ) && 'overlay' === $settings->get( 'age_gate_mode' ) && rmh()->get( 'age_gate' )->applies_to_request(),
				'minAge'    => (int) $settings->get( 'minimum_age' ),
				'i18n'      => array(
					'loading'   => __( 'Loading…', 'rss-media-hub' ),
					'loadError' => __( 'Could not load more items. Please try again.', 'rss-media-hub' ),
					'sending'   => __( 'Sending…', 'rss-media-hub' ),
					'sent'      => __( 'Thank you. Your report was received.', 'rss-media-hub' ),
					'sendError' => __( 'Your report could not be sent. Please try again later.', 'rss-media-hub' ),
					'copied'    => __( 'Link copied', 'rss-media-hub' ),
					'close'     => __( 'Close', 'rss-media-hub' ),
					'prev'      => __( 'Previous image', 'rss-media-hub' ),
					'next'      => __( 'Next image', 'rss-media-hub' ),
					'gateTitle' => __( 'Adults only', 'rss-media-hub' ),
					'gateText'  => (string) ( $settings->get( 'age_gate_message' ) ? $settings->get( 'age_gate_message' ) : __( 'This site contains sexually explicit material intended for adults only.', 'rss-media-hub' ) ),
					'gateEnter' => sprintf( /* translators: %d: age */ __( 'I am %d or older – enter', 'rss-media-hub' ), (int) $settings->get( 'minimum_age' ) ),
					'gateLeave' => __( 'Leave', 'rss-media-hub' ),
				),
			)
		);
	}

	/**
	 * Remote thumbnail when no local featured image exists.
	 *
	 * @param string       $html              HTML.
	 * @param int          $post_id           Post.
	 * @param int          $post_thumbnail_id Attachment.
	 * @param string|int[] $size              Size.
	 * @param string|array $attr              Attributes.
	 * @return string
	 */
	public function remote_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
		if ( '' !== $html || PostTypes::POST_TYPE !== get_post_type( $post_id ) ) {
			return $html;
		}
		$url = Template::thumbnail_url( $post_id );
		if ( '' === $url ) {
			return $html;
		}
		$gate = rmh()->get( 'age_gate' );
		if ( $gate->applies_to_post( $post_id ) && ! $gate->is_verified() && 'server' === rmh()->get( 'settings' )->get( 'age_gate_mode' ) ) {
			return '';
		}
		$alt = is_array( $attr ) && isset( $attr['alt'] ) ? $attr['alt'] : get_the_title( $post_id );
		return '<img class="rmh-remote-thumb wp-post-image" src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
	}

	/**
	 * Report a thumbnail for posts with a remote thumbnail URL.
	 *
	 * @param bool             $has     Has.
	 * @param int|\WP_Post|null $post   Post.
	 * @param int|false        $thumb_id Attachment.
	 * @return bool
	 */
	public function has_remote_thumbnail( $has, $post, $thumb_id ) {
		if ( $has || is_admin() ) {
			return $has;
		}
		$post = get_post( $post );
		return $post && PostTypes::POST_TYPE === $post->post_type && '' !== (string) get_post_meta( $post->ID, '_rmh_thumbnail_url', true );
	}

	/**
	 * Content-rating meta tags.
	 */
	public function head_meta() {
		$settings = rmh()->get( 'settings' );
		if ( ! $settings->get( 'adult_site' ) && ! ( is_singular( PostTypes::POST_TYPE ) && 'adult' === get_post_meta( get_queried_object_id(), '_rmh_content_rating', true ) ) ) {
			return;
		}
		echo '<meta name="rating" content="adult">' . "\n";
		if ( $settings->get( 'rta_label' ) ) {
			echo '<meta name="RATING" content="RTA-5042-1996-1400-1577-RTA">' . "\n";
		}
	}

	/**
	 * Body classes.
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public function body_class( $classes ) {
		if ( self::is_rmh_request() ) {
			$classes[] = 'rmh-page';
		}
		return $classes;
	}

	/**
	 * Title for the directory page.
	 *
	 * @param array $parts Parts.
	 * @return array
	 */
	public function title_parts( $parts ) {
		if ( get_query_var( 'rmh_directory' ) ) {
			$parts['title'] = __( 'Sources', 'rss-media-hub' );
		}
		return $parts;
	}

	/**
	 * Security headers on plugin pages: restrict which origins may be framed
	 * (allowlisted embed providers only) and block plugins/objects.
	 */
	public function security_headers() {
		if ( ! self::is_rmh_request() || headers_sent() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		if ( rmh()->get( 'settings' )->get( 'send_frame_csp' ) ) {
			$origins = rmh()->get( 'embeds' )->frame_origins();
			$frame   = $origins ? implode( ' ', $origins ) : "'none'";
			// Only frame-src (not child-src: browsers fall back to child-src for workers, which would
			// break core/theme web workers such as the emoji support check).
			header( "Content-Security-Policy: frame-src {$frame}; object-src 'none'; base-uri 'self'; form-action 'self'" );
		}
	}

	/**
	 * [rmh_feed_directory] shortcode.
	 *
	 * @return string
	 */
	public function directory_shortcode() {
		$feeds = rmh()->get( 'feeds' )->query(
			array(
				'status'   => 'active',
				'per_page' => 500,
			)
		)['rows'];
		$out   = '<ul class="rmh-sources" role="list">';
		foreach ( $feeds as $feed ) {
			$term = get_term_by( 'slug', 'feed-' . (int) $feed->id, PostTypes::TAX_SOURCE );
			$link = $term ? add_query_arg( 'source', $term->slug, get_post_type_archive_link( PostTypes::POST_TYPE ) ) : '';
			$out .= '<li class="rmh-source"><strong>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $feed->name ) . '</a>' : esc_html( $feed->name ) ) . '</strong></li>';
		}
		return $out . '</ul>';
	}
}

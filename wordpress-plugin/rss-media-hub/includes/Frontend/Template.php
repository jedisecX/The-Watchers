<?php
/**
 * Template helpers used by the reader templates (theme-overridable).
 *
 * @package RSSMediaHub
 */

namespace RMH\Frontend;

use RMH\Db\FeedConfig;
use RMH\Media\MimeMap;
use RMH\PostTypes;
use RMH\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Rendering helpers. All output is escaped here; templates only arrange it.
 * Themes can override any template by copying it to
 * `yourtheme/rss-media-hub/{name}.php` (e.g. `rss-media-hub/parts/media-video.php`).
 */
final class Template {

	/**
	 * Locate a template (theme override first).
	 *
	 * @param string $name Relative name without .php, e.g. 'single' or 'parts/card'.
	 * @return string Path.
	 */
	public static function locate( $name ) {
		$name  = preg_replace( '#[^a-z0-9/_-]#', '', strtolower( $name ) );
		$theme = locate_template( array( 'rss-media-hub/' . $name . '.php' ) );
		$path  = $theme ? $theme : RMH_DIR . 'templates/' . $name . '.php';
		return apply_filters( 'rmh_template_path', $path, $name );
	}

	/**
	 * Render a template to a string.
	 *
	 * @param string $name Template.
	 * @param array  $args Variables available as $args.
	 * @return string
	 */
	public static function render( $name, array $args = array() ) {
		$path = self::locate( $name );
		if ( ! is_readable( $path ) ) {
			return '';
		}
		ob_start();
		( static function ( $__path, $args ) {
			include $__path;
		} )( $path, $args );
		return (string) ob_get_clean();
	}

	/**
	 * Media rows for a post.
	 *
	 * @param int $post_id Post.
	 * @return object[]
	 */
	public static function media_rows( $post_id ) {
		return rmh()->get( 'media' )->for_post( $post_id );
	}

	/**
	 * Is a stored media row unusable (known broken/blocked)?
	 *
	 * @param object $row Row.
	 * @return bool
	 */
	private static function broken( $row ) {
		return in_array( $row->validation_status, array( 'invalid', 'unavailable', 'blocked' ), true )
			|| rmh()->get( 'blocklist' )->is_url_blocked( $row->url );
	}

	/**
	 * Source URL for a media row (local copy when stored).
	 *
	 * @param object $row Row.
	 * @return string
	 */
	private static function src( $row ) {
		if ( $row->attachment_id ) {
			$local = wp_get_attachment_url( $row->attachment_id );
			if ( $local ) {
				return $local;
			}
		}
		return Sanitizer::url( $row->url );
	}

	/**
	 * Effective media handling for the post's feed.
	 *
	 * @param int $post_id Post.
	 * @return array Config.
	 */
	public static function feed_config( $post_id ) {
		$feed = rmh()->get( 'feeds' )->find( (int) get_post_meta( $post_id, '_rmh_feed_id', true ) );
		if ( ! $feed ) {
			$feed = (object) array(
				'id'            => 0,
				'poll_interval' => 0,
				'config'        => FeedConfig::sanitize( array() ),
			);
		}
		return FeedConfig::effective( $feed, rmh()->get( 'settings' ) );
	}

	/**
	 * Main media block for a single post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string HTML.
	 */
	public static function media( $post ) {
		$rows      = self::media_rows( $post->ID );
		$type      = (string) get_post_meta( $post->ID, '_rmh_media_type', true );
		$cfg       = self::feed_config( $post->ID );
		$link_only = 'remote_link' === $cfg['media_handling'];
		$thumb     = self::thumbnail_url( $post->ID );
		$args      = array(
			'post'      => $post,
			'thumbnail' => $thumb,
			'title'     => get_the_title( $post ),
		);

		$html = '';
		if ( ! $link_only ) {
			$videos = array();
			$audios = array();
			$images = array();
			$embeds = array();
			foreach ( $rows as $row ) {
				if ( self::broken( $row ) ) {
					continue;
				}
				switch ( $row->media_type ) {
					case 'video':
						if ( MimeMap::is_playable( $row->mime_type ) || $row->attachment_id ) {
							$videos[] = $row;
						}
						break;
					case 'audio':
						if ( MimeMap::is_playable( $row->mime_type ) || '' === $row->mime_type || $row->attachment_id ) {
							$audios[] = $row;
						}
						break;
					case 'image':
						$images[] = $row;
						break;
					case 'embed':
						$m = $cfg['allow_embeds'] ? rmh()->get( 'embeds' )->revalidate( $row->provider, (string) $row->embed_url ) : null;
						if ( $m ) {
							$row->safe_embed = $m;
							$embeds[]        = $row;
						}
						break;
				}
			}

			if ( $videos && in_array( $type, array( 'video', 'embed', 'gallery', 'image', 'audio' ), true ) ) {
				$sources = array();
				foreach ( array_slice( $videos, 0, 3 ) as $v ) {
					$sources[] = array(
						'src'  => self::src( $v ),
						'type' => $v->attachment_id ? (string) get_post_mime_type( $v->attachment_id ) : MimeMap::canonical( $v->mime_type ),
					);
				}
				$first = $videos[0];
				$html  = self::render(
					'parts/media-video',
					$args + array(
						'sources'     => $sources,
						'poster'      => '' !== $first->thumbnail_url ? Sanitizer::url( $first->thumbnail_url ) : $thumb,
						'width'       => $first->width,
						'height'      => $first->height,
						'source_link' => Sanitizer::url( $first->url ),
					)
				);
			} elseif ( $embeds ) {
				$e    = $embeds[0];
				$html = self::render(
					'parts/media-embed',
					$args + array(
						'embed_url' => $e->safe_embed['embed_url'],
						'sandbox'   => $e->safe_embed['sandbox'],
						'provider'  => $e->provider,
						'page_url'  => Sanitizer::url( $e->safe_embed['page_url'] ),
						'width'     => $e->width,
						'height'    => $e->height,
					)
				);
			} elseif ( $audios ) {
				$tracks = array();
				foreach ( array_slice( $audios, 0, 5 ) as $a ) {
					$tracks[] = array(
						'src'   => self::src( $a ),
						'type'  => MimeMap::canonical( $a->mime_type ),
						'title' => $a->title,
					);
				}
				$html = self::render( 'parts/media-audio', $args + array( 'tracks' => $tracks ) );
			} elseif ( count( $images ) > 1 ) {
				$list = array();
				foreach ( $images as $i => $img ) {
					$list[] = array(
						'src'    => self::src( $img ),
						'alt'    => '' !== $img->title && 'og:image' !== $img->title ? $img->title : sprintf( /* translators: 1: title, 2: number */ __( '%1$s – image %2$d', 'rss-media-hub' ), get_the_title( $post ), $i + 1 ),
						'width'  => $img->width,
						'height' => $img->height,
					);
				}
				$html = self::render( 'parts/media-gallery', $args + array( 'images' => $list ) );
			} elseif ( 1 === count( $images ) ) {
				$img  = $images[0];
				$html = self::render(
					'parts/media-image',
					$args + array(
						'src'    => self::src( $img ),
						'alt'    => '' !== $img->title && 'og:image' !== $img->title ? $img->title : get_the_title( $post ),
						'width'  => $img->width,
						'height' => $img->height,
					)
				);
			}
		}

		if ( '' === $html && 'text' !== $type ) {
			// Nothing playable/embeddable: usable fallback with thumbnail, attribution and link.
			$unavailable = false;
			foreach ( $rows as $row ) {
				if ( in_array( $row->validation_status, array( 'unavailable', 'invalid' ), true ) ) {
					$unavailable = true;
				}
			}
			$html = self::render(
				'parts/media-fallback',
				$args + array(
					'source_url'  => Sanitizer::url( get_post_meta( $post->ID, '_rmh_source_url', true ) ),
					'source_name' => self::source_name( $post->ID ),
					'unavailable' => $unavailable,
					'link_only'   => $link_only,
					'excerpt'     => get_the_excerpt( $post ),
				)
			);
		}
		return (string) apply_filters( 'rmh_media_html', $html, $post, $rows );
	}

	/**
	 * Remote or local thumbnail URL for a post.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function thumbnail_url( $post_id ) {
		if ( has_post_thumbnail( $post_id ) && get_post_thumbnail_id( $post_id ) ) {
			$src = wp_get_attachment_image_url( get_post_thumbnail_id( $post_id ), 'large' );
			if ( $src ) {
				return $src;
			}
		}
		$url = Sanitizer::url( (string) get_post_meta( $post_id, '_rmh_thumbnail_url', true ) );
		if ( '' !== $url && rmh()->get( 'blocklist' )->is_url_blocked( $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Source (feed) display name.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function source_name( $post_id ) {
		$terms = get_the_terms( $post_id, PostTypes::TAX_SOURCE );
		if ( $terms && ! is_wp_error( $terms ) ) {
			return $terms[0]->name;
		}
		$host = wp_parse_url( (string) get_post_meta( $post_id, '_rmh_source_url', true ), PHP_URL_HOST );
		return is_string( $host ) ? $host : __( 'Original source', 'rss-media-hub' );
	}

	/**
	 * Attribution line from the configured template.
	 *
	 * @param \WP_Post $post Post.
	 * @return string Safe HTML.
	 */
	public static function attribution( $post ) {
		$cfg    = self::feed_config( $post->ID );
		$tpl    = (string) $cfg['attribution_template'];
		$author = (string) get_post_meta( $post->ID, '_rmh_author', true );
		$src    = Sanitizer::url( (string) get_post_meta( $post->ID, '_rmh_source_url', true ) );
		$feed   = rmh()->get( 'feeds' )->find( (int) get_post_meta( $post->ID, '_rmh_feed_id', true ) );
		$ts     = (int) get_post_meta( $post->ID, '_rmh_source_published', true );
		$host   = wp_parse_url( $src, PHP_URL_HOST );
		$values = array(
			'{feed_name}'     => esc_html( self::source_name( $post->ID ) ),
			'{site_url}'      => esc_url( $feed && $feed->site_url ? $feed->site_url : $src ),
			'{source_url}'    => esc_url( $src ),
			'{source_host}'   => esc_html( is_string( $host ) ? $host : '' ),
			'{author}'        => esc_html( $author ),
			'{author_suffix}' => '' !== $author ? esc_html( sprintf( /* translators: %s: author */ __( ' by %s', 'rss-media-hub' ), $author ) ) : '',
			'{date}'          => esc_html( $ts ? wp_date( get_option( 'date_format' ), $ts ) : '' ),
		);
		$html   = strtr( $tpl, $values );
		$html   = wp_kses( $html, \RMH\Security\Sanitizer::attribution_allowed_html() );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			// Attribution must always remain visible.
			$html = esc_html__( 'Source:', 'rss-media-hub' ) . ' <a href="' . esc_url( $src ) . '" rel="nofollow noopener" target="_blank">' . esc_html( self::source_name( $post->ID ) ) . '</a>';
		}
		return (string) apply_filters( 'rmh_attribution_html', $html, $post );
	}

	/**
	 * Badges: media type + content rating.
	 *
	 * @param int $post_id Post.
	 * @return string HTML.
	 */
	public static function badges( $post_id ) {
		$labels = PostTypes::media_type_labels();
		$type   = (string) get_post_meta( $post_id, '_rmh_media_type', true );
		$out    = '';
		if ( isset( $labels[ $type ] ) ) {
			$out .= '<span class="rmh-badge rmh-badge--' . esc_attr( $type ) . '">' . esc_html( $labels[ $type ] ) . '</span>';
		}
		if ( 'adult' === get_post_meta( $post_id, '_rmh_content_rating', true ) ) {
			$out .= '<span class="rmh-badge rmh-badge--adult" title="' . esc_attr__( 'Explicit content (18+)', 'rss-media-hub' ) . '">' . esc_html__( '18+ Explicit', 'rss-media-hub' ) . '</span>';
		}
		return $out;
	}

	/**
	 * Related posts (shared tags/categories, then same source).
	 *
	 * @param \WP_Post $post Post.
	 * @return \WP_Post[]
	 */
	public static function related( $post ) {
		$settings = rmh()->get( 'settings' );
		if ( ! $settings->get( 'show_related' ) ) {
			return array();
		}
		$count = (int) $settings->get( 'related_count' );
		$key   = 'rmh_related_' . $post->ID . '_' . $count;
		$ids   = wp_cache_get( $key, 'rmh' );
		if ( false === $ids ) {
			$tax = array( 'relation' => 'OR' );
			foreach ( array( PostTypes::TAX_TAG, PostTypes::TAX_CATEGORY, PostTypes::TAX_SOURCE ) as $taxonomy ) {
				$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$tax[] = array(
						'taxonomy' => $taxonomy,
						'terms'    => array_slice( $terms, 0, 10 ),
					);
				}
			}
			$q   = new \WP_Query(
				array(
					'post_type'           => PostTypes::POST_TYPE,
					'post_status'         => 'publish',
					'post__not_in'        => array( $post->ID ),
					'posts_per_page'      => $count,
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'fields'              => 'ids',
					'tax_query'           => count( $tax ) > 1 ? $tax : array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_tax_query
				)
			);
			$ids = array_map( 'intval', $q->posts );
			wp_cache_set( $key, $ids, 'rmh', HOUR_IN_SECONDS );
		}
		return array_filter( array_map( 'get_post', $ids ) );
	}

	/**
	 * Share links (plain links, no third-party scripts).
	 *
	 * @param \WP_Post $post Post.
	 * @return array[] {label, url}
	 */
	public static function share_links( $post ) {
		if ( ! rmh()->get( 'settings' )->get( 'show_share' ) ) {
			return array();
		}
		$url   = rawurlencode( get_permalink( $post ) );
		$title = rawurlencode( wp_strip_all_tags( get_the_title( $post ) ) );
		return apply_filters(
			'rmh_share_links',
			array(
				array(
					'label' => 'X',
					'url'   => 'https://x.com/intent/post?url=' . $url . '&text=' . $title,
				),
				array(
					'label' => 'Reddit',
					'url'   => 'https://www.reddit.com/submit?url=' . $url . '&title=' . $title,
				),
				array(
					'label' => 'Bluesky',
					'url'   => 'https://bsky.app/intent/compose?text=' . $title . '%20' . $url,
				),
				array(
					'label' => __( 'Email', 'rss-media-hub' ),
					'url'   => 'mailto:?subject=' . $title . '&body=' . $url,
				),
			),
			$post
		);
	}

	/**
	 * Card for archive listings.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $view grid|list.
	 * @return string
	 */
	public static function card( $post, $view ) {
		$gate  = rmh()->get( 'age_gate' );
		$adult = 'adult' === get_post_meta( $post->ID, '_rmh_content_rating', true );
		$thumb = self::thumbnail_url( $post->ID );
		if ( $adult && $gate->applies_to_post( $post->ID ) && ! $gate->is_verified() && 'server' === rmh()->get( 'settings' )->get( 'age_gate_mode' ) ) {
			$thumb = ''; // Unverified visitors never load explicit thumbnails.
		}
		return self::render(
			'parts/card',
			array(
				'post'      => $post,
				'view'      => $view,
				'thumbnail' => $thumb,
				'adult'     => $adult,
				'source'    => self::source_name( $post->ID ),
				'date'      => get_the_date( '', $post ),
			)
		);
	}

	/**
	 * Report reasons for the public form.
	 *
	 * @return array<string,string>
	 */
	public static function report_reasons() {
		return \RMH\Db\ReportRepository::reasons();
	}
}

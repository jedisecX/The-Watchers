<?php
/**
 * Creates and updates WordPress posts from imported items.
 *
 * @package RSSMediaHub
 */

namespace RMH\Import;

use RMH\PostTypes;
use RMH\Security\Sanitizer;
use RMH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a stored item (payload + media) onto an `rmh_item` post. Creation is
 * idempotent (an existing post for the item is reused) and updates never
 * overwrite fields an editor has changed since the last sync.
 */
class Publisher {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build the post fields we own for an item.
	 *
	 * @param object $item Item row (hydrated).
	 * @param object $feed Feed row (may be a stub for deleted feeds).
	 * @param array  $cfg  Effective config.
	 * @return array{title:string,excerpt:string,content:string}
	 */
	public function fields( $item, $feed, array $cfg ) {
		$data    = $item->data['item'];
		$words   = (int) $this->settings->get( 'excerpt_words' );
		$source  = '' !== (string) $data['summary'] ? $data['summary'] : $data['content'];
		$excerpt = Sanitizer::excerpt( $source, $words );
		if ( '' === $excerpt && ! empty( $data['media_description'] ) ) {
			$excerpt = Sanitizer::excerpt( $data['media_description'], $words );
		}

		if ( 'full' === $cfg['content_policy'] ) {
			$html    = '' !== (string) $data['content'] ? $data['content'] : $data['summary'];
			$content = Sanitizer::content( $html, $data['link'] );
		} else {
			// Excerpt policy: never republish the full article body.
			$content = '' !== $excerpt ? '<p>' . esc_html( $excerpt ) . '</p>' : '';
		}

		return apply_filters(
			'rmh_post_fields',
			array(
				'title'   => Sanitizer::text( $data['title'], 300 ),
				'excerpt' => $excerpt,
				'content' => $content,
			),
			$item,
			$feed,
			$cfg
		);
	}

	/**
	 * Create (or adopt) the post for an item.
	 *
	 * @param object $item   Item row.
	 * @param object $feed   Feed row.
	 * @param array  $cfg    Effective config.
	 * @param string $status publish|pending|draft.
	 * @return int|\WP_Error Post id.
	 */
	public function create( $item, $feed, array $cfg, $status ) {
		// Crash recovery: a previous run may have inserted the post but died before updating the item.
		$existing = $this->find_post_for_item( $item->id );
		if ( $existing ) {
			if ( get_post_status( $existing ) !== $status && 'publish' === $status ) {
				wp_update_post(
					array(
						'ID'          => $existing,
						'post_status' => 'publish',
					)
				);
			}
			return $existing;
		}

		$data   = $item->data['item'];
		$fields = $this->fields( $item, $feed, $cfg );
		$now    = rmh()->get( 'clock' )->now();
		$ts     = ( $this->settings->get( 'use_source_date' ) && ! empty( $data['published'] ) && (int) $data['published'] <= $now ) ? (int) $data['published'] : $now;

		$postarr = array(
			'post_type'      => PostTypes::POST_TYPE,
			'post_status'    => $status,
			'post_title'     => $fields['title'],
			'post_excerpt'   => $fields['excerpt'],
			'post_content'   => $fields['content'],
			'post_author'    => (int) $this->settings->get( 'post_author' ),
			'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $ts ),
			'post_date'      => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'meta_input'     => $this->meta( $item, $feed, $cfg ),
		);
		$postarr = apply_filters( 'rmh_insert_post_args', $postarr, $item, $feed, $cfg );

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( ! $post_id ) {
			return new \WP_Error( 'rmh_insert_failed', __( 'wp_insert_post() returned 0.', 'rss-media-hub' ) );
		}
		$this->store_fingerprint( $post_id, array( 'title', 'excerpt', 'content' ), array() );
		$this->assign_terms( $post_id, $item, $feed, $cfg );
		rmh()->get( 'media' )->assign_post( $item->id, $post_id );
		$this->queue_local_media( $item, $post_id, $cfg );

		do_action( 'rmh_post_created', $post_id, $item, $feed );
		return (int) $post_id;
	}

	/**
	 * Update an existing post after the source item changed.
	 * Only fields that still match what we last wrote are overwritten.
	 *
	 * @param int    $post_id Post id.
	 * @param object $item    Item row.
	 * @param object $feed    Feed row.
	 * @param array  $cfg     Effective config.
	 * @return bool Whether anything changed.
	 */
	public function update( $post_id, $item, $feed, array $cfg ) {
		$post = get_post( $post_id );
		if ( ! $post || PostTypes::POST_TYPE !== $post->post_type ) {
			return false;
		}
		$fields = $this->fields( $item, $feed, $cfg );
		$synced = json_decode( (string) get_post_meta( $post_id, '_rmh_synced', true ), true );
		$synced = is_array( $synced ) ? $synced : array();
		$now    = $this->fingerprint(
			array(
				'title'   => $post->post_title,
				'excerpt' => $post->post_excerpt,
				'content' => $post->post_content,
			)
		);

		$update = array( 'ID' => $post_id );
		$owned  = array();
		$map    = array(
			'title'   => 'post_title',
			'excerpt' => 'post_excerpt',
		);
		if ( 'full' === $cfg['update_policy'] ) {
			$map['content'] = 'post_content';
		}
		foreach ( $map as $key => $column ) {
			$edited = isset( $synced[ $key ] ) && $synced[ $key ] !== $now[ $key ];
			if ( $edited ) {
				continue; // An editor changed this field; leave it alone.
			}
			// Compare against the value as WordPress would store it (save filters such as kses applied).
			$as_stored = sanitize_post_field( $column, $fields[ $key ], $post_id, 'db' );
			if ( (string) $post->$column !== (string) $as_stored ) {
				$update[ $column ] = $fields[ $key ];
			}
			$owned[] = $key;
		}

		$changed = count( $update ) > 1;
		if ( $changed ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}
		foreach ( $this->meta( $item, $feed, $cfg ) as $key => $value ) {
			if ( (string) get_post_meta( $post_id, $key, true ) !== (string) $value ) {
				update_post_meta( $post_id, $key, $value );
				$changed = true;
			}
		}
		$this->store_fingerprint( $post_id, $owned, $synced );
		wp_set_object_terms( $post_id, array( $item->media_type ), PostTypes::TAX_TYPE );
		rmh()->get( 'media' )->assign_post( $item->id, $post_id );

		if ( $changed ) {
			do_action( 'rmh_post_updated', $post_id, $item, $feed );
		}
		return $changed;
	}

	/**
	 * Post meta for an item.
	 *
	 * @param object $item Item.
	 * @param object $feed Feed.
	 * @param array  $cfg  Config.
	 * @return array
	 */
	private function meta( $item, $feed, array $cfg ) {
		$data = $item->data['item'];
		return array(
			'_rmh_item_id'          => (int) $item->id,
			'_rmh_feed_id'          => (int) $item->feed_id,
			'_rmh_source_url'       => Sanitizer::url( $data['link'] ),
			'_rmh_guid'             => mb_substr( (string) $data['guid'], 0, 2000 ),
			'_rmh_source_published' => ! empty( $data['published'] ) ? (int) $data['published'] : 0,
			'_rmh_media_type'       => (string) $item->media_type,
			'_rmh_author'           => mb_substr( implode( ', ', (array) $data['authors'] ), 0, 200 ),
			'_rmh_content_rating'   => (string) $item->content_rating,
			'_rmh_thumbnail_url'    => Sanitizer::url( isset( $item->data['thumbnail'] ) ? $item->data['thumbnail'] : '' ),
		);
	}

	/**
	 * Field fingerprints used to detect editorial changes.
	 *
	 * @param array $fields title/excerpt/content.
	 * @return array
	 */
	private function fingerprint( array $fields ) {
		return array(
			'title'   => md5( (string) $fields['title'] ),
			'excerpt' => md5( (string) $fields['excerpt'] ),
			'content' => md5( (string) $fields['content'] ),
		);
	}

	/**
	 * Fingerprint the stored values (after core save filters) of the fields we own.
	 *
	 * @param int      $post_id Post.
	 * @param string[] $keys    Fields written by the importer.
	 * @param array    $previous Previous fingerprints (kept for fields we did not write).
	 */
	private function store_fingerprint( $post_id, array $keys, array $previous ) {
		clean_post_cache( $post_id );
		$saved = get_post( $post_id );
		$fp    = $this->fingerprint(
			array(
				'title'   => $saved->post_title,
				'excerpt' => $saved->post_excerpt,
				'content' => $saved->post_content,
			)
		);
		$out   = $previous;
		foreach ( $keys as $key ) {
			$out[ $key ] = $fp[ $key ];
		}
		if ( ! isset( $out['content'] ) ) {
			$out['content'] = $fp['content'];
		}
		update_post_meta( $post_id, '_rmh_synced', wp_json_encode( $out ) );
	}

	/**
	 * Find a post already linked to an item id.
	 *
	 * @param int $item_id Item id.
	 * @return int
	 */
	public function find_post_for_item( $item_id ) {
		$ids = get_posts(
			array(
				'post_type'        => PostTypes::POST_TYPE,
				'post_status'      => 'any',
				'meta_key'         => '_rmh_item_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'       => (int) $item_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Assign taxonomies.
	 *
	 * @param int    $post_id Post.
	 * @param object $item    Item.
	 * @param object $feed    Feed.
	 * @param array  $cfg     Config.
	 */
	private function assign_terms( $post_id, $item, $feed, array $cfg ) {
		$data = $item->data['item'];

		$cats = array_values(
			array_filter(
				array_map( 'absint', (array) $cfg['categories'] ),
				static function ( $id ) {
					return (bool) get_term( $id, PostTypes::TAX_CATEGORY );
				}
			)
		);
		$tags = (array) $cfg['tags'];

		$mode = (string) $this->settings->get( 'source_categories' );
		if ( 'tags' === $mode ) {
			$tags = array_merge( $tags, array_slice( (array) $data['categories'], 0, 10 ) );
		} elseif ( 'categories' === $mode ) {
			foreach ( array_slice( (array) $data['categories'], 0, 10 ) as $name ) {
				$term = term_exists( $name, PostTypes::TAX_CATEGORY );
				if ( ! $term ) {
					$term = wp_insert_term( $name, PostTypes::TAX_CATEGORY );
				}
				if ( ! is_wp_error( $term ) && $term ) {
					$cats[] = (int) $term['term_id'];
				}
			}
		}
		if ( $cats ) {
			wp_set_object_terms( $post_id, array_values( array_unique( $cats ) ), PostTypes::TAX_CATEGORY );
		}
		if ( $tags ) {
			wp_set_object_terms( $post_id, array_values( array_unique( array_map( 'strval', $tags ) ) ), PostTypes::TAX_TAG );
		}
		wp_set_object_terms( $post_id, array( $item->media_type ), PostTypes::TAX_TYPE );

		$slug = 'feed-' . (int) $item->feed_id;
		$term = get_term_by( 'slug', $slug, PostTypes::TAX_SOURCE );
		if ( ! $term ) {
			$created = wp_insert_term( '' !== (string) $feed->name ? $feed->name : $slug, PostTypes::TAX_SOURCE, array( 'slug' => $slug ) );
			$term_id = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}
		if ( $term_id ) {
			wp_set_object_terms( $post_id, array( $term_id ), PostTypes::TAX_SOURCE );
		}
	}

	/**
	 * Queue authorized local downloads (never automatic without operator permission).
	 *
	 * @param object $item    Item.
	 * @param int    $post_id Post.
	 * @param array  $cfg     Config.
	 */
	private function queue_local_media( $item, $post_id, array $cfg ) {
		if ( empty( $cfg['rights_confirmed'] ) ) {
			return;
		}
		$handling = $cfg['media_handling'];
		if ( 'hybrid' === $handling || 'local_import' === $handling ) {
			if ( ! empty( $item->data['thumbnail'] ) ) {
				update_post_meta( $post_id, '_rmh_thumb_queue', 1 );
			}
		}
		if ( 'local_import' === $handling ) {
			foreach ( rmh()->get( 'media' )->for_item( $item->id ) as $m ) {
				if ( in_array( $m->media_type, array( 'video', 'audio', 'image' ), true ) && ! $m->attachment_id ) {
					rmh()->get( 'media' )->update( $m->id, array( 'download_status' => 'queued' ) );
				}
			}
		}
	}
}

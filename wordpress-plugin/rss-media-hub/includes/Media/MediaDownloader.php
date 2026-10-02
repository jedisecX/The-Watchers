<?php
/**
 * Authorized local media import.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media;

use RMH\Db\FeedConfig;
use RMH\Plugin;
use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads media into the Media Library only when the operator has confirmed
 * rights for the feed and selected a local/hybrid handling mode. Runs in the
 * background batch (never during feed polling) with size, quota and
 * magic-byte MIME verification.
 */
class MediaDownloader {

	/**
	 * MIME types accepted for local storage.
	 */
	const STORABLE = array( 'video/mp4', 'video/webm', 'video/ogg', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/flac', 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Extension for each storable MIME.
	 */
	const EXT_FOR = array(
		'video/mp4'   => 'mp4',
		'video/webm'  => 'webm',
		'video/ogg'   => 'ogv',
		'audio/mpeg'  => 'mp3',
		'audio/mp4'   => 'm4a',
		'audio/ogg'   => 'ogg',
		'audio/wav'   => 'wav',
		'audio/x-wav' => 'wav',
		'audio/flac'  => 'flac',
		'image/jpeg'  => 'jpg',
		'image/png'   => 'png',
		'image/gif'   => 'gif',
		'image/webp'  => 'webp',
	);

	/**
	 * Container.
	 *
	 * @var Plugin
	 */
	private $c;

	/**
	 * Constructor.
	 *
	 * @param Plugin $container Container.
	 */
	public function __construct( Plugin $container ) {
		$this->c = $container;
	}

	/**
	 * Process queued downloads and featured-image imports.
	 *
	 * @param int   $limit    Max files.
	 * @param float $deadline microtime deadline.
	 * @return int Files stored.
	 */
	public function run_batch( $limit, $deadline ) {
		$done = 0;
		foreach ( $this->c->get( 'media' )->queued_downloads( $limit ) as $row ) {
			if ( microtime( true ) >= $deadline ) {
				return $done;
			}
			if ( ! is_wp_error( $this->download_media( $row ) ) ) {
				++$done;
			}
		}
		$posts = get_posts(
			array(
				'post_type'      => PostTypes::POST_TYPE,
				'post_status'    => 'any',
				'meta_key'       => '_rmh_thumb_queue', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'fields'         => 'ids',
				'posts_per_page' => max( 1, $limit ),
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post_id ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			if ( ! is_wp_error( $this->import_thumbnail( $post_id ) ) ) {
				++$done;
			}
		}
		return $done;
	}

	/**
	 * Is local storage authorized for this feed?
	 *
	 * @param int  $feed_id     Feed.
	 * @param bool $thumbs_only Only thumbnails requested.
	 * @return bool
	 */
	public function authorized( $feed_id, $thumbs_only ) {
		$feed = $this->c->get( 'feeds' )->find( $feed_id );
		if ( ! $feed ) {
			return false;
		}
		$cfg = FeedConfig::effective( $feed, $this->c->get( 'settings' ) );
		if ( empty( $cfg['rights_confirmed'] ) ) {
			return false;
		}
		return $thumbs_only ? in_array( $cfg['media_handling'], array( 'hybrid', 'local_import' ), true ) : 'local_import' === $cfg['media_handling'];
	}

	/**
	 * Download a media row into the library.
	 *
	 * @param object $row Media row.
	 * @return int|\WP_Error Attachment id.
	 */
	public function download_media( $row ) {
		$media = $this->c->get( 'media' );
		$item  = $this->c->get( 'items' )->find( $row->item_id );
		if ( ! $item || ! $this->authorized( $item->feed_id, false ) ) {
			$media->update( $row->id, array( 'download_status' => 'refused' ) );
			return new \WP_Error( 'rmh_not_authorized', __( 'Local import is not authorized for this feed.', 'rss-media-hub' ) );
		}
		$result = $this->sideload( $row->url, $row->media_type, (int) $row->post_id, $row->title );
		if ( is_wp_error( $result ) ) {
			$media->update(
				$row->id,
				array(
					'download_status'    => 'failed',
					'validation_message' => mb_substr( $result->get_error_message(), 0, 255 ),
				)
			);
			$this->c->get( 'logger' )->warning( 'media', 'Local import failed: ' . $result->get_error_message(), array( 'item_id' => $row->item_id ) );
			return $result;
		}
		$media->update(
			$row->id,
			array(
				'download_status'   => 'done',
				'attachment_id'     => $result['id'],
				'local_bytes'       => $result['bytes'],
				'mime_type'         => $result['mime'],
				'validation_status' => 'valid',
				'checked_at'        => rmh()->get( 'clock' )->mysql(),
			)
		);
		$this->c->get( 'logger' )->info( 'media', sprintf( 'Stored media #%d locally (%s, %d bytes).', $row->id, $result['mime'], $result['bytes'] ), array( 'item_id' => $row->item_id ) );
		return $result['id'];
	}

	/**
	 * Import the remote thumbnail as featured image.
	 *
	 * @param int $post_id Post.
	 * @return int|\WP_Error
	 */
	public function import_thumbnail( $post_id ) {
		delete_post_meta( $post_id, '_rmh_thumb_queue' );
		$feed_id = (int) get_post_meta( $post_id, '_rmh_feed_id', true );
		$url     = (string) get_post_meta( $post_id, '_rmh_thumbnail_url', true );
		if ( '' === $url || has_post_thumbnail( $post_id ) ) {
			return new \WP_Error( 'rmh_nothing', 'No thumbnail to import.' );
		}
		if ( ! $this->authorized( $feed_id, true ) ) {
			return new \WP_Error( 'rmh_not_authorized', __( 'Local thumbnails are not authorized for this feed.', 'rss-media-hub' ) );
		}
		$result = $this->sideload( $url, 'image', $post_id, get_the_title( $post_id ) );
		if ( is_wp_error( $result ) ) {
			$this->c->get( 'logger' )->warning( 'media', 'Thumbnail import failed: ' . $result->get_error_message(), array( 'post_id' => $post_id ) );
			return $result;
		}
		set_post_thumbnail( $post_id, $result['id'] );
		update_post_meta( $result['id'], '_rmh_local_bytes', $result['bytes'] );
		return $result['id'];
	}

	/**
	 * Download, verify and sideload a file.
	 *
	 * @param string $url      URL.
	 * @param string $category video|audio|image.
	 * @param int    $post_id  Parent post.
	 * @param string $title    Title.
	 * @return array|\WP_Error {id, bytes, mime}
	 */
	private function sideload( $url, $category, $post_id, $title ) {
		$settings = $this->c->get( 'settings' );
		$max      = MB_IN_BYTES * (int) $settings->get( 'max_download_mb' );
		$quota    = MB_IN_BYTES * (int) $settings->get( 'storage_quota_mb' );
		$used     = $this->c->get( 'media' )->local_bytes_total();
		if ( $used >= $quota ) {
			return new \WP_Error( 'rmh_quota', __( 'Local media storage quota reached.', 'rss-media-hub' ) );
		}
		if ( $this->c->get( 'blocklist' )->is_url_blocked( $url ) ) {
			return new \WP_Error( 'rmh_blocked', __( 'URL is blocklisted.', 'rss-media-hub' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( 'rmh-media' );
		if ( ! $tmp ) {
			return new \WP_Error( 'rmh_tmp', __( 'Could not create a temporary file.', 'rss-media-hub' ) );
		}
		$resp = $this->c->get( 'http' )->get(
			$url,
			array(
				'filename'  => $tmp,
				'max_bytes' => min( $max, max( 1, $quota - $used ) ),
				'timeout'   => 60,
				'retries'   => 1,
			)
		);
		if ( ! $resp->ok() || 200 !== $resp->status ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'rmh_download', $resp->describe() );
		}
		$bytes = (int) filesize( $tmp );
		if ( $bytes <= 0 ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'rmh_empty', __( 'Downloaded file is empty.', 'rss-media-hub' ) );
		}

		// Trust the bytes, not the server: detect the type from file content.
		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = (string) finfo_file( $finfo, $tmp );
			finfo_close( $finfo );
		}
		$mime = MimeMap::canonical( $mime );
		if ( 'audio/x-wav' === $mime ) {
			$mime = 'audio/wav';
		}
		if ( ! in_array( $mime, self::STORABLE, true ) || ! MimeMap::compatible( $mime, $category ) || 'application/octet-stream' === $mime ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'rmh_mime', sprintf( /* translators: %s: MIME */ __( 'Refused file with detected type "%s".', 'rss-media-hub' ), $mime ) );
		}
		if ( 'image' === $category && ! @getimagesize( $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $tmp );
			return new \WP_Error( 'rmh_image', __( 'File is not a valid image.', 'rss-media-hub' ) );
		}

		$name = sanitize_file_name( 'rmh-' . substr( sha1( $url ), 0, 12 ) . '.' . self::EXT_FOR[ $mime ] );
		$id   = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			$post_id,
			sanitize_text_field( (string) $title )
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		update_post_meta( $id, '_rmh_source_url', esc_url_raw( $url ) );
		return array(
			'id'    => (int) $id,
			'bytes' => $bytes,
			'mime'  => $mime,
		);
	}

	/**
	 * Remove a locally stored file (admin action). The remote record is kept.
	 *
	 * @param int $media_id Media row.
	 * @return true|\WP_Error
	 */
	public function remove_local( $media_id ) {
		$media = $this->c->get( 'media' );
		$row   = $media->find( $media_id );
		if ( ! $row || ! $row->attachment_id ) {
			return new \WP_Error( 'rmh_no_local', __( 'This media has no local file.', 'rss-media-hub' ) );
		}
		if ( get_post_meta( $row->attachment_id, '_rmh_source_url', true ) === '' ) {
			return new \WP_Error( 'rmh_not_ours', __( 'The attachment was not created by RSS Media Hub; not deleted.', 'rss-media-hub' ) );
		}
		wp_delete_attachment( $row->attachment_id, true );
		$media->update(
			$row->id,
			array(
				'attachment_id'   => 0,
				'local_bytes'     => 0,
				'download_status' => '',
			)
		);
		$this->c->get( 'logger' )->info( 'media', sprintf( 'Removed local file for media #%d.', $row->id ), array( 'item_id' => $row->item_id ) );
		return true;
	}
}

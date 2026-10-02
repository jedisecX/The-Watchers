<?php
/**
 * Remote media validation (reachability + content type).
 *
 * @package RSSMediaHub
 */

namespace RMH\Media;

use RMH\Db\Logger;
use RMH\Db\MediaRepository;
use RMH\Http\HttpClient;

defined( 'ABSPATH' ) || exit;

/**
 * Probes direct media URLs with HEAD (falling back to a 1 KB ranged GET) through
 * the SSRF-guarded client. Nothing is reported as "valid" unless the server
 * answered 2xx with a compatible Content-Type. Media that disappears is marked
 * unavailable; the post is kept and renders its fallback.
 */
class MediaValidator {

	/**
	 * HTTP.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Media repo.
	 *
	 * @var MediaRepository
	 */
	private $media;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param HttpClient      $http  HTTP.
	 * @param MediaRepository $media Media.
	 * @param Logger          $log   Logger.
	 */
	public function __construct( HttpClient $http, MediaRepository $media, Logger $log ) {
		$this->http  = $http;
		$this->media = $media;
		$this->log   = $log;
	}

	/**
	 * Validate a batch of media due for (re)checking.
	 *
	 * @param int   $limit      Max rows.
	 * @param int   $older_than Seconds since last check.
	 * @param float $deadline   microtime deadline.
	 * @return int Rows checked.
	 */
	public function run_batch( $limit, $older_than, $deadline ) {
		$n = 0;
		foreach ( $this->media->due_for_validation( $limit, $older_than ) as $row ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$this->validate( $row );
			++$n;
		}
		return $n;
	}

	/**
	 * Validate one media row and persist the result.
	 *
	 * @param object $row Media row.
	 * @return array {status, message}
	 */
	public function validate( $row ) {
		$result = $this->probe( $row );
		$update = array(
			'validation_status'  => $result['status'],
			'validation_message' => mb_substr( $result['message'], 0, 255 ),
			'checked_at'         => rmh()->get( 'clock' )->mysql(),
		);
		if ( ! empty( $result['mime'] ) && 'valid' === $result['status'] ) {
			$update['mime_type'] = $result['mime'];
		}
		if ( ! empty( $result['size'] ) ) {
			$update['filesize'] = (int) $result['size'];
		}
		$this->media->update( $row->id, $update );

		$level = in_array( $result['status'], array( 'valid', 'unchecked' ), true ) ? 'debug' : 'warning';
		$this->log->log(
			$level,
			'media',
			sprintf( 'Media #%d %s: %s', $row->id, $result['status'], $result['message'] ),
			array(
				'item_id' => $row->item_id,
				'url'     => $row->url,
			)
		);
		if ( (int) $row->post_id ) {
			do_action( 'rmh_media_validated', (int) $row->post_id, $row->id, $result['status'] );
		}
		return $result;
	}

	/**
	 * Probe without persisting.
	 *
	 * @param object $row Media row.
	 * @return array {status, message, mime, size}
	 */
	public function probe( $row ) {
		if ( in_array( $row->media_type, array( 'embed', 'link' ), true ) ) {
			return array(
				'status'  => 'unchecked',
				'message' => 'Embeds and links are not probed.',
			);
		}
		if ( rmh()->get( 'blocklist' )->is_url_blocked( $row->url ) ) {
			return array(
				'status'  => 'blocked',
				'message' => 'URL matches the blocklist.',
			);
		}

		$opts = array(
			'retries'        => 0,
			'timeout'        => 10,
			'max_bytes'      => 1024,
			'allow_truncate' => true,
		);
		$resp = $this->http->request( 'HEAD', $row->url, $opts );
		if ( ! $resp->error && in_array( $resp->status, array( 400, 403, 405, 501 ), true ) ) {
			// Some servers reject HEAD; ask for the first kilobyte instead.
			$resp = $this->http->request( 'GET', $row->url, array_merge( $opts, array( 'headers' => array( 'Range' => 'bytes=0-1023' ) ) ) );
		}

		if ( $resp->error ) {
			$code = $resp->error->get_error_code();
			return array(
				'status'  => 'rmh_blocked_destination' === $code ? 'blocked' : 'error',
				'message' => $resp->error->get_error_message(),
			);
		}
		if ( in_array( $resp->status, array( 404, 410 ), true ) ) {
			return array(
				'status'  => 'unavailable',
				'message' => 'Media no longer available (HTTP ' . $resp->status . ').',
			);
		}
		if ( in_array( $resp->status, array( 401, 402, 403, 451 ), true ) ) {
			return array(
				'status'  => 'unavailable',
				'message' => 'Access restricted by the source (HTTP ' . $resp->status . '); not bypassed.',
			);
		}
		if ( $resp->status < 200 || $resp->status >= 300 ) {
			return array(
				'status'  => 'error',
				'message' => 'Unexpected HTTP ' . $resp->status . '.',
			);
		}
		$ct = MimeMap::canonical( $resp->content_type() );
		if ( ! MimeMap::compatible( $ct, $row->media_type ) ) {
			return array(
				'status'  => 'invalid',
				'message' => sprintf( 'Unexpected content type "%s" for %s media.', $ct, $row->media_type ),
				'mime'    => $ct,
			);
		}
		$size = 0;
		if ( preg_match( '#/(\d+)\s*$#', $resp->header( 'content-range' ), $m ) ) {
			$size = (int) $m[1];
		} elseif ( $resp->header( 'content-length' ) && 206 !== $resp->status ) {
			$size = (int) $resp->header( 'content-length' );
		}
		return array(
			'status'  => 'valid',
			'message' => 'Reachable (HTTP ' . $resp->status . ( $ct ? ', ' . $ct : '' ) . ').',
			'mime'    => in_array( $ct, array( '', 'application/octet-stream', 'binary/octet-stream' ), true ) ? '' : $ct,
			'size'    => $size,
		);
	}
}

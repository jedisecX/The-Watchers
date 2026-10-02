<?php
/**
 * REST API: rmh/v1.
 *
 * @package RSSMediaHub
 */

namespace RMH\Rest;

use RMH\Compliance\Reports;
use RMH\Security\RateLimiter;
use RMH\Security\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated management endpoints (cookie + X-WP-Nonce, or application
 * passwords) and two rate-limited public endpoints (reports, view counts).
 * Every route has an explicit permission callback.
 */
class RestController {

	const NS = 'rmh/v1';

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Permission: manage feeds.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'rmh_manage_feeds' );
	}

	/**
	 * Permission: moderate.
	 *
	 * @return bool
	 */
	public function can_moderate() {
		return current_user_can( 'rmh_moderate' );
	}

	/**
	 * Register routes.
	 */
	public function routes() {
		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/feeds',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_feeds' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'status'   => array(
							'type' => 'string',
							'enum' => array( '', 'active', 'paused', 'disabled' ),
						),
						'search'   => array( 'type' => 'string' ),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_feed' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'url'           => array(
							'type'     => 'string',
							'required' => true,
							'format'   => 'uri',
						),
						'name'          => array( 'type' => 'string' ),
						'status'        => array(
							'type' => 'string',
							'enum' => array( 'active', 'paused', 'disabled' ),
						),
						'poll_interval' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 604800,
						),
						'config'        => array( 'type' => 'object' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/feeds/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_feed' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_feed' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id_arg + array(
						'url'           => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'name'          => array( 'type' => 'string' ),
						'status'        => array(
							'type' => 'string',
							'enum' => array( 'active', 'paused', 'disabled' ),
						),
						'poll_interval' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 604800,
						),
						'config'        => array( 'type' => 'object' ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_feed' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id_arg,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/feeds/(?P<id>\d+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_feed' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/feeds/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_url' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'url' => array(
						'type'     => 'string',
						'required' => true,
						'format'   => 'uri',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/feeds/(?P<id>\d+)/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_feed' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/items',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => array( $this, 'can_moderate' ),
				'args'                => array(
					'feed_id'    => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'status'     => array( 'type' => 'string' ),
					'media_type' => array( 'type' => 'string' ),
					'search'     => array( 'type' => 'string' ),
					'page'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 30,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)/(?P<action>approve|reject|retry|reprocess)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'item_action' ),
				'permission_callback' => array( $this, 'can_moderate' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/media',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_media' ),
				'permission_callback' => array( $this, 'can_moderate' ),
				'args'                => array(
					'media_type'        => array( 'type' => 'string' ),
					'validation_status' => array( 'type' => 'string' ),
					'post_id'           => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'page'              => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'          => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 30,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/media/(?P<id>\d+)/revalidate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revalidate_media' ),
				'permission_callback' => array( $this, 'can_moderate' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_jobs' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/retry-failed',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry_failed' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/blocklist',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_blocklist' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_blocklist' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'type'   => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => \RMH\Db\Blocklist::TYPES,
						),
						'value'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'reason' => array( 'type' => 'string' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/blocklist/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_blocklist' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/reports',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_report' ),
					'permission_callback' => '__return_true', // Public; rate-limited and validated in Reports::submit().
					'args'                => array(
						'post_id' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
						'reason'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'details' => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
						'contact' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'website' => array( 'type' => 'string' ),
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_reports' ),
					'permission_callback' => array( $this, 'can_moderate' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/views/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'count_view' ),
				'permission_callback' => '__return_true', // Public; aggregate counter only, rate-limited.
				'args'                => $id_arg,
			)
		);
	}

	/* ---------------------------------------------------------------- formatters */

	/**
	 * Public representation of a feed (credentials never leave the server).
	 *
	 * @param object $f Feed.
	 * @return array
	 */
	public static function feed_out( $f ) {
		$config = $f->config;
		unset( $config['auth_pass'] );
		$config['has_auth'] = '' !== (string) $f->config['auth_user'];
		unset( $config['auth_user'] );
		return array(
			'id'                   => (int) $f->id,
			'name'                 => $f->name,
			'url'                  => Redactor::url( $f->url ),
			'site_url'             => $f->site_url,
			'status'               => $f->status,
			'health'               => $f->health,
			'poll_interval'        => (int) $f->poll_interval,
			'next_poll_at'         => $f->next_poll_at,
			'last_polled_at'       => $f->last_polled_at,
			'last_success_at'      => $f->last_success_at,
			'last_http_status'     => (int) $f->last_http_status,
			'last_outcome'         => $f->last_outcome,
			'last_error'           => Redactor::text( (string) $f->last_error ),
			'consecutive_failures' => (int) $f->consecutive_failures,
			'items_total'          => (int) $f->items_total,
			'config'               => $config,
		);
	}

	/**
	 * Item representation.
	 *
	 * @param object $i Item.
	 * @return array
	 */
	public static function item_out( $i ) {
		return array(
			'id'             => (int) $i->id,
			'feed_id'        => (int) $i->feed_id,
			'title'          => $i->title,
			'source_url'     => $i->source_url,
			'guid'           => $i->guid,
			'status'         => $i->status,
			'post_id'        => (int) $i->post_id,
			'permalink'      => $i->post_id ? get_permalink( $i->post_id ) : null,
			'media_type'     => $i->media_type,
			'content_rating' => $i->content_rating,
			'published_at'   => $i->source_published_at,
			'discovered_at'  => $i->discovered_at,
			'attempts'       => (int) $i->attempts,
			'last_error'     => $i->last_error,
			'duplicate_of'   => (int) $i->duplicate_of,
		);
	}

	/**
	 * Media representation.
	 *
	 * @param object $m Media.
	 * @return array
	 */
	public static function media_out( $m ) {
		return array(
			'id'                 => (int) $m->id,
			'item_id'            => (int) $m->item_id,
			'post_id'            => (int) $m->post_id,
			'type'               => $m->media_type,
			'url'                => $m->url,
			'mime_type'          => $m->mime_type,
			'thumbnail_url'      => $m->thumbnail_url,
			'width'              => (int) $m->width,
			'height'             => (int) $m->height,
			'duration'           => (int) $m->duration,
			'filesize'           => (int) $m->filesize,
			'provider'           => $m->provider,
			'embed_url'          => $m->embed_url,
			'extraction_method'  => $m->extraction_method,
			'validation_status'  => $m->validation_status,
			'validation_message' => $m->validation_message,
			'checked_at'         => $m->checked_at,
			'local'              => (bool) $m->attachment_id,
			'credit'             => $m->credit,
			'retrieved_at'       => $m->retrieved_at,
		);
	}

	/**
	 * Paged response with headers.
	 *
	 * @param array $rows  Rows.
	 * @param int   $total Total.
	 * @param int   $per   Per page.
	 * @return \WP_REST_Response
	 */
	private function paged( array $rows, $total, $per ) {
		$r = rest_ensure_response( $rows );
		$r->header( 'X-WP-Total', (string) (int) $total );
		$r->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per ) ) );
		return $r;
	}

	/**
	 * WP_Error -> REST error with status.
	 *
	 * @param \WP_Error $e      Error.
	 * @param int       $status Status.
	 * @return \WP_Error
	 */
	private function err( \WP_Error $e, $status = 400 ) {
		$data = $e->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$e->add_data( array( 'status' => $status ) );
		}
		return $e;
	}

	/* ---------------------------------------------------------------- callbacks */

	/**
	 * GET /status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		return rest_ensure_response(
			array(
				'version'   => RMH_VERSION,
				'db'        => get_option( \RMH\Installer::DB_OPTION ),
				'scheduler' => rmh()->get( 'scheduler' )->status(),
				'feeds'     => rmh()->get( 'feeds' )->counts(),
				'items'     => rmh()->get( 'items' )->counts_by_status(),
				'media'     => rmh()->get( 'media' )->counts_by_validation(),
				'reports'   => rmh()->get( 'reports' )->open_count(),
			)
		);
	}

	/**
	 * GET /feeds.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function list_feeds( $req ) {
		$res = rmh()->get( 'feeds' )->query(
			array(
				'status'   => (string) $req['status'],
				'search'   => (string) $req['search'],
				'page'     => (int) $req['page'],
				'per_page' => (int) $req['per_page'],
			)
		);
		return $this->paged( array_map( array( __CLASS__, 'feed_out' ), $res['rows'] ), $res['total'], (int) $req['per_page'] );
	}

	/**
	 * POST /feeds.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_feed( $req ) {
		$id = rmh()->get( 'feeds' )->create(
			array(
				'url'           => (string) $req['url'],
				'name'          => (string) $req['name'],
				'status'        => $req['status'] ? (string) $req['status'] : 'active',
				'poll_interval' => (int) $req['poll_interval'],
				'config'        => is_array( $req['config'] ) ? $req['config'] : array(),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $this->err( $id, 'rmh_feed_exists' === $id->get_error_code() ? 409 : 400 );
		}
		$r = rest_ensure_response( self::feed_out( rmh()->get( 'feeds' )->find( $id ) ) );
		$r->set_status( 201 );
		return $r;
	}

	/**
	 * GET /feeds/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_feed( $req ) {
		$f = rmh()->get( 'feeds' )->find( (int) $req['id'] );
		return $f ? rest_ensure_response( self::feed_out( $f ) ) : new \WP_Error( 'rmh_feed_missing', __( 'Feed not found.', 'rss-media-hub' ), array( 'status' => 404 ) );
	}

	/**
	 * PATCH /feeds/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_feed( $req ) {
		$data = array();
		foreach ( array( 'url', 'name', 'status', 'poll_interval', 'config' ) as $k ) {
			if ( null !== $req[ $k ] ) {
				$data[ $k ] = $req[ $k ];
			}
		}
		$ok = rmh()->get( 'feeds' )->update( (int) $req['id'], $data );
		if ( is_wp_error( $ok ) ) {
			return $this->err( $ok, 'rmh_feed_missing' === $ok->get_error_code() ? 404 : 400 );
		}
		return $this->get_feed( $req );
	}

	/**
	 * DELETE /feeds/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_feed( $req ) {
		if ( ! rmh()->get( 'feeds' )->delete( (int) $req['id'] ) ) {
			return new \WP_Error( 'rmh_feed_missing', __( 'Feed not found.', 'rss-media-hub' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'deleted' => true,
				'note'    => __( 'Imported posts were kept.', 'rss-media-hub' ),
			)
		);
	}

	/**
	 * POST /feeds/{id}/test.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_feed( $req ) {
		$f = rmh()->get( 'feeds' )->find( (int) $req['id'] );
		if ( ! $f ) {
			return new \WP_Error( 'rmh_feed_missing', __( 'Feed not found.', 'rss-media-hub' ), array( 'status' => 404 ) );
		}
		$cfg              = $f->config;
		$cfg['auth_pass'] = \RMH\Security\Secrets::decrypt( (string) $cfg['auth_pass'] );
		return rest_ensure_response( rmh()->get( 'importer' )->test_feed( $f->url, $cfg ) );
	}

	/**
	 * POST /feeds/test.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_url( $req ) {
		$url = \RMH\Db\FeedRepository::validate_url( (string) $req['url'] );
		if ( is_wp_error( $url ) ) {
			return $this->err( $url );
		}
		return rest_ensure_response( rmh()->get( 'importer' )->test_feed( $url ) );
	}

	/**
	 * POST /feeds/{id}/run (queued; does not block the request).
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_feed( $req ) {
		if ( ! rmh()->get( 'feeds' )->find( (int) $req['id'] ) ) {
			return new \WP_Error( 'rmh_feed_missing', __( 'Feed not found.', 'rss-media-hub' ), array( 'status' => 404 ) );
		}
		rmh()->get( 'scheduler' )->queue_feed( (int) $req['id'] );
		$r = rest_ensure_response( array( 'queued' => true ) );
		$r->set_status( 202 );
		return $r;
	}

	/**
	 * GET /items.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function list_items( $req ) {
		$res = rmh()->get( 'items' )->query(
			array(
				'feed_id'    => (int) $req['feed_id'],
				'status'     => $req['status'] ? array_map( 'sanitize_key', explode( ',', (string) $req['status'] ) ) : array(),
				'media_type' => (string) $req['media_type'],
				'search'     => (string) $req['search'],
				'page'       => (int) $req['page'],
				'per_page'   => (int) $req['per_page'],
			)
		);
		return $this->paged( array_map( array( __CLASS__, 'item_out' ), $res['rows'] ), $res['total'], (int) $req['per_page'] );
	}

	/**
	 * POST /items/{id}/{action}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function item_action( $req ) {
		$action   = (string) $req['action'];
		$importer = rmh()->get( 'importer' );
		$result   = $importer->$action( (int) $req['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->err( $result, 'rmh_item_missing' === $result->get_error_code() ? 404 : 409 );
		}
		return rest_ensure_response( self::item_out( rmh()->get( 'items' )->find( (int) $req['id'] ) ) );
	}

	/**
	 * GET /media.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function list_media( $req ) {
		$res = rmh()->get( 'media' )->query(
			array(
				'media_type'        => (string) $req['media_type'],
				'validation_status' => (string) $req['validation_status'],
				'post_id'           => (int) $req['post_id'],
				'page'              => (int) $req['page'],
				'per_page'          => (int) $req['per_page'],
			)
		);
		return $this->paged( array_map( array( __CLASS__, 'media_out' ), $res['rows'] ), $res['total'], (int) $req['per_page'] );
	}

	/**
	 * POST /media/{id}/revalidate.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revalidate_media( $req ) {
		$m = rmh()->get( 'media' )->find( (int) $req['id'] );
		if ( ! $m ) {
			return new \WP_Error( 'rmh_media_missing', __( 'Media not found.', 'rss-media-hub' ), array( 'status' => 404 ) );
		}
		$result = rmh()->get( 'validator' )->validate( $m );
		return rest_ensure_response( array_merge( self::media_out( rmh()->get( 'media' )->find( $m->id ) ), array( 'result' => $result ) ) );
	}

	/**
	 * GET /jobs.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function list_jobs( $req ) {
		$per = max( 1, min( 200, (int) ( $req['per_page'] ? $req['per_page'] : 30 ) ) );
		$res = rmh()->get( 'jobs' )->query(
			array(
				'feed_id'  => (int) $req['feed_id'],
				'status'   => (string) $req['status'],
				'page'     => max( 1, (int) $req['page'] ),
				'per_page' => $per,
			)
		);
		return $this->paged( $res['rows'], $res['total'], $per );
	}

	/**
	 * POST /jobs/retry-failed: requeue failed items and failing feeds.
	 *
	 * @return \WP_REST_Response
	 */
	public static function retry_failed() {
		global $wpdb;
		$items = \RMH\Installer::table( 'items' );
		$feeds = \RMH\Installer::table( 'feeds' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$n_items = (int) $wpdb->query( "UPDATE {$items} SET status = 'discovered', attempts = 0, next_attempt_at = NULL WHERE status = 'failed'" );
		$n_feeds = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$feeds} SET next_poll_at = %s WHERE status = 'active' AND health = 'error'", gmdate( 'Y-m-d H:i:s' ) ) );
		// phpcs:enable
		rmh()->get( 'logger' )->info( 'retry', sprintf( 'Manual retry: %d failed item(s) and %d failing feed(s) requeued.', $n_items, $n_feeds ) );
		return rest_ensure_response(
			array(
				'items_requeued' => $n_items,
				'feeds_requeued' => $n_feeds,
			)
		);
	}

	/**
	 * GET /blocklist.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_blocklist() {
		return rest_ensure_response( rmh()->get( 'blocklist' )->all() );
	}

	/**
	 * POST /blocklist.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_blocklist( $req ) {
		$id = rmh()->get( 'blocklist' )->add( (string) $req['type'], (string) $req['value'], (string) $req['reason'] );
		if ( is_wp_error( $id ) ) {
			return $this->err( $id, 'rmh_block_exists' === $id->get_error_code() ? 409 : 400 );
		}
		$r = rest_ensure_response( array( 'id' => $id ) );
		$r->set_status( 201 );
		return $r;
	}

	/**
	 * DELETE /blocklist/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_blocklist( $req ) {
		return rmh()->get( 'blocklist' )->remove( (int) $req['id'] ) ? rest_ensure_response( array( 'deleted' => true ) ) : new \WP_Error( 'rmh_block_missing', __( 'Not found.', 'rss-media-hub' ), array( 'status' => 404 ) );
	}

	/**
	 * POST /reports (public).
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_report( $req ) {
		$result = Reports::submit(
			array(
				'post_id' => (int) $req['post_id'],
				'reason'  => (string) $req['reason'],
				'details' => (string) $req['details'],
				'contact' => (string) $req['contact'],
				'website' => (string) $req['website'],
			)
		);
		if ( is_wp_error( $result ) ) {
			return $this->err( $result );
		}
		$r = rest_ensure_response( array( 'received' => true ) );
		$r->set_status( 202 );
		return $r;
	}

	/**
	 * GET /reports.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function list_reports( $req ) {
		$res = rmh()->get( 'reports' )->query(
			array(
				'status' => (string) $req['status'],
				'page'   => max( 1, (int) $req['page'] ),
			)
		);
		return $this->paged( $res['rows'], $res['total'], 30 );
	}

	/**
	 * POST /views/{id}: privacy-conscious aggregate counter (no IP, no cookies stored).
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function count_view( $req ) {
		if ( ! rmh()->get( 'settings' )->get( 'analytics_enabled' ) ) {
			return new \WP_Error( 'rmh_analytics_off', __( 'Analytics disabled.', 'rss-media-hub' ), array( 'status' => 404 ) );
		}
		$post_id = (int) $req['id'];
		if ( \RMH\PostTypes::POST_TYPE !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'rmh_not_found', __( 'Not found.', 'rss-media-hub' ), array( 'status' => 404 ) );
		}
		if ( ! RateLimiter::hit( 'view', 60, HOUR_IN_SECONDS ) || ! RateLimiter::hit( 'view-' . $post_id, 1, 30 * MINUTE_IN_SECONDS ) ) {
			return rest_ensure_response( array( 'counted' => false ) );
		}
		update_post_meta( $post_id, '_rmh_views', (int) get_post_meta( $post_id, '_rmh_views', true ) + 1 );
		return rest_ensure_response( array( 'counted' => true ) );
	}
}

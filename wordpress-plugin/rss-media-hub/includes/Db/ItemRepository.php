<?php
/**
 * Discovered feed items: deduplication identity, queue state and payload.
 *
 * @package RSSMediaHub
 */

namespace RMH\Db;

use RMH\Installer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

/**
 * Item statuses:
 *  discovered – parsed, waiting for the publish step (or retry after failure);
 *  queued     – waiting for administrator approval (manual mode);
 *  pending    – a pending/draft post exists, awaiting editorial review;
 *  published  – a published post exists;
 *  rejected   – rejected by an administrator;
 *  skipped    – filtered out by import rules (e.g. media-only feed, no media);
 *  duplicate  – same canonical URL already imported by another feed;
 *  blocked    – matched the blocklist;
 *  failed     – exhausted publish retries;
 *  removed    – taken down (removal request / moderation).
 */
class ItemRepository {

	const STATUSES = array( 'discovered', 'queued', 'pending', 'published', 'rejected', 'skipped', 'duplicate', 'blocked', 'failed', 'removed' );

	const MAX_ATTEMPTS = 5;

	/**
	 * Table.
	 *
	 * @return string
	 */
	private function table() {
		return Installer::table( 'items' );
	}

	/**
	 * Hydrate payload JSON.
	 *
	 * @param object|null $row Row.
	 * @return object|null
	 */
	private function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row->id      = (int) $row->id;
		$row->feed_id = (int) $row->feed_id;
		$row->post_id = (int) $row->post_id;
		$decoded      = json_decode( (string) $row->payload, true );
		$row->data    = is_array( $decoded ) ? $decoded : array();
		return $row;
	}

	/**
	 * Compute the stable per-feed identity of an item.
	 * Priority: GUID, normalized link, then a content fingerprint.
	 *
	 * @param int   $feed_id Feed id.
	 * @param array $item    Normalized item (guid, link, title, published).
	 * @return array{identity:string,guid_hash:string,url_hash:string}
	 */
	public static function identity( $feed_id, array $item ) {
		$guid     = isset( $item['guid'] ) ? trim( (string) $item['guid'] ) : '';
		$url_hash = \RMH\Http\UrlNormalizer::hash( isset( $item['link'] ) ? $item['link'] : '' );
		if ( '' !== $guid ) {
			$basis = 'guid:' . $guid;
		} elseif ( '' !== $url_hash ) {
			$basis = 'url:' . $url_hash;
		} else {
			$basis = 'fp:' . sha1( strtolower( trim( (string) ( isset( $item['title'] ) ? $item['title'] : '' ) ) ) . '|' . ( isset( $item['published'] ) ? (string) $item['published'] : '' ) . '|' . sha1( (string) ( isset( $item['summary'] ) ? $item['summary'] : '' ) ) );
		}
		return array(
			'identity'  => sha1( (int) $feed_id . "\n" . $basis ),
			'guid_hash' => '' !== $guid ? sha1( $guid ) : '',
			'url_hash'  => $url_hash,
		);
	}

	/**
	 * Insert a new item. Returns false when the identity already exists
	 * (unique key), which makes concurrent/re-run inserts idempotent.
	 *
	 * @param array $row Column values.
	 * @return int|false
	 */
	public function insert( array $row ) {
		global $wpdb;
		$now = rmh()->get( 'clock' )->mysql();
		$row = array_merge(
			array(
				'status'        => 'discovered',
				'discovered_at' => $now,
				'updated_at'    => $now,
			),
			$row
		);
		if ( isset( $row['payload'] ) && is_array( $row['payload'] ) ) {
			$row['payload'] = wp_json_encode( $row['payload'] );
		}
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( $this->table(), $row );
		$wpdb->suppress_errors( $suppress );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update columns.
	 *
	 * @param int   $id  Item id.
	 * @param array $row Columns.
	 * @return bool
	 */
	public function update( $id, array $row ) {
		global $wpdb;
		if ( isset( $row['payload'] ) && is_array( $row['payload'] ) ) {
			$row['payload'] = wp_json_encode( $row['payload'] );
		}
		$row['updated_at'] = rmh()->get( 'clock' )->mysql();
		return false !== $wpdb->update( $this->table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * Find by id.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public function find( $id ) {
		global $wpdb;
		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * Find by identity hash.
	 *
	 * @param string $identity Hash.
	 * @return object|null
	 */
	public function find_by_identity( $identity ) {
		global $wpdb;
		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE identity_hash = %s", (string) $identity ) ) );
	}

	/**
	 * Find the first item (in another feed) with the same canonical URL.
	 *
	 * @param string $url_hash   Normalized URL hash.
	 * @param int    $exclude_id Item id to exclude.
	 * @return object|null
	 */
	public function find_by_url_hash( $url_hash, $exclude_id = 0 ) {
		global $wpdb;
		if ( '' === (string) $url_hash ) {
			return null;
		}
		return $this->hydrate(
			$wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table()} WHERE url_hash = %s AND id <> %d AND status NOT IN ('duplicate','rejected','blocked','skipped') ORDER BY id ASC LIMIT 1",
					(string) $url_hash,
					(int) $exclude_id
				)
			)
		);
	}

	/**
	 * Find the item that produced a post.
	 *
	 * @param int $post_id Post id.
	 * @return object|null
	 */
	public function find_by_post( $post_id ) {
		global $wpdb;
		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE post_id = %d ORDER BY id ASC LIMIT 1", (int) $post_id ) ) );
	}

	/**
	 * Items waiting for the publish step (new, or failed and due for retry).
	 *
	 * @param int $limit   Max.
	 * @param int $feed_id Restrict to a feed (0 = any).
	 * @return object[]
	 */
	public function due_for_processing( $limit, $feed_id = 0 ) {
		global $wpdb;
		$now    = rmh()->get( 'clock' )->mysql();
		$sql    = "SELECT * FROM {$this->table()} WHERE status = 'discovered' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)";
		$params = array( $now );
		if ( $feed_id ) {
			$sql     .= ' AND feed_id = %d';
			$params[] = (int) $feed_id;
		}
		$sql     .= ' ORDER BY id ASC LIMIT %d';
		$params[] = max( 1, (int) $limit );
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from constant fragments and placeholders only.
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Record a processing failure with exponential retry scheduling.
	 *
	 * @param object $item  Item.
	 * @param string $error Message.
	 * @return bool True if it will be retried, false if it is now 'failed'.
	 */
	public function record_failure( $item, $error ) {
		$attempts = (int) $item->attempts + 1;
		$clock    = rmh()->get( 'clock' );
		$retry    = $attempts < self::MAX_ATTEMPTS;
		$this->update(
			$item->id,
			array(
				'attempts'        => $attempts,
				'last_error'      => mb_substr( \RMH\Security\Redactor::text( (string) $error ), 0, 1000 ),
				'status'          => $retry ? 'discovered' : 'failed',
				'next_attempt_at' => $retry ? $clock->mysql( $clock->now() + 60 * (int) pow( 4, $attempts - 1 ) ) : null,
			)
		);
		return $retry;
	}

	/**
	 * Query items for admin screens and the REST API.
	 *
	 * @param array $args feed_id, status, media_type, search, date_from, date_to, duplicates_only, per_page, page, orderby, order.
	 * @return array{rows:object[],total:int}
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['feed_id'] ) ) {
			$where[]  = 'feed_id = %d';
			$params[] = (int) $args['feed_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$statuses = array_values( array_intersect( (array) $args['status'], self::STATUSES ) );
			if ( $statuses ) {
				$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$params  = array_merge( $params, $statuses );
			}
		}
		if ( ! empty( $args['media_type'] ) ) {
			$where[]  = 'media_type = %s';
			$params[] = sanitize_key( $args['media_type'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(title LIKE %s OR source_url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'discovered_at >= %s';
			$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( (string) $args['date_from'] ) );
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'discovered_at <= %s';
			$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( (string) $args['date_to'] ) );
		}
		$orderable = array( 'id', 'discovered_at', 'source_published_at', 'title', 'status' );
		$orderby   = isset( $args['orderby'] ) && in_array( $args['orderby'], $orderable, true ) ? $args['orderby'] : 'id';
		$order     = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page  = isset( $args['per_page'] ) ? max( 1, min( 500, (int) $args['per_page'] ) ) : 30;
		$page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$w         = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$w}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $count_sql holds only table names and placeholders; values are bound by prepare() whenever present.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders and values are assembled together from the same filter list.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE {$w} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			)
		);
		return array(
			'rows'  => array_map( array( $this, 'hydrate' ), $rows ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Counts by status.
	 *
	 * @return array<string,int>
	 */
	public function counts_by_status() {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status" ) as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		$out['total'] = array_sum( $out );
		return $out;
	}

	/**
	 * Counts by media type (published + pending items).
	 *
	 * @return array<string,int>
	 */
	public function counts_by_media_type() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT media_type, COUNT(*) AS n FROM {$this->table()} WHERE status IN ('published','pending','queued') GROUP BY media_type" ) as $r ) {
			$out[ $r->media_type ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Delete rejected/skipped rows older than N days (keeps identity history otherwise).
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public function purge_rejected( $days ) {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE status IN ('rejected','skipped') AND post_id = 0 AND updated_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, (int) $days ) )
			)
		);
	}
}

<?php
/**
 * Media asset records (one item has zero or more).
 *
 * @package RSSMediaHub
 */

namespace RMH\Db;

use RMH\Installer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

/**
 * Validation statuses: unchecked, valid, invalid (wrong type), unavailable (gone/4xx/5xx),
 * blocked (prohibited destination or blocklisted), error (transient failure).
 */
class MediaRepository {

	const VALIDATION = array( 'unchecked', 'valid', 'invalid', 'unavailable', 'blocked', 'error' );

	/**
	 * Table.
	 *
	 * @return string
	 */
	private function table() {
		return Installer::table( 'media' );
	}

	/**
	 * Cast numeric fields.
	 *
	 * @param object|null $row Row.
	 * @return object|null
	 */
	private function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'item_id', 'post_id', 'position', 'width', 'height', 'duration', 'filesize', 'attachment_id', 'local_bytes', 'priority' ) as $k ) {
			$row->$k = (int) $row->$k;
		}
		return $row;
	}

	/**
	 * Replace the media set of an item. Validation state is preserved for URLs
	 * that are unchanged; local attachments of removed URLs are reported back.
	 *
	 * @param int   $item_id    Item id.
	 * @param int   $post_id    Post id (0 if none yet).
	 * @param array $candidates MediaCandidate arrays (see Media\MediaCandidate::to_array()).
	 * @return int[] Inserted/kept media ids in order.
	 */
	public function replace_for_item( $item_id, $post_id, array $candidates ) {
		global $wpdb;
		$existing = array();
		foreach ( $this->for_item( $item_id ) as $m ) {
			$existing[ $m->url_hash ] = $m;
		}
		$now  = rmh()->get( 'clock' )->mysql();
		$ids  = array();
		$seen = array();
		foreach ( array_values( $candidates ) as $pos => $c ) {
			$hash = sha1( (string) $c['url'] );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$row           = array(
				'item_id'           => (int) $item_id,
				'post_id'           => (int) $post_id,
				'position'          => (int) $pos,
				'media_type'        => (string) $c['type'],
				'url'               => (string) $c['url'],
				'url_hash'          => $hash,
				'mime_type'         => mb_substr( (string) $c['mime'], 0, 100 ),
				'thumbnail_url'     => (string) $c['thumbnail'],
				'width'             => (int) $c['width'],
				'height'            => (int) $c['height'],
				'duration'          => (int) $c['duration'],
				'filesize'          => (int) $c['filesize'],
				'provider'          => (string) $c['provider'],
				'embed_url'         => (string) $c['embed_url'],
				'extraction_method' => (string) $c['method'],
				'priority'          => (int) $c['priority'],
				'title'             => mb_substr( (string) $c['title'], 0, 500 ),
				'description'       => mb_substr( (string) $c['description'], 0, 2000 ),
				'credit'            => mb_substr( (string) $c['credit'], 0, 255 ),
			);
			if ( isset( $existing[ $hash ] ) ) {
				$wpdb->update( $this->table(), $row, array( 'id' => $existing[ $hash ]->id ) );
				$ids[] = $existing[ $hash ]->id;
				unset( $existing[ $hash ] );
			} else {
				$row['retrieved_at'] = $now;
				$wpdb->insert( $this->table(), $row );
				$ids[] = (int) $wpdb->insert_id;
			}
		}
		// Media no longer present in the source: keep rows that hold a local
		// file (an admin decides), drop the rest.
		foreach ( $existing as $gone ) {
			if ( $gone->attachment_id ) {
				$wpdb->update(
					$this->table(),
					array(
						'validation_status'  => 'unavailable',
						'validation_message' => 'No longer present in the source feed.',
					),
					array( 'id' => $gone->id )
				);
			} else {
				$wpdb->delete( $this->table(), array( 'id' => $gone->id ) );
			}
		}
		return $ids;
	}

	/**
	 * Attach item media to a post id.
	 *
	 * @param int $item_id Item.
	 * @param int $post_id Post.
	 */
	public function assign_post( $item_id, $post_id ) {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'post_id' => (int) $post_id ), array( 'item_id' => (int) $item_id ) );
	}

	/**
	 * Media for an item, ordered.
	 *
	 * @param int $item_id Item id.
	 * @return object[]
	 */
	public function for_item( $item_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE item_id = %d ORDER BY priority ASC, position ASC, id ASC", (int) $item_id ) );
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Media for a post, ordered.
	 *
	 * @param int $post_id Post id.
	 * @return object[]
	 */
	public function for_post( $post_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE post_id = %d ORDER BY priority ASC, position ASC, id ASC", (int) $post_id ) );
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
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
	 * Update columns.
	 *
	 * @param int   $id  Id.
	 * @param array $row Columns.
	 * @return bool
	 */
	public function update( $id, array $row ) {
		global $wpdb;
		return false !== $wpdb->update( $this->table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Media needing (re)validation: unchecked first, then oldest checks.
	 *
	 * @param int $limit      Max rows.
	 * @param int $older_than Seconds since last check.
	 * @return object[]
	 */
	public function due_for_validation( $limit, $older_than ) {
		global $wpdb;
		$cut  = gmdate( 'Y-m-d H:i:s', rmh()->get( 'clock' )->now() - (int) $older_than );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE media_type IN ('video','audio','image') AND attachment_id = 0 AND (checked_at IS NULL OR checked_at < %s) ORDER BY checked_at IS NOT NULL, checked_at ASC, id ASC LIMIT %d",
				$cut,
				max( 1, (int) $limit )
			)
		);
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Rows queued for authorized local download.
	 *
	 * @param int $limit Max rows.
	 * @return object[]
	 */
	public function queued_downloads( $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE download_status = 'queued' ORDER BY id ASC LIMIT %d", max( 1, (int) $limit ) ) );
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Total bytes stored locally by the plugin.
	 *
	 * @return int
	 */
	public function local_bytes_total() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(local_bytes),0) FROM {$this->table()} WHERE attachment_id > 0" );
	}

	/**
	 * Query for the media library screen / REST.
	 *
	 * @param array $args media_type, validation_status, post_id, search, per_page, page.
	 * @return array{rows:object[],total:int}
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['media_type'] ) ) {
			$where[]  = 'media_type = %s';
			$params[] = sanitize_key( $args['media_type'] );
		}
		if ( ! empty( $args['validation_status'] ) && in_array( $args['validation_status'], self::VALIDATION, true ) ) {
			$where[]  = 'validation_status = %s';
			$params[] = $args['validation_status'];
		}
		if ( ! empty( $args['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $args['post_id'];
		}
		if ( ! empty( $args['local_only'] ) ) {
			$where[] = 'attachment_id > 0';
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 500, (int) $args['per_page'] ) ) : 30;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$w        = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$w}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $count_sql holds only table names and placeholders; values are bound by prepare() whenever present.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders and values are assembled together from the same filter list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );
		return array(
			'rows'  => array_map( array( $this, 'hydrate' ), $rows ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Counts by validation status.
	 *
	 * @return array<string,int>
	 */
	public function counts_by_validation() {
		global $wpdb;
		$out = array_fill_keys( self::VALIDATION, 0 );
		foreach ( (array) $wpdb->get_results( "SELECT validation_status, COUNT(*) AS n FROM {$this->table()} GROUP BY validation_status" ) as $r ) {
			$out[ $r->validation_status ] = (int) $r->n;
		}
		return $out;
	}
}

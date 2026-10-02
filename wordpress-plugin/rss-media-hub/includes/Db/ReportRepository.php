<?php
/**
 * Content reports, removal requests and moderation decisions.
 *
 * @package RSSMediaHub
 */

namespace RMH\Db;

use RMH\Installer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

/**
 * Report statuses: open, escalated, resolved, dismissed.
 */
class ReportRepository {

	/**
	 * Report reasons with labels. 'minor' (suspected child sexual abuse
	 * material / underage subject) and 'nonconsensual' trigger immediate
	 * hiding and escalation.
	 *
	 * @return array<string,string>
	 */
	public static function reasons() {
		return array(
			'broken_media'  => __( 'Media is broken or unavailable', 'rss-media-hub' ),
			'copyright'     => __( 'Copyright / removal request', 'rss-media-hub' ),
			'nonconsensual' => __( 'Non-consensual content', 'rss-media-hub' ),
			'minor'         => __( 'Involves a minor (suspected CSAM)', 'rss-media-hub' ),
			'illegal'       => __( 'Other illegal content', 'rss-media-hub' ),
			'mislabeled'    => __( 'Wrong content label / missing warning', 'rss-media-hub' ),
			'other'         => __( 'Other', 'rss-media-hub' ),
		);
	}

	/**
	 * Reasons that hide the post immediately and escalate.
	 */
	const URGENT = array( 'minor', 'nonconsensual' );

	const STATUSES = array( 'open', 'escalated', 'resolved', 'dismissed' );

	/**
	 * Table.
	 *
	 * @return string
	 */
	private function table() {
		return Installer::table( 'reports' );
	}

	/**
	 * Create a report.
	 *
	 * @param array $data post_id, item_id, reason, details, contact, reporter_hash, status.
	 * @return int
	 */
	public function create( array $data ) {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'post_id'       => isset( $data['post_id'] ) ? (int) $data['post_id'] : 0,
				'item_id'       => isset( $data['item_id'] ) ? (int) $data['item_id'] : 0,
				'reason'        => (string) $data['reason'],
				'details'       => isset( $data['details'] ) ? mb_substr( sanitize_textarea_field( (string) $data['details'] ), 0, 2000 ) : '',
				'contact'       => isset( $data['contact'] ) ? mb_substr( sanitize_text_field( (string) $data['contact'] ), 0, 200 ) : '',
				'reporter_hash' => isset( $data['reporter_hash'] ) ? (string) $data['reporter_hash'] : '',
				'status'        => isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'open',
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a report.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Set a report's status.
	 *
	 * @param int    $id         Id.
	 * @param string $status     Status.
	 * @param string $resolution Note.
	 * @return bool
	 */
	public function set_status( $id, $status, $resolution = '' ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$row = array(
			'status'      => $status,
			'resolution'  => mb_substr( sanitize_textarea_field( (string) $resolution ), 0, 2000 ),
			'resolved_by' => get_current_user_id(),
		);
		if ( in_array( $status, array( 'resolved', 'dismissed' ), true ) ) {
			$row['resolved_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		return false !== $wpdb->update( $this->table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * Has this reporter already reported this post with this reason (open)?
	 *
	 * @param int    $post_id Post id.
	 * @param string $reason  Reason.
	 * @param string $hash    Reporter hash.
	 * @return bool
	 */
	public function is_duplicate( $post_id, $reason, $hash ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE post_id = %d AND reason = %s AND reporter_hash = %s AND status IN ('open','escalated') LIMIT 1", (int) $post_id, (string) $reason, (string) $hash ) );
	}

	/**
	 * Query reports.
	 *
	 * @param array $args status, reason, post_id, per_page, page.
	 * @return array{rows:object[],total:int}
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['reason'] ) ) {
			$where[]  = 'reason = %s';
			$params[] = sanitize_key( $args['reason'] );
		}
		if ( ! empty( $args['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $args['post_id'];
		}
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 500, (int) $args['per_page'] ) ) : 30;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$w        = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$w}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $count_sql holds only table names and placeholders; values are bound by prepare() whenever present.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders and values are assembled together from the same filter list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$w} ORDER BY (status = 'escalated') DESC, (status = 'open') DESC, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );
		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Count open + escalated reports.
	 *
	 * @return int
	 */
	public function open_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE status IN ('open','escalated')" );
	}
}

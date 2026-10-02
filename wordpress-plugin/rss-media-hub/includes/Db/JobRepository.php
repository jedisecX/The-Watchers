<?php
/**
 * Import job runs and their statistics.
 *
 * @package RSSMediaHub
 */

namespace RMH\Db;

use RMH\Installer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

/**
 * Job statuses: running, success, partial, failed, skipped, interrupted.
 * Outcomes distinguish: ok, not_modified, empty_feed, all_duplicates, unsupported_media,
 * unreachable, blocked_destination, http_error, invalid_feed, publish_failure, locked, dry_run.
 */
class JobRepository {

	const STAT_FIELDS = array( 'items_fetched', 'items_new', 'duplicates', 'posts_created', 'posts_updated', 'pending_review', 'media_found', 'extraction_failures', 'publish_failures', 'skipped' );

	/**
	 * Table.
	 *
	 * @return string
	 */
	private function table() {
		return Installer::table( 'jobs' );
	}

	/**
	 * Start a job.
	 *
	 * @param string $type    poll|process|revalidate|download|maintenance.
	 * @param int    $feed_id Feed id (0 for global jobs).
	 * @param string $source  cron|manual|cli|rest|test.
	 * @return int Job id.
	 */
	public function start( $type, $feed_id, $source ) {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'type'           => substr( sanitize_key( $type ), 0, 20 ),
				'feed_id'        => (int) $feed_id,
				'trigger_source' => substr( sanitize_key( $source ), 0, 20 ),
				'status'         => 'running',
				'started_at'     => rmh()->get( 'clock' )->mysql(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Finish a job.
	 *
	 * @param int    $id          Job id.
	 * @param string $status      Final status.
	 * @param string $outcome     Outcome code.
	 * @param array  $stats       Stat fields.
	 * @param int    $http_status HTTP status.
	 * @param string $message     Summary message.
	 */
	public function finish( $id, $status, $outcome, array $stats, $http_status = 0, $message = '' ) {
		global $wpdb;
		$row = array(
			'status'      => substr( sanitize_key( $status ), 0, 20 ),
			'outcome'     => substr( sanitize_key( $outcome ), 0, 40 ),
			'http_status' => (int) $http_status,
			'finished_at' => rmh()->get( 'clock' )->mysql(),
			'message'     => mb_substr( \RMH\Security\Redactor::text( (string) $message ), 0, 2000 ),
		);
		foreach ( self::STAT_FIELDS as $f ) {
			$row[ $f ] = isset( $stats[ $f ] ) ? (int) $stats[ $f ] : 0;
		}
		$wpdb->update( $this->table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * Find a job.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Mark jobs that have been running far longer than allowed as interrupted
	 * (e.g. PHP fatal, timeout, server restart). Their feeds' leases expire on
	 * their own, and unfinished items stay in 'discovered' for the next run.
	 *
	 * @param int $max_age Seconds.
	 * @return int Jobs recovered.
	 */
	public function recover_stale( $max_age ) {
		global $wpdb;
		$clock = rmh()->get( 'clock' );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = 'interrupted', outcome = 'interrupted', finished_at = %s, message = 'Job did not finish; recovered by scheduler.' WHERE status = 'running' AND started_at < %s",
				$clock->mysql(),
				$clock->mysql( $clock->now() - (int) $max_age )
			)
		);
	}

	/**
	 * Query jobs.
	 *
	 * @param array $args feed_id, status, type, per_page, page.
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
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = sanitize_key( $args['type'] );
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
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Last successful poll time (any feed).
	 *
	 * @return string|null
	 */
	public function last_success_at() {
		global $wpdb;
		return $wpdb->get_var( "SELECT MAX(finished_at) FROM {$this->table()} WHERE type = 'poll' AND status IN ('success','partial')" );
	}

	/**
	 * Count of failed jobs in the last N hours.
	 *
	 * @param int $hours Hours.
	 * @return int
	 */
	public function failures_since( $hours ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE status IN ('failed','interrupted') AND started_at >= %s", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS * (int) $hours ) ) );
	}

	/**
	 * Purge old jobs.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public function purge_older_than( $days ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE status <> 'running' AND started_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, (int) $days ) ) ) );
	}
}

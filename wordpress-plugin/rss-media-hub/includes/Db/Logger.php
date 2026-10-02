<?php
/**
 * Structured, redacted event log stored in {prefix}rmh_logs.
 *
 * @package RSSMediaHub
 */

namespace RMH\Db;

use RMH\Installer;
use RMH\Security\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Channels: import, http, parse, media, schedule, db, security, retry, test, publish, moderation.
 */
class Logger {

	const LEVELS = array(
		'debug'   => 0,
		'info'    => 1,
		'warning' => 2,
		'error'   => 3,
	);

	/**
	 * Write a log entry. Messages and context are redacted before storage.
	 *
	 * @param string $level   debug|info|warning|error.
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Extra data (feed_id, item_id, job_id are lifted into columns).
	 * @return int Log id (0 when filtered by level or on failure).
	 */
	public function log( $level, $channel, $message, array $context = array() ) {
		global $wpdb;
		$level = isset( self::LEVELS[ $level ] ) ? $level : 'info';
		$min   = (string) rmh()->get( 'settings' )->get( 'log_level' );
		if ( self::LEVELS[ $level ] < ( isset( self::LEVELS[ $min ] ) ? self::LEVELS[ $min ] : 1 ) ) {
			return 0;
		}
		$ids = array();
		foreach ( array( 'feed_id', 'item_id', 'job_id' ) as $k ) {
			$ids[ $k ] = isset( $context[ $k ] ) ? absint( $context[ $k ] ) : 0;
			unset( $context[ $k ] );
		}
		$row      = array(
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'level'      => $level,
			'channel'    => substr( sanitize_key( $channel ), 0, 20 ),
			'feed_id'    => $ids['feed_id'],
			'item_id'    => $ids['item_id'],
			'job_id'     => $ids['job_id'],
			'message'    => mb_substr( Redactor::text( wp_strip_all_tags( (string) $message ) ), 0, 2000 ),
			'context'    => $context ? wp_json_encode( Redactor::data( $context ) ) : null,
		);
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( Installer::table( 'logs' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->suppress_errors( $suppress );
		/**
		 * Fires for every stored log entry (e.g. to forward to an external sink).
		 *
		 * @param array $row Redacted row.
		 */
		do_action( 'rmh_logged', $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Info shortcut.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return int
	 */
	public function info( $channel, $message, array $context = array() ) {
		return $this->log( 'info', $channel, $message, $context );
	}

	/**
	 * Warning shortcut.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return int
	 */
	public function warning( $channel, $message, array $context = array() ) {
		return $this->log( 'warning', $channel, $message, $context );
	}

	/**
	 * Error shortcut.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return int
	 */
	public function error( $channel, $message, array $context = array() ) {
		return $this->log( 'error', $channel, $message, $context );
	}

	/**
	 * Debug shortcut.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return int
	 */
	public function debug( $channel, $message, array $context = array() ) {
		return $this->log( 'debug', $channel, $message, $context );
	}

	/**
	 * Query logs.
	 *
	 * @param array $args channel, level, feed_id, job_id, search, per_page, page.
	 * @return array{rows:array,total:int}
	 */
	public function query( array $args ) {
		global $wpdb;
		$table  = Installer::table( 'logs' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['channel'] ) ) {
			$where[]  = 'channel = %s';
			$params[] = (string) $args['channel'];
		}
		if ( ! empty( $args['level'] ) ) {
			$where[]  = 'level = %s';
			$params[] = (string) $args['level'];
		}
		if ( ! empty( $args['feed_id'] ) ) {
			$where[]  = 'feed_id = %d';
			$params[] = (int) $args['feed_id'];
		}
		if ( ! empty( $args['job_id'] ) ) {
			$where[]  = 'job_id = %d';
			$params[] = (int) $args['job_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		$per_page  = isset( $args['per_page'] ) ? max( 1, min( 500, (int) $args['per_page'] ) ) : 50;
		$page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$sql_where = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $count_sql holds only table names and placeholders; values are bound by prepare() whenever present.
		$total    = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		$rows_sql = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constant fragments and placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );
		// phpcs:enable

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Delete logs older than N days.
	 *
	 * @param int $days Days.
	 * @return int Rows deleted.
	 */
	public function purge_older_than( $days ) {
		global $wpdb;
		$table = Installer::table( 'logs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, (int) $days ) ) ) );
	}

	/**
	 * Delete every log row.
	 *
	 * @return int
	 */
	public function purge_all() {
		global $wpdb;
		$table = Installer::table( 'logs' );
		return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}

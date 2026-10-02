<?php
/**
 * WP-CLI commands.
 *
 * @package RSSMediaHub
 */

namespace RMH\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Manage RSS Media Hub from the command line (and from system cron).
 *
 * ## EXAMPLES
 *
 *     # From system cron every 5 minutes (crontab minute field "every 5"):
 *     wp --path=/var/www/html rmh run --quiet
 */
class Command {

	/**
	 * Run due imports now (same work as the WP-Cron tick).
	 *
	 * ## OPTIONS
	 *
	 * [--feed=<id>]
	 * : Only run this feed (ignores its next poll time).
	 *
	 * [--all]
	 * : Run every active feed regardless of schedule.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public function run( $args, $assoc ) {
		$opts = array();
		if ( ! empty( $assoc['feed'] ) ) {
			$opts['feed_ids'] = array( (int) $assoc['feed'] );
		} elseif ( ! empty( $assoc['all'] ) ) {
			$opts['feed_ids'] = wp_list_pluck(
				rmh()->get( 'feeds' )->query(
					array(
						'status'   => 'active',
						'per_page' => 1000,
					)
				)['rows'],
				'id'
			);
		}
		$summary = rmh()->get( 'scheduler' )->tick( 'cli', $opts );
		if ( $summary['locked'] ) {
			\WP_CLI::warning( 'Another run holds the lock; nothing done.' );
			return;
		}
		$rows = array();
		foreach ( $summary['feeds'] as $feed_id => $r ) {
			$rows[] = array(
				'feed'     => $feed_id,
				'status'   => $r['status'],
				'outcome'  => $r['outcome'],
				'new'      => $r['stats']['items_new'],
				'dupes'    => $r['stats']['duplicates'],
				'created'  => $r['stats']['posts_created'],
				'updated'  => $r['stats']['posts_updated'],
				'review'   => $r['stats']['pending_review'],
				'failures' => $r['stats']['publish_failures'] + $r['stats']['extraction_failures'],
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}
		\WP_CLI::success( sprintf( '%d feed(s) processed, %d retried item(s), %d media validated, %d downloaded, %d recovered job(s).', count( $summary['feeds'] ), $summary['retried'], $summary['validated'], $summary['downloaded'], $summary['recovered'] ) );
	}

	/**
	 * Test a feed URL without importing anything.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Feed URL.
	 *
	 * [--format=<format>]
	 * : table|json
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public function test( $args, $assoc ) {
		$url = \RMH\Db\FeedRepository::validate_url( $args[0] );
		if ( is_wp_error( $url ) ) {
			\WP_CLI::error( $url->get_error_message() );
		}
		$report = rmh()->get( 'importer' )->test_feed( $url );
		if ( 'json' === \WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT ) );
			return;
		}
		if ( ! $report['ok'] ) {
			\WP_CLI::error( $report['outcome'] . ': ' . $report['message'] );
		}
		$rows = array();
		foreach ( $report['items'] as $i ) {
			$rows[] = array(
				'title' => mb_substr( $i['title'], 0, 50 ),
				'type'  => $i['media_type'],
				'media' => count( $i['media'] ),
				'date'  => $i['published'] ? $i['published'] : 'invalid/missing',
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'title', 'type', 'media', 'date' ) );
		}
		foreach ( $report['warnings'] as $w ) {
			\WP_CLI::warning( $w );
		}
		\WP_CLI::success( $report['message'] );
	}

	/**
	 * Manage feeds.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list|add|pause|resume|delete
	 *
	 * [<arg>]
	 * : URL (add) or feed id (pause/resume/delete).
	 *
	 * [--name=<name>]
	 * : Feed name (add).
	 *
	 * [--mode=<mode>]
	 * : Import mode override: auto|pending|manual (add).
	 *
	 * [--media-only]
	 * : Only import items with media (add).
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public function feeds( $args, $assoc ) {
		$repo   = rmh()->get( 'feeds' );
		$action = $args[0];
		switch ( $action ) {
			case 'list':
				$rows = array();
				foreach ( $repo->query( array( 'per_page' => 1000 ) )['rows'] as $f ) {
					$rows[] = array(
						'id'           => $f->id,
						'name'         => $f->name,
						'status'       => $f->status,
						'health'       => $f->health,
						'next_poll'    => $f->next_poll_at,
						'last_success' => $f->last_success_at,
						'items'        => $f->items_total,
					);
				}
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'status', 'health', 'next_poll', 'last_success', 'items' ) );
				return;
			case 'add':
				$config = array();
				if ( ! empty( $assoc['mode'] ) ) {
					$config['import_mode'] = $assoc['mode'];
				}
				if ( ! empty( $assoc['media-only'] ) ) {
					$config['content_mode'] = 'media_only';
				}
				$id = $repo->create(
					array(
						'url'    => isset( $args[1] ) ? $args[1] : '',
						'name'   => isset( $assoc['name'] ) ? $assoc['name'] : '',
						'config' => $config,
					)
				);
				if ( is_wp_error( $id ) ) {
					\WP_CLI::error( $id->get_error_message() );
				}
				\WP_CLI::success( "Feed #{$id} added." );
				return;
			case 'pause':
			case 'resume':
				$ok = $repo->update( (int) ( isset( $args[1] ) ? $args[1] : 0 ), array( 'status' => 'pause' === $action ? 'paused' : 'active' ) );
				if ( is_wp_error( $ok ) ) {
					\WP_CLI::error( $ok->get_error_message() );
				}
				\WP_CLI::success( 'Feed updated.' );
				return;
			case 'delete':
				if ( ! $repo->delete( (int) ( isset( $args[1] ) ? $args[1] : 0 ) ) ) {
					\WP_CLI::error( 'Feed not found.' );
				}
				\WP_CLI::success( 'Feed deleted (imported posts kept).' );
				return;
		}
		\WP_CLI::error( 'Unknown action. Use list|add|pause|resume|delete.' );
	}

	/**
	 * Re-check media URLs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max media rows.
	 * ---
	 * default: 50
	 * ---
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public function revalidate( $args, $assoc ) {
		$n = rmh()->get( 'validator' )->run_batch( (int) $assoc['limit'], 0, microtime( true ) + 300 );
		\WP_CLI::success( "Checked {$n} media item(s)." );
	}

	/**
	 * Requeue failed items and failing feeds.
	 */
	public function retry_failed() {
		$r = \RMH\Rest\RestController::retry_failed()->get_data();
		\WP_CLI::success( sprintf( '%d item(s) and %d feed(s) requeued.', $r['items_requeued'], $r['feeds_requeued'] ) );
	}

	/**
	 * Show scheduling and health status.
	 */
	public function status() {
		$s = rmh()->get( 'scheduler' )->status();
		foreach ( $s as $k => $v ) {
			\WP_CLI::line( sprintf( '%-18s %s', $k, is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : ( in_array( $k, array( 'next_tick', 'last_tick', 'lock_expires', 'daily_next' ), true ) && $v ? gmdate( 'c', (int) $v ) : (string) $v ) ) );
		}
		\WP_CLI::line( (string) wp_json_encode( rmh()->get( 'feeds' )->counts() ) );
	}

	/**
	 * Run retention clean-up now.
	 */
	public function maintenance() {
		$r = rmh()->get( 'scheduler' )->maintenance();
		\WP_CLI::success( (string) wp_json_encode( $r ) );
	}

	/**
	 * Print the redacted diagnostic report.
	 */
	public function diagnostics() {
		\WP_CLI::line( \RMH\Admin\Diagnostics::report() );
	}
}

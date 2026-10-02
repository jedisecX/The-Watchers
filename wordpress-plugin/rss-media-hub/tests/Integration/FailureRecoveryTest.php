<?php
/**
 * Failure handling, retries, locking and scheduling.
 * Scenarios 9, 10, 11, 21, 22.
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests\Integration;

use RMH\Cron\Lock;
use RMH\Tests\TestCase;

/**
 * Isolation and recovery.
 */
class FailureRecoveryTest extends TestCase {

	public function test_scenario_9_malformed_feed_is_reported_and_other_feeds_continue() {
		$this->http->on( 'https://broken.example/feed', array( 'body' => $this->fixture( 'malformed.xml' ) ) );
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$bad  = $this->add_feed( 'https://broken.example/feed' );
		$good = $this->add_feed( 'https://alpha.example/feed' );
		rmh()->get( 'feeds' )->mark_due( $bad );
		rmh()->get( 'feeds' )->mark_due( $good );

		$summary = rmh()->get( 'scheduler' )->tick( 'test', array( 'feed_ids' => array( $bad, $good ) ) );
		$this->assertSame( 'invalid_feed', $summary['feeds'][ $bad ]['outcome'] );
		$this->assertSame( 'failed', $summary['feeds'][ $bad ]['status'] );
		$this->assertSame( 'ok', $summary['feeds'][ $good ]['outcome'], 'a failing feed never blocks healthy feeds' );
		$this->assertCount( 1, $this->posts() );

		$f = rmh()->get( 'feeds' )->find( $bad );
		$this->assertSame( 'error', $f->health );
		$this->assertStringContainsString( 'Invalid XML', $f->last_error );
		$logs = rmh()->get( 'logger' )->query( array( 'channel' => 'parse', 'level' => 'error', 'feed_id' => $bad ) );
		$this->assertSame( 1, $logs['total'] );
	}

	public function test_scenario_10_unreachable_feed_backs_off() {
		$this->http->on( 'https://down.example/feed', new \WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' ) );
		$feed = $this->add_feed( 'https://down.example/feed' );
		$res  = $this->run_feed( $feed );
		$this->assertSame( 'unreachable', $res['outcome'] );
		$this->assertSame( 3, $this->http->count( 'https://down.example/feed' ), '1 attempt + 2 immediate retries (retry_limit)' );
		$f = rmh()->get( 'feeds' )->find( $feed );
		$this->assertSame( 1, (int) $f->consecutive_failures );
		$this->assertSame( 'warning', $f->health, 'temporary failure' );
		$delay1 = strtotime( $f->next_poll_at . ' UTC' ) - $this->clock->now();
		$this->assertGreaterThanOrEqual( 290, $delay1 );

		$this->run_feed( $feed );
		$f2     = rmh()->get( 'feeds' )->find( $feed );
		$delay2 = strtotime( $f2->next_poll_at . ' UTC' ) - $this->clock->now();
		$this->assertGreaterThan( $delay1, $delay2, 'exponential backoff' );
		$this->run_feed( $feed );
		$this->assertSame( 'error', rmh()->get( 'feeds' )->find( $feed )->health, 'three consecutive failures => error' );

		$jobs = rmh()->get( 'jobs' )->query( array( 'feed_id' => $feed ) );
		$this->assertSame( 3, $jobs['total'] );
		$this->assertSame( 'unreachable', $jobs['rows'][0]->outcome );
	}

	public function test_scenario_11_temporary_http_error_then_success() {
		$this->http->on(
			'https://flaky.example/feed',
			array(
				array( 'status' => 503, 'headers' => array( 'Retry-After' => '1' ) ),
				array( 'status' => 200, 'body' => $this->fixture( 'rss-video.xml' ) ),
			)
		);
		$res = $this->run_feed( $this->add_feed( 'https://flaky.example/feed' ) );
		$this->assertSame( 'success', $res['status'] );
		$this->assertSame( 1, $res['stats']['posts_created'] );
		$this->assertSame( 2, $this->http->count( 'https://flaky.example/feed' ) );
		$this->assertNotEmpty( $this->clock->sleeps, 'backoff between attempts' );
	}

	public function test_long_retry_after_defers_to_feed_backoff() {
		$this->http->on( 'https://busy.example/feed', array( 'status' => 429, 'headers' => array( 'Retry-After' => '3600' ) ) );
		$feed = $this->add_feed( 'https://busy.example/feed' );
		$res  = $this->run_feed( $feed );
		$this->assertSame( 'http_error', $res['outcome'] );
		$this->assertSame( 1, $this->http->count( 'https://busy.example/feed' ), 'no hammering when the server asks for a long pause' );
		$f = rmh()->get( 'feeds' )->find( $feed );
		$this->assertGreaterThanOrEqual( 3600, strtotime( $f->next_poll_at . ' UTC' ) - $this->clock->now() );
	}

	public function test_permanent_http_errors_are_not_retried_immediately() {
		$this->http->on( 'https://gone.example/feed', array( 'status' => 404 ) );
		$res = $this->run_feed( $this->add_feed( 'https://gone.example/feed' ) );
		$this->assertSame( 'http_error', $res['outcome'] );
		$this->assertSame( 1, $this->http->count( 'https://gone.example/feed' ) );
		$this->assertSame( 404, $res['job_id'] ? (int) rmh()->get( 'jobs' )->find( $res['job_id'] )->http_status : 0 );
	}

	public function test_scenario_21_failure_halfway_through_a_batch_is_isolated_and_retried() {
		$this->serve( 'https://news.example/feed', 'rss-no-media.xml' );
		// Two items; publishing the "roundup" item fails once mid-batch.
		$fail_once = true;
		$this->hook(
			'rmh_before_publish_item',
			static function ( $item ) use ( &$fail_once ) {
				if ( $fail_once && false !== strpos( $item->title, 'roundup' ) ) {
					$fail_once = false;
					throw new \RuntimeException( 'Simulated database failure' );
				}
			}
		);
		$feed = $this->add_feed( 'https://news.example/feed' );
		$res  = $this->run_feed( $feed );
		$this->assertSame( 'partial', $res['status'] );
		$this->assertSame( 1, $res['stats']['posts_created'] );
		$this->assertSame( 1, $res['stats']['publish_failures'] );
		$this->assertCount( 1, $this->posts() );

		$failed = rmh()->get( 'items' )->query( array( 'search' => 'roundup' ) )['rows'][0];
		$this->assertSame( 'discovered', $failed->status, 'kept for retry' );
		$this->assertSame( 1, (int) $failed->attempts );
		$this->assertNotNull( $failed->next_attempt_at );

		// Next run after the retry delay: the failed item is published, nothing is duplicated.
		$this->clock->offset = 120;
		$this->http->on( 'https://news.example/feed', array( 'status' => 304 ) );
		$this->run_feed( $feed );
		$this->assertCount( 2, $this->posts() );
		$this->assertSame( 'published', rmh()->get( 'items' )->find( $failed->id )->status );

		// Running again changes nothing.
		$this->run_feed( $feed );
		$this->assertCount( 2, $this->posts() );
	}

	public function test_items_fail_permanently_after_max_attempts() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$this->hook(
			'rmh_before_publish_item',
			static function () {
				throw new \RuntimeException( 'always fails' );
			}
		);
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		$this->run_feed( $feed );
		$item = rmh()->get( 'items' )->query( array() )['rows'][0];
		for ( $i = 0; $i < 6; $i++ ) {
			$this->clock->offset += 100000;
			$stats                = \RMH\Import\Importer::empty_stats();
			rmh()->get( 'importer' )->publish_due( 0, $stats, microtime( true ) + 10 );
		}
		$this->assertSame( 'failed', rmh()->get( 'items' )->find( $item->id )->status );
		$this->assertSame( \RMH\Db\ItemRepository::MAX_ATTEMPTS, (int) rmh()->get( 'items' )->find( $item->id )->attempts );
	}

	public function test_crash_after_post_insert_is_recovered_without_duplicate() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		// Simulate a crash right after wp_insert_post() (before the item row is updated).
		$this->hook(
			'rmh_post_created',
			static function () {
				throw new \RuntimeException( 'process died' );
			}
		);
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		$this->run_feed( $feed );
		$this->assertCount( 1, $this->posts() );
		remove_all_actions( 'rmh_post_created' );
		( new \RMH\Frontend\CacheIntegration() )->register_hooks();

		$this->clock->offset = 120;
		$stats               = \RMH\Import\Importer::empty_stats();
		rmh()->get( 'importer' )->publish_due( 0, $stats, microtime( true ) + 10 );
		$this->assertCount( 1, $this->posts(), 'the orphaned post is adopted, not duplicated' );
		$this->assertSame( 'published', rmh()->get( 'items' )->query( array() )['rows'][0]->status );
	}

	public function test_scenario_22_overlapping_runs_are_prevented() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		rmh()->get( 'feeds' )->mark_due( $feed );

		$other = new Lock( 'tick' );
		$this->assertTrue( $other->acquire( 300 ) );
		$summary = rmh()->get( 'scheduler' )->tick( 'cron' );
		$this->assertTrue( $summary['locked'] );
		$this->assertSame( 0, rmh()->get( 'jobs' )->query( array() )['total'] );
		$this->assertFalse( ( new Lock( 'tick' ) )->acquire( 300 ), 'second acquire fails while held' );
		$other->release();

		// Per-feed lease: a feed claimed by another runner is skipped even outside the global lock.
		$token = rmh()->get( 'feeds' )->claim( $feed, 300 );
		$this->assertNotFalse( $token );
		$res = $this->run_feed( $feed );
		$this->assertSame( 'locked', $res['outcome'] );
		$this->assertCount( 0, $this->posts() );
		rmh()->get( 'feeds' )->release( $feed, $token );

		$summary = rmh()->get( 'scheduler' )->tick( 'cron' );
		$this->assertFalse( $summary['locked'] );
		$this->assertCount( 1, $this->posts() );
	}

	public function test_expired_lock_is_taken_over_and_stale_jobs_recovered() {
		$stale = new Lock( 'tick' );
		$this->assertTrue( $stale->acquire( 60 ) );
		$job = rmh()->get( 'jobs' )->start( 'poll', 1, 'cron' );

		$this->clock->offset = 3600; // The previous process died an hour ago.
		$fresh               = new Lock( 'tick' );
		$this->assertTrue( $fresh->acquire( 60 ), 'expired lock can be taken over' );
		$fresh->release();

		$summary = rmh()->get( 'scheduler' )->tick( 'cron' );
		$this->assertSame( 1, $summary['recovered'] );
		$this->assertSame( 'interrupted', rmh()->get( 'jobs' )->find( $job )->status );
	}

	public function test_scheduler_processes_only_due_feeds_and_staggers_next_poll() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$this->serve( 'https://audio.example/feed', 'rss-audio.xml' );
		$due     = $this->add_feed( 'https://alpha.example/feed' );
		$not_due = $this->add_feed( 'https://audio.example/feed' );
		rmh()->get( 'feeds' )->mark_due( $due );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'rmh_feeds', array( 'next_poll_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'id' => $not_due ) );

		$summary = rmh()->get( 'scheduler' )->tick( 'cron' );
		$this->assertArrayHasKey( $due, $summary['feeds'] );
		$this->assertArrayNotHasKey( $not_due, $summary['feeds'] );
		$next = strtotime( rmh()->get( 'feeds' )->find( $due )->next_poll_at . ' UTC' ) - time();
		$this->assertGreaterThan( 3000, $next );
		$this->assertLessThan( 4000, $next, 'interval ±10% jitter' );
		$this->assertNotEmpty( get_option( 'rmh_last_tick' ) );
	}

	public function test_paused_feeds_are_not_polled() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		rmh()->get( 'feeds' )->update( $feed, array( 'status' => 'paused' ) );
		rmh()->get( 'feeds' )->mark_due( $feed );
		rmh()->get( 'scheduler' )->tick( 'cron' );
		$this->assertSame( 0, $this->http->count( 'https://alpha.example/feed' ) );
	}

	public function test_cron_events_are_registered_and_self_heal() {
		\RMH\Cron\Scheduler::unschedule_all();
		$this->assertFalse( wp_next_scheduled( 'rmh_tick' ) );
		\RMH\Cron\Scheduler::ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( 'rmh_tick' ) );
		$this->assertNotFalse( wp_next_scheduled( 'rmh_daily' ) );
		$this->assertArrayHasKey( 'rmh_five_minutes', wp_get_schedules() );
		$status = rmh()->get( 'scheduler' )->status();
		$this->assertTrue( $status['scheduled'] );
	}

	public function test_manual_run_is_queued_not_executed_synchronously() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		rmh()->get( 'scheduler' )->queue_feed( $feed );
		$this->assertSame( 0, $this->http->count( 'https://alpha.example/feed' ), 'request returns before the import runs' );
		$this->assertNotFalse( wp_next_scheduled( 'rmh_run_feed', array( $feed ) ) );
		do_action( 'rmh_run_feed', $feed ); // What WP-Cron executes.
		$this->assertCount( 1, $this->posts() );
		wp_clear_scheduled_hook( 'rmh_run_feed', array( $feed ) );
	}

	public function test_retention_maintenance() {
		rmh()->get( 'logger' )->info( 'import', 'old entry' );
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}rmh_logs SET created_at = '2000-01-01 00:00:00'" );
		rmh()->get( 'logger' )->info( 'import', 'new entry' );
		$r = rmh()->get( 'scheduler' )->maintenance();
		$this->assertSame( 1, $r['logs'] );
		$this->assertSame( 2, rmh()->get( 'logger' )->query( array() )['total'], 'new entry + maintenance summary remain' );
	}
}

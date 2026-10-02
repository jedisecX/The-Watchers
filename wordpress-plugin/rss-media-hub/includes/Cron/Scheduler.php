<?php
/**
 * WP-Cron / system-cron scheduling, locking and recovery.
 *
 * @package RSSMediaHub
 */

namespace RMH\Cron;

use RMH\Import\Importer;
use RMH\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A single recurring "tick" (every 5 minutes) processes due feeds within a time
 * budget. Feeds carry their own next_poll_at, so polling is staggered and
 * per-feed intervals and backoff are honoured. A global lock prevents
 * overlapping ticks; per-feed leases prevent double processing even when
 * multiple runners (WP-Cron + system cron + CLI) are active.
 */
class Scheduler {

	const TICK_HOOK  = 'rmh_tick';
	const DAILY_HOOK = 'rmh_daily';
	const FEED_HOOK  = 'rmh_run_feed';
	const NOW_HOOK   = 'rmh_tick_now';
	const INTERVAL   = 'rmh_five_minutes';

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
	 * Hooks.
	 */
	public function register_hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::TICK_HOOK, array( $this, 'cron_tick' ) );
		add_action( self::DAILY_HOOK, array( $this, 'maintenance' ) );
		add_action( self::FEED_HOOK, array( $this, 'run_single_feed' ), 10, 1 );
		add_action( self::NOW_HOOK, array( $this, 'manual_tick' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Add the 5-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		// A short tick is intentional: each feed keeps its own interval (min 5 min) and backoff.
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		$schedules[ self::INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (RSS Media Hub)', 'rss-media-hub' ),
		);
		return $schedules;
	}

	/**
	 * Make sure recurring events exist (self-healing if they were removed).
	 */
	public static function ensure_scheduled() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 60, self::INTERVAL, self::TICK_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Remove all plugin events.
	 */
	public static function unschedule_all() {
		wp_clear_scheduled_hook( self::TICK_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_unschedule_hook( self::FEED_HOOK );
		wp_unschedule_hook( self::NOW_HOOK );
	}

	/**
	 * WP-Cron callback.
	 */
	public function cron_tick() {
		$this->tick( 'cron' );
	}

	/**
	 * Manually requested background run.
	 */
	public function manual_tick() {
		$this->tick( 'manual' );
	}

	/**
	 * Process due work within the time budget.
	 *
	 * @param string $source cron|cli|manual|rest.
	 * @param array  $opts   feed_ids (int[]) to restrict, force (bool) to ignore next_poll_at.
	 * @return array Summary.
	 */
	public function tick( $source = 'cron', array $opts = array() ) {
		$settings = $this->c->get( 'settings' );
		$log      = $this->c->get( 'logger' );
		$runtime  = (int) $settings->get( 'max_runtime' );
		$lock     = new Lock( 'tick' );
		$summary  = array(
			'locked'     => false,
			'feeds'      => array(),
			'recovered'  => 0,
			'retried'    => 0,
			'validated'  => 0,
			'downloaded' => 0,
		);

		if ( ! $lock->acquire( $runtime * 3 + 60 ) ) {
			$summary['locked'] = true;
			$log->info( 'schedule', 'Another import run is in progress; this run was skipped to avoid overlap.', array( 'source' => $source ) );
			return $summary;
		}

		$start    = microtime( true );
		$deadline = $start + $runtime;
		update_option(
			'rmh_last_tick',
			array(
				'time'   => time(),
				'source' => $source,
			),
			false
		);

		try {
			if ( function_exists( 'set_time_limit' ) && 'cli' !== $source ) {
				@set_time_limit( $runtime + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$summary['recovered'] = $this->c->get( 'jobs' )->recover_stale( max( 15 * MINUTE_IN_SECONDS, $runtime * 4 ) );
			if ( $summary['recovered'] ) {
				$log->warning( 'schedule', sprintf( 'Recovered %d interrupted job(s).', $summary['recovered'] ) );
			}

			if ( ! empty( $opts['feed_ids'] ) ) {
				$feeds = array_filter( array_map( array( $this->c->get( 'feeds' ), 'find' ), array_map( 'intval', (array) $opts['feed_ids'] ) ) );
			} else {
				$feeds = $this->c->get( 'feeds' )->due( (int) $settings->get( 'feeds_per_tick' ) );
			}

			$importer = $this->c->get( 'importer' );
			foreach ( $feeds as $feed ) {
				if ( microtime( true ) >= $deadline ) {
					$log->info( 'schedule', 'Time budget exhausted; remaining due feeds will run next tick.' );
					break;
				}
				// Per-feed isolation: one feed's failure never stops the others.
				$summary['feeds'][ $feed->id ] = $importer->run_feed( $feed, $source, $deadline );
			}

			if ( microtime( true ) < $deadline ) {
				$stats              = Importer::empty_stats();
				$summary['retried'] = $importer->publish_due( 0, $stats, $deadline );
			}
			if ( microtime( true ) < $deadline && $settings->get( 'validate_media' ) ) {
				$summary['validated'] = $this->c->get( 'validator' )->run_batch( (int) $settings->get( 'revalidate_batch' ), DAY_IN_SECONDS * (int) $settings->get( 'revalidate_days' ), $deadline );
			}
			if ( microtime( true ) < $deadline ) {
				$summary['downloaded'] = $this->c->get( 'downloader' )->run_batch( 3, $deadline );
			}
		} catch ( \Throwable $e ) {
			$log->error( 'schedule', 'Scheduler error: ' . $e->getMessage() );
		}
		$lock->release();
		update_option(
			'rmh_last_tick_done',
			array(
				'time'     => time(),
				'duration' => round( microtime( true ) - $start, 2 ),
				'feeds'    => count( $summary['feeds'] ),
			),
			false
		);
		return $summary;
	}

	/**
	 * Manual "run now" for one feed (async single event).
	 *
	 * @param int $feed_id Feed.
	 */
	public function run_single_feed( $feed_id ) {
		$this->c->get( 'feeds' )->mark_due( (int) $feed_id );
		$this->tick( 'manual', array( 'feed_ids' => array( (int) $feed_id ) ) );
	}

	/**
	 * Queue a feed to run as soon as possible without blocking the request.
	 *
	 * @param int $feed_id Feed.
	 * @return bool
	 */
	public function queue_feed( $feed_id ) {
		$this->c->get( 'feeds' )->mark_due( (int) $feed_id );
		$args = array( (int) $feed_id );
		if ( ! wp_next_scheduled( self::FEED_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::FEED_HOOK, $args );
		}
		if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return true;
	}

	/**
	 * Daily retention and housekeeping.
	 *
	 * @return array Counts removed.
	 */
	public function maintenance() {
		$s   = $this->c->get( 'settings' );
		$out = array(
			'logs'  => $this->c->get( 'logger' )->purge_older_than( (int) $s->get( 'log_retention_days' ) ),
			'jobs'  => $this->c->get( 'jobs' )->purge_older_than( (int) $s->get( 'job_retention_days' ) ),
			'items' => $this->c->get( 'items' )->purge_rejected( (int) $s->get( 'rejected_retention_days' ) ),
		);
		$this->c->get( 'logger' )->info( 'schedule', sprintf( 'Retention: removed %d log rows, %d job rows, %d rejected/skipped items.', $out['logs'], $out['jobs'], $out['items'] ) );
		return $out;
	}

	/**
	 * Scheduling health for the dashboard and diagnostics.
	 *
	 * @return array
	 */
	public function status() {
		$next      = wp_next_scheduled( self::TICK_HOOK );
		$last      = get_option( 'rmh_last_tick', array() );
		$last_done = get_option( 'rmh_last_tick_done', array() );
		$now       = time();
		$disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$last_time = isset( $last['time'] ) ? (int) $last['time'] : 0;
		$lock      = ( new Lock( 'tick' ) )->status();
		return array(
			'next_tick'        => $next ? (int) $next : 0,
			'scheduled'        => (bool) $next,
			'overdue'          => ! $next || $next < $now - 15 * MINUTE_IN_SECONDS || ( $last_time && $last_time < $now - 30 * MINUTE_IN_SECONDS ),
			'last_tick'        => $last_time,
			'last_source'      => isset( $last['source'] ) ? $last['source'] : '',
			'last_duration'    => isset( $last_done['duration'] ) ? (float) $last_done['duration'] : 0,
			'wp_cron_disabled' => $disabled,
			'lock_held'        => null !== $lock,
			'lock_expires'     => $lock ? $lock['expires'] : 0,
			'daily_next'       => (int) wp_next_scheduled( self::DAILY_HOOK ),
		);
	}
}

<?php
/**
 * Injectable clock so scheduling logic is testable.
 *
 * @package RSSMediaHub
 */

namespace RMH;

defined( 'ABSPATH' ) || exit;

/**
 * Wall clock (UTC unix timestamps).
 */
class Clock {

	/**
	 * Fixed time override (tests).
	 *
	 * @var int|null
	 */
	private $fixed = null;

	/**
	 * Current unix time.
	 *
	 * @return int
	 */
	public function now() {
		return null === $this->fixed ? time() : $this->fixed;
	}

	/**
	 * Freeze time (tests).
	 *
	 * @param int|null $ts Timestamp or null to unfreeze.
	 */
	public function freeze( $ts ) {
		$this->fixed = null === $ts ? null : (int) $ts;
	}

	/**
	 * Monotonic seconds for runtime budgets.
	 *
	 * @return float
	 */
	public function elapsed_base() {
		return microtime( true );
	}

	/**
	 * Sleep helper (no-op when frozen, so tests never wait).
	 *
	 * @param float $seconds Seconds.
	 */
	public function sleep( $seconds ) {
		if ( null !== $this->fixed || $seconds <= 0 ) {
			return;
		}
		usleep( (int) min( 5000000, $seconds * 1000000 ) );
	}

	/**
	 * MySQL DATETIME (UTC) for a timestamp.
	 *
	 * @param int|null $ts Timestamp, defaults to now.
	 * @return string
	 */
	public function mysql( $ts = null ) {
		return gmdate( 'Y-m-d H:i:s', null === $ts ? $this->now() : (int) $ts );
	}
}

<?php
/**
 * Clock that never sleeps.
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests;

/**
 * Records requested sleeps instead of waiting.
 */
class TestClock extends \RMH\Clock {

	/**
	 * Requested sleeps.
	 *
	 * @var float[]
	 */
	public $sleeps = array();

	/**
	 * Offset added to real time.
	 *
	 * @var int
	 */
	public $offset = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function now() {
		return parent::now() + $this->offset;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param float $seconds Seconds.
	 */
	public function sleep( $seconds ) {
		$this->sleeps[] = $seconds;
	}
}

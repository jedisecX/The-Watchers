<?php
/**
 * Atomic, expiring mutex stored in the options table.
 *
 * @package RSSMediaHub
 */

namespace RMH\Cron;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Uses the UNIQUE option_name key for atomic acquisition and a conditional
 * UPDATE for safe takeover of an expired lock. Bypasses the object cache.
 */
class Lock {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Token held by this process.
	 *
	 * @var string
	 */
	private $token = '';

	/**
	 * Constructor.
	 *
	 * @param string $name Lock name.
	 */
	public function __construct( $name ) {
		$this->name = 'rmh_lock_' . sanitize_key( $name );
	}

	/**
	 * Try to acquire.
	 *
	 * @param int $ttl Seconds until the lock is considered abandoned.
	 * @return bool
	 */
	public function acquire( $ttl ) {
		global $wpdb;
		$now   = rmh()->get( 'clock' )->now();
		$token = wp_generate_password( 20, false, false );
		$value = $token . '|' . ( $now + max( 10, (int) $ttl ) );

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $this->name,
				'option_value' => $value,
				'autoload'     => 'no',
			)
		);
		$wpdb->suppress_errors( $suppress );
		$this->flush_cache();

		if ( $inserted ) {
			$this->token = $token;
			return true;
		}

		$current = $this->read();
		if ( null === $current ) {
			return false;
		}
		list( , $expires ) = array_pad( explode( '|', $current, 2 ), 2, '0' );
		if ( (int) $expires >= $now ) {
			return false;
		}
		// Expired: take over only if nobody else did in the meantime.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$this->name,
				$current
			)
		);
		$this->flush_cache();
		if ( 1 === (int) $updated ) {
			$this->token = $token;
			return true;
		}
		return false;
	}

	/**
	 * Release (only if we still hold it).
	 */
	public function release() {
		global $wpdb;
		if ( '' === $this->token ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				$this->name,
				$wpdb->esc_like( $this->token . '|' ) . '%'
			)
		);
		$this->flush_cache();
		$this->token = '';
	}

	/**
	 * Is the lock currently held by anyone (and unexpired)?
	 *
	 * @return array|null {expires:int} or null.
	 */
	public function status() {
		$current = $this->read();
		if ( null === $current ) {
			return null;
		}
		list( , $expires ) = array_pad( explode( '|', $current, 2 ), 2, '0' );
		if ( (int) $expires < rmh()->get( 'clock' )->now() ) {
			return null;
		}
		return array( 'expires' => (int) $expires );
	}

	/**
	 * Force-release (admin recovery).
	 */
	public function force_release() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $this->name ) );
		$this->flush_cache();
	}

	/**
	 * Raw value straight from the DB.
	 *
	 * @return string|null
	 */
	private function read() {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->name ) );
		return null === $v ? null : (string) $v;
	}

	/**
	 * Keep WordPress' option caches coherent.
	 */
	private function flush_cache() {
		wp_cache_delete( $this->name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}

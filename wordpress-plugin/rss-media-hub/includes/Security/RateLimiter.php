<?php
/**
 * Fixed-window rate limiting for public endpoints.
 *
 * @package RSSMediaHub
 */

namespace RMH\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Counts hits per bucket and client in transients. Client identity is a keyed
 * hash of the IP address; raw IPs are never stored.
 */
final class RateLimiter {

	/**
	 * Client IP (REMOTE_ADDR; behind a trusted proxy, filter `rmh_client_ip`).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'rmh_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Keyed, non-reversible client identifier.
	 *
	 * @return string 64 hex chars.
	 */
	public static function client_hash() {
		return hash_hmac( 'sha256', self::client_ip(), wp_salt( 'nonce' ) );
	}

	/**
	 * Record a hit.
	 *
	 * @param string $bucket Bucket.
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Seconds.
	 * @return bool True if allowed.
	 */
	public static function hit( $bucket, $limit, $window ) {
		$key   = 'rmh_rl_' . substr( hash( 'sha256', $bucket . '|' . self::client_hash() ), 0, 32 );
		$state = get_transient( $key );
		$now   = time();
		if ( ! is_array( $state ) || $state['reset'] <= $now ) {
			$state = array(
				'count' => 0,
				'reset' => $now + (int) $window,
			);
		}
		if ( $state['count'] >= (int) $limit ) {
			return false;
		}
		++$state['count'];
		set_transient( $key, $state, max( 1, $state['reset'] - $now ) );
		return true;
	}
}

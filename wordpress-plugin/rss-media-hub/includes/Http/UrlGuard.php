<?php
/**
 * SSRF guard: validates every outbound destination after DNS resolution.
 *
 * @package RSSMediaHub
 */

namespace RMH\Http;

use RMH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a URL for outbound fetching and returns the IP to pin the
 * connection to, so a second (rebinding) DNS answer cannot redirect it.
 */
class UrlGuard {

	/**
	 * Resolver.
	 *
	 * @var ResolverInterface
	 */
	private $resolver;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param ResolverInterface $resolver Resolver.
	 * @param Settings          $settings Settings.
	 */
	public function __construct( ResolverInterface $resolver, Settings $settings ) {
		$this->resolver = $resolver;
		$this->settings = $settings;
	}

	/**
	 * Check a URL.
	 *
	 * @param string $url URL.
	 * @return array|\WP_Error {url, scheme, host, port, ip, ips}
	 */
	public function check( $url ) {
		if ( false !== strpos( (string) $url, "\0" ) ) {
			return $this->deny( 'malformed URL' );
		}
		$url = trim( (string) $url );
		if ( '' === $url || strlen( $url ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return $this->deny( 'malformed URL' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $this->deny( 'malformed URL' );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return $this->deny( 'scheme not allowed' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $this->deny( 'credentials in URL are not allowed' );
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( ! in_array( $port, $this->settings->allowed_ports(), true ) ) {
			return $this->deny( 'port not allowed' );
		}

		$host = strtolower( trim( $parts['host'], '[]' ) );
		$host = rtrim( $host, '.' );
		if ( '' === $host ) {
			return $this->deny( 'empty host' );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			// Refuse non-canonical numeric hosts (2130706433, 0x7f.1, 0177.0.0.1 ...).
			if ( preg_match( '/^[0-9.]+$/', $host ) || preg_match( '/^(0x[0-9a-f]+\.?)+$/i', $host ) || preg_match( '/(^|\.)0x[0-9a-f]*(\.|$)/i', $host ) ) {
				return $this->deny( 'non-canonical numeric host' );
			}
			if ( ! preg_match( '/^(?=.{1,253}$)([a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host ) ) {
				return $this->deny( 'invalid hostname' );
			}
			if ( 'localhost' === $host || preg_match( '/\.(localhost|local|internal|intranet|lan|home\.arpa|corp|localdomain)$/', $host ) ) {
				return $this->deny( 'internal hostname' );
			}
			$ips = $this->resolver->resolve( $host );
			if ( ! $ips ) {
				return new \WP_Error( 'rmh_dns_failed', sprintf( /* translators: %s: hostname */ __( 'Could not resolve host %s.', 'rss-media-hub' ), $host ) );
			}
		}

		// Every resolved address must be public; one private answer taints the host.
		foreach ( $ips as $ip ) {
			if ( ! IpRules::is_public( $ip ) ) {
				return $this->deny( 'destination resolves to a non-public address' );
			}
		}

		/**
		 * Final veto over an outbound destination.
		 *
		 * @param true|\WP_Error $allowed True to allow.
		 * @param string         $url     URL.
		 * @param string[]       $ips     Resolved addresses.
		 */
		$verdict = apply_filters( 'rmh_url_guard_verdict', true, $url, $ips );
		if ( is_wp_error( $verdict ) ) {
			return $verdict;
		}

		return array(
			'url'    => $url,
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
			'ip'     => $ips[0],
			'ips'    => $ips,
		);
	}

	/**
	 * Build a denial error.
	 *
	 * @param string $why Reason.
	 * @return \WP_Error
	 */
	private function deny( $why ) {
		return new \WP_Error(
			'rmh_blocked_destination',
			/* translators: %s: reason */
			sprintf( __( 'Destination refused: %s.', 'rss-media-hub' ), $why )
		);
	}
}

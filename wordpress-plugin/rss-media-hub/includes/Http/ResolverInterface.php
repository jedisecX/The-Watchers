<?php
/**
 * DNS resolver seam.
 *
 * @package RSSMediaHub
 */

namespace RMH\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a hostname to IP addresses.
 */
interface ResolverInterface {

	/**
	 * Resolve a host to all of its A and AAAA addresses.
	 *
	 * @param string $host Hostname (never an IP literal).
	 * @return string[] IP addresses; empty if resolution failed.
	 */
	public function resolve( $host );
}

<?php
/**
 * System DNS resolver.
 *
 * @package RSSMediaHub
 */

namespace RMH\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves A and AAAA records using the system resolver.
 */
class DnsResolver implements ResolverInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	public function resolve( $host ) {
		$ips = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- resolver warnings are expected for hosts without AAAA.
			if ( is_array( $records ) ) {
				foreach ( $records as $r ) {
					if ( ! empty( $r['ipv6'] ) ) {
						$ips[] = $r['ipv6'];
					}
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}
}

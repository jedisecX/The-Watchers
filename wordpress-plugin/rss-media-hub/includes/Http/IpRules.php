<?php
/**
 * IP range classification for SSRF protection.
 *
 * @package RSSMediaHub
 */

namespace RMH\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Determines whether an IP address is publicly routable. Everything that is
 * loopback, private, link-local, shared (CGNAT), reserved, documentation,
 * multicast or a transition mechanism that embeds such an address is refused.
 */
final class IpRules {

	/**
	 * Blocked IPv4 CIDRs.
	 */
	const V4_BLOCKED = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.31.196.0/24',
		'192.52.193.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'192.175.48.0/24',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'255.255.255.255/32',
	);

	/**
	 * Blocked IPv6 CIDRs (embedded-IPv4 ranges are checked separately).
	 */
	const V6_BLOCKED = array(
		'::/128',
		'::1/128',
		'::/96',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'2002::/16',
		'3fff::/20',
		'5f00::/16',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	);

	/**
	 * Is the address safe to connect to?
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public( $ip ) {
		$ip  = trim( (string) $ip, '[] ' );
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		if ( false === $bin ) {
			return false;
		}
		if ( 4 === strlen( $bin ) ) {
			return ! self::in_any( $bin, self::V4_BLOCKED );
		}
		// IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::/96, 64:ff9b:1::/48): judge the embedded IPv4.
		if ( self::in_cidr( $bin, '::ffff:0:0/96' ) || self::in_cidr( $bin, '64:ff9b::/96' ) || self::in_cidr( $bin, '64:ff9b:1::/48' ) ) {
			return ! self::in_any( substr( $bin, 12, 4 ), self::V4_BLOCKED );
		}
		return ! self::in_any( $bin, self::V6_BLOCKED );
	}

	/**
	 * Is $bin inside any of the CIDRs?
	 *
	 * @param string   $bin   Packed address.
	 * @param string[] $cidrs CIDRs of the same family.
	 * @return bool
	 */
	private static function in_any( $bin, array $cidrs ) {
		foreach ( $cidrs as $cidr ) {
			if ( self::in_cidr( $bin, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CIDR membership on packed addresses.
	 *
	 * @param string $bin  Packed address.
	 * @param string $cidr CIDR.
	 * @return bool
	 */
	public static function in_cidr( $bin, $cidr ) {
		list( $net, $bits ) = explode( '/', $cidr );
		$net_bin            = inet_pton( $net );
		if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( $bytes > 0 && substr( $bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( 0 === $rem ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rem ) ) & 0xff;
		return ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask );
	}
}

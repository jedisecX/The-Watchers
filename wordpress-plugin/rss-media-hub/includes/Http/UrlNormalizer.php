<?php
/**
 * URL normalization for duplicate detection.
 *
 * @package RSSMediaHub
 */

namespace RMH\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a canonical form of http(s) URLs (lowercase scheme/host, default
 * ports removed, fragments and tracking parameters dropped).
 */
final class UrlNormalizer {

	/**
	 * Tracking parameters removed before hashing.
	 */
	const TRACKING = array( 'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'igshid', 'ref_src', '_hsenc', '_hsmi', 'yclid' );

	/**
	 * Normalize a URL; returns '' if it is not an absolute http(s) URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url || strlen( $url ) > 4096 ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$path = preg_replace( '#/{2,}#', '/', $path );

		$query = '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$kept = array();
			foreach ( explode( '&', $parts['query'] ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$name = strtolower( rawurldecode( strtok( $pair, '=' ) ) );
				if ( 0 === strpos( $name, 'utm_' ) || in_array( $name, self::TRACKING, true ) ) {
					continue;
				}
				$kept[] = $pair;
			}
			$query = $kept ? '?' . implode( '&', $kept ) : '';
		}

		return $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path . $query;
	}

	/**
	 * Stable hash of the normalized URL ('' when not a URL).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function hash( $url ) {
		$norm = self::normalize( $url );
		return '' === $norm ? '' : sha1( $norm );
	}

	/**
	 * Resolve a possibly relative URL against a base.
	 *
	 * @param string $url  Candidate.
	 * @param string $base Base URL.
	 * @return string Absolute URL or ''.
	 */
	public static function absolutize( $url, $base ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$b = wp_parse_url( (string) $base );
		if ( ! is_array( $b ) || empty( $b['scheme'] ) || empty( $b['host'] ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return $b['scheme'] . ':' . $url;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			return ''; // Any other scheme such as javascript, data or mailto is refused.
		}
		$origin = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $url;
		}
		$dir = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', $b['path'] ) : '/';
		return $origin . $dir . $url;
	}

	/**
	 * Lowercased host of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( rtrim( $host, '.' ) ) : '';
	}
}

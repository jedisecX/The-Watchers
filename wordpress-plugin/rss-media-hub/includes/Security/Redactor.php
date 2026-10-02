<?php
/**
 * Removes secrets from strings and structures before they are logged.
 *
 * @package RSSMediaHub
 */

namespace RMH\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Credential redaction for logs, diagnostics and admin output.
 */
final class Redactor {

	const MASK = '[redacted]';

	/**
	 * Sensitive key segments (keys are split on '_', '-' and '.').
	 */
	const SENSITIVE = array( 'token', 'key', 'apikey', 'secret', 'password', 'passwd', 'pass', 'pwd', 'auth', 'authorization', 'sig', 'signature', 'session', 'sessionid', 'cookie', 'credential', 'credentials', 'private', 'jwt' );

	/**
	 * Is a key name sensitive? Matches whole segments so that e.g. "author"
	 * or "keyword" are not treated as secrets but "api_key" and "auth" are.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_sensitive_key( $key ) {
		$key = strtolower( (string) $key );
		if ( in_array( $key, array( 'accesstoken', 'access_token', 'refresh_token', 'client_secret' ), true ) ) {
			return true;
		}
		foreach ( preg_split( '/[_.\-\[\]]+/', $key, -1, PREG_SPLIT_NO_EMPTY ) as $segment ) {
			if ( in_array( $segment, self::SENSITIVE, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redact a URL: userinfo and sensitive query values.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url( $url ) {
		$url = (string) $url;
		$url = preg_replace( '#^([a-z][a-z0-9+.-]*://)[^/@\s]*@#i', '$1' . self::MASK . '@', $url );
		return preg_replace_callback(
			'/([?&;])([^=&#;\s]+)=([^&#;\s]*)/',
			static function ( $m ) {
				return $m[1] . $m[2] . '=' . ( self::is_sensitive_key( rawurldecode( $m[2] ) ) ? self::MASK : $m[3] );
			},
			$url
		);
	}

	/**
	 * Redact free text: embedded URLs, bearer/basic credentials and key=value secrets.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function text( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ' . self::MASK, $text );
		$text = preg_replace_callback(
			'#https?://[^\s"\'<>]+#i',
			static function ( $m ) {
				return self::url( $m[0] );
			},
			$text
		);
		return preg_replace_callback(
			'/\b([A-Za-z_-]*(?:password|passwd|secret|token|api[_-]?key)[A-Za-z_-]*)\s*([=:])\s*("[^"]*"|\'[^\']*\'|\S+)/i',
			static function ( $m ) {
				return $m[1] . $m[2] . self::MASK;
			},
			$text
		);
	}

	/**
	 * Recursively redact an array/scalar context.
	 *
	 * @param mixed $data  Data.
	 * @param int   $depth Recursion guard.
	 * @return mixed
	 */
	public static function data( $data, $depth = 0 ) {
		if ( $depth > 6 ) {
			return '[depth]';
		}
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				if ( is_string( $k ) && self::is_sensitive_key( $k ) ) {
					$out[ $k ] = self::MASK;
				} else {
					$out[ $k ] = self::data( $v, $depth + 1 );
				}
			}
			return $out;
		}
		if ( is_string( $data ) ) {
			return self::text( $data );
		}
		if ( is_scalar( $data ) || null === $data ) {
			return $data;
		}
		return '[' . gettype( $data ) . ']';
	}
}

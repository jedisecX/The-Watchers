<?php
/**
 * Symmetric encryption for stored feed credentials.
 *
 * @package RSSMediaHub
 */

namespace RMH\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts small secrets with libsodium secretbox using a key derived from the
 * site's auth salt, so credentials are never stored in plaintext in the DB.
 */
final class Secrets {

	/**
	 * Derive the key.
	 *
	 * @return string 32-byte key.
	 */
	private static function key() {
		$material = defined( 'RMH_SECRET_KEY' ) ? (string) RMH_SECRET_KEY : wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( 'rmh-credentials|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt.
	 *
	 * @param string $plain Plaintext.
	 * @return string base64 payload, '' for empty input.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt.
	 *
	 * @param string $payload Payload from encrypt().
	 * @return string Plaintext or '' if invalid.
	 */
	public static function decrypt( $payload ) {
		$payload = (string) $payload;
		if ( 0 !== strpos( $payload, 'v1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $payload, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);
		return false === $plain ? '' : $plain;
	}
}

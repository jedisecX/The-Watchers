<?php
/**
 * Per-feed configuration with global inheritance.
 *
 * @package RSSMediaHub
 */

namespace RMH\Db;

use RMH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes stored per-feed overrides and resolves effective values.
 */
final class FeedConfig {

	/**
	 * Per-feed override schema: key => [type, default, choices].
	 *
	 * @return array
	 */
	public static function schema() {
		return array(
			'import_mode'          => array( 'enum', 'inherit', array_merge( array( 'inherit' ), Settings::IMPORT_MODES ) ),
			'content_mode'         => array( 'enum', 'inherit', array_merge( array( 'inherit' ), Settings::CONTENT_MODES ) ),
			'media_handling'       => array( 'enum', 'inherit', array_merge( array( 'inherit' ), Settings::HANDLING_MODES ) ),
			'content_policy'       => array( 'enum', 'inherit', array( 'inherit', 'excerpt', 'full' ) ),
			'update_policy'        => array( 'enum', 'inherit', array( 'inherit', 'none', 'metadata', 'full' ) ),
			'content_rating'       => array( 'enum', 'inherit', array_merge( array( 'inherit' ), Settings::RATINGS ) ),
			'fetch_article'        => array( 'enum', 'inherit', array( 'inherit', 'yes', 'no' ) ),
			'allowed_media_types'  => array( 'set', array(), Settings::MEDIA_TYPES ),
			'max_items'            => array( 'int', 0, array( 0, 200 ) ),
			'attribution_template' => array( 'html', '' ),
			'categories'           => array( 'ids', array() ),
			'tags'                 => array( 'names', array() ),
			'allow_embeds'         => array( 'bool', true ),
			'extract_html'         => array( 'bool', true ),
			'rights_confirmed'     => array( 'bool', false ),
			'rights_note'          => array( 'textarea', '' ),
			'auth_user'            => array( 'text', '' ),
			'auth_pass'            => array( 'secret', '' ),
		);
	}

	/**
	 * Sanitize stored overrides. Unknown keys are dropped.
	 *
	 * @param array $input    Raw values.
	 * @param array $existing Existing stored values (to keep secrets when the field is left blank).
	 * @return array
	 */
	public static function sanitize( array $input, array $existing = array() ) {
		$out = array();
		foreach ( self::schema() as $key => $def ) {
			$type    = $def[0];
			$default = $def[1];
			$raw     = array_key_exists( $key, $input ) ? $input[ $key ] : ( array_key_exists( $key, $existing ) ? $existing[ $key ] : $default );
			switch ( $type ) {
				case 'ids':
					$raw         = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
					$out[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
					break;
				case 'names':
					$raw         = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
					$names       = array_map(
						static function ( $n ) {
							return mb_substr( sanitize_text_field( (string) $n ), 0, 60 );
						},
						$raw
					);
					$out[ $key ] = array_values( array_unique( array_filter( $names, 'strlen' ) ) );
					break;
				case 'secret':
					if ( array_key_exists( $key, $input ) && '' !== (string) $input[ $key ] ) {
						$out[ $key ] = \RMH\Security\Secrets::encrypt( (string) $input[ $key ] );
					} elseif ( ! empty( $input[ $key . '_clear' ] ) ) {
						$out[ $key ] = '';
					} else {
						$out[ $key ] = isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';
					}
					break;
				default:
					$out[ $key ] = Settings::sanitize_value( $type, $raw, $default, isset( $def[2] ) ? $def[2] : null );
			}
		}
		return $out;
	}

	/**
	 * Load stored (already sanitized) overrides, filling missing keys with defaults.
	 *
	 * @param array $stored Decoded JSON.
	 * @return array
	 */
	public static function from_stored( array $stored ) {
		$out = array();
		foreach ( self::schema() as $key => $def ) {
			$out[ $key ] = array_key_exists( $key, $stored ) ? $stored[ $key ] : $def[1];
		}
		return $out;
	}

	/**
	 * Resolve effective configuration for a feed row.
	 *
	 * @param object   $feed     Feed row (with decoded ->config array).
	 * @param Settings $settings Global settings.
	 * @return array
	 */
	public static function effective( $feed, Settings $settings ) {
		$c    = isset( $feed->config ) && is_array( $feed->config ) ? $feed->config : self::sanitize( array() );
		$pick = static function ( $key, $global_key = null ) use ( $c, $settings ) {
			$v = isset( $c[ $key ] ) ? $c[ $key ] : 'inherit';
			return 'inherit' === $v ? $settings->get( $global_key ? $global_key : $key ) : $v;
		};

		$global_types = (array) $settings->get( 'allowed_media_types' );
		$feed_types   = ! empty( $c['allowed_media_types'] ) ? (array) $c['allowed_media_types'] : $global_types;

		$fetch = isset( $c['fetch_article'] ) ? $c['fetch_article'] : 'inherit';

		return array(
			'import_mode'          => $pick( 'import_mode' ),
			'content_mode'         => $pick( 'content_mode' ),
			'media_handling'       => $pick( 'media_handling' ),
			'content_policy'       => $pick( 'content_policy' ),
			'update_policy'        => $pick( 'update_policy' ),
			'content_rating'       => $pick( 'content_rating', 'default_content_rating' ),
			'fetch_article'        => 'inherit' === $fetch ? (bool) $settings->get( 'fetch_article_html' ) : ( 'yes' === $fetch ),
			// A feed can only narrow the global allowlist, never widen it.
			'allowed_media_types'  => array_values( array_intersect( $feed_types, $global_types ) ),
			'max_items'            => ! empty( $c['max_items'] ) ? (int) $c['max_items'] : (int) $settings->get( 'max_items_per_run' ),
			'poll_interval'        => ! empty( $feed->poll_interval ) ? (int) $feed->poll_interval : (int) $settings->get( 'default_poll_interval' ),
			'attribution_template' => ! empty( $c['attribution_template'] ) ? $c['attribution_template'] : (string) $settings->get( 'attribution_template' ),
			'categories'           => isset( $c['categories'] ) ? (array) $c['categories'] : array(),
			'tags'                 => isset( $c['tags'] ) ? (array) $c['tags'] : array(),
			'allow_embeds'         => ! isset( $c['allow_embeds'] ) || (bool) $c['allow_embeds'],
			'extract_html'         => ! isset( $c['extract_html'] ) || (bool) $c['extract_html'],
			'rights_confirmed'     => ! empty( $c['rights_confirmed'] ),
			'auth_user'            => isset( $c['auth_user'] ) ? (string) $c['auth_user'] : '',
			'auth_pass'            => isset( $c['auth_pass'] ) ? \RMH\Security\Secrets::decrypt( (string) $c['auth_pass'] ) : '',
		);
	}
}

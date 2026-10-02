<?php
/**
 * Source restrictions: blocked domains, URLs, GUIDs and title keywords.
 *
 * @package RSSMediaHub
 */

namespace RMH\Db;

use RMH\Http\UrlNormalizer;
use RMH\Installer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

/**
 * Blocklist checked before import, before rendering embeds and before any fetch of media.
 */
class Blocklist {

	const TYPES = array( 'domain', 'url', 'guid', 'keyword' );

	/**
	 * Request-level cache of entries by type.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Table.
	 *
	 * @return string
	 */
	private function table() {
		return Installer::table( 'blocklist' );
	}

	/**
	 * Normalize a value for its type.
	 *
	 * @param string $type  Type.
	 * @param string $value Raw value.
	 * @return string '' if invalid.
	 */
	public static function normalize( $type, $value ) {
		$value = trim( (string) $value );
		switch ( $type ) {
			case 'domain':
				if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
					$value = UrlNormalizer::host( $value );
				}
				$value = strtolower( rtrim( $value, '.' ) );
				$value = preg_replace( '/^\*\./', '', $value );
				return preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value ) ? $value : '';
			case 'url':
				return UrlNormalizer::normalize( $value );
			case 'guid':
				return mb_substr( $value, 0, 2000 );
			case 'keyword':
				return mb_strtolower( mb_substr( sanitize_text_field( $value ), 0, 100 ) );
		}
		return '';
	}

	/**
	 * Add an entry.
	 *
	 * @param string $type   Type.
	 * @param string $value  Value.
	 * @param string $reason Reason.
	 * @return int|\WP_Error
	 */
	public function add( $type, $value, $reason = '' ) {
		global $wpdb;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'rmh_block_type', __( 'Unknown restriction type.', 'rss-media-hub' ) );
		}
		$norm = self::normalize( $type, $value );
		if ( '' === $norm ) {
			return new \WP_Error( 'rmh_block_value', __( 'The value is not valid for this restriction type.', 'rss-media-hub' ) );
		}
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert(
			$this->table(),
			array(
				'type'       => $type,
				'value'      => $norm,
				'value_hash' => sha1( $norm ),
				'reason'     => mb_substr( sanitize_textarea_field( (string) $reason ), 0, 1000 ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'created_by' => get_current_user_id(),
			)
		);
		$wpdb->suppress_errors( $suppress );
		$this->cache = null;
		if ( ! $ok ) {
			return new \WP_Error( 'rmh_block_exists', __( 'This restriction already exists.', 'rss-media-hub' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove an entry.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function remove( $id ) {
		global $wpdb;
		$this->cache = null;
		return (bool) $wpdb->delete( $this->table(), array( 'id' => (int) $id ) );
	}

	/**
	 * All entries (bounded).
	 *
	 * @param string $type Optional type filter.
	 * @return object[]
	 */
	public function all( $type = '' ) {
		global $wpdb;
		if ( $type ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE type = %s ORDER BY id DESC LIMIT 5000", $type ) );
		}
		return (array) $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT 5000" );
	}

	/**
	 * Load entries grouped by type.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function load() {
		if ( null === $this->cache ) {
			$this->cache = array_fill_keys( self::TYPES, array() );
			foreach ( $this->all() as $row ) {
				$this->cache[ $row->type ][ $row->value ] = true;
			}
		}
		return $this->cache;
	}

	/**
	 * Is a URL blocked (exact URL or its domain / any parent domain)?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_url_blocked( $url ) {
		$c    = $this->load();
		$norm = UrlNormalizer::normalize( $url );
		if ( '' !== $norm && isset( $c['url'][ $norm ] ) ) {
			return true;
		}
		return $this->is_domain_blocked( UrlNormalizer::host( $url ) );
	}

	/**
	 * Is a host (or any parent domain) blocked?
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public function is_domain_blocked( $host ) {
		$c    = $this->load();
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return false;
		}
		// Check the host and every parent domain: a.b.example, b.example, example.
		$parts = explode( '.', $host );
		while ( $parts ) {
			if ( isset( $c['domain'][ implode( '.', $parts ) ] ) ) {
				return true;
			}
			array_shift( $parts );
		}
		return false;
	}

	/**
	 * Check an item against GUID, URL/domain and keyword rules.
	 *
	 * @param array $item Normalized item.
	 * @return string Matched rule description, '' if allowed.
	 */
	public function match_item( array $item ) {
		$c = $this->load();
		if ( ! empty( $item['guid'] ) && isset( $c['guid'][ (string) $item['guid'] ] ) ) {
			return 'guid';
		}
		if ( ! empty( $item['link'] ) && $this->is_url_blocked( $item['link'] ) ) {
			return 'url/domain';
		}
		$title = mb_strtolower( (string) ( isset( $item['title'] ) ? $item['title'] : '' ) );
		foreach ( array_keys( $c['keyword'] ) as $kw ) {
			if ( '' !== $kw && false !== mb_strpos( $title, $kw ) ) {
				return 'keyword';
			}
		}
		return '';
	}
}

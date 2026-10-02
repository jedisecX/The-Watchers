<?php
/**
 * Global settings model (single option, schema-driven sanitization).
 *
 * @package RSSMediaHub
 */

namespace RMH;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the `rmh_settings` option.
 */
class Settings {

	const OPTION = 'rmh_settings';

	const MEDIA_TYPES    = array( 'video', 'audio', 'image', 'gallery', 'embed', 'text' );
	const IMPORT_MODES   = array( 'auto', 'pending', 'manual' );
	const CONTENT_MODES  = array( 'article_media', 'media_only' );
	const HANDLING_MODES = array( 'remote_embed', 'remote_link', 'local_import', 'hybrid' );
	const RATINGS        = array( 'unknown', 'general', 'adult' );

	/**
	 * Cached values.
	 *
	 * @var array|null
	 */
	private $values = null;

	/**
	 * Field schema: key => [type, default, constraint].
	 * Types: int (constraint = [min,max]), bool, enum (constraint = choices),
	 * set (constraint = choices; stored as array), text, textarea, html, email, csv_upper.
	 *
	 * @return array<string,array>
	 */
	public static function schema() {
		return array(
			// Publishing.
			'import_mode'             => array( 'enum', 'pending', self::IMPORT_MODES ),
			'content_mode'            => array( 'enum', 'article_media', self::CONTENT_MODES ),
			'content_policy'          => array( 'enum', 'excerpt', array( 'excerpt', 'full' ) ),
			'pending_status'          => array( 'enum', 'pending', array( 'pending', 'draft' ) ),
			'excerpt_words'           => array( 'int', 55, array( 10, 500 ) ),
			'use_source_date'         => array( 'bool', true ),
			'source_categories'       => array( 'enum', 'tags', array( 'tags', 'categories', 'ignore' ) ),
			'update_policy'           => array( 'enum', 'metadata', array( 'none', 'metadata', 'full' ) ),
			'cross_feed_dedupe'       => array( 'bool', true ),
			'post_author'             => array( 'int', 0, array( 0, PHP_INT_MAX ) ),
			// Polling.
			'default_poll_interval'   => array( 'int', 3600, array( 300, 604800 ) ),
			'max_items_per_run'       => array( 'int', 25, array( 1, 200 ) ),
			'feeds_per_tick'          => array( 'int', 10, array( 1, 200 ) ),
			'max_runtime'             => array( 'int', 40, array( 5, 600 ) ),
			'max_pages'               => array( 'int', 1, array( 1, 10 ) ),
			// Network.
			'request_timeout'         => array( 'int', 15, array( 2, 120 ) ),
			'max_feed_mb'             => array( 'int', 5, array( 1, 50 ) ),
			'retry_limit'             => array( 'int', 2, array( 0, 5 ) ),
			'max_backoff_hours'       => array( 'int', 24, array( 1, 168 ) ),
			'max_redirects'           => array( 'int', 5, array( 0, 10 ) ),
			'allowed_ports'           => array( 'text', '80,443' ),
			'user_agent'              => array( 'text', '' ),
			// Media.
			'allowed_media_types'     => array( 'set', self::MEDIA_TYPES, self::MEDIA_TYPES ),
			'media_handling'          => array( 'enum', 'remote_embed', self::HANDLING_MODES ),
			'fetch_article_html'      => array( 'bool', false ),
			'max_article_fetches'     => array( 'int', 5, array( 0, 50 ) ),
			'max_download_mb'         => array( 'int', 100, array( 1, 4096 ) ),
			'storage_quota_mb'        => array( 'int', 2048, array( 10, 1048576 ) ),
			'validate_media'          => array( 'bool', true ),
			'revalidate_days'         => array( 'int', 7, array( 1, 365 ) ),
			'revalidate_batch'        => array( 'int', 20, array( 1, 500 ) ),
			// Embeds.
			'embed_providers'         => array( 'set', array( 'youtube', 'vimeo', 'dailymotion' ), array( 'youtube', 'vimeo', 'dailymotion' ) ),
			'custom_providers'        => array( 'textarea', '' ),
			'send_frame_csp'          => array( 'bool', true ),
			// Reader and templates.
			'attribution_template'    => array( 'html', 'Source: <a href="{source_url}" rel="nofollow noopener" target="_blank">{feed_name}</a>{author_suffix}' ),
			'per_page'                => array( 'int', 24, array( 4, 100 ) ),
			'default_view'            => array( 'enum', 'grid', array( 'grid', 'list' ) ),
			'show_on_home'            => array( 'bool', false ),
			'show_related'            => array( 'bool', true ),
			'related_count'           => array( 'int', 6, array( 1, 24 ) ),
			'show_share'              => array( 'bool', true ),
			// Access and compliance.
			'adult_site'              => array( 'bool', false ),
			'rta_label'               => array( 'bool', false ),
			'default_content_rating'  => array( 'enum', 'unknown', self::RATINGS ),
			'content_warning'         => array( 'textarea', '' ),
			'age_gate_enabled'        => array( 'bool', false ),
			'age_gate_mode'           => array( 'enum', 'server', array( 'server', 'overlay' ) ),
			'age_gate_scope'          => array( 'enum', 'adult_only', array( 'adult_only', 'all' ) ),
			'age_verifier'            => array( 'text', 'self_declaration' ),
			'minimum_age'             => array( 'int', 18, array( 13, 25 ) ),
			'age_gate_ttl_days'       => array( 'int', 30, array( 1, 365 ) ),
			'age_gate_message'        => array( 'textarea', '' ),
			'blocked_countries'       => array( 'csv_upper', '' ),
			'country_header'          => array( 'text', '' ),
			'reports_enabled'         => array( 'bool', true ),
			'moderation_email'        => array( 'email', '' ),
			'analytics_enabled'       => array( 'bool', false ),
			// Retention and diagnostics.
			'log_level'               => array( 'enum', 'info', array( 'debug', 'info', 'warning', 'error' ) ),
			'log_retention_days'      => array( 'int', 30, array( 1, 3650 ) ),
			'job_retention_days'      => array( 'int', 90, array( 1, 3650 ) ),
			'rejected_retention_days' => array( 'int', 60, array( 1, 3650 ) ),
			'uninstall_mode'          => array( 'enum', 'keep_all', array( 'keep_all', 'keep_content', 'delete_all' ) ),
		);
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::schema() as $key => $def ) {
			$out[ $key ] = $def[1];
		}
		return $out;
	}

	/**
	 * All values (stored merged over defaults).
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION, array() );
			$this->values = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->values;
	}

	/**
	 * Get one value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Persist a partial update (sanitized).
	 *
	 * @param array $changes Key/value pairs.
	 * @return array Sanitized full settings.
	 */
	public function update( array $changes ) {
		$clean = self::sanitize( array_merge( $this->all(), $changes ) );
		update_option( self::OPTION, $clean );
		$this->values = null;
		return $clean;
	}

	/**
	 * Clear cache (after external option writes).
	 */
	public function flush() {
		$this->values = null;
	}

	/**
	 * Sanitize a full settings array against the schema.
	 * Unknown keys are dropped; invalid values fall back to defaults.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( self::schema() as $key => $def ) {
			list( $type, $default ) = $def;
			$constraint             = isset( $def[2] ) ? $def[2] : null;
			$raw                    = array_key_exists( $key, $input ) ? $input[ $key ] : $default;
			$out[ $key ]            = self::sanitize_value( $type, $raw, $default, $constraint );
		}
		return $out;
	}

	/**
	 * Sanitize one field.
	 *
	 * @param string $type       Field type.
	 * @param mixed  $raw        Raw.
	 * @param mixed  $fallback   Default.
	 * @param mixed  $constraint Constraint.
	 * @return mixed
	 */
	public static function sanitize_value( $type, $raw, $fallback, $constraint ) {
		switch ( $type ) {
			case 'int':
				if ( ! is_numeric( $raw ) ) {
					return $fallback;
				}
				return max( (int) $constraint[0], min( (int) $constraint[1], (int) $raw ) );
			case 'bool':
				return in_array( $raw, array( true, 1, '1', 'yes', 'on', 'true' ), true );
			case 'enum':
				return in_array( (string) $raw, $constraint, true ) ? (string) $raw : $fallback;
			case 'set':
				if ( is_string( $raw ) ) {
					$raw = explode( ',', $raw );
				}
				if ( ! is_array( $raw ) ) {
					return $fallback;
				}
				return array_values( array_intersect( $constraint, array_map( 'strval', $raw ) ) );
			case 'text':
				return sanitize_text_field( (string) $raw );
			case 'textarea':
				return sanitize_textarea_field( (string) $raw );
			case 'html':
				return wp_kses( (string) $raw, Security\Sanitizer::attribution_allowed_html() );
			case 'email':
				$email = sanitize_email( (string) $raw );
				return is_email( $email ) ? $email : '';
			case 'csv_upper':
				$parts = preg_split( '/[\s,]+/', strtoupper( (string) $raw ), -1, PREG_SPLIT_NO_EMPTY );
				$parts = array_filter(
					$parts,
					static function ( $p ) {
						return (bool) preg_match( '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $p );
					}
				);
				return implode( ',', array_unique( $parts ) );
		}
		return $fallback;
	}

	/**
	 * Allowed outbound ports.
	 *
	 * @return int[]
	 */
	public function allowed_ports() {
		$ports = array_map( 'intval', preg_split( '/[\s,]+/', (string) $this->get( 'allowed_ports' ), -1, PREG_SPLIT_NO_EMPTY ) );
		$ports = array_filter(
			$ports,
			static function ( $p ) {
				return $p > 0 && $p < 65536;
			}
		);
		return $ports ? array_values( $ports ) : array( 80, 443 );
	}

	/**
	 * User agent for outbound requests.
	 *
	 * @return string
	 */
	public function user_agent() {
		$ua = trim( (string) $this->get( 'user_agent' ) );
		return '' !== $ua ? $ua : 'RSSMediaHub/' . RMH_VERSION . ' (+' . home_url( '/' ) . ')';
	}
}

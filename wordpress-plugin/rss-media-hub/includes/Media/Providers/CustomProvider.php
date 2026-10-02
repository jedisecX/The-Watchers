<?php
/**
 * Operator-defined embed provider (exact host + path prefix).
 *
 * @package RSSMediaHub
 */

namespace RMH\Media\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts https URLs on one exact host whose path starts with a configured
 * prefix (e.g. a partner site's official "/embed/" player). The URL itself is
 * used as the embed URL after normalization; no regex from settings is evaluated.
 *
 * Settings line format:  Label | host.example.com | /embed/
 */
class CustomProvider extends AbstractProvider {

	/**
	 * Id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Exact host (lowercase).
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Path prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $label  Label.
	 * @param string $host   Host.
	 * @param string $prefix Path prefix.
	 */
	public function __construct( $label, $host, $prefix ) {
		$this->label  = sanitize_text_field( $label );
		$this->host   = strtolower( trim( $host ) );
		$this->prefix = '/' . ltrim( trim( $prefix ), '/' );
		$this->id     = 'custom-' . substr( sha1( $this->host . $this->prefix ), 0, 8 );
	}

	/**
	 * Parse the settings textarea into providers (invalid lines are skipped).
	 *
	 * @param string $text Settings text.
	 * @return CustomProvider[]
	 */
	public static function from_settings( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( 3 !== count( $parts ) ) {
				continue;
			}
			list( $label, $host, $prefix ) = $parts;
			$host                          = strtolower( $host );
			if ( '' === $label || ! preg_match( '/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host ) ) {
				continue;
			}
			if ( ! preg_match( '#^/[A-Za-z0-9._~/-]*$#', '/' . ltrim( $prefix, '/' ) ) || strlen( trim( $prefix, '/' ) ) < 1 ) {
				continue; // Require a non-trivial path prefix so whole sites can't be framed.
			}
			$out[] = new self( $label, $host, $prefix );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return $this->label;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	public function match( $url ) {
		$p = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $p ) || empty( $p['host'] ) || empty( $p['scheme'] ) || 'https' !== strtolower( $p['scheme'] ) ) {
			return null;
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || strtolower( $p['host'] ) !== $this->host ) {
			return null;
		}
		$path = isset( $p['path'] ) ? $p['path'] : '/';
		if ( 0 !== strpos( $path, $this->prefix ) || false !== strpos( $path, '..' ) || ! preg_match( '#^[A-Za-z0-9._~/%-]*$#', $path ) ) {
			return null;
		}
		$query = '';
		if ( ! empty( $p['query'] ) && preg_match( '/^[A-Za-z0-9._~=&%-]{1,512}$/', $p['query'] ) ) {
			$query = '?' . $p['query'];
		}
		$embed = 'https://' . $this->host . $path . $query;
		return array(
			'embed_url' => $embed,
			'page_url'  => $embed,
			'thumbnail' => '',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function frame_origins() {
		return array( 'https://' . $this->host );
	}

	/**
	 * Stricter sandbox for operator-defined providers: no popups.
	 *
	 * @return string
	 */
	public function sandbox() {
		return 'allow-scripts allow-same-origin allow-presentation';
	}
}

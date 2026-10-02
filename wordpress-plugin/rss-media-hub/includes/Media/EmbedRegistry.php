<?php
/**
 * Registry of enabled embed providers.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media;

use RMH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the provider allowlist and matches URLs against it.
 */
class EmbedRegistry {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Cached providers.
	 *
	 * @var EmbedProviderInterface[]|null
	 */
	private $providers = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Built-in providers keyed by id.
	 *
	 * @return EmbedProviderInterface[]
	 */
	public static function builtin() {
		return array(
			'youtube'     => new Providers\YouTube(),
			'vimeo'       => new Providers\Vimeo(),
			'dailymotion' => new Providers\Dailymotion(),
		);
	}

	/**
	 * Enabled providers (built-ins enabled in settings + custom + filtered).
	 *
	 * @return EmbedProviderInterface[]
	 */
	public function providers() {
		if ( null === $this->providers ) {
			$enabled = (array) $this->settings->get( 'embed_providers' );
			$list    = array();
			foreach ( self::builtin() as $id => $provider ) {
				if ( in_array( $id, $enabled, true ) ) {
					$list[ $id ] = $provider;
				}
			}
			foreach ( Providers\CustomProvider::from_settings( (string) $this->settings->get( 'custom_providers' ) ) as $provider ) {
				$list[ $provider->id() ] = $provider;
			}
			/**
			 * Filter the enabled embed providers.
			 *
			 * @param EmbedProviderInterface[] $list Providers keyed by id.
			 */
			$list            = apply_filters( 'rmh_embed_providers', $list );
			$this->providers = array_filter(
				(array) $list,
				static function ( $p ) {
					return $p instanceof EmbedProviderInterface;
				}
			);
		}
		return $this->providers;
	}

	/**
	 * Reset cache (after settings change).
	 */
	public function flush() {
		$this->providers = null;
	}

	/**
	 * Match a URL against enabled providers.
	 *
	 * @param string $url URL.
	 * @return array|null {provider, embed_url, page_url, thumbnail}
	 */
	public function match( $url ) {
		foreach ( $this->providers() as $provider ) {
			$m = $provider->match( $url );
			if ( $m && ! empty( $m['embed_url'] ) && 0 === strpos( $m['embed_url'], 'https://' ) ) {
				$m['provider'] = $provider->id();
				return $m;
			}
		}
		return null;
	}

	/**
	 * Get a provider by id.
	 *
	 * @param string $id Id.
	 * @return EmbedProviderInterface|null
	 */
	public function get( $id ) {
		$all = $this->providers();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Re-validate a stored embed URL at render time: it must still be produced
	 * by an enabled provider (provider disabled => no iframe).
	 *
	 * @param string $provider_id Provider id.
	 * @param string $embed_url   Stored embed URL.
	 * @return array|null Fresh match.
	 */
	public function revalidate( $provider_id, $embed_url ) {
		$provider = $this->get( $provider_id );
		if ( ! $provider ) {
			return null;
		}
		$m = $provider->match( $embed_url );
		if ( ! $m ) {
			return null;
		}
		$m['provider'] = $provider->id();
		$m['sandbox']  = $provider->sandbox();
		return $m;
	}

	/**
	 * All frame origins for CSP.
	 *
	 * @return string[]
	 */
	public function frame_origins() {
		$out = array();
		foreach ( $this->providers() as $p ) {
			foreach ( $p->frame_origins() as $o ) {
				if ( preg_match( '#^https://[a-z0-9.-]+$#i', $o ) ) {
					$out[] = strtolower( $o );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}
}

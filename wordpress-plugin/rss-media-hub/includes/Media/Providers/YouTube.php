<?php
/**
 * YouTube provider (privacy-enhanced youtube-nocookie.com embeds).
 *
 * @package RSSMediaHub
 */

namespace RMH\Media\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Recognizes watch, youtu.be, shorts, live and embed URLs.
 */
class YouTube extends AbstractProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'youtube';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'YouTube';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	public function match( $url ) {
		$p = $this->parts( $url );
		if ( ! $p ) {
			return null;
		}
		$id = '';
		if ( 'youtu.be' === $p['host'] ) {
			$id = trim( $p['path'], '/' );
		} elseif ( in_array( $p['host'], array( 'youtube.com', 'youtube-nocookie.com', 'music.youtube.com' ), true ) ) {
			if ( '/watch' === rtrim( $p['path'], '/' ) && isset( $p['query']['v'] ) && is_string( $p['query']['v'] ) ) {
				$id = $p['query']['v'];
			} elseif ( preg_match( '#^/(embed|shorts|live|v)/([^/?]+)#', $p['path'], $m ) ) {
				$id = $m[2];
			}
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) {
			return null;
		}
		return array(
			'embed_url' => 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0',
			'page_url'  => 'https://www.youtube.com/watch?v=' . $id,
			'thumbnail' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function frame_origins() {
		return array( 'https://www.youtube-nocookie.com' );
	}
}

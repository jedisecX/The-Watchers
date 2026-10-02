<?php
/**
 * Vimeo provider.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Recognizes vimeo.com/{id}, vimeo.com/channels/x/{id} and player.vimeo.com/video/{id}.
 */
class Vimeo extends AbstractProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'vimeo';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'Vimeo';
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
		if ( 'player.vimeo.com' === $p['host'] && preg_match( '#^/video/(\d{1,12})/?$#', $p['path'], $m ) ) {
			$id = $m[1];
		} elseif ( 'vimeo.com' === $p['host'] && preg_match( '#^/(?:channels/[\w-]+/|groups/[\w-]+/videos/|video/)?(\d{1,12})/?$#', $p['path'], $m ) ) {
			$id = $m[1];
		}
		if ( '' === $id ) {
			return null;
		}
		$embed = 'https://player.vimeo.com/video/' . $id . '?dnt=1';
		// Unlisted videos carry a privacy hash (?h=...).
		if ( isset( $p['query']['h'] ) && is_string( $p['query']['h'] ) && preg_match( '/^[a-f0-9]{6,20}$/', $p['query']['h'] ) ) {
			$embed .= '&h=' . $p['query']['h'];
		}
		return array(
			'embed_url' => $embed,
			'page_url'  => 'https://vimeo.com/' . $id,
			'thumbnail' => '',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function frame_origins() {
		return array( 'https://player.vimeo.com' );
	}
}

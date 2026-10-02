<?php
/**
 * Dailymotion provider.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Recognizes dailymotion.com/video/{id}, dai.ly/{id} and embed URLs.
 */
class Dailymotion extends AbstractProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'dailymotion';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'Dailymotion';
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
		if ( 'dai.ly' === $p['host'] ) {
			$id = trim( $p['path'], '/' );
		} elseif ( 'dailymotion.com' === $p['host'] && preg_match( '#^/(?:embed/)?video/([A-Za-z0-9]+)#', $p['path'], $m ) ) {
			$id = $m[1];
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{5,12}$/', $id ) ) {
			return null;
		}
		return array(
			'embed_url' => 'https://www.dailymotion.com/embed/video/' . $id,
			'page_url'  => 'https://www.dailymotion.com/video/' . $id,
			'thumbnail' => 'https://www.dailymotion.com/thumbnail/video/' . $id,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function frame_origins() {
		return array( 'https://www.dailymotion.com' );
	}
}

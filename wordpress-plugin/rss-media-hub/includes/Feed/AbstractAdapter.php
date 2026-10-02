<?php
/**
 * Shared normalization for feed adapters.
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

use RMH\Http\UrlNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Base adapter with item normalization helpers.
 */
abstract class AbstractAdapter implements FeedAdapterInterface {

	/**
	 * Build a normalized item with defaults and absolute URLs.
	 *
	 * @param array  $data Partial item.
	 * @param string $base Base URL.
	 * @return array
	 */
	protected function item( array $data, $base ) {
		$item = array_merge(
			array(
				'guid'              => '',
				'title'             => '',
				'link'              => '',
				'summary'           => '',
				'content'           => '',
				'authors'           => array(),
				'published'         => null,
				'updated'           => null,
				'date_raw'          => '',
				'categories'        => array(),
				'enclosures'        => array(),
				'media'             => array(),
				'thumbnails'        => array(),
				'media_description' => '',
				'media_credit'      => '',
			),
			$data
		);

		$item['guid']    = mb_substr( trim( (string) $item['guid'] ), 0, 2000 );
		$item['title']   = mb_substr( Dom::plain( $item['title'] ), 0, 500 );
		$item['link']    = $this->abs( $item['link'], $base );
		$item['authors'] = array_values( array_unique( array_filter( array_map( array( Dom::class, 'plain' ), (array) $item['authors'] ), 'strlen' ) ) );

		$cats = array();
		foreach ( (array) $item['categories'] as $cat ) {
			$cat = mb_substr( Dom::plain( $cat ), 0, 80 );
			if ( '' !== $cat ) {
				$cats[ mb_strtolower( $cat ) ] = $cat;
			}
		}
		$item['categories'] = array_slice( array_values( $cats ), 0, 25 );

		foreach ( $item['enclosures'] as $k => $enc ) {
			$item['enclosures'][ $k ]['url'] = $this->abs( $enc['url'], $base );
		}
		foreach ( $item['media'] as $k => $m ) {
			$item['media'][ $k ]['url']       = $this->abs( $m['url'], $base );
			$item['media'][ $k ]['thumbnail'] = $this->abs( $m['thumbnail'], $base );
			$item['media'][ $k ]['player']    = $this->abs( $m['player'], $base );
		}
		$item['thumbnails'] = array_values( array_filter( array_map( array( $this, 'abs_cb' ), $item['thumbnails'], array_fill( 0, count( $item['thumbnails'] ), $base ) ) ) );

		// GUID permalink fallback.
		if ( '' === $item['link'] && '' !== $item['guid'] && preg_match( '#^https?://#i', $item['guid'] ) ) {
			$item['link'] = $item['guid'];
		}
		if ( '' === $item['title'] ) {
			$fallback      = Dom::plain( '' !== $item['summary'] ? $item['summary'] : $item['content'] );
			$item['title'] = '' !== $fallback ? wp_trim_words( $fallback, 12, '…' ) : __( '(untitled)', 'rss-media-hub' );
		}
		return $item;
	}

	/**
	 * Absolutize helper for array_map.
	 *
	 * @param string $url  URL.
	 * @param string $base Base.
	 * @return string
	 */
	public function abs_cb( $url, $base ) {
		return $this->abs( $url, $base );
	}

	/**
	 * Absolute http(s) URL or ''.
	 *
	 * @param string $url  URL.
	 * @param string $base Base.
	 * @return string
	 */
	protected function abs( $url, $base ) {
		$abs = UrlNormalizer::absolutize( (string) $url, (string) $base );
		if ( '' === $abs || strlen( $abs ) > 2048 ) {
			return '';
		}
		return esc_url_raw( $abs, array( 'http', 'https' ) );
	}

	/**
	 * Parse dates into item fields.
	 *
	 * @param string $published Published raw.
	 * @param string $updated   Updated raw.
	 * @return array
	 */
	protected function dates( $published, $updated ) {
		$p = DateParser::parse( $published );
		$u = DateParser::parse( $updated );
		return array(
			'published' => null !== $p ? $p : $u,
			'updated'   => $u,
			'date_raw'  => '' !== $published ? $published : $updated,
		);
	}
}

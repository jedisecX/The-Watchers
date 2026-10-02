<?php
/**
 * Media candidate factory.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Media candidates are plain arrays (JSON-serializable) with a fixed shape.
 *
 * type: video|audio|image|embed|link
 * method (extraction): feed_media | enclosure | media_rss | article_html | provider_embed | fallback_link
 * priority: 1 (explicit feed metadata) ... 6 (fallback link)
 */
final class MediaCandidate {

	const PRIORITY = array(
		'feed_media'     => 1,
		'enclosure'      => 2,
		'media_rss'      => 3,
		'article_html'   => 4,
		'provider_embed' => 5,
		'fallback_link'  => 6,
	);

	/**
	 * Build a candidate.
	 *
	 * @param string $type   Type.
	 * @param string $url    URL.
	 * @param string $method Extraction method.
	 * @param array  $extra  Other fields.
	 * @return array
	 */
	public static function make( $type, $url, $method, array $extra = array() ) {
		$c = array_merge(
			array(
				'type'        => $type,
				'url'         => (string) $url,
				'mime'        => '',
				'thumbnail'   => '',
				'width'       => 0,
				'height'      => 0,
				'duration'    => 0,
				'filesize'    => 0,
				'provider'    => '',
				'embed_url'   => '',
				'method'      => $method,
				'priority'    => isset( self::PRIORITY[ $method ] ) ? self::PRIORITY[ $method ] : 9,
				'title'       => '',
				'description' => '',
				'credit'      => '',
				'playable'    => false,
			),
			$extra
		);
		foreach ( array( 'width', 'height', 'duration', 'filesize', 'priority' ) as $k ) {
			$c[ $k ] = max( 0, (int) $c[ $k ] );
		}
		$c['title']       = mb_substr( \RMH\Feed\Dom::plain( $c['title'] ), 0, 300 );
		$c['description'] = mb_substr( \RMH\Feed\Dom::plain( $c['description'] ), 0, 1000 );
		$c['credit']      = mb_substr( \RMH\Feed\Dom::plain( $c['credit'] ), 0, 200 );
		return $c;
	}
}

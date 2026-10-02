<?php
/**
 * Feed parser: XML loading plus adapter dispatch.
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the format and delegates to the matching adapter.
 */
class FeedParser {

	/**
	 * Registered adapters.
	 *
	 * @return FeedAdapterInterface[]
	 */
	public function adapters() {
		$adapters = apply_filters( 'rmh_feed_adapters', array( new Rss2Adapter(), new AtomAdapter(), new Rss1Adapter() ) );
		return array_values(
			array_filter(
				(array) $adapters,
				static function ( $a ) {
					return $a instanceof FeedAdapterInterface;
				}
			)
		);
	}

	/**
	 * Parse a raw feed body.
	 *
	 * @param string $body      Raw body.
	 * @param string $base_url  Feed URL.
	 * @param int    $max_bytes Size limit.
	 * @return array|\WP_Error Normalized document.
	 */
	public function parse( $body, $base_url, $max_bytes = 10485760 ) {
		$loaded = XmlLoader::load( $body, $max_bytes );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$dom = $loaded['dom'];
		foreach ( $this->adapters() as $adapter ) {
			if ( $adapter->supports( $dom ) ) {
				$doc             = $adapter->parse( $dom, $base_url );
				$doc['warnings'] = array_merge( $loaded['warnings'], isset( $doc['warnings'] ) ? (array) $doc['warnings'] : array() );
				$doc['adapter']  = $adapter->id();
				return apply_filters( 'rmh_parsed_feed', $doc, $base_url );
			}
		}
		$root = $dom->documentElement ? $dom->documentElement->localName : '?';
		return new \WP_Error(
			'rmh_unknown_format',
			/* translators: %s: XML root element */
			sprintf( __( 'Unrecognized feed format (root element <%s>).', 'rss-media-hub' ), $root )
		);
	}
}

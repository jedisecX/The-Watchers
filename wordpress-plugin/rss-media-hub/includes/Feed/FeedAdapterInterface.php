<?php
/**
 * Extension point: feed format adapters.
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * A feed adapter turns a parsed XML document into the normalized feed array.
 * Register extra adapters with the `rmh_feed_adapters` filter.
 *
 * Normalized document:
 *  format, title, link, description, next_page, warnings[], items[]
 * Normalized item:
 *  guid, title, link, summary (HTML), content (HTML), authors[], published (int|null),
 *  updated (int|null), date_raw, categories[], enclosures[{url,type,length}],
 *  media[] (see MediaRss), thumbnails[], media_description, media_credit
 */
interface FeedAdapterInterface {

	/**
	 * Adapter id.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Can this adapter parse the document?
	 *
	 * @param \DOMDocument $dom DOM.
	 * @return bool
	 */
	public function supports( \DOMDocument $dom );

	/**
	 * Parse the document.
	 *
	 * @param \DOMDocument $dom      DOM.
	 * @param string       $base_url Feed URL (for resolving relative links).
	 * @return array Normalized document.
	 */
	public function parse( \DOMDocument $dom, $base_url );
}

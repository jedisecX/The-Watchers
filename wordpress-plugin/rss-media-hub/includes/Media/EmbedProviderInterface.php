<?php
/**
 * Extension point: trusted embed providers.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media;

defined( 'ABSPATH' ) || exit;

/**
 * A provider recognizes its page/embed URLs and produces a canonical embed URL
 * that the plugin builds itself. Raw iframe HTML from feeds is never used.
 * Register with the `rmh_embed_providers` filter.
 */
interface EmbedProviderInterface {

	/**
	 * Provider id (lowercase slug).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Recognize a URL.
	 *
	 * @param string $url Any URL (page link, player URL, iframe src).
	 * @return array|null {embed_url, page_url, thumbnail} or null.
	 */
	public function match( $url );

	/**
	 * Origins the embed iframe loads from (for Content-Security-Policy frame-src).
	 *
	 * @return string[]
	 */
	public function frame_origins();

	/**
	 * iframe sandbox tokens.
	 *
	 * @return string
	 */
	public function sandbox();
}

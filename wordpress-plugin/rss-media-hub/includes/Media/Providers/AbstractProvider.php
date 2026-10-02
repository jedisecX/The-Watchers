<?php
/**
 * Base class for embed providers.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media\Providers;

use RMH\Media\EmbedProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Common URL parsing helpers.
 */
abstract class AbstractProvider implements EmbedProviderInterface {

	/**
	 * Default sandbox: scripts and same-origin are needed by every major player;
	 * the iframe is cross-origin so it cannot touch this site. Top navigation is not allowed.
	 *
	 * @return string
	 */
	public function sandbox() {
		return 'allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox';
	}

	/**
	 * Parse an https/http URL into host/path/query (host lowercased, no "www.").
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	protected function parts( $url ) {
		$p = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $p ) || empty( $p['host'] ) || empty( $p['scheme'] ) || ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) ) {
			return null;
		}
		$query = array();
		if ( ! empty( $p['query'] ) ) {
			wp_parse_str( $p['query'], $query );
		}
		return array(
			'host'  => preg_replace( '/^(www\.|m\.)/', '', strtolower( $p['host'] ) ),
			'path'  => isset( $p['path'] ) ? $p['path'] : '/',
			'query' => $query,
		);
	}
}

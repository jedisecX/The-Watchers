<?php
/**
 * HTTP transport seam (real WordPress HTTP API, or a mock in tests).
 *
 * @package RSSMediaHub
 */

namespace RMH\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Performs exactly one HTTP request: no redirect following, no retries.
 */
interface TransportInterface {

	/**
	 * Perform a single request.
	 *
	 * @param string $url       Validated URL.
	 * @param array  $args      method, headers, timeout, max_bytes, filename (stream to file).
	 * @param string $pinned_ip IP the connection must use.
	 * @return array|\WP_Error {status:int, headers:array<string,string>, body:string}
	 */
	public function request( $url, array $args, $pinned_ip );
}

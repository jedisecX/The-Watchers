<?php
/**
 * In-memory HTTP transport: serves fixtures, records requests, never touches the network.
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests;

use RMH\Http\TransportInterface;

/**
 * Routes: URL => response | response[] (served in sequence) | callable($url, $args) | \WP_Error.
 * Response: ['status' => int, 'headers' => [], 'body' => string].
 */
class MockTransport implements TransportInterface {

	/**
	 * Routes.
	 *
	 * @var array
	 */
	public $routes = array();

	/**
	 * Requests made: [url, args, pinned_ip].
	 *
	 * @var array
	 */
	public $requests = array();

	/**
	 * Register a route.
	 *
	 * @param string $url      URL.
	 * @param mixed  $response Response definition.
	 */
	public function on( $url, $response ) {
		$this->routes[ $url ] = $response;
	}

	/**
	 * Count requests to a URL.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	public function count( $url ) {
		return count(
			array_filter(
				$this->requests,
				static function ( $r ) use ( $url ) {
					return $r[0] === $url;
				}
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url       URL.
	 * @param array  $args      Args.
	 * @param string $pinned_ip IP.
	 * @return array|\WP_Error
	 */
	public function request( $url, array $args, $pinned_ip ) {
		$this->requests[] = array( $url, $args, $pinned_ip );
		if ( ! array_key_exists( $url, $this->routes ) ) {
			return new \WP_Error( 'unmocked', 'No mock for ' . $url );
		}
		$def = $this->routes[ $url ];
		if ( is_array( $def ) && isset( $def[0] ) && ( is_array( $def[0] ) || $def[0] instanceof \WP_Error ) ) {
			$next = count( $def ) > 1 ? array_shift( $def ) : $def[0];
			$this->routes[ $url ] = $def;
			$def                  = $next;
		}
		if ( is_callable( $def ) && ! is_array( $def ) ) {
			$def = call_user_func( $def, $url, $args );
		}
		if ( $def instanceof \WP_Error ) {
			return $def;
		}
		$resp = array_merge(
			array(
				'status'  => 200,
				'headers' => array(),
				'body'    => '',
			),
			$def
		);
		$resp['headers'] = array_change_key_case( $resp['headers'], CASE_LOWER );
		if ( 'HEAD' === ( isset( $args['method'] ) ? $args['method'] : 'GET' ) ) {
			$resp['body'] = '';
		}
		if ( ! empty( $args['max_bytes'] ) && strlen( $resp['body'] ) > $args['max_bytes'] + 1 ) {
			$resp['body'] = substr( $resp['body'], 0, $args['max_bytes'] + 1 ); // Mimic limit_response_size.
		}
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $resp['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$resp['body'] = '';
		}
		return $resp;
	}
}

<?php
/**
 * Transport backed by the WordPress HTTP API with connection pinning.
 *
 * @package RSSMediaHub
 */

namespace RMH\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one request through wp_remote_request(). With the cURL transport the
 * connection is pinned to the IP validated by UrlGuard (CURLOPT_RESOLVE), which
 * closes the DNS-rebinding window between validation and connection.
 */
class WpTransport implements TransportInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url       URL.
	 * @param array  $args      Args.
	 * @param string $pinned_ip IP.
	 * @return array|\WP_Error
	 */
	public function request( $url, array $args, $pinned_ip ) {
		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? trim( $parts['host'], '[]' ) : '';
		$port  = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( (string) $parts['scheme'] ) ? 443 : 80 );

		$pin = static function ( $handle ) use ( $host, $port, $pinned_ip ) {
			if ( ! function_exists( 'curl_setopt' ) ) {
				return;
			}
			$ip = false !== strpos( $pinned_ip, ':' ) ? '[' . $pinned_ip . ']' : $pinned_ip;
			if ( ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			}
			curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			if ( defined( 'CURLPROTO_HTTP' ) ) {
				curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			}
		};
		add_action( 'http_api_curl', $pin, 1 );

		$wp_args = array(
			'method'              => isset( $args['method'] ) ? $args['method'] : 'GET',
			'timeout'             => isset( $args['timeout'] ) ? (int) $args['timeout'] : 15,
			'redirection'         => 0,
			'headers'             => isset( $args['headers'] ) ? $args['headers'] : array(),
			'user-agent'          => isset( $args['user_agent'] ) ? $args['user_agent'] : 'RSSMediaHub',
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => isset( $args['max_bytes'] ) ? (int) $args['max_bytes'] + 1 : null,
			'decompress'          => true,
		);
		if ( ! empty( $args['filename'] ) ) {
			$wp_args['stream']   = true;
			$wp_args['filename'] = $args['filename'];
		}

		// Our own guard already validated the destination (including ports from settings);
		// let WordPress' unsafe-URL check accept the same ports.
		$ports = static function ( $allowed ) use ( $port ) {
			return array_unique( array_merge( (array) $allowed, array( $port ) ) );
		};
		add_filter( 'http_allowed_safe_ports', $ports );

		$response = wp_remote_request( $url, $wp_args );

		remove_filter( 'http_allowed_safe_ports', $ports );
		remove_action( 'http_api_curl', $pin, 1 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$headers = array();
		$raw     = wp_remote_retrieve_headers( $response );
		if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
			$raw = $raw->getAll();
		}
		foreach ( is_array( $raw ) ? $raw : array() as $k => $v ) {
			$headers[ strtolower( $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'body'    => ! empty( $args['filename'] ) ? '' : (string) wp_remote_retrieve_body( $response ),
		);
	}
}

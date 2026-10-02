<?php
/**
 * Guarded HTTP client: SSRF checks on every hop, manual redirects,
 * retries with backoff, conditional requests and response-size limits.
 *
 * @package RSSMediaHub
 */

namespace RMH\Http;

use RMH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * All outbound requests made by the plugin go through this class.
 */
class HttpClient {

	/**
	 * Transport.
	 *
	 * @var TransportInterface
	 */
	private $transport;

	/**
	 * Guard.
	 *
	 * @var UrlGuard
	 */
	private $guard;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param TransportInterface $transport Transport.
	 * @param UrlGuard           $guard     Guard.
	 * @param Settings           $settings  Settings.
	 */
	public function __construct( TransportInterface $transport, UrlGuard $guard, Settings $settings ) {
		$this->transport = $transport;
		$this->guard     = $guard;
		$this->settings  = $settings;
	}

	/**
	 * GET a URL.
	 *
	 * @param string $url  URL.
	 * @param array  $opts Options (see request()).
	 * @return HttpResponse
	 */
	public function get( $url, array $opts = array() ) {
		return $this->request( 'GET', $url, $opts );
	}

	/**
	 * Perform a guarded request.
	 *
	 * Options:
	 *  - headers (array), accept (string)
	 *  - etag / last_modified (conditional GET)
	 *  - auth_user / auth_pass (HTTP Basic, dropped on cross-origin redirect)
	 *  - max_bytes (int), timeout (int), retries (int), max_redirects (int)
	 *  - filename (stream body to this file)
	 *  - allow_truncate (bool: accept a body cut at max_bytes, for probes)
	 *
	 * @param string $method Method (GET or HEAD).
	 * @param string $url    URL.
	 * @param array  $opts   Options.
	 * @return HttpResponse
	 */
	public function request( $method, $url, array $opts = array() ) {
		$response      = new HttpResponse();
		$max_redirects = isset( $opts['max_redirects'] ) ? (int) $opts['max_redirects'] : (int) $this->settings->get( 'max_redirects' );
		$retries       = isset( $opts['retries'] ) ? (int) $opts['retries'] : (int) $this->settings->get( 'retry_limit' );
		$max_bytes     = isset( $opts['max_bytes'] ) ? (int) $opts['max_bytes'] : MB_IN_BYTES * (int) $this->settings->get( 'max_feed_mb' );
		$timeout       = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : (int) $this->settings->get( 'request_timeout' );
		$clock         = rmh()->get( 'clock' );

		$origin  = $this->origin( $url );
		$current = $url;
		$method  = strtoupper( $method );

		for ( $hop = 0; $hop <= $max_redirects; $hop++ ) {
			$checked = $this->guard->check( $current );
			if ( is_wp_error( $checked ) ) {
				$response->error     = $checked;
				$response->final_url = $current;
				return $response;
			}

			$headers = isset( $opts['headers'] ) ? (array) $opts['headers'] : array();
			if ( ! empty( $opts['accept'] ) ) {
				$headers['Accept'] = $opts['accept'];
			}
			if ( 0 === $hop && ! empty( $opts['etag'] ) ) {
				$headers['If-None-Match'] = $opts['etag'];
			}
			if ( 0 === $hop && ! empty( $opts['last_modified'] ) ) {
				$headers['If-Modified-Since'] = $opts['last_modified'];
			}
			// Credentials only ever go to the origin they were configured for.
			if ( ! empty( $opts['auth_user'] ) && $this->origin( $current ) === $origin ) {
				$headers['Authorization'] = 'Basic ' . base64_encode( $opts['auth_user'] . ':' . ( isset( $opts['auth_pass'] ) ? $opts['auth_pass'] : '' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}

			$args = array(
				'method'     => $method,
				'headers'    => $headers,
				'timeout'    => $timeout,
				'max_bytes'  => $max_bytes,
				'user_agent' => $this->settings->user_agent(),
			);
			if ( ! empty( $opts['filename'] ) ) {
				$args['filename'] = $opts['filename'];
			}

			// Retry loop for this hop.
			$raw = null;
			for ( $attempt = 0; $attempt <= $retries; $attempt++ ) {
				++$response->attempts;
				$raw = $this->transport->request( $current, $args, $checked['ip'] );
				if ( is_wp_error( $raw ) ) {
					if ( $attempt < $retries ) {
						$clock->sleep( min( 8, pow( 2, $attempt ) ) );
						continue;
					}
					break;
				}
				$status = (int) $raw['status'];
				if ( in_array( $status, array( 408, 425, 429, 500, 502, 503, 504 ), true ) && $attempt < $retries ) {
					$wait = $this->retry_after( $raw );
					if ( $wait > 10 ) {
						break; // Server asked for a long pause: give up now, back off at feed level.
					}
					$clock->sleep( max( $wait, min( 8, pow( 2, $attempt ) ) ) );
					continue;
				}
				break;
			}

			$response->final_url = $current;
			if ( is_wp_error( $raw ) ) {
				$response->error = new \WP_Error( 'rmh_unreachable', $raw->get_error_message() );
				return $response;
			}

			$response->status      = (int) $raw['status'];
			$response->headers     = isset( $raw['headers'] ) ? array_change_key_case( (array) $raw['headers'], CASE_LOWER ) : array();
			$response->body        = isset( $raw['body'] ) ? (string) $raw['body'] : '';
			$response->retry_after = $this->retry_after( $raw );

			$declared = (int) $response->header( 'content-length' );
			if ( ! empty( $opts['allow_truncate'] ) ) {
				// Probe requests (HEAD / ranged GET) only need headers; the transport caps the body.
				$response->body = substr( $response->body, 0, $max_bytes );
			} elseif ( $declared > $max_bytes || strlen( $response->body ) > $max_bytes || ( ! empty( $opts['filename'] ) && is_file( $opts['filename'] ) && filesize( $opts['filename'] ) > $max_bytes ) ) {
				$response->error = new \WP_Error( 'rmh_too_large', sprintf( /* translators: %d: bytes */ __( 'Response exceeds the %d byte limit.', 'rss-media-hub' ), $max_bytes ) );
				$response->body  = '';
				return $response;
			}

			if ( in_array( $response->status, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = $response->header( 'location' );
				$next     = UrlNormalizer::absolutize( $location, $current );
				if ( '' === $next ) {
					$response->error = new \WP_Error( 'rmh_bad_redirect', __( 'Redirect without a valid http(s) Location.', 'rss-media-hub' ) );
					return $response;
				}
				$response->redirects[] = $next;
				if ( 303 === $response->status || ( 'POST' === $method && in_array( $response->status, array( 301, 302 ), true ) ) ) {
					$method = 'GET';
				}
				$current = $next;
				continue;
			}
			return $response;
		}

		$response->error = new \WP_Error( 'rmh_too_many_redirects', __( 'Too many redirects.', 'rss-media-hub' ) );
		return $response;
	}

	/**
	 * Parse Retry-After (seconds or HTTP date).
	 *
	 * @param array $raw Raw transport response.
	 * @return int Seconds.
	 */
	private function retry_after( $raw ) {
		$headers = isset( $raw['headers'] ) ? array_change_key_case( (array) $raw['headers'], CASE_LOWER ) : array();
		if ( empty( $headers['retry-after'] ) ) {
			return 0;
		}
		$v = trim( (string) $headers['retry-after'] );
		if ( ctype_digit( $v ) ) {
			return min( 86400, (int) $v );
		}
		$ts = strtotime( $v );
		return $ts ? max( 0, min( 86400, $ts - time() ) ) : 0;
	}

	/**
	 * scheme://host:port of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function origin( $url ) {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return '';
		}
		$scheme = isset( $p['scheme'] ) ? strtolower( $p['scheme'] ) : 'http';
		$port   = isset( $p['port'] ) ? (int) $p['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $p['host'] ) . ':' . $port;
	}
}

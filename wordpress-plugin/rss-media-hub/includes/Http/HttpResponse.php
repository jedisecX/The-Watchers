<?php
/**
 * Result of a guarded HTTP request.
 *
 * @package RSSMediaHub
 */

namespace RMH\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable-ish response value object.
 */
class HttpResponse {

	/**
	 * HTTP status (0 when no response).
	 *
	 * @var int
	 */
	public $status = 0;

	/**
	 * Lowercased headers.
	 *
	 * @var array<string,string>
	 */
	public $headers = array();

	/**
	 * Body.
	 *
	 * @var string
	 */
	public $body = '';

	/**
	 * Final URL after redirects.
	 *
	 * @var string
	 */
	public $final_url = '';

	/**
	 * Error, if the request failed.
	 *
	 * @var \WP_Error|null
	 */
	public $error = null;

	/**
	 * Attempts made.
	 *
	 * @var int
	 */
	public $attempts = 0;

	/**
	 * Redirect hops followed.
	 *
	 * @var string[]
	 */
	public $redirects = array();

	/**
	 * Retry-After seconds advertised by the server.
	 *
	 * @var int
	 */
	public $retry_after = 0;

	/**
	 * Was the request successful (2xx or 304)?
	 *
	 * @return bool
	 */
	public function ok() {
		return null === $this->error && ( ( $this->status >= 200 && $this->status < 300 ) || 304 === $this->status );
	}

	/**
	 * Is the failure likely temporary (worth retrying later)?
	 *
	 * @return bool
	 */
	public function is_temporary_failure() {
		if ( $this->error ) {
			return ! in_array( $this->error->get_error_code(), array( 'rmh_blocked_destination', 'rmh_too_large', 'rmh_too_many_redirects', 'rmh_bad_redirect' ), true );
		}
		return 408 === $this->status || 425 === $this->status || 429 === $this->status || $this->status >= 500;
	}

	/**
	 * Header value.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	public function header( $name ) {
		$name = strtolower( $name );
		return isset( $this->headers[ $name ] ) ? (string) $this->headers[ $name ] : '';
	}

	/**
	 * Media type from Content-Type (without parameters).
	 *
	 * @return string
	 */
	public function content_type() {
		$ct = strtolower( trim( explode( ';', $this->header( 'content-type' ) )[0] ) );
		return $ct;
	}

	/**
	 * Human readable error/status summary.
	 *
	 * @return string
	 */
	public function describe() {
		if ( $this->error ) {
			return $this->error->get_error_message();
		}
		return 'HTTP ' . $this->status;
	}
}

<?php
/**
 * Request-time access control: jurisdiction rules and the age gate.
 *
 * @package RSSMediaHub
 */

namespace RMH\Compliance;

use RMH\Frontend\Frontend;
use RMH\Frontend\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces geo rules and the server-side age gate on plugin pages, and handles
 * the public form posts (age verification, reports).
 */
class AccessControl {

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'template_redirect', array( $this, 'enforce' ), 1 );
		add_action( 'admin_post_nopriv_rmh_age_verify', array( $this, 'handle_age_verify' ) );
		add_action( 'admin_post_rmh_age_verify', array( $this, 'handle_age_verify' ) );
		add_action( 'admin_post_nopriv_rmh_report', array( $this, 'handle_report' ) );
		add_action( 'admin_post_rmh_report', array( $this, 'handle_report' ) );
	}

	/**
	 * Visitor country (ISO 3166-1 alpha-2) from a configured trusted header,
	 * or from the `rmh_visitor_country` filter (e.g. a GeoIP integration).
	 *
	 * @return string
	 */
	public static function visitor_country() {
		$header  = (string) rmh()->get( 'settings' )->get( 'country_header' );
		$country = '';
		if ( '' !== $header ) {
			$key = 'HTTP_' . strtoupper( str_replace( '-', '_', preg_replace( '/[^A-Za-z0-9-]/', '', $header ) ) );
			if ( isset( $_SERVER[ $key ] ) ) {
				$country = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ), 0, 2 ) );
			}
		}
		$country = strtoupper( (string) apply_filters( 'rmh_visitor_country', $country ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * Decide what to do with the current request.
	 *
	 * @return string ok|geo_block|age_gate
	 */
	public function decide() {
		if ( ! Frontend::is_rmh_request() ) {
			return 'ok';
		}
		$blocked = array_filter( explode( ',', (string) rmh()->get( 'settings' )->get( 'blocked_countries' ) ) );
		if ( $blocked ) {
			$country = self::visitor_country();
			foreach ( $blocked as $code ) {
				if ( '' !== $country && ( $country === $code || 0 === strpos( $code, $country . '-' ) ) ) {
					return 'geo_block';
				}
			}
		}
		$gate = rmh()->get( 'age_gate' );
		if ( 'server' === rmh()->get( 'settings' )->get( 'age_gate_mode' ) && $gate->applies_to_request() && ! $gate->is_verified() ) {
			return 'age_gate';
		}
		return 'ok';
	}

	/**
	 * Mark the response as uncacheable by shared caches and page-cache plugins.
	 */
	public static function no_shared_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- page-cache plugin convention.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}

	/**
	 * template_redirect enforcement.
	 */
	public function enforce() {
		$decision = $this->decide();
		$settings = rmh()->get( 'settings' );
		if ( Frontend::is_rmh_request() && ! headers_sent() && '' !== (string) $settings->get( 'blocked_countries' ) && '' !== (string) $settings->get( 'country_header' ) ) {
			header( 'Vary: ' . preg_replace( '/[^A-Za-z0-9-]/', '', (string) $settings->get( 'country_header' ) ), false );
		}
		// Server-mode gated pages vary per visitor and must never be cached publicly.
		if ( Frontend::is_rmh_request() && 'server' === $settings->get( 'age_gate_mode' ) && rmh()->get( 'age_gate' )->applies_to_request() ) {
			self::no_shared_cache();
		}
		if ( 'ok' === $decision ) {
			return;
		}
		self::no_shared_cache();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		if ( 'geo_block' === $decision ) {
			status_header( 451 );
			include Template::locate( 'blocked' );
			$this->terminate();
			return;
		}
		status_header( 200 );
		$gate   = rmh()->get( 'age_gate' );
		$return = esc_url_raw( home_url( add_query_arg( array() ) ) );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Template::render() escapes everything it outputs.
		echo Template::render(
			'age-gate',
			array(
				'challenge'   => $gate->verifier()->render_challenge( $return ),
				'message'     => (string) $settings->get( 'age_gate_message' ),
				'minimum_age' => (int) $settings->get( 'minimum_age' ),
				'legal'       => $gate->verifier()->is_legal_verification(),
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->terminate();
	}

	/**
	 * Stop the request (filterable for tests).
	 */
	protected function terminate() {
		if ( apply_filters( 'rmh_exit_after_gate', true ) ) {
			exit;
		}
	}

	/**
	 * admin-post: age verification submission.
	 */
	public function handle_age_verify() {
		$nonce = isset( $_POST['_rmh_age_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rmh_age_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'rmh_age_verify' ) ) {
			wp_die( esc_html__( 'Your session expired. Please go back and try again.', 'rss-media-hub' ), '', array( 'response' => 403 ) );
		}
		$request = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed to the verifier, which validates its own fields.
		$result  = rmh()->get( 'age_gate' )->verifier()->handle( is_array( $request ) ? $request : array() );
		$return  = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : home_url( '/' );
		$return  = wp_validate_redirect( $return, home_url( '/' ) );
		if ( is_wp_error( $result ) ) {
			// Declined: send the visitor to the operator-configured exit URL (trusted configuration, not user input).
			wp_redirect( esc_url_raw( (string) apply_filters( 'rmh_age_gate_leave_url', 'https://www.google.com/' ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			$this->terminate();
			return;
		}
		wp_safe_redirect( $return );
		$this->terminate();
	}

	/**
	 * admin-post: report submission (no-JS form and JS fetch).
	 */
	public function handle_report() {
		// Anonymous public form: protected by honeypot, rate limit and validation instead of a nonce,
		// so that it keeps working on cached pages.
		$input = array();
		foreach ( array( 'post_id', 'reason', 'details', 'contact', 'website' ) as $k ) {
			$input[ $k ] = isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$result = Reports::submit( $input );
		$wants  = isset( $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ), 'application/json' );
		if ( $wants ) {
			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				wp_send_json_error( array( 'message' => $result->get_error_message() ), isset( $data['status'] ) ? (int) $data['status'] : 400 );
			}
			wp_send_json_success( array( 'message' => __( 'Thank you. Your report was received.', 'rss-media-hub' ) ) );
		}
		$post_id = absint( $input['post_id'] );
		$back    = $post_id && get_permalink( $post_id ) && 'publish' === get_post_status( $post_id ) ? get_permalink( $post_id ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'rmh_reported', is_wp_error( $result ) ? 'error' : 'ok', $back ) );
		$this->terminate();
	}
}

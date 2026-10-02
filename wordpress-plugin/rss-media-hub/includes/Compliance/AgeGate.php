<?php
/**
 * Age gate policy and signed proof cookie.
 *
 * @package RSSMediaHub
 */

namespace RMH\Compliance;

use RMH\PostTypes;
use RMH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides where the gate applies and stores a signed, expiring proof that the
 * visitor passed the selected verifier. The cookie contains no personal data.
 */
class AgeGate {

	const COOKIE = 'rmh_age_ok';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Cached verifiers.
	 *
	 * @var AgeVerifierInterface[]|null
	 */
	private $verifiers = null;

	/**
	 * Proof set during this request (cookie not yet readable).
	 *
	 * @var string
	 */
	private $fresh_proof = '';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Available verifiers keyed by id.
	 *
	 * @return AgeVerifierInterface[]
	 */
	public function verifiers() {
		if ( null === $this->verifiers ) {
			$list            = array( 'self_declaration' => new SelfDeclarationVerifier( $this ) );
			$list            = apply_filters( 'rmh_age_verifiers', $list, $this );
			$this->verifiers = array_filter(
				(array) $list,
				static function ( $v ) {
					return $v instanceof AgeVerifierInterface;
				}
			);
		}
		return $this->verifiers;
	}

	/**
	 * Selected verifier (falls back to self-declaration).
	 *
	 * @return AgeVerifierInterface
	 */
	public function verifier() {
		$all = $this->verifiers();
		$id  = (string) $this->settings->get( 'age_verifier' );
		return isset( $all[ $id ] ) ? $all[ $id ] : $all['self_declaration'];
	}

	/**
	 * Is the gate enabled?
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) $this->settings->get( 'age_gate_enabled' );
	}

	/**
	 * Does the gate apply to a post?
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public function applies_to_post( $post_id ) {
		if ( ! $this->enabled() ) {
			return false;
		}
		if ( 'all' === $this->settings->get( 'age_gate_scope' ) ) {
			return true;
		}
		return 'adult' === get_post_meta( $post_id, '_rmh_content_rating', true );
	}

	/**
	 * Does the gate apply to the current request?
	 *
	 * @return bool
	 */
	public function applies_to_request() {
		if ( ! $this->enabled() || ! \RMH\Frontend\Frontend::is_rmh_request() ) {
			return false;
		}
		if ( 'all' === $this->settings->get( 'age_gate_scope' ) ) {
			return true;
		}
		return is_singular( PostTypes::POST_TYPE ) && $this->applies_to_post( get_queried_object_id() );
	}

	/**
	 * Has the visitor passed the selected verifier?
	 *
	 * @return bool
	 */
	public function is_verified() {
		if ( current_user_can( 'rmh_moderate' ) ) {
			return true; // Staff reviewing content.
		}
		return (bool) apply_filters( 'rmh_age_verified', $this->verifier()->is_verified(), $this );
	}

	/**
	 * Sign a proof value.
	 *
	 * @param string $verifier_id Verifier.
	 * @param int    $expires     Expiry.
	 * @return string
	 */
	private function sign( $verifier_id, $expires ) {
		return hash_hmac( 'sha256', 'rmh-age|' . $verifier_id . '|' . (int) $expires . '|' . (int) $this->settings->get( 'minimum_age' ), wp_salt( 'auth' ) );
	}

	/**
	 * Issue a proof cookie for a verifier.
	 *
	 * @param string $verifier_id Verifier.
	 */
	public function set_proof( $verifier_id ) {
		$expires           = time() + DAY_IN_SECONDS * (int) $this->settings->get( 'age_gate_ttl_days' );
		$value             = $verifier_id . '.' . $expires . '.' . $this->sign( $verifier_id, $expires );
		$this->fresh_proof = $value;
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => $expires,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * Validate the proof cookie.
	 *
	 * @param string $verifier_id Verifier expected.
	 * @return bool
	 */
	public function has_valid_proof( $verifier_id ) {
		$raw = '' !== $this->fresh_proof ? $this->fresh_proof : ( isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '' );
		if ( '' === $raw ) {
			return false;
		}
		$parts = explode( '.', $raw );
		if ( 3 !== count( $parts ) || $parts[0] !== $verifier_id || ! ctype_digit( $parts[1] ) ) {
			return false;
		}
		if ( (int) $parts[1] < time() ) {
			return false;
		}
		return hash_equals( $this->sign( $verifier_id, (int) $parts[1] ), $parts[2] );
	}
}

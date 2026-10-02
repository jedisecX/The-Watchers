<?php
/**
 * Extension point: age-verification providers.
 *
 * @package RSSMediaHub
 */

namespace RMH\Compliance;

defined( 'ABSPATH' ) || exit;

/**
 * Implement this interface to integrate a real age-verification/assurance
 * provider (document check, facial age estimation, digital ID, bank/credit
 * check, ...). Register it with the `rmh_age_verifiers` filter and select it in
 * Settings → Access & Compliance. See docs/AGE-VERIFICATION.md.
 */
interface AgeVerifierInterface {

	/**
	 * Unique id.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Does this method constitute age verification that may satisfy legal
	 * requirements (as opposed to a self-declaration)? The admin UI warns when false.
	 *
	 * @return bool
	 */
	public function is_legal_verification();

	/**
	 * Has the current visitor passed verification?
	 *
	 * @return bool
	 */
	public function is_verified();

	/**
	 * HTML shown on the gate (must escape its own output).
	 *
	 * @param string $return_url Same-site URL to return to.
	 * @return string
	 */
	public function render_challenge( $return_url );

	/**
	 * Handle the gate form submission / provider callback (admin-post action
	 * `rmh_age_verify`). The verifier sets its own proof (e.g. via AgeGate::set_proof()).
	 *
	 * @param array $request Unslashed request data.
	 * @return true|\WP_Error
	 */
	public function handle( array $request );
}

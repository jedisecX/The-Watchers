<?php
/**
 * Self-declaration age gate ("I am 18 or older").
 *
 * @package RSSMediaHub
 */

namespace RMH\Compliance;

defined( 'ABSPATH' ) || exit;

/**
 * A simple confirmation button. This is NOT age verification and does not
 * satisfy laws that require verifying a visitor's age; it is a content
 * warning/deterrent only. The admin UI states this explicitly.
 */
class SelfDeclarationVerifier implements AgeVerifierInterface {

	/**
	 * Gate.
	 *
	 * @var AgeGate
	 */
	private $gate;

	/**
	 * Constructor.
	 *
	 * @param AgeGate $gate Gate.
	 */
	public function __construct( AgeGate $gate ) {
		$this->gate = $gate;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'self_declaration';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Self-declaration (confirmation button – not legal age verification)', 'rss-media-hub' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_legal_verification() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_verified() {
		return $this->gate->has_valid_proof( $this->id() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $return_url Return URL.
	 * @return string
	 */
	public function render_challenge( $return_url ) {
		$age   = (int) rmh()->get( 'settings' )->get( 'minimum_age' );
		$leave = apply_filters( 'rmh_age_gate_leave_url', 'https://www.google.com/' );
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rmh-agegate-form">
			<input type="hidden" name="action" value="rmh_age_verify">
			<input type="hidden" name="return" value="<?php echo esc_attr( $return_url ); ?>">
			<?php wp_nonce_field( 'rmh_age_verify', '_rmh_age_nonce' ); ?>
			<p class="rmh-agegate-actions">
				<button type="submit" name="confirm" value="1" class="rmh-button rmh-button--primary">
					<?php
					/* translators: %d: minimum age */
					echo esc_html( sprintf( __( 'I am %d or older – enter', 'rss-media-hub' ), $age ) );
					?>
				</button>
				<a class="rmh-button rmh-button--secondary" href="<?php echo esc_url( $leave ); ?>" rel="nofollow"><?php esc_html_e( 'Leave', 'rss-media-hub' ); ?></a>
			</p>
			<p class="rmh-agegate-small">
				<?php
				/* translators: %d: minimum age */
				echo esc_html( sprintf( __( 'By entering you confirm that you are at least %d years old and that viewing adult material is legal where you are.', 'rss-media-hub' ), $age ) );
				?>
			</p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $request Request.
	 * @return true|\WP_Error
	 */
	public function handle( array $request ) {
		if ( empty( $request['confirm'] ) || '1' !== (string) $request['confirm'] ) {
			return new \WP_Error( 'rmh_age_declined', __( 'Age confirmation declined.', 'rss-media-hub' ) );
		}
		$this->gate->set_proof( $this->id() );
		return true;
	}
}

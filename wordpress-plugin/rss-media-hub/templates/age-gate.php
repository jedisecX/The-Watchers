<?php
/**
 * Age gate (server mode). Rendered instead of the requested page; never cached.
 *
 * Variables: $args['challenge'] (verifier HTML), $args['message'], $args['minimum_age'], $args['legal'] (bool).
 *
 * @package RSSMediaHub
 */

use RMH\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

Frontend::open_page();
?>
<main id="rmh-main" class="rmh rmh-agegate">
	<div class="rmh-agegate-box" role="dialog" aria-modal="true" aria-labelledby="rmh-agegate-title">
		<h1 id="rmh-agegate-title">
			<?php
			/* translators: %d: minimum age */
			echo esc_html( sprintf( __( 'Adults only (%d+)', 'rss-media-hub' ), (int) $args['minimum_age'] ) );
			?>
		</h1>
		<p><?php echo esc_html( '' !== $args['message'] ? $args['message'] : __( 'This page contains sexually explicit material intended for adults only.', 'rss-media-hub' ) ); ?></p>
		<?php echo $args['challenge']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- produced by the verifier adapter, which escapes its own output. ?>
	</div>
</main>
<?php
Frontend::close_page();

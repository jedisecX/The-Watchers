<?php
/**
 * Report / broken media / removal request form. Works without JavaScript
 * (posts to admin-post.php); assets/js/frontend.js submits it in place.
 *
 * Variables: $args['post'].
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;

$rmh_post = $args['post'];
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
$rmh_done = isset( $_GET['rmh_reported'] ) ? sanitize_key( wp_unslash( $_GET['rmh_reported'] ) ) : '';
?>
<details class="rmh-report"<?php echo '' !== $rmh_done ? ' open' : ''; ?>>
	<summary><?php esc_html_e( 'Report this post / broken media', 'rss-media-hub' ); ?></summary>
	<?php if ( 'ok' === $rmh_done ) : ?>
		<p class="rmh-notice rmh-notice--ok" role="status"><?php esc_html_e( 'Thank you. Your report was received and will be reviewed.', 'rss-media-hub' ); ?></p>
	<?php elseif ( '' !== $rmh_done ) : ?>
		<p class="rmh-notice rmh-notice--error" role="alert"><?php esc_html_e( 'Your report could not be submitted. Please try again later.', 'rss-media-hub' ); ?></p>
	<?php endif; ?>
	<form class="rmh-report-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-rmh-report>
		<input type="hidden" name="action" value="rmh_report">
		<input type="hidden" name="post_id" value="<?php echo (int) $rmh_post->ID; ?>">
		<p class="rmh-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
		<p>
			<label for="rmh-reason-<?php echo (int) $rmh_post->ID; ?>"><?php esc_html_e( 'Reason', 'rss-media-hub' ); ?></label>
			<select id="rmh-reason-<?php echo (int) $rmh_post->ID; ?>" name="reason" required>
				<?php foreach ( \RMH\Frontend\Template::report_reasons() as $rmh_key => $rmh_label ) : ?>
					<option value="<?php echo esc_attr( $rmh_key ); ?>"><?php echo esc_html( $rmh_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="rmh-details-<?php echo (int) $rmh_post->ID; ?>"><?php esc_html_e( 'Details (optional)', 'rss-media-hub' ); ?></label>
			<textarea id="rmh-details-<?php echo (int) $rmh_post->ID; ?>" name="details" rows="3" maxlength="2000"></textarea>
		</p>
		<p>
			<label for="rmh-contact-<?php echo (int) $rmh_post->ID; ?>"><?php esc_html_e( 'Contact for follow-up (optional, for removal requests)', 'rss-media-hub' ); ?></label>
			<input id="rmh-contact-<?php echo (int) $rmh_post->ID; ?>" type="text" name="contact" maxlength="200" autocomplete="email">
		</p>
		<p><button type="submit" class="rmh-button"><?php esc_html_e( 'Send report', 'rss-media-hub' ); ?></button> <span class="rmh-form-status" role="status" aria-live="polite"></span></p>
	</form>
</details>

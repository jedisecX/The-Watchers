<?php
/**
 * Fallback when media cannot be embedded or played.
 *
 * Variables: $args['thumbnail'], $args['title'], $args['source_url'], $args['source_name'], $args['unavailable'], $args['link_only'], $args['excerpt'].
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="rmh-media rmh-media--fallback">
	<?php if ( ! empty( $args['thumbnail'] ) ) : ?>
		<a href="<?php echo esc_url( $args['source_url'] ); ?>" rel="nofollow noopener" target="_blank" class="rmh-fallback-thumb">
			<img src="<?php echo esc_url( $args['thumbnail'] ); ?>" alt="<?php echo esc_attr( $args['title'] ); ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer">
		</a>
	<?php endif; ?>
	<div class="rmh-fallback-body">
		<p class="rmh-fallback-note" role="status">
			<?php
			if ( $args['unavailable'] ) {
				esc_html_e( 'This media is currently unavailable at its source.', 'rss-media-hub' );
			} elseif ( $args['link_only'] ) {
				esc_html_e( 'This media is hosted by the original publisher.', 'rss-media-hub' );
			} else {
				esc_html_e( 'This media cannot be played here.', 'rss-media-hub' );
			}
			?>
		</p>
		<?php if ( '' !== $args['source_url'] ) : ?>
			<a class="rmh-button" href="<?php echo esc_url( $args['source_url'] ); ?>" rel="nofollow noopener" target="_blank">
				<?php
				/* translators: %s: source name */
				echo esc_html( sprintf( __( 'View at %s', 'rss-media-hub' ), $args['source_name'] ) );
				?>
			</a>
		<?php endif; ?>
	</div>
</div>

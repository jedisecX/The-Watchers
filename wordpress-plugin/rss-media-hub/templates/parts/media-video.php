<?php
/**
 * Direct video player.
 *
 * Variables: $args['sources'] (src,type), $args['poster'], $args['width'], $args['height'], $args['title'], $args['source_link'].
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;

$rmh_ratio = ( $args['width'] > 0 && $args['height'] > 0 ) ? ( $args['width'] . ' / ' . $args['height'] ) : '16 / 9';
?>
<figure class="rmh-media rmh-media--video" style="<?php echo esc_attr( '--rmh-ratio:' . $rmh_ratio ); ?>">
	<video class="rmh-video" controls playsinline preload="metadata"
		<?php
		if ( ! empty( $args['poster'] ) ) :
			?>
			poster="<?php echo esc_url( $args['poster'] ); ?>"<?php endif; ?>
		aria-label="<?php echo esc_attr( $args['title'] ); ?>">
		<?php foreach ( $args['sources'] as $rmh_source ) : ?>
			<source src="<?php echo esc_url( $rmh_source['src'] ); ?>"<?php echo '' !== $rmh_source['type'] ? ' type="' . esc_attr( $rmh_source['type'] ) . '"' : ''; ?>>
		<?php endforeach; ?>
		<p class="rmh-noplay">
			<?php esc_html_e( 'Your browser cannot play this video.', 'rss-media-hub' ); ?>
			<a href="<?php echo esc_url( $args['source_link'] ); ?>" rel="nofollow noopener" target="_blank"><?php esc_html_e( 'Open the video file', 'rss-media-hub' ); ?></a>
		</p>
	</video>
</figure>

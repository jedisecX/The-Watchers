<?php
/**
 * Image gallery with keyboard-accessible lightbox (assets/js/frontend.js).
 *
 * Variables: $args['images'] (src, alt, width, height), $args['title'].
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="rmh-media rmh-media--gallery" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: count */ _n( 'Gallery of %d image', 'Gallery of %d images', count( $args['images'] ), 'rss-media-hub' ), count( $args['images'] ) ) ); ?>">
	<ul class="rmh-gallery" role="list">
		<?php foreach ( $args['images'] as $rmh_i => $rmh_img ) : ?>
			<li>
				<a class="rmh-lightbox-link" href="<?php echo esc_url( $rmh_img['src'] ); ?>" data-rmh-lightbox="<?php echo (int) $rmh_i; ?>">
					<img src="<?php echo esc_url( $rmh_img['src'] ); ?>" alt="<?php echo esc_attr( $rmh_img['alt'] ); ?>"
						<?php echo $rmh_img['width'] ? 'width="' . (int) $rmh_img['width'] . '"' : ''; ?> <?php echo $rmh_img['height'] ? 'height="' . (int) $rmh_img['height'] . '"' : ''; ?>
						loading="<?php echo $rmh_i < 2 ? 'eager' : 'lazy'; ?>" decoding="async" referrerpolicy="no-referrer">
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</section>

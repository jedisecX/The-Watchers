<?php
/**
 * Single responsive image.
 *
 * Variables: $args['src'], $args['alt'], $args['width'], $args['height'].
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;
?>
<figure class="rmh-media rmh-media--image">
	<a class="rmh-lightbox-link" href="<?php echo esc_url( $args['src'] ); ?>" data-rmh-lightbox="0">
		<img src="<?php echo esc_url( $args['src'] ); ?>" alt="<?php echo esc_attr( $args['alt'] ); ?>"
			<?php echo $args['width'] ? 'width="' . (int) $args['width'] . '"' : ''; ?> <?php echo $args['height'] ? 'height="' . (int) $args['height'] . '"' : ''; ?>
			decoding="async" referrerpolicy="no-referrer">
	</a>
</figure>

<?php
/**
 * Audio player(s).
 *
 * Variables: $args['tracks'] (src,type,title), $args['thumbnail'], $args['title'].
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="rmh-media rmh-media--audio">
	<?php if ( ! empty( $args['thumbnail'] ) ) : ?>
		<img class="rmh-audio-art" src="<?php echo esc_url( $args['thumbnail'] ); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
	<?php endif; ?>
	<?php foreach ( $args['tracks'] as $rmh_i => $rmh_track ) : ?>
		<figure class="rmh-track">
			<?php if ( count( $args['tracks'] ) > 1 || '' !== $rmh_track['title'] ) : ?>
				<figcaption><?php echo esc_html( '' !== $rmh_track['title'] ? $rmh_track['title'] : sprintf( /* translators: %d: track number */ __( 'Track %d', 'rss-media-hub' ), $rmh_i + 1 ) ); ?></figcaption>
			<?php endif; ?>
			<audio controls preload="none" aria-label="<?php echo esc_attr( $args['title'] ); ?>">
				<source src="<?php echo esc_url( $rmh_track['src'] ); ?>"<?php echo '' !== $rmh_track['type'] ? ' type="' . esc_attr( $rmh_track['type'] ) . '"' : ''; ?>>
				<a href="<?php echo esc_url( $rmh_track['src'] ); ?>" rel="nofollow noopener"><?php esc_html_e( 'Download audio', 'rss-media-hub' ); ?></a>
			</audio>
		</figure>
	<?php endforeach; ?>
</div>

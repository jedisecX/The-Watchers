<?php
/**
 * Trusted provider embed. The URL was produced by an allowlisted provider adapter
 * and re-validated at render time; feed-supplied iframe markup is never used.
 *
 * Variables: $args['embed_url'], $args['sandbox'], $args['provider'], $args['page_url'], $args['title'], $args['thumbnail'].
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;

$rmh_ratio = ( $args['width'] > 0 && $args['height'] > 0 ) ? ( $args['width'] . ' / ' . $args['height'] ) : '16 / 9';
?>
<figure class="rmh-media rmh-media--embed" style="<?php echo esc_attr( '--rmh-ratio:' . $rmh_ratio ); ?>">
	<iframe class="rmh-embed"
		src="<?php echo esc_url( $args['embed_url'] ); ?>"
		title="<?php echo esc_attr( $args['title'] ); ?>"
		loading="lazy"
		referrerpolicy="strict-origin-when-cross-origin"
		sandbox="<?php echo esc_attr( $args['sandbox'] ); ?>"
		allow="fullscreen; picture-in-picture; encrypted-media"
		allowfullscreen></iframe>
	<figcaption class="rmh-embed-caption">
		<a href="<?php echo esc_url( $args['page_url'] ); ?>" rel="nofollow noopener" target="_blank">
			<?php
			/* translators: %s: provider */
			echo esc_html( sprintf( __( 'Watch on %s', 'rss-media-hub' ), ucfirst( preg_replace( '/^custom-[a-f0-9]+$/', __( 'the source site', 'rss-media-hub' ), $args['provider'] ) ) ) );
			?>
		</a>
	</figcaption>
</figure>

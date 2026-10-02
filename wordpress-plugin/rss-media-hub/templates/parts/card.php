<?php
/**
 * Archive card (grid or compact list view). Thumbnails only; no players on archives.
 *
 * Variables: $args['post'], $args['view'], $args['thumbnail'], $args['adult'], $args['source'], $args['date'].
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;

$rmh_post = $args['post'];
?>
<article class="rmh-card rmh-card--<?php echo esc_attr( $args['view'] ); ?><?php echo $args['adult'] ? ' rmh-card--adult' : ''; ?>">
	<a class="rmh-card-link" href="<?php echo esc_url( get_permalink( $rmh_post ) ); ?>">
		<div class="rmh-card-thumb">
			<?php if ( '' !== $args['thumbnail'] ) : ?>
				<img src="<?php echo esc_url( $args['thumbnail'] ); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
			<?php else : ?>
				<span class="rmh-card-placeholder" aria-hidden="true"><?php echo $args['adult'] ? '18+' : '&#9654;'; ?></span>
			<?php endif; ?>
			<span class="rmh-card-badges"><?php echo \RMH\Frontend\Template::badges( $rmh_post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Template::badges(). ?></span>
		</div>
		<div class="rmh-card-body">
			<h2 class="rmh-card-title"><?php echo esc_html( get_the_title( $rmh_post ) ); ?></h2>
			<p class="rmh-card-meta">
				<span class="rmh-card-source"><?php echo esc_html( $args['source'] ); ?></span>
				<span aria-hidden="true">&middot;</span>
				<time datetime="<?php echo esc_attr( get_the_date( 'c', $rmh_post ) ); ?>"><?php echo esc_html( $args['date'] ); ?></time>
			</p>
			<?php if ( 'list' === $args['view'] && has_excerpt( $rmh_post ) ) : ?>
				<p class="rmh-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt( $rmh_post ), 30, '…' ) ); ?></p>
			<?php endif; ?>
		</div>
	</a>
</article>

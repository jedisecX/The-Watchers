<?php
/**
 * Single imported media post.
 *
 * Copy to yourtheme/rss-media-hub/single.php to customize.
 *
 * @package RSSMediaHub
 */

use RMH\Frontend\Frontend;
use RMH\Frontend\Template;
use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

Frontend::open_page();

while ( have_posts() ) :
	the_post();
	$rmh_post     = get_post();
	$rmh_settings = rmh()->get( 'settings' );
	$rmh_source   = esc_url( (string) get_post_meta( $rmh_post->ID, '_rmh_source_url', true ) );
	$rmh_rating   = (string) get_post_meta( $rmh_post->ID, '_rmh_content_rating', true );
	$rmh_warning  = trim( (string) $rmh_settings->get( 'content_warning' ) );
	?>
	<main id="rmh-main" class="rmh rmh-single" data-rmh-post="<?php echo (int) $rmh_post->ID; ?>">
		<article <?php post_class( 'rmh-article' ); ?>>
			<header class="rmh-header">
				<div class="rmh-badges"><?php echo Template::badges( $rmh_post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Template::badges(). ?></div>
				<h1 class="rmh-title"><?php the_title(); ?></h1>
				<p class="rmh-meta">
					<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
					<span aria-hidden="true">&middot;</span>
					<span class="rmh-attribution"><?php echo Template::attribution( $rmh_post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized with wp_kses in Template::attribution(). ?></span>
				</p>
			</header>

			<?php if ( 'adult' === $rmh_rating && '' !== $rmh_warning ) : ?>
				<p class="rmh-warning" role="note"><?php echo esc_html( $rmh_warning ); ?></p>
			<?php endif; ?>

			<?php echo Template::media( $rmh_post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped template parts. ?>

			<?php if ( has_excerpt() && 'full' === Template::feed_config( $rmh_post->ID )['content_policy'] ) : ?>
				<p class="rmh-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
			<?php endif; ?>

			<div class="rmh-content entry-content">
				<?php the_content(); ?>
			</div>

			<?php if ( $rmh_source ) : ?>
				<p class="rmh-source-link">
					<a class="rmh-button" href="<?php echo esc_url( $rmh_source ); ?>" rel="nofollow noopener" target="_blank">
						<?php
						/* translators: %s: source name */
						echo esc_html( sprintf( __( 'Read the original at %s', 'rss-media-hub' ), Template::source_name( $rmh_post->ID ) ) );
						?>
					</a>
				</p>
			<?php endif; ?>

			<footer class="rmh-footer">
				<?php
				$rmh_cats = get_the_term_list( $rmh_post->ID, PostTypes::TAX_CATEGORY, '', ', ' );
				$rmh_tags = get_the_term_list( $rmh_post->ID, PostTypes::TAX_TAG, '', ', ' );
				?>
				<?php if ( $rmh_cats && ! is_wp_error( $rmh_cats ) ) : ?>
					<p class="rmh-terms"><span class="rmh-terms-label"><?php esc_html_e( 'Categories:', 'rss-media-hub' ); ?></span> <?php echo wp_kses_post( $rmh_cats ); ?></p>
				<?php endif; ?>
				<?php if ( $rmh_tags && ! is_wp_error( $rmh_tags ) ) : ?>
					<p class="rmh-terms"><span class="rmh-terms-label"><?php esc_html_e( 'Tags:', 'rss-media-hub' ); ?></span> <?php echo wp_kses_post( $rmh_tags ); ?></p>
				<?php endif; ?>

				<?php $rmh_share = Template::share_links( $rmh_post ); ?>
				<?php if ( $rmh_share ) : ?>
					<nav class="rmh-share" aria-label="<?php esc_attr_e( 'Share', 'rss-media-hub' ); ?>">
						<span class="rmh-terms-label"><?php esc_html_e( 'Share:', 'rss-media-hub' ); ?></span>
						<?php foreach ( $rmh_share as $rmh_link ) : ?>
							<a href="<?php echo esc_url( $rmh_link['url'] ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( $rmh_link['label'] ); ?></a>
						<?php endforeach; ?>
						<button type="button" class="rmh-copy-link" data-url="<?php echo esc_url( get_permalink() ); ?>"><?php esc_html_e( 'Copy link', 'rss-media-hub' ); ?></button>
					</nav>
				<?php endif; ?>

				<?php if ( $rmh_settings->get( 'reports_enabled' ) ) : ?>
					<?php echo Template::render( 'parts/report-form', array( 'post' => $rmh_post ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template part. ?>
				<?php endif; ?>
			</footer>
		</article>

		<nav class="rmh-postnav" aria-label="<?php esc_attr_e( 'More media', 'rss-media-hub' ); ?>">
			<div class="rmh-postnav-prev"><?php previous_post_link( '%link', '&larr; %title' ); ?></div>
			<div class="rmh-postnav-next"><?php next_post_link( '%link', '%title &rarr;' ); ?></div>
		</nav>

		<?php $rmh_related = Template::related( $rmh_post ); ?>
		<?php if ( $rmh_related ) : ?>
			<section class="rmh-related" aria-labelledby="rmh-related-title">
				<h2 id="rmh-related-title"><?php esc_html_e( 'Related', 'rss-media-hub' ); ?></h2>
				<div class="rmh-grid rmh-grid--grid">
					<?php
					foreach ( $rmh_related as $rmh_rel ) {
						echo Template::card( $rmh_rel, 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template part.
					}
					?>
				</div>
			</section>
		<?php endif; ?>
	</main>
	<?php
endwhile;

Frontend::close_page();

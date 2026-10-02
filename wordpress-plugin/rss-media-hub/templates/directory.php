<?php
/**
 * Public feed directory (/media/sources/). Shows sources and a coarse status;
 * feed URLs are never shown (they may contain private tokens).
 *
 * @package RSSMediaHub
 */

use RMH\Frontend\Frontend;
use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

Frontend::open_page();
$rmh_feeds = rmh()->get( 'feeds' )->query(
	array(
		'status'   => 'active',
		'per_page' => 500,
	)
)['rows'];
?>
<main id="rmh-main" class="rmh rmh-directory">
	<header class="rmh-reader-header">
		<h1 class="rmh-title"><?php esc_html_e( 'Sources', 'rss-media-hub' ); ?></h1>
		<p class="rmh-reader-links"><a href="<?php echo esc_url( get_post_type_archive_link( PostTypes::POST_TYPE ) ); ?>"><?php esc_html_e( 'Back to all media', 'rss-media-hub' ); ?></a></p>
	</header>
	<?php if ( ! $rmh_feeds ) : ?>
		<div class="rmh-empty" role="status"><p><?php esc_html_e( 'No sources are configured yet.', 'rss-media-hub' ); ?></p></div>
	<?php else : ?>
		<ul class="rmh-sources" role="list">
			<?php foreach ( $rmh_feeds as $rmh_feed ) : ?>
				<?php
				$rmh_term   = get_term_by( 'slug', 'feed-' . (int) $rmh_feed->id, PostTypes::TAX_SOURCE );
				$rmh_link   = $rmh_term ? add_query_arg( 'source', $rmh_term->slug, get_post_type_archive_link( PostTypes::POST_TYPE ) ) : '';
				$rmh_health = in_array( $rmh_feed->health, array( 'ok', 'new' ), true ) ? 'ok' : ( 'warning' === $rmh_feed->health ? 'warning' : 'error' );
				$rmh_labels = array(
					'ok'      => __( 'Updating normally', 'rss-media-hub' ),
					'warning' => __( 'Updating with warnings', 'rss-media-hub' ),
					'error'   => __( 'Temporarily not updating', 'rss-media-hub' ),
				);
				?>
				<li class="rmh-source">
					<h2 class="rmh-source-name">
						<?php if ( $rmh_link ) : ?>
							<a href="<?php echo esc_url( $rmh_link ); ?>"><?php echo esc_html( $rmh_feed->name ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $rmh_feed->name ); ?>
						<?php endif; ?>
					</h2>
					<p class="rmh-source-meta">
						<span class="rmh-health rmh-health--<?php echo esc_attr( $rmh_health ); ?>"><?php echo esc_html( $rmh_labels[ $rmh_health ] ); ?></span>
						<?php if ( $rmh_term ) : ?>
							<span><?php echo esc_html( sprintf( /* translators: %s: count */ _n( '%s post', '%s posts', (int) $rmh_term->count, 'rss-media-hub' ), number_format_i18n( (int) $rmh_term->count ) ) ); ?></span>
						<?php endif; ?>
						<?php if ( $rmh_feed->last_success_at ) : ?>
							<span>
								<?php
								/* translators: %s: human time difference */
								echo esc_html( sprintf( __( 'Updated %s ago', 'rss-media-hub' ), human_time_diff( strtotime( $rmh_feed->last_success_at . ' UTC' ) ) ) );
								?>
							</span>
						<?php endif; ?>
						<?php if ( $rmh_feed->site_url ) : ?>
							<a href="<?php echo esc_url( $rmh_feed->site_url ); ?>" rel="nofollow noopener" target="_blank"><?php esc_html_e( 'Website', 'rss-media-hub' ); ?></a>
						<?php endif; ?>
					</p>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</main>
<?php
Frontend::close_page();

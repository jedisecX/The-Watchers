<?php
/**
 * Reader body (filters, view toggle, cards, pagination, load more).
 *
 * Variables: $args['query'] (WP_Query), $args['filters'], $args['title'], $args['base_url'].
 *
 * @package RSSMediaHub
 */

use RMH\Frontend\Template;
use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

$rmh_q       = $args['query'];
$rmh_f       = $args['filters'];
$rmh_view    = $rmh_f['view'];
$rmh_sources = get_terms(
	array(
		'taxonomy'   => PostTypes::TAX_SOURCE,
		'hide_empty' => true,
		'number'     => 200,
	)
);
$rmh_cats    = get_terms(
	array(
		'taxonomy'   => PostTypes::TAX_CATEGORY,
		'hide_empty' => true,
		'number'     => 200,
	)
);
$rmh_types   = PostTypes::media_type_labels();
$rmh_sorts   = array(
	'newest' => __( 'Newest', 'rss-media-hub' ),
	'oldest' => __( 'Oldest', 'rss-media-hub' ),
	'title'  => __( 'Title (A–Z)', 'rss-media-hub' ),
	'source' => __( 'Source publication date', 'rss-media-hub' ),
);
if ( '' !== $rmh_f['q'] ) {
	$rmh_sorts['relevance'] = __( 'Relevance', 'rss-media-hub' );
}
?>
<main id="rmh-main" class="rmh rmh-reader">
	<header class="rmh-reader-header">
		<h1 class="rmh-title"><?php echo esc_html( wp_strip_all_tags( $args['title'] ) ); ?></h1>
		<p class="rmh-reader-links"><a href="<?php echo esc_url( home_url( '/' . rmh_permalink_base() . '/sources/' ) ); ?>"><?php esc_html_e( 'Browse sources', 'rss-media-hub' ); ?></a></p>
	</header>

	<form class="rmh-filters" method="get" action="<?php echo esc_url( $args['base_url'] ); ?>" role="search" aria-label="<?php esc_attr_e( 'Filter media', 'rss-media-hub' ); ?>">
		<p class="rmh-filter rmh-filter--search">
			<label for="rmh-q"><?php esc_html_e( 'Search', 'rss-media-hub' ); ?></label>
			<input id="rmh-q" type="search" name="q" value="<?php echo esc_attr( $rmh_f['q'] ); ?>" maxlength="100">
		</p>
		<p class="rmh-filter">
			<label for="rmh-mtype"><?php esc_html_e( 'Type', 'rss-media-hub' ); ?></label>
			<select id="rmh-mtype" name="mtype">
				<option value=""><?php esc_html_e( 'All types', 'rss-media-hub' ); ?></option>
				<?php foreach ( $rmh_types as $rmh_k => $rmh_l ) : ?>
					<option value="<?php echo esc_attr( $rmh_k ); ?>" <?php selected( $rmh_f['mtype'], $rmh_k ); ?>><?php echo esc_html( $rmh_l ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( $rmh_sources && ! is_wp_error( $rmh_sources ) ) : ?>
			<p class="rmh-filter">
				<label for="rmh-source"><?php esc_html_e( 'Source', 'rss-media-hub' ); ?></label>
				<select id="rmh-source" name="source">
					<option value=""><?php esc_html_e( 'All sources', 'rss-media-hub' ); ?></option>
					<?php foreach ( $rmh_sources as $rmh_t ) : ?>
						<option value="<?php echo esc_attr( $rmh_t->slug ); ?>" <?php selected( $rmh_f['source'], $rmh_t->slug ); ?>><?php echo esc_html( $rmh_t->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>
		<?php if ( $rmh_cats && ! is_wp_error( $rmh_cats ) ) : ?>
			<p class="rmh-filter">
				<label for="rmh-mcat"><?php esc_html_e( 'Category', 'rss-media-hub' ); ?></label>
				<select id="rmh-mcat" name="mcat">
					<option value=""><?php esc_html_e( 'All categories', 'rss-media-hub' ); ?></option>
					<?php foreach ( $rmh_cats as $rmh_t ) : ?>
						<option value="<?php echo esc_attr( $rmh_t->slug ); ?>" <?php selected( $rmh_f['mcat'], $rmh_t->slug ); ?>><?php echo esc_html( $rmh_t->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>
		<p class="rmh-filter">
			<label for="rmh-mtag"><?php esc_html_e( 'Tag', 'rss-media-hub' ); ?></label>
			<input id="rmh-mtag" type="text" name="mtag" value="<?php echo esc_attr( $rmh_f['mtag'] ); ?>" maxlength="60">
		</p>
		<p class="rmh-filter">
			<label for="rmh-sort"><?php esc_html_e( 'Sort', 'rss-media-hub' ); ?></label>
			<select id="rmh-sort" name="sort">
				<?php foreach ( $rmh_sorts as $rmh_k => $rmh_l ) : ?>
					<option value="<?php echo esc_attr( $rmh_k ); ?>" <?php selected( $rmh_f['sort'], $rmh_k ); ?>><?php echo esc_html( $rmh_l ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<input type="hidden" name="view" value="<?php echo esc_attr( $rmh_view ); ?>">
		<p class="rmh-filter rmh-filter--actions">
			<button type="submit" class="rmh-button"><?php esc_html_e( 'Apply', 'rss-media-hub' ); ?></button>
			<a class="rmh-reset" href="<?php echo esc_url( $args['base_url'] ); ?>"><?php esc_html_e( 'Reset', 'rss-media-hub' ); ?></a>
		</p>
	</form>

	<div class="rmh-toolbar">
		<p class="rmh-count" role="status">
			<?php
			/* translators: %s: number of results */
			echo esc_html( sprintf( _n( '%s result', '%s results', (int) $rmh_q->found_posts, 'rss-media-hub' ), number_format_i18n( (int) $rmh_q->found_posts ) ) );
			?>
		</p>
		<nav class="rmh-viewtoggle" aria-label="<?php esc_attr_e( 'View', 'rss-media-hub' ); ?>">
			<a href="<?php echo esc_url( add_query_arg( 'view', 'grid' ) ); ?>" <?php echo 'grid' === $rmh_view ? 'aria-current="true"' : ''; ?>><?php esc_html_e( 'Grid', 'rss-media-hub' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'list' ) ); ?>" <?php echo 'list' === $rmh_view ? 'aria-current="true"' : ''; ?>><?php esc_html_e( 'Reader', 'rss-media-hub' ); ?></a>
		</nav>
	</div>

	<?php if ( $rmh_q->have_posts() ) : ?>
		<div class="rmh-grid rmh-grid--<?php echo esc_attr( $rmh_view ); ?>" data-rmh-results>
			<?php
			foreach ( $rmh_q->posts as $rmh_post ) {
				echo Template::card( $rmh_post, $rmh_view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template part.
			}
			?>
		</div>
		<?php
		$rmh_total   = (int) $rmh_q->max_num_pages;
		$rmh_current = max( 1, (int) $rmh_q->get( 'paged' ) );
		if ( $rmh_total > 1 ) :
			?>
			<nav class="rmh-pagination" aria-label="<?php esc_attr_e( 'Pages', 'rss-media-hub' ); ?>">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'total'     => $rmh_total,
							'current'   => $rmh_current,
							'mid_size'  => 1,
							'prev_text' => __( '&larr; Newer', 'rss-media-hub' ),
							'next_text' => __( 'Older &rarr;', 'rss-media-hub' ),
						)
					)
				);
				?>
			</nav>
			<?php if ( $rmh_current < $rmh_total ) : ?>
				<p class="rmh-loadmore-wrap">
					<button type="button" class="rmh-button rmh-loadmore" data-next="<?php echo esc_url( get_pagenum_link( $rmh_current + 1 ) ); ?>" data-total="<?php echo (int) $rmh_total; ?>" data-current="<?php echo (int) $rmh_current; ?>" hidden>
						<?php esc_html_e( 'Load more', 'rss-media-hub' ); ?>
					</button>
					<span class="rmh-form-status" role="status" aria-live="polite"></span>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	<?php else : ?>
		<div class="rmh-empty" role="status">
			<h2><?php esc_html_e( 'Nothing here yet', 'rss-media-hub' ); ?></h2>
			<p>
				<?php
				if ( $rmh_f['active'] ) {
					esc_html_e( 'No media matches these filters. Try removing a filter or searching for something else.', 'rss-media-hub' );
				} else {
					esc_html_e( 'No media has been published yet. New items appear here automatically as feeds are imported.', 'rss-media-hub' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>
</main>

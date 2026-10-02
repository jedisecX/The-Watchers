<?php
/**
 * Reader query building (search, filters, sort, pagination).
 *
 * @package RSSMediaHub
 */

namespace RMH\Frontend;

use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Translates validated GET parameters into WP_Query arguments.
 * Parameter names (q, mtype, source, mcat, mtag, sort, view) deliberately avoid
 * WordPress reserved query vars.
 */
final class Reader {

	const SORTS = array( 'newest', 'oldest', 'title', 'source', 'relevance' );

	/**
	 * Validated filters from the request.
	 *
	 * @param array $defaults Defaults (e.g. shortcode attributes).
	 * @return array
	 */
	public static function current_filters( array $defaults = array() ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only public filters.
		$get = static function ( $key ) use ( $defaults ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
			return isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
		};
		// phpcs:enable

		$types = PostTypes::media_type_labels();
		$f     = array(
			'q'      => mb_substr( $get( 'q' ), 0, 100 ),
			'mtype'  => sanitize_key( $get( 'mtype' ) ),
			'source' => sanitize_title( $get( 'source' ) ),
			'mcat'   => sanitize_title( $get( 'mcat' ) ),
			'mtag'   => sanitize_title( $get( 'mtag' ) ),
			'sort'   => sanitize_key( $get( 'sort' ) ),
			'view'   => sanitize_key( $get( 'view' ) ),
		);
		if ( ! isset( $types[ $f['mtype'] ] ) ) {
			$f['mtype'] = '';
		}
		if ( ! in_array( $f['sort'], self::SORTS, true ) ) {
			$f['sort'] = '' !== $f['q'] ? 'relevance' : 'newest';
		}
		if ( 'relevance' === $f['sort'] && '' === $f['q'] ) {
			$f['sort'] = 'newest';
		}
		if ( ! in_array( $f['view'], array( 'grid', 'list' ), true ) ) {
			$f['view'] = (string) rmh()->get( 'settings' )->get( 'default_view' );
		}
		$f['active'] = '' !== $f['q'] || '' !== $f['mtype'] || '' !== $f['source'] || '' !== $f['mcat'] || '' !== $f['mtag'];
		return $f;
	}

	/**
	 * Apply filters to a query.
	 *
	 * @param \WP_Query $q       Query.
	 * @param array     $filters Filters.
	 */
	public static function apply( \WP_Query $q, array $filters ) {
		$q->set( 'post_type', PostTypes::POST_TYPE );
		$q->set( 'post_status', 'publish' );
		$q->set( 'posts_per_page', (int) rmh()->get( 'settings' )->get( 'per_page' ) );
		$q->set( 'ignore_sticky_posts', true );

		$tax = (array) $q->get( 'tax_query' );
		$map = array(
			'mtype'  => PostTypes::TAX_TYPE,
			'source' => PostTypes::TAX_SOURCE,
			'mcat'   => PostTypes::TAX_CATEGORY,
			'mtag'   => PostTypes::TAX_TAG,
		);
		foreach ( $map as $key => $taxonomy ) {
			if ( '' !== $filters[ $key ] ) {
				$tax[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => array( $filters[ $key ] ),
				);
			}
		}
		if ( count( $tax ) > 0 ) {
			$tax['relation'] = 'AND';
			$q->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_tax_query
		}
		if ( '' !== $filters['q'] ) {
			$q->set( 's', $filters['q'] );
		}
		switch ( $filters['sort'] ) {
			case 'oldest':
				$q->set(
					'orderby',
					array(
						'date' => 'ASC',
						'ID'   => 'ASC',
					)
				);
				break;
			case 'title':
				$q->set(
					'orderby',
					array(
						'title' => 'ASC',
						'ID'    => 'ASC',
					)
				);
				break;
			case 'source':
				$q->set( 'meta_key', '_rmh_source_published' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				$q->set(
					'orderby',
					array(
						'meta_value_num' => 'DESC',
						'date'           => 'DESC',
					)
				);
				break;
			case 'relevance':
				$q->set( 'orderby', 'relevance' );
				break;
			default:
				$q->set(
					'orderby',
					array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					)
				);
		}
	}

	/**
	 * Render the reader for a query.
	 *
	 * @param \WP_Query $query    Query.
	 * @param array     $filters  Filters.
	 * @param string    $title    Heading.
	 * @param string    $base_url Form action.
	 * @return string
	 */
	public static function render( \WP_Query $query, array $filters, $title, $base_url = '' ) {
		if ( '' === $base_url ) {
			$base_url = is_tax() || is_post_type_archive() ? get_pagenum_link( 1, false ) : get_post_type_archive_link( PostTypes::POST_TYPE );
			$base_url = remove_query_arg( array( 'q', 'mtype', 'source', 'mcat', 'mtag', 'sort', 'view', 'paged' ), $base_url );
		}
		return Template::render(
			'parts/reader',
			array(
				'query'    => $query,
				'filters'  => $filters,
				'title'    => '' !== (string) $title ? $title : __( 'Media', 'rss-media-hub' ),
				'base_url' => $base_url,
			)
		);
	}

	/**
	 * [rmh_reader] shortcode.
	 *
	 * @param array $atts Attributes: per_page, view, type, source, category, tag, sort.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts    = shortcode_atts(
			array(
				'per_page' => 0,
				'view'     => '',
				'type'     => '',
				'source'   => '',
				'category' => '',
				'tag'      => '',
				'sort'     => '',
				'title'    => '',
			),
			$atts,
			'rmh_reader'
		);
		$filters = self::current_filters(
			array(
				'view'   => $atts['view'],
				'mtype'  => $atts['type'],
				'source' => $atts['source'],
				'mcat'   => $atts['category'],
				'mtag'   => $atts['tag'],
				'sort'   => $atts['sort'],
			)
		);
		$q       = new \WP_Query();
		self::apply( $q, $filters );
		if ( (int) $atts['per_page'] > 0 ) {
			$q->set( 'posts_per_page', min( 100, (int) $atts['per_page'] ) );
		}
		$q->set( 'paged', max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) ) );
		$q->get_posts();
		return self::render( $q, $filters, '' !== $atts['title'] ? sanitize_text_field( $atts['title'] ) : __( 'Latest media', 'rss-media-hub' ), get_permalink() );
	}
}

<?php
/**
 * Media reader: archive, taxonomy and search listings with filters.
 *
 * Copy to yourtheme/rss-media-hub/archive.php to customize.
 *
 * @package RSSMediaHub
 */

use RMH\Frontend\Frontend;
use RMH\Frontend\Reader;

defined( 'ABSPATH' ) || exit;

Frontend::open_page();
add_filter( 'get_the_archive_title_prefix', '__return_empty_string' );
$rmh_title = is_post_type_archive() || Frontend::home_is_reader() ? __( 'Latest media', 'rss-media-hub' ) : get_the_archive_title();
echo Reader::render( $GLOBALS['wp_query'], Reader::current_filters(), $rmh_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template part.
Frontend::close_page();

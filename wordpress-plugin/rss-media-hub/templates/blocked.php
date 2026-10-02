<?php
/**
 * Unavailable in the visitor's jurisdiction (HTTP 451).
 *
 * @package RSSMediaHub
 */

use RMH\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

Frontend::open_page();
?>
<main id="rmh-main" class="rmh rmh-blocked">
	<h1><?php esc_html_e( 'Not available in your region', 'rss-media-hub' ); ?></h1>
	<p><?php esc_html_e( 'This content is not available in your country or region.', 'rss-media-hub' ); ?></p>
</main>
<?php
Frontend::close_page();

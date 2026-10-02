<?php
/**
 * Plugin Name:       RSS Media Hub
 * Plugin URI:        https://github.com/jedisecX/The-Watchers
 * Description:       Secure RSS/Atom media aggregator and embedded media reader. Discovers feed items, extracts video, audio, images and trusted embeds, and publishes each item on its own page with attribution, moderation and age-gate controls.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            The Watchers
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rss-media-hub
 * Domain Path:       /languages
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;

define( 'RMH_VERSION', '1.0.0' );
define( 'RMH_DB_VERSION', '1' );
define( 'RMH_FILE', __FILE__ );
define( 'RMH_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMH_URL', plugin_dir_url( __FILE__ ) );

require_once RMH_DIR . 'includes/Autoloader.php';
\RMH\Autoloader::register();

register_activation_hook( __FILE__, array( '\RMH\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\RMH\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\RMH\Plugin', 'boot' ) );

/**
 * Global accessor for the plugin service container.
 *
 * @return \RMH\Plugin
 */
function rmh() {
	return \RMH\Plugin::instance();
}

/**
 * URL base for imported media (default "media" => /media/{slug}/).
 *
 * @return string
 */
function rmh_permalink_base() {
	$base = sanitize_title( (string) apply_filters( 'rmh_permalink_base', 'media' ) );
	return '' !== $base ? $base : 'media';
}

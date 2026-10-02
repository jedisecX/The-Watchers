<?php
/**
 * PHPUnit bootstrap: loads a real, disposable WordPress install that has the
 * plugin active (see tests/bin/install-wp-tests.sh). Tests mutate its database.
 *
 * Required environment:
 *   RMH_TEST_WP_PATH  Absolute path to the WordPress root (contains wp-load.php).
 *
 * All HTTP is served by tests/Support/MockTransport: no test contacts a real host.
 *
 * @package RSSMediaHub
 */

$rmh_wp = getenv( 'RMH_TEST_WP_PATH' );
if ( ! $rmh_wp || ! is_file( rtrim( $rmh_wp, '/' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set RMH_TEST_WP_PATH to a disposable WordPress install (tests/bin/install-wp-tests.sh creates one).\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
define( 'RMH_TESTING', true );
$_SERVER['HTTP_HOST']       = getenv( 'RMH_TEST_HOST' ) ? getenv( 'RMH_TEST_HOST' ) : 'localhost:8089';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '203.0.113.10';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

require rtrim( $rmh_wp, '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! function_exists( 'rmh' ) ) {
	fwrite( STDERR, "The rss-media-hub plugin is not active in RMH_TEST_WP_PATH.\n" );
	exit( 1 );
}

require __DIR__ . '/Support/WPDieException.php';
require __DIR__ . '/Support/MockTransport.php';
require __DIR__ . '/Support/MockResolver.php';
require __DIR__ . '/Support/TestClock.php';
require __DIR__ . '/Support/TestCase.php';

// Turn wp_die() into an exception so authorization failures are assertable.
add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message, $title = '', $args = array() ) {
			$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500;
			throw new RMH\Tests\WPDieException( is_string( $message ) ? $message : ( is_wp_error( $message ) ? $message->get_error_message() : 'wp_die' ), $status );
		};
	}
);
add_filter( 'wp_die_ajax_handler', static function () { return apply_filters( 'wp_die_handler', '' ); } );
add_filter( 'wp_die_json_handler', static function () { return apply_filters( 'wp_die_handler', '' ); } );
add_filter( 'rmh_exit_after_redirect', '__return_false' );
add_filter( 'rmh_exit_after_gate', '__return_false' );
add_filter( 'wp_redirect', static function ( $location ) { $GLOBALS['rmh_test_redirect'] = $location; return $location; }, 999 );
add_filter( 'pre_wp_mail', static function ( $null, $atts ) { $GLOBALS['rmh_test_mails'][] = $atts; return true; }, 10, 2 );

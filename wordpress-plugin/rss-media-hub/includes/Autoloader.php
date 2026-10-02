<?php
/**
 * PSR-4 style autoloader for the RMH namespace.
 *
 * @package RSSMediaHub
 */

namespace RMH;

defined( 'ABSPATH' ) || exit;

/**
 * Maps RMH\Sub\ClassName to includes/Sub/ClassName.php.
 */
final class Autoloader {

	/**
	 * Register with SPL.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, 'RMH\\' ) ) {
			return;
		}
		$relative = substr( $class_name, 4 );
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}

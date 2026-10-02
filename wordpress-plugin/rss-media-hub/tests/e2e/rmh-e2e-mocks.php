<?php
/**
 * Plugin Name: RSS Media Hub – E2E network mocks (TEST ONLY)
 * Description: Serves tests/fixtures instead of real feeds. Copy into wp-content/mu-plugins of a
 *              disposable test site only. Never install on a production site.
 *
 * @package RSSMediaHub
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rmh_loaded',
	static function () {
		$fixtures = getenv( 'RMH_FIXTURES' ) ? getenv( 'RMH_FIXTURES' ) : dirname( __DIR__ ) . '/plugins/rss-media-hub/tests/fixtures';
		$routes   = array(
			'https://mrss.example/feed'   => 'mrss-video.xml',
			'https://embeds.example/feed' => 'rss-iframe.xml',
			'https://photos.example/feed' => 'rss-images.xml',
			'https://audio.example/feed'  => 'rss-audio.xml',
		);
		$transport = new class( $fixtures, $routes ) implements \RMH\Http\TransportInterface {
			/** @var string */
			private $dir;
			/** @var array */
			private $routes;
			public function __construct( $dir, $routes ) {
				$this->dir    = $dir;
				$this->routes = $routes;
			}
			public function request( $url, array $args, $pinned_ip ) {
				if ( isset( $this->routes[ $url ] ) ) {
					return array(
						'status'  => 200,
						'headers' => array( 'content-type' => 'application/rss+xml' ),
						'body'    => (string) file_get_contents( $this->dir . '/' . $this->routes[ $url ] ), // phpcs:ignore
					);
				}
				if ( preg_match( '/\.(mp4|webm)$/', $url ) ) {
					return array( 'status' => 200, 'headers' => array( 'content-type' => 'video/' . pathinfo( $url, PATHINFO_EXTENSION ) ), 'body' => '' );
				}
				return new \WP_Error( 'e2e_unmocked', 'Unmocked URL in E2E: ' . $url );
			}
		};
		$resolver = new class() implements \RMH\Http\ResolverInterface {
			public function resolve( $host ) {
				return array( '93.184.215.14' );
			}
		};
		$plugin = rmh();
		$plugin->set( 'transport', $transport );
		$plugin->set( 'resolver', $resolver );
	}
);

<?php
/**
 * Base test case: isolates plugin state and wires mocks into the container.
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests;

use RMH\Installer;
use RMH\PostTypes;
use RMH\Settings;

/**
 * Every test starts with empty plugin tables, no imported posts, default
 * settings, a mocked transport/resolver and a non-sleeping clock.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/**
	 * Mock transport.
	 *
	 * @var MockTransport
	 */
	protected $http;

	/**
	 * Mock resolver.
	 *
	 * @var MockResolver
	 */
	protected $dns;

	/**
	 * Test clock.
	 *
	 * @var TestClock
	 */
	protected $clock;

	/**
	 * Filters added by a test (removed in tearDown).
	 *
	 * @var array
	 */
	private $added = array();

	/**
	 * Reset state.
	 */
	protected function setUp(): void {
		global $wpdb;
		parent::setUp();
		wp_set_current_user( 0 );

		foreach ( Installer::TABLES as $t ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" ); // phpcs:ignore
		}
		$ids = get_posts(
			array(
				'post_type'      => array( PostTypes::POST_TYPE, 'attachment' ),
				'post_status'    => array_values( get_post_stati() ), // 'any' would skip trash.
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( array( PostTypes::TAX_CATEGORY, PostTypes::TAX_TAG, PostTypes::TAX_SOURCE ) as $tax ) {
			foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) {
				wp_delete_term( $term_id, $tax );
			}
		}
		PostTypes::ensure_media_type_terms();
		update_option( Settings::OPTION, Settings::defaults() );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rmh\_lock\_%' OR option_name LIKE '\_transient\_rmh\_%' OR option_name LIKE '\_transient\_timeout\_rmh\_%'" ); // phpcs:ignore
		wp_cache_flush();

		rmh()->reset();
		$this->http  = new MockTransport();
		$this->dns   = new MockResolver();
		$this->clock = new TestClock();
		rmh()->set( 'transport', $this->http );
		rmh()->set( 'resolver', $this->dns );
		rmh()->set( 'clock', $this->clock );

		\RMH\Frontend\CacheIntegration::$purged = array();
		$GLOBALS['rmh_test_redirect']          = null;
		$GLOBALS['rmh_test_mails']             = array();
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		$_COOKIE  = array();
	}

	/**
	 * Remove test filters.
	 */
	protected function tearDown(): void {
		foreach ( $this->added as $f ) {
			remove_filter( $f[0], $f[1], $f[2] );
		}
		$this->added = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Add a filter/action that is removed after the test.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 */
	protected function hook( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->added[] = array( $hook, $callback, $priority );
	}

	/**
	 * Fixture contents.
	 *
	 * @param string $name File name in tests/fixtures.
	 * @return string
	 */
	protected function fixture( $name ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Serve a fixture at a URL.
	 *
	 * @param string $url     URL.
	 * @param string $fixture Fixture name.
	 * @param array  $headers Headers.
	 */
	protected function serve( $url, $fixture, array $headers = array() ) {
		$this->http->on(
			$url,
			array(
				'status'  => 200,
				'headers' => array_merge( array( 'content-type' => 'application/rss+xml; charset=utf-8' ), $headers ),
				'body'    => $this->fixture( $fixture ),
			)
		);
	}

	/**
	 * Update settings.
	 *
	 * @param array $changes Changes.
	 */
	protected function settings( array $changes ) {
		rmh()->get( 'settings' )->update( $changes );
		rmh()->get( 'embeds' )->flush();
	}

	/**
	 * Create a feed.
	 *
	 * @param string $url    URL.
	 * @param array  $config Per-feed overrides.
	 * @return int
	 */
	protected function add_feed( $url, array $config = array() ) {
		$id = rmh()->get( 'feeds' )->create(
			array(
				'url'    => $url,
				'name'   => 'Test Feed',
				'config' => array_merge( array( 'import_mode' => 'auto' ), $config ),
			)
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * Run a feed import.
	 *
	 * @param int $feed_id Feed.
	 * @return array Result.
	 */
	protected function run_feed( $feed_id ) {
		return rmh()->get( 'importer' )->run_feed( $feed_id, 'test', microtime( true ) + 60 );
	}

	/**
	 * Imported posts (any status), oldest first.
	 *
	 * @param string $status Status.
	 * @return \WP_Post[]
	 */
	protected function posts( $status = 'any' ) {
		return get_posts(
			array(
				'post_type'      => PostTypes::POST_TYPE,
				'post_status'    => $status,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Log a user in with a role.
	 *
	 * @param string $role Role.
	 * @return int User id.
	 */
	protected function login( $role ) {
		$login = 'rmh_' . $role;
		$user  = get_user_by( 'login', $login );
		$id    = $user ? $user->ID : wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.com',
				'role'       => $role,
			)
		);
		wp_set_current_user( $id );
		return (int) $id;
	}

	/**
	 * Render the full single template for a post (theme header/footer included).
	 *
	 * @param int $post_id Post.
	 * @return string HTML.
	 */
	protected function render_single( $post_id ) {
		global $wp_query, $wp_the_query, $post;
		$wp_query     = new \WP_Query(
			array(
				'p'           => $post_id,
				'post_type'   => PostTypes::POST_TYPE,
				'post_status' => 'any',
			)
		);
		$wp_the_query = $wp_query;
		$wp_query->is_singular = true;
		$wp_query->is_single   = true;
		$post                  = get_post( $post_id );
		// Each render is a fresh page: assets printed by an earlier render must print again.
		$GLOBALS['wp_styles']  = null;
		$GLOBALS['wp_scripts'] = null;
		ob_start();
		include \RMH\Frontend\Template::locate( 'single' );
		$html = (string) ob_get_clean();
		wp_reset_postdata();
		return $html;
	}

	/**
	 * Media rows for a post.
	 *
	 * @param int $post_id Post.
	 * @return object[]
	 */
	protected function media_for( $post_id ) {
		return rmh()->get( 'media' )->for_post( $post_id );
	}
}

<?php
/**
 * Authorization, CSRF and admin controls.
 * Scenarios 23, 24 and "every administration control performs its action".
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests\Integration;

use RMH\Admin\Actions;
use RMH\Tests\TestCase;
use RMH\Tests\WPDieException;

/**
 * Admin-post handlers and REST permissions.
 */
class AdminSecurityTest extends TestCase {

	/**
	 * Submit an admin-post action as the current user.
	 *
	 * @param string    $action Action.
	 * @param array     $fields POST fields.
	 * @param bool|null $nonce  true = valid nonce, false = none, null = forged.
	 * @return string|null Redirect location.
	 */
	private function post_action( $action, array $fields = array(), $nonce = true ) {
		$_POST = array_merge( array( 'action' => $action ), $fields );
		if ( true === $nonce ) {
			$_POST['_rmh_nonce'] = wp_create_nonce( $action );
		} elseif ( null === $nonce ) {
			$_POST['_rmh_nonce'] = 'deadbeef00';
		}
		$_REQUEST                     = $_POST;
		$_SERVER['REQUEST_METHOD']    = 'POST';
		$GLOBALS['rmh_test_redirect'] = null;
		( new Actions() )->dispatch();
		return $GLOBALS['rmh_test_redirect'];
	}

	public function test_scenario_23_unauthorized_users_are_refused_everywhere() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		foreach ( array( 'subscriber', 'author', 'contributor' ) as $role ) {
			$this->login( $role );
			foreach ( array_keys( Actions::map() ) as $action ) {
				try {
					$this->post_action( $action, array( 'id' => $feed, 'op' => 'delete', 'url' => 'https://x.test/' ) );
					$this->fail( "$role could run $action" );
				} catch ( WPDieException $e ) {
					$this->assertSame( 403, $e->getCode(), "$role / $action" );
				}
			}
		}
		$this->assertNotNull( rmh()->get( 'feeds' )->find( $feed ), 'nothing was deleted' );

		// Editors moderate but cannot manage feeds or settings.
		$this->login( 'editor' );
		try {
			$this->post_action( 'rmh_feed_action', array( 'id' => $feed, 'op' => 'delete' ) );
			$this->fail( 'editor deleted a feed' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertTrue( current_user_can( 'rmh_moderate' ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	public function test_scenario_23_rest_permission_matrix() {
		// Valid bodies, so that the permission check (not parameter validation) decides.
		$routes = array(
			array( 'GET', '/rmh/v1/status' ),
			array( 'GET', '/rmh/v1/feeds' ),
			array( 'POST', '/rmh/v1/feeds', array( 'url' => 'https://alpha.example/feed' ) ),
			array( 'POST', '/rmh/v1/feeds/test', array( 'url' => 'https://alpha.example/feed' ) ),
			array( 'GET', '/rmh/v1/items' ),
			array( 'POST', '/rmh/v1/items/1/approve' ),
			array( 'GET', '/rmh/v1/media' ),
			array( 'POST', '/rmh/v1/media/1/revalidate' ),
			array( 'POST', '/rmh/v1/jobs/retry-failed' ),
			array( 'GET', '/rmh/v1/blocklist' ),
			array( 'POST', '/rmh/v1/blocklist', array( 'type' => 'domain', 'value' => 'x.example' ) ),
			array( 'GET', '/rmh/v1/reports' ),
		);
		foreach ( array( 0 => 401, 'subscriber' => 403 ) as $who => $expected ) {
			if ( $who ) {
				$this->login( $who );
			} else {
				wp_set_current_user( 0 );
			}
			foreach ( $routes as $r ) {
				$req = new \WP_REST_Request( $r[0], $r[1] );
				if ( isset( $r[2] ) ) {
					$req->set_body_params( $r[2] );
				}
				$res = rest_do_request( $req );
				$this->assertSame( $expected, $res->get_status(), ( $who ? $who : 'anonymous' ) . ' ' . $r[0] . ' ' . $r[1] );
			}
		}
		$this->assertSame( 0, rmh()->get( 'feeds' )->query( array() )['total'], 'no unauthorized write happened' );
		// Editors: moderation routes yes, feed management no.
		$this->login( 'editor' );
		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'GET', '/rmh/v1/items' ) )->get_status() );
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'GET', '/rmh/v1/feeds' ) )->get_status() );
	}

	public function test_every_route_has_a_permission_callback() {
		foreach ( rest_get_server()->get_routes( 'rmh/v1' ) as $route => $handlers ) {
			if ( '/rmh/v1' === $route ) {
				continue; // Core's namespace index.
			}
			foreach ( $handlers as $h ) {
				$this->assertArrayHasKey( 'permission_callback', $h, $route );
				$this->assertNotNull( $h['permission_callback'], $route );
			}
		}
	}

	public function test_scenario_24_missing_or_invalid_nonce_is_rejected() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		$this->login( 'administrator' );
		foreach ( array( false, null ) as $nonce ) {
			try {
				$this->post_action( 'rmh_feed_action', array( 'id' => $feed, 'op' => 'delete' ), $nonce );
				$this->fail( 'CSRF accepted' );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode() );
			}
		}
		// Nonce for a different action is not accepted either.
		$_POST = array( 'action' => 'rmh_feed_action', 'id' => $feed, 'op' => 'delete', '_rmh_nonce' => wp_create_nonce( 'rmh_block_add' ) );
		$_REQUEST = $_POST;
		try {
			( new Actions() )->dispatch();
			$this->fail( 'cross-action nonce accepted' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertNotNull( rmh()->get( 'feeds' )->find( $feed ) );

		// REST cookie authentication: no X-WP-Nonce => request runs as anonymous (then 401 on our routes);
		// a forged nonce => rest_cookie_invalid_nonce.
		global $wp_rest_auth_cookie;
		$wp_rest_auth_cookie = true;
		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( 0, get_current_user_id(), 'cookie session without nonce is downgraded' );
		$this->assertSame( 401, rest_do_request( new \WP_REST_Request( 'GET', '/rmh/v1/feeds' ) )->get_status() );
		$this->login( 'administrator' );
		$_SERVER['HTTP_X_WP_NONCE'] = 'forged';
		$result                     = rest_cookie_check_errors( null );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cookie_invalid_nonce', $result->get_error_code() );
		$wp_rest_auth_cookie = false;
	}

	public function test_admin_controls_perform_their_actions() {
		$this->login( 'administrator' );

		// Add feed through the form handler.
		$this->post_action(
			'rmh_save_feed',
			array(
				'url'           => 'https://alpha.example/feed',
				'name'          => 'Alpha',
				'status'        => 'active',
				'poll_interval' => 'custom',
				'poll_minutes'  => '45',
				'config'        => array( 'import_mode' => 'manual', 'tags' => 'x, y', 'allowed_media_types' => array( 'video' ), 'extract_html' => '1' ),
			)
		);
		$feed = rmh()->get( 'feeds' )->find_by_url( 'https://alpha.example/feed' );
		$this->assertNotNull( $feed );
		$this->assertSame( 'Alpha', $feed->name );
		$this->assertSame( 2700, (int) $feed->poll_interval );
		$this->assertSame( 'manual', $feed->config['import_mode'] );
		$this->assertSame( array( 'x', 'y' ), $feed->config['tags'] );
		$this->assertFalse( $feed->config['allow_embeds'], 'unchecked checkbox saved as off' );

		// Invalid URL is rejected with an error notice.
		$this->post_action( 'rmh_save_feed', array( 'url' => 'file:///etc/passwd', 'status' => 'active' ) );
		$notices = get_transient( 'rmh_notices_' . get_current_user_id() );
		$this->assertSame( 'error', end( $notices )['type'] );

		// Test (dry run) stores a report and imports nothing.
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$this->post_action( 'rmh_feed_action', array( 'id' => $feed->id, 'op' => 'test' ) );
		$report = get_transient( 'rmh_test_' . get_current_user_id() );
		$this->assertTrue( $report['ok'] );
		$this->assertSame( 'video', $report['items'][0]['media_type'] );
		$this->assertSame( 0, rmh()->get( 'items' )->query( array() )['total'] );

		// Pause / resume.
		$this->post_action( 'rmh_feed_action', array( 'id' => $feed->id, 'op' => 'pause' ) );
		$this->assertSame( 'paused', rmh()->get( 'feeds' )->find( $feed->id )->status );
		$this->post_action( 'rmh_feed_action', array( 'id' => $feed->id, 'op' => 'resume' ) );
		$this->assertSame( 'active', rmh()->get( 'feeds' )->find( $feed->id )->status );

		// Run now queues a background event.
		$this->post_action( 'rmh_feed_action', array( 'id' => $feed->id, 'op' => 'run' ) );
		$this->assertNotFalse( wp_next_scheduled( 'rmh_run_feed', array( $feed->id ) ) );
		do_action( 'rmh_run_feed', $feed->id );
		wp_clear_scheduled_hook( 'rmh_run_feed', array( $feed->id ) );
		$item = rmh()->get( 'items' )->query( array( 'status' => array( 'queued' ) ) )['rows'][0];

		// Queue: approve publishes.
		$this->post_action( 'rmh_item_action', array( 'id' => $item->id, 'op' => 'approve' ) );
		$this->assertCount( 1, $this->posts( 'publish' ) );
		$post_id = $this->posts( 'publish' )[0]->ID;

		// Media library: recheck + block.
		$media = $this->media_for( $post_id )[0];
		$this->http->on( $media->url, array( 'status' => 404 ) );
		$this->post_action( 'rmh_media_action', array( 'id' => $media->id, 'op' => 'recheck' ) );
		$this->assertSame( 'unavailable', rmh()->get( 'media' )->find( $media->id )->validation_status );
		$this->post_action( 'rmh_media_action', array( 'id' => $media->id, 'op' => 'block' ) );
		$this->assertTrue( rmh()->get( 'blocklist' )->is_url_blocked( $media->url ) );

		// Restrictions: add + remove.
		$this->post_action( 'rmh_block_add', array( 'type' => 'domain', 'value' => 'https://bad.example/path', 'reason' => 'test' ) );
		$this->assertTrue( rmh()->get( 'blocklist' )->is_domain_blocked( 'cdn.bad.example' ) );
		$entry = rmh()->get( 'blocklist' )->all( 'domain' )[0];
		$this->post_action( 'rmh_block_remove', array( 'id' => $entry->id ) );
		$this->assertFalse( ( new \RMH\Db\Blocklist() )->is_domain_blocked( 'bad.example' ) );

		// Removal request by URL takes the post down and blocks re-import.
		$this->post_action( 'rmh_takedown_url', array( 'url' => 'https://alpha.example/clips/one', 'reason' => 'DMCA test' ) );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 'removed', rmh()->get( 'items' )->find( $item->id )->status );

		// Retry failed + run due + purge logs + release lock + maintenance.
		$this->post_action( 'rmh_retry_failed' );
		$this->post_action( 'rmh_run_due' );
		$this->assertNotFalse( wp_next_scheduled( 'rmh_tick_now' ) );
		wp_clear_scheduled_hook( 'rmh_tick_now' );
		$this->post_action( 'rmh_purge_logs' );
		$this->assertLessThanOrEqual( 1, rmh()->get( 'logger' )->query( array() )['total'] );
		( new \RMH\Cron\Lock( 'tick' ) )->acquire( 600 );
		$this->post_action( 'rmh_release_lock' );
		$this->assertNull( ( new \RMH\Cron\Lock( 'tick' ) )->status() );
		$this->post_action( 'rmh_run_maintenance' );

		// Test URL tool.
		$this->post_action( 'rmh_test_url', array( 'url' => 'https://alpha.example/feed' ) );
		$this->assertTrue( get_transient( 'rmh_test_' . get_current_user_id() )['ok'] );

		// Delete feed keeps posts.
		$this->post_action( 'rmh_feed_action', array( 'id' => $feed->id, 'op' => 'delete' ) );
		$this->assertNull( rmh()->get( 'feeds' )->find( $feed->id ) );
		$this->assertNotNull( get_post( $post_id ) );
	}

	public function test_settings_form_sanitization_handles_unchecked_boxes() {
		$this->login( 'administrator' );
		$this->settings( array( 'show_related' => true ) );
		$clean = \RMH\Admin\SettingsPage::sanitize( array( '__form' => '1', 'import_mode' => 'auto' ) );
		$this->assertFalse( $clean['show_related'] );
		$this->assertSame( array(), $clean['allowed_media_types'] );
		$this->assertSame( 'auto', $clean['import_mode'] );
	}

	public function test_rest_feed_lifecycle_as_admin() {
		$this->login( 'administrator' );
		$create = new \WP_REST_Request( 'POST', '/rmh/v1/feeds' );
		$create->set_body_params( array( 'url' => 'https://alpha.example/feed', 'name' => 'API feed', 'config' => array( 'auth_user' => 'u', 'auth_pass' => 'p' ) ) );
		$res = rest_do_request( $create );
		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data();
		$this->assertArrayNotHasKey( 'auth_pass', $data['config'] );
		$this->assertTrue( $data['config']['has_auth'] );

		$dupe = new \WP_REST_Request( 'POST', '/rmh/v1/feeds' );
		$dupe->set_body_params( array( 'url' => 'https://ALPHA.example:443/feed' ) );
		$this->assertSame( 409, rest_do_request( $dupe )->get_status(), 'normalized URL duplicate' );

		$bad = new \WP_REST_Request( 'POST', '/rmh/v1/feeds' );
		$bad->set_body_params( array( 'url' => 'gopher://x' ) );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );

		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$test = rest_do_request( new \WP_REST_Request( 'POST', '/rmh/v1/feeds/' . $data['id'] . '/test' ) );
		$this->assertTrue( $test->get_data()['ok'] );
		$this->assertSame( 'Basic ' . base64_encode( 'u:p' ), end( $this->http->requests )[1]['headers']['Authorization'] );

		$run = rest_do_request( new \WP_REST_Request( 'POST', '/rmh/v1/feeds/' . $data['id'] . '/run' ) );
		$this->assertSame( 202, $run->get_status() );
		wp_clear_scheduled_hook( 'rmh_run_feed', array( $data['id'] ) );

		$patch = new \WP_REST_Request( 'PATCH', '/rmh/v1/feeds/' . $data['id'] );
		$patch->set_body_params( array( 'status' => 'paused' ) );
		$this->assertSame( 'paused', rest_do_request( $patch )->get_data()['status'] );

		$this->run_feed( $data['id'] );
		$items_req = new \WP_REST_Request( 'GET', '/rmh/v1/items' );
		$items     = rest_do_request( $items_req );
		$this->assertSame( 1, (int) $items->get_headers()['X-WP-Total'] );
		// serve_request() applies rest_post_dispatch; rest_do_request() does not, so apply it as core would.
		$served = apply_filters( 'rest_post_dispatch', $items, rest_get_server(), $items_req );
		$this->assertSame( 'no-store, private', $served->get_headers()['Cache-Control'] );

		$media = rest_do_request( new \WP_REST_Request( 'GET', '/rmh/v1/media' ) )->get_data();
		$this->http->on( $media[0]['url'], array( 'status' => 200, 'headers' => array( 'Content-Type' => 'video/mp4' ) ) );
		$reval = rest_do_request( new \WP_REST_Request( 'POST', '/rmh/v1/media/' . $media[0]['id'] . '/revalidate' ) );
		$this->assertSame( 'valid', $reval->get_data()['validation_status'] );

		$block = new \WP_REST_Request( 'POST', '/rmh/v1/blocklist' );
		$block->set_body_params( array( 'type' => 'domain', 'value' => 'spam.example' ) );
		$this->assertSame( 201, rest_do_request( $block )->get_status() );

		$del = rest_do_request( new \WP_REST_Request( 'DELETE', '/rmh/v1/feeds/' . $data['id'] ) );
		$this->assertTrue( $del->get_data()['deleted'] );
		$this->assertCount( 1, $this->posts(), 'posts kept when the feed is removed' );
	}
}

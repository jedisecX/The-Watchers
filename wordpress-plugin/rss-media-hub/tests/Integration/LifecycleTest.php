<?php
/**
 * Upgrades, cache plugins, moderation and uninstall.
 * Scenarios 28, 29 + reporting/moderation workflow.
 *
 * @package RSSMediaHub
 */

namespace {
	if ( ! function_exists( 'wp_cache_post_change' ) ) {
		/**
		 * Stand-in for WP Super Cache's purge API (records calls).
		 *
		 * @param int $post_id Post.
		 */
		function wp_cache_post_change( $post_id ) {
			$GLOBALS['rmh_test_supercache'][] = (int) $post_id;
		}
	}
}

namespace RMH\Tests\Integration {

	use RMH\Compliance\Reports;
	use RMH\Installer;
	use RMH\Tests\TestCase;

	/**
	 * Lifecycle tests.
	 */
	class LifecycleTest extends TestCase {

		/**
		 * Import a couple of posts.
		 *
		 * @return int[] Post ids.
		 */
		private function seed() {
			$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
			$this->serve( 'https://audio.example/feed', 'rss-audio.xml' );
			$this->run_feed( $this->add_feed( 'https://alpha.example/feed' ) );
			$this->run_feed( $this->add_feed( 'https://audio.example/feed' ) );
			return wp_list_pluck( $this->posts(), 'ID' );
		}

		public function test_scenario_28_upgrade_preserves_imported_posts() {
			global $wpdb;
			$ids = $this->seed();
			wp_update_post( array( 'ID' => $ids[0], 'post_title' => 'Edited by editor' ) );
			$this->settings( array( 'import_mode' => 'manual', 'per_page' => 12 ) );
			$items_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rmh_items" );

			// Simulate an older schema: a missing table, a missing column and an old version marker.
			$wpdb->query( "DROP TABLE {$wpdb->prefix}rmh_blocklist" );
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}rmh_jobs DROP COLUMN skipped" );
			update_option( Installer::DB_OPTION, '0' );
			$this->assertNotContains( 'skipped', $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}rmh_jobs" ) );

			Installer::maybe_upgrade();

			$this->assertSame( RMH_DB_VERSION, get_option( Installer::DB_OPTION ) );
			$this->assertContains( 'skipped', $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}rmh_jobs" ) );
			$this->assertNotNull( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rmh_blocklist'" ) );
			$this->assertSame( $ids, wp_list_pluck( $this->posts(), 'ID' ), 'every imported post survived' );
			$this->assertSame( 'Edited by editor', get_post( $ids[0] )->post_title, 'editorial changes untouched' );
			$this->assertSame( $items_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rmh_items" ) );
			rmh()->get( 'settings' )->flush();
			$this->assertSame( 'manual', rmh()->get( 'settings' )->get( 'import_mode' ), 'settings preserved' );
			$this->assertSame( 12, rmh()->get( 'settings' )->get( 'per_page' ) );

			// Re-running install is idempotent; dedup history still prevents re-imports.
			Installer::install();
			$this->run_feed( rmh()->get( 'feeds' )->find_by_url( 'https://alpha.example/feed' )->id );
			$this->assertCount( 2, $this->posts() );

			// Deactivate + activate keeps everything and reschedules cron.
			Installer::deactivate();
			$this->assertFalse( wp_next_scheduled( 'rmh_tick' ) );
			Installer::activate( false );
			$this->assertNotFalse( wp_next_scheduled( 'rmh_tick' ) );
			$this->assertCount( 2, $this->posts() );
		}

		public function test_scenario_29_cache_plugins_are_purged_and_dynamic_responses_not_cached() {
			$GLOBALS['rmh_test_supercache'] = array();
			$ids                            = $this->seed();
			foreach ( $ids as $id ) {
				$this->assertContains( $id, $GLOBALS['rmh_test_supercache'], 'WP Super Cache purge API called on create' );
				$this->assertContains( 'wp-super-cache', \RMH\Frontend\CacheIntegration::$purged[ $id ] );
			}

			// Updates and moderation also purge.
			$GLOBALS['rmh_test_supercache'] = array();
			$this->serve( 'https://alpha.example/feed', 'rss-video-updated.xml' );
			$this->run_feed( rmh()->get( 'feeds' )->find_by_url( 'https://alpha.example/feed' )->id );
			$this->assertNotEmpty( $GLOBALS['rmh_test_supercache'] );

			$GLOBALS['rmh_test_supercache'] = array();
			Reports::hide_post( $ids[1], 'test' );
			$this->assertSame( array( $ids[1] ), array_values( array_unique( $GLOBALS['rmh_test_supercache'] ) ) );

			// Custom/edge cache hook receives affected URLs.
			$urls = null;
			$this->hook(
				'rmh_purge_post_cache',
				static function ( $post_id, $u ) use ( &$urls ) {
					$urls = $u;
				},
				10,
				2
			);
			Reports::restore_post( $ids[1] );
			$this->assertContains( get_permalink( $ids[1] ), $urls );

			$this->assertContains( 'WP Super Cache', \RMH\Admin\Diagnostics::cache_plugins() );
		}

		public function test_reports_and_moderation_workflow() {
			$ids = $this->seed();
			$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

			$this->assertSame( 'rmh_spam', Reports::submit( array( 'post_id' => $ids[0], 'reason' => 'other', 'website' => 'http://spam' ) )->get_error_code() );
			$this->assertSame( 'rmh_report_reason', Reports::submit( array( 'post_id' => $ids[0], 'reason' => 'bogus' ) )->get_error_code() );
			$this->assertSame( 'rmh_report_post', Reports::submit( array( 'post_id' => 999999, 'reason' => 'other' ) )->get_error_code() );

			// Broken media report schedules revalidation, never fetches synchronously.
			rmh()->get( 'media' )->update( $this->media_for( $ids[0] )[0]->id, array( 'checked_at' => '2026-01-01 00:00:00', 'validation_status' => 'valid' ) );
			$broken = Reports::submit( array( 'post_id' => $ids[0], 'reason' => 'broken_media' ) );
			$this->assertIsInt( $broken );
			$this->assertNull( $this->media_for( $ids[0] )[0]->checked_at );
			$this->assertCount( 0, array_filter( $this->http->requests, static function ( $r ) { return false !== strpos( $r[0], 'cdn.' ); } ) );
			$this->assertSame( 0, Reports::submit( array( 'post_id' => $ids[0], 'reason' => 'broken_media' ) ), 'duplicate report accepted silently' );

			// Urgent report: hidden immediately, escalated, moderator notified.
			$urgent = Reports::submit( array( 'post_id' => $ids[1], 'reason' => 'minor', 'details' => 'test' ) );
			$this->assertSame( 'draft', get_post_status( $ids[1] ) );
			$this->assertSame( 'escalated', rmh()->get( 'reports' )->find( $urgent )->status );
			$this->assertNotEmpty( $GLOBALS['rmh_test_mails'] );
			$this->assertStringContainsString( 'Do not delete', $GLOBALS['rmh_test_mails'][0]['message'] );
			$this->assertStringNotContainsString( '198.51.100.7', (string) rmh()->get( 'reports' )->find( $urgent )->reporter_hash, 'IP never stored' );

			// Dismissing restores an auto-hidden post; takedown removes and blocks re-import.
			$this->login( 'editor' );
			$this->assertTrue( Reports::moderate( $urgent, 'dismiss', 'false alarm' ) );
			$this->assertSame( 'publish', get_post_status( $ids[1] ) );
			$this->assertTrue( Reports::moderate( $broken, 'takedown' ) );
			$this->assertSame( 'draft', get_post_status( $ids[0] ) );
			$feed = rmh()->get( 'feeds' )->find_by_url( 'https://alpha.example/feed' )->id;
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->prefix}rmh_items" ); // Even without dedup history...
			$this->run_feed( $feed );
			$this->assertCount( 2, $this->posts(), '...the GUID blocklist prevents re-import' );

			// Block source: feed disabled, domain blocked.
			$r3 = Reports::submit( array( 'post_id' => $ids[1], 'reason' => 'copyright' ) );
			$this->assertTrue( Reports::moderate( $r3, 'block_source' ) );
			$this->assertSame( 'disabled', rmh()->get( 'feeds' )->find( (int) get_post_meta( $ids[1], '_rmh_feed_id', true ) )->status );
			$this->assertSame( 'draft', get_post_status( $ids[1] ) );
			$this->assertTrue( rmh()->get( 'blocklist' )->is_domain_blocked( 'audio.example' ) );
		}

		public function test_report_rate_limit() {
			$ids                    = $this->seed();
			$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
			$reasons                = array( 'other', 'mislabeled', 'copyright', 'illegal', 'broken_media' );
			foreach ( $reasons as $reason ) {
				$this->assertIsInt( Reports::submit( array( 'post_id' => $ids[0], 'reason' => $reason ) ) );
			}
			$this->assertSame( 'rmh_rate_limited', Reports::submit( array( 'post_id' => $ids[0], 'reason' => 'other' ) )->get_error_code() );
			$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		}

		public function test_public_report_endpoint() {
			$ids = $this->seed();
			$req = new \WP_REST_Request( 'POST', '/rmh/v1/reports' );
			$req->set_body_params( array( 'post_id' => $ids[0], 'reason' => 'mislabeled' ) );
			$this->assertSame( 202, rest_do_request( $req )->get_status() );
			$this->settings( array( 'reports_enabled' => false ) );
			$this->assertSame( 403, rest_do_request( $req )->get_status() );
		}

		public function test_view_counter_is_opt_in_and_anonymous() {
			$ids = $this->seed();
			$req = new \WP_REST_Request( 'POST', '/rmh/v1/views/' . $ids[0] );
			$this->assertSame( 404, rest_do_request( $req )->get_status(), 'disabled by default' );
			$this->settings( array( 'analytics_enabled' => true ) );
			$this->assertTrue( rest_do_request( $req )->get_data()['counted'] );
			$this->assertFalse( rest_do_request( $req )->get_data()['counted'], 'one count per visitor window' );
			$this->assertSame( '1', (string) get_post_meta( $ids[0], '_rmh_views', true ) );
		}

		public function test_uninstall_modes() {
			global $wpdb;
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'rss-media-hub/rss-media-hub.php' );
			}
			$ids = $this->seed();

			// keep_all (default): nothing removed.
			include RMH_DIR . 'uninstall.php';
			$this->assertNotNull( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rmh_items'" ) );
			$this->assertCount( 2, $this->posts() );

			// keep_content: plugin data removed, posts kept.
			$this->settings( array( 'uninstall_mode' => 'keep_content' ) );
			include RMH_DIR . 'uninstall.php';
			$this->assertNull( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rmh_items'" ) );
			$this->assertFalse( get_option( 'rmh_settings' ) );
			$this->assertCount( 2, $this->posts() );
			$this->assertFalse( get_role( 'administrator' )->has_cap( 'rmh_manage_feeds' ) );

			// Reinstall and test delete_all.
			Installer::install();
			Installer::add_caps();
			rmh()->reset();
			rmh()->set( 'transport', $this->http );
			rmh()->set( 'resolver', $this->dns );
			rmh()->set( 'clock', $this->clock );
			update_option( 'rmh_settings', array_merge( \RMH\Settings::defaults(), array( 'uninstall_mode' => 'delete_all' ) ) );
			include RMH_DIR . 'uninstall.php';
			$this->assertCount( 0, $this->posts() );

			// Restore for subsequent tests.
			Installer::install();
			Installer::add_caps();
			\RMH\Cron\Scheduler::ensure_scheduled();
		}
	}
}

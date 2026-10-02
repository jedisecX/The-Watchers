<?php
/**
 * admin-post.php handlers for every admin control.
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

use RMH\Compliance\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Each handler: verifies the nonce, checks the capability, validates input,
 * performs the action, stores a notice and redirects back.
 */
class Actions {

	/**
	 * action => [method, capability].
	 *
	 * @return array
	 */
	public static function map() {
		return array(
			'rmh_save_feed'       => array( 'save_feed', 'rmh_manage_feeds' ),
			'rmh_feed_action'     => array( 'feed_action', 'rmh_manage_feeds' ),
			'rmh_item_action'     => array( 'item_action', 'rmh_moderate' ),
			'rmh_item_bulk'       => array( 'item_bulk', 'rmh_moderate' ),
			'rmh_media_action'    => array( 'media_action', 'rmh_moderate' ),
			'rmh_media_bulk'      => array( 'media_bulk', 'rmh_moderate' ),
			'rmh_report_action'   => array( 'report_action', 'rmh_moderate' ),
			'rmh_block_add'       => array( 'block_add', 'rmh_manage_feeds' ),
			'rmh_block_remove'    => array( 'block_remove', 'rmh_manage_feeds' ),
			'rmh_takedown_url'    => array( 'takedown_url', 'rmh_moderate' ),
			'rmh_run_due'         => array( 'run_due', 'rmh_manage_feeds' ),
			'rmh_retry_failed'    => array( 'retry_failed', 'rmh_manage_feeds' ),
			'rmh_release_lock'    => array( 'release_lock', 'rmh_manage_feeds' ),
			'rmh_purge_logs'      => array( 'purge_logs', 'rmh_manage_feeds' ),
			'rmh_run_maintenance' => array( 'run_maintenance', 'rmh_manage_feeds' ),
			'rmh_test_url'        => array( 'test_url', 'rmh_manage_feeds' ),
		);
	}

	/**
	 * Hooks (logged-in only: no nopriv variants exist for admin actions).
	 */
	public function register_hooks() {
		foreach ( array_keys( self::map() ) as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'dispatch' ) );
		}
	}

	/**
	 * Route to the handler after nonce and capability checks.
	 */
	public function dispatch() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$map    = self::map();
		if ( ! isset( $map[ $action ] ) ) {
			wp_die( esc_html__( 'Unknown action.', 'rss-media-hub' ), '', array( 'response' => 400 ) );
		}
		list( $method, $cap ) = $map[ $action ];
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'rss-media-hub' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action, '_rmh_nonce' );
		$this->$method();
	}

	/**
	 * POST value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function post( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dispatch().
		return isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Redirect back.
	 *
	 * @param string $page Page.
	 * @param array  $args Args.
	 */
	private function back( $page, array $args = array() ) {
		$ref = wp_get_referer();
		$url = $ref && false !== strpos( $ref, 'page=rmh-' ) && ! $args ? $ref : Ui::url( $page, $args );
		wp_safe_redirect( $url );
		if ( apply_filters( 'rmh_exit_after_redirect', true ) ) {
			exit;
		}
	}

	/**
	 * Report a result as a notice.
	 *
	 * @param true|\WP_Error $result  Result.
	 * @param string         $success Success message.
	 */
	private function result( $result, $success ) {
		if ( is_wp_error( $result ) ) {
			Admin::flash( 'error', $result->get_error_message() );
		} else {
			Admin::flash( 'success', $success );
		}
	}

	/* ---------------------------------------------------------------- feeds */

	/**
	 * Create/update a feed from the editor form.
	 */
	private function save_feed() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in dispatch().
		$id  = absint( $this->post( 'id' ) );
		$raw = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by FeedConfig::sanitize().
		// phpcs:enable
		$config = array();
		foreach ( \RMH\Db\FeedConfig::schema() as $key => $def ) {
			if ( 'bool' === $def[0] ) {
				$config[ $key ] = ! empty( $raw[ $key ] );
			} elseif ( isset( $raw[ $key ] ) ) {
				$config[ $key ] = $raw[ $key ];
			} elseif ( in_array( $def[0], array( 'set', 'ids' ), true ) ) {
				$config[ $key ] = array();
			}
		}
		if ( ! empty( $raw['auth_pass_clear'] ) ) {
			$config['auth_pass_clear'] = 1;
		}
		$interval = $this->post( 'poll_interval' );
		if ( 'custom' === $interval ) {
			$interval = (string) ( 60 * absint( $this->post( 'poll_minutes' ) ) );
		}
		$data  = array(
			'url'           => $this->post( 'url' ),
			'name'          => $this->post( 'name' ),
			'site_url'      => $this->post( 'site_url' ),
			'status'        => $this->post( 'status' ),
			'poll_interval' => (int) $interval,
			'config'        => $config,
		);
		$feeds = rmh()->get( 'feeds' );
		if ( $id ) {
			$result = $feeds->update( $id, $data );
		} else {
			$result = $feeds->create( $data );
			if ( ! is_wp_error( $result ) ) {
				$id = (int) $result;
			}
		}
		if ( is_wp_error( $result ) ) {
			Admin::flash( 'error', $result->get_error_message() );
			set_transient( 'rmh_feed_form_' . get_current_user_id(), $data, 5 * MINUTE_IN_SECONDS );
			$this->back(
				'feeds',
				array(
					'action' => $id ? 'edit' : 'new',
					'id'     => $id,
				)
			);
			return;
		}
		rmh()->get( 'logger' )->info( 'import', sprintf( 'Feed #%d saved by user #%d.', $id, get_current_user_id() ), array( 'feed_id' => $id ) );
		if ( '1' === $this->post( 'test_after' ) ) {
			$this->run_test( $id );
			$this->back(
				'feeds',
				array(
					'action' => 'test',
					'id'     => $id,
				)
			);
			return;
		}
		Admin::flash( 'success', __( 'Feed saved.', 'rss-media-hub' ) );
		$this->back(
			'feeds',
			array(
				'action' => 'edit',
				'id'     => $id,
			)
		);
	}

	/**
	 * Run a dry-run test and store the report for display.
	 *
	 * @param int $id Feed.
	 */
	private function run_test( $id ) {
		$feed = rmh()->get( 'feeds' )->find( $id );
		if ( ! $feed ) {
			return;
		}
		$cfg              = $feed->config;
		$cfg['auth_pass'] = \RMH\Security\Secrets::decrypt( (string) $cfg['auth_pass'] );
		$report           = rmh()->get( 'importer' )->test_feed( $feed->url, $cfg );
		set_transient( 'rmh_test_' . get_current_user_id(), $report, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Row actions on a feed.
	 */
	private function feed_action() {
		$id   = absint( $this->post( 'id' ) );
		$op   = sanitize_key( $this->post( 'op' ) );
		$repo = rmh()->get( 'feeds' );
		if ( ! $repo->find( $id ) ) {
			Admin::flash( 'error', __( 'Feed not found.', 'rss-media-hub' ) );
			$this->back( 'feeds' );
			return;
		}
		switch ( $op ) {
			case 'test':
				$this->run_test( $id );
				$this->back(
					'feeds',
					array(
						'action' => 'test',
						'id'     => $id,
					)
				);
				return;
			case 'run':
				rmh()->get( 'scheduler' )->queue_feed( $id );
				Admin::flash( 'success', __( 'Import queued. It runs in the background within a minute (refresh to see results).', 'rss-media-hub' ) );
				break;
			case 'pause':
				$this->result( $repo->update( $id, array( 'status' => 'paused' ) ), __( 'Feed paused.', 'rss-media-hub' ) );
				break;
			case 'resume':
				$this->result( $repo->update( $id, array( 'status' => 'active' ) ), __( 'Feed resumed and scheduled now.', 'rss-media-hub' ) );
				break;
			case 'delete':
				$repo->delete( $id );
				Admin::flash( 'success', __( 'Feed removed. Its imported posts were kept.', 'rss-media-hub' ) );
				$this->back( 'feeds', array( 'deleted' => 1 ) );
				return;
			default:
				Admin::flash( 'error', __( 'Unknown feed action.', 'rss-media-hub' ) );
		}
		$this->back( 'feeds' );
	}

	/* ---------------------------------------------------------------- items */

	/**
	 * Single item action.
	 */
	private function item_action() {
		$id   = absint( $this->post( 'id' ) );
		$op   = sanitize_key( $this->post( 'op' ) );
		$msgs = array(
			'approve'   => __( 'Item approved and published.', 'rss-media-hub' ),
			'reject'    => __( 'Item rejected.', 'rss-media-hub' ),
			'retry'     => __( 'Item processed again.', 'rss-media-hub' ),
			'reprocess' => __( 'Media extraction re-run.', 'rss-media-hub' ),
			'remove'    => __( 'Item taken down and its GUID blocked from re-import.', 'rss-media-hub' ),
		);
		if ( ! isset( $msgs[ $op ] ) ) {
			Admin::flash( 'error', __( 'Unknown item action.', 'rss-media-hub' ) );
			$this->back( 'queue' );
			return;
		}
		$importer = rmh()->get( 'importer' );
		$result   = 'remove' === $op ? $importer->remove( $id, 'admin takedown' ) : $importer->$op( $id );
		$this->result( $result, $msgs[ $op ] );
		$this->back( 'queue' );
	}

	/**
	 * Bulk item action.
	 */
	private function item_bulk() {
		$op  = sanitize_key( $this->post( 'bulk' ) );
		$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_slice( array_map( 'absint', wp_unslash( $_POST['ids'] ) ), 0, 200 ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified/absint.
		if ( ! in_array( $op, array( 'approve', 'reject', 'retry' ), true ) || ! $ids ) {
			Admin::flash( 'warning', __( 'Choose a bulk action and at least one item.', 'rss-media-hub' ) );
			$this->back( 'queue' );
			return;
		}
		$ok  = 0;
		$bad = 0;
		foreach ( $ids as $id ) {
			if ( is_wp_error( rmh()->get( 'importer' )->$op( $id ) ) ) {
				++$bad;
			} else {
				++$ok;
			}
		}
		/* translators: 1: action, 2: ok count, 3: failed count */
		Admin::flash( $bad ? 'warning' : 'success', sprintf( __( 'Bulk %1$s: %2$d succeeded, %3$d failed.', 'rss-media-hub' ), $op, $ok, $bad ) );
		$this->back( 'queue' );
	}

	/* ---------------------------------------------------------------- media */

	/**
	 * Single media action.
	 */
	private function media_action() {
		$id    = absint( $this->post( 'id' ) );
		$op    = sanitize_key( $this->post( 'op' ) );
		$media = rmh()->get( 'media' )->find( $id );
		if ( ! $media ) {
			Admin::flash( 'error', __( 'Media not found.', 'rss-media-hub' ) );
			$this->back( 'media' );
			return;
		}
		switch ( $op ) {
			case 'recheck':
				$r = rmh()->get( 'validator' )->validate( $media );
				/* translators: 1: status, 2: message */
				Admin::flash( 'valid' === $r['status'] ? 'success' : 'warning', sprintf( __( 'Validation: %1$s – %2$s', 'rss-media-hub' ), $r['status'], $r['message'] ) );
				break;
			case 'reprocess':
				$this->result( rmh()->get( 'importer' )->reprocess( $media->item_id ), __( 'Media extraction re-run for the item.', 'rss-media-hub' ) );
				break;
			case 'remove_local':
				$this->result( rmh()->get( 'downloader' )->remove_local( $id ), __( 'Local file removed; the remote reference was kept.', 'rss-media-hub' ) );
				break;
			case 'block':
				$r = rmh()->get( 'blocklist' )->add( 'url', $media->url, 'Blocked from media library' );
				rmh()->get( 'media' )->update(
					$id,
					array(
						'validation_status'  => 'blocked',
						'validation_message' => 'Blocked by administrator.',
					)
				);
				$this->result( is_wp_error( $r ) && 'rmh_block_exists' !== $r->get_error_code() ? $r : true, __( 'Media URL blocked; it will no longer be embedded.', 'rss-media-hub' ) );
				break;
			case 'queue_download':
				$item = rmh()->get( 'items' )->find( $media->item_id );
				if ( ! $item || ! rmh()->get( 'downloader' )->authorized( $item->feed_id, false ) ) {
					Admin::flash( 'error', __( 'Local import is not authorized for this feed (confirm rights and choose "Authorized local import" in the feed settings).', 'rss-media-hub' ) );
					break;
				}
				rmh()->get( 'media' )->update( $id, array( 'download_status' => 'queued' ) );
				Admin::flash( 'success', __( 'Download queued for the next background run.', 'rss-media-hub' ) );
				break;
			default:
				Admin::flash( 'error', __( 'Unknown media action.', 'rss-media-hub' ) );
		}
		$this->back( 'media' );
	}

	/**
	 * Bulk recheck.
	 */
	private function media_bulk() {
		$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_slice( array_map( 'absint', wp_unslash( $_POST['ids'] ) ), 0, 25 ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified/absint.
		$n   = 0;
		foreach ( $ids as $id ) {
			$m = rmh()->get( 'media' )->find( $id );
			if ( $m ) {
				rmh()->get( 'validator' )->validate( $m );
				++$n;
			}
		}
		/* translators: %d: count */
		Admin::flash( 'success', sprintf( __( 'Rechecked %d media item(s).', 'rss-media-hub' ), $n ) );
		$this->back( 'media' );
	}

	/* ---------------------------------------------------------------- moderation */

	/**
	 * Moderation decision.
	 */
	private function report_action() {
		$id = absint( $this->post( 'id' ) );
		$op = sanitize_key( $this->post( 'op' ) );
		$this->result( Reports::moderate( $id, $op, $this->post( 'note' ) ), __( 'Moderation decision saved.', 'rss-media-hub' ) );
		$this->back( 'moderation' );
	}

	/**
	 * Add a restriction.
	 */
	private function block_add() {
		$r = rmh()->get( 'blocklist' )->add( sanitize_key( $this->post( 'type' ) ), $this->post( 'value' ), $this->post( 'reason' ) );
		$this->result( is_wp_error( $r ) ? $r : true, __( 'Restriction added.', 'rss-media-hub' ) );
		$this->back( 'restrictions' );
	}

	/**
	 * Remove a restriction.
	 */
	private function block_remove() {
		$this->result( rmh()->get( 'blocklist' )->remove( absint( $this->post( 'id' ) ) ) ? true : new \WP_Error( 'x', __( 'Restriction not found.', 'rss-media-hub' ) ), __( 'Restriction removed.', 'rss-media-hub' ) );
		$this->back( 'restrictions' );
	}

	/**
	 * Removal request by source URL.
	 */
	private function takedown_url() {
		$url  = esc_url_raw( $this->post( 'url' ) );
		$hash = \RMH\Http\UrlNormalizer::hash( $url );
		$repo = rmh()->get( 'items' );
		global $wpdb;
		$table = \RMH\Installer::table( 'items' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = '' === $hash ? array() : $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s LIMIT 50", $hash ) );
		if ( ! $ids ) {
			Admin::flash( 'error', __( 'No imported item matches that source URL.', 'rss-media-hub' ) );
			$this->back( 'restrictions' );
			return;
		}
		$reason = $this->post( 'reason' );
		foreach ( $ids as $id ) {
			rmh()->get( 'importer' )->remove( (int) $id, '' !== $reason ? $reason : 'removal request' );
		}
		rmh()->get( 'blocklist' )->add( 'url', $url, 'Removal request: ' . $reason );
		/* translators: %d: count */
		Admin::flash( 'success', sprintf( __( '%d item(s) taken down and the URL blocked from re-import.', 'rss-media-hub' ), count( $ids ) ) );
		$this->back( 'restrictions' );
	}

	/* ---------------------------------------------------------------- operations */

	/**
	 * Queue a tick for all due feeds (non-blocking).
	 */
	private function run_due() {
		// Make every active feed due, then trigger a background run (never synchronously here).
		global $wpdb;
		$feeds = \RMH\Installer::table( 'feeds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$feeds} SET next_poll_at = %s WHERE status = 'active'", gmdate( 'Y-m-d H:i:s' ) ) );
		if ( ! wp_next_scheduled( \RMH\Cron\Scheduler::NOW_HOOK ) ) {
			wp_schedule_single_event( time(), \RMH\Cron\Scheduler::NOW_HOOK );
		}
		\RMH\Cron\Scheduler::ensure_scheduled();
		if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
		Admin::flash( 'success', __( 'All active feeds are due now; the background run has been triggered.', 'rss-media-hub' ) );
		$this->back( 'dashboard' );
	}

	/**
	 * Requeue failures.
	 */
	private function retry_failed() {
		$r = \RMH\Rest\RestController::retry_failed()->get_data();
		/* translators: 1: items, 2: feeds */
		Admin::flash( 'success', sprintf( __( '%1$d failed item(s) and %2$d failing feed(s) requeued.', 'rss-media-hub' ), $r['items_requeued'], $r['feeds_requeued'] ) );
		$this->back( 'dashboard' );
	}

	/**
	 * Force-release the scheduler lock.
	 */
	private function release_lock() {
		( new \RMH\Cron\Lock( 'tick' ) )->force_release();
		rmh()->get( 'logger' )->warning( 'schedule', sprintf( 'Scheduler lock force-released by user #%d.', get_current_user_id() ) );
		Admin::flash( 'success', __( 'Scheduler lock released.', 'rss-media-hub' ) );
		$this->back( 'logs', array( 'tab' => 'scheduling' ) );
	}

	/**
	 * Purge logs.
	 */
	private function purge_logs() {
		$n = rmh()->get( 'logger' )->purge_all();
		/* translators: %d: rows */
		Admin::flash( 'success', sprintf( __( '%d log entries deleted.', 'rss-media-hub' ), $n ) );
		$this->back( 'logs' );
	}

	/**
	 * Run retention now.
	 */
	private function run_maintenance() {
		$r = rmh()->get( 'scheduler' )->maintenance();
		/* translators: 1: logs, 2: jobs, 3: items */
		Admin::flash( 'success', sprintf( __( 'Retention applied: %1$d logs, %2$d jobs, %3$d rejected items removed.', 'rss-media-hub' ), $r['logs'], $r['jobs'], $r['items'] ) );
		$this->back( 'logs', array( 'tab' => 'scheduling' ) );
	}

	/**
	 * Test an arbitrary URL (diagnostics).
	 */
	private function test_url() {
		$url = \RMH\Db\FeedRepository::validate_url( $this->post( 'url' ) );
		if ( is_wp_error( $url ) ) {
			Admin::flash( 'error', $url->get_error_message() );
		} else {
			set_transient( 'rmh_test_' . get_current_user_id(), rmh()->get( 'importer' )->test_feed( $url ), 15 * MINUTE_IN_SECONDS );
		}
		$this->back( 'logs', array( 'tab' => 'test' ) );
	}
}

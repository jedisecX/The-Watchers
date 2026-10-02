<?php
/**
 * Admin: Logs & Diagnostics.
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Tabs: logs, import history, scheduling status, diagnostics report, feed URL tester.
 */
final class LogsPage {

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! current_user_can( 'rmh_manage_feeds' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'rss-media-hub' ) );
		}
		$tab  = Ui::get( 'tab' );
		$tabs = array(
			'logs'        => __( 'Logs', 'rss-media-hub' ),
			'jobs'        => __( 'Import history', 'rss-media-hub' ),
			'scheduling'  => __( 'Scheduling', 'rss-media-hub' ),
			'diagnostics' => __( 'Diagnostic report', 'rss-media-hub' ),
			'test'        => __( 'Test a feed URL', 'rss-media-hub' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'logs';
		Ui::header( __( 'Logs & Diagnostics', 'rss-media-hub' ) );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( 'logs', array( 'tab' => $k ) ) ) . '"' . ( $k === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		Ui::footer();
	}

	/**
	 * Feed id => name.
	 *
	 * @return array
	 */
	private static function feed_names() {
		$out = array( '' => __( 'All feeds', 'rss-media-hub' ) );
		foreach ( rmh()->get( 'feeds' )->query( array( 'per_page' => 500 ) )['rows'] as $f ) {
			$out[ $f->id ] = $f->name;
		}
		return $out;
	}

	/**
	 * Logs tab.
	 */
	private static function tab_logs() {
		$page     = Ui::paged();
		$res      = rmh()->get( 'logger' )->query(
			array(
				'channel'  => sanitize_key( Ui::get( 'channel' ) ),
				'level'    => sanitize_key( Ui::get( 'level' ) ),
				'feed_id'  => absint( Ui::get( 'feed_id' ) ),
				'job_id'   => absint( Ui::get( 'job_id' ) ),
				'search'   => Ui::get( 's' ),
				'page'     => $page,
				'per_page' => 50,
			)
		);
		$channels = array(
			''           => __( 'All channels', 'rss-media-hub' ),
			'import'     => __( 'Import', 'rss-media-hub' ),
			'parse'      => __( 'Parsing', 'rss-media-hub' ),
			'http'       => 'HTTP',
			'media'      => __( 'Media extraction / validation', 'rss-media-hub' ),
			'publish'    => __( 'Publishing', 'rss-media-hub' ),
			'schedule'   => __( 'Scheduling', 'rss-media-hub' ),
			'retry'      => __( 'Retries', 'rss-media-hub' ),
			'security'   => __( 'Security', 'rss-media-hub' ),
			'db'         => __( 'Database', 'rss-media-hub' ),
			'moderation' => __( 'Moderation', 'rss-media-hub' ),
			'test'       => __( 'Feed tests', 'rss-media-hub' ),
		);
		?>
		<form method="get" class="rmh-filters">
			<input type="hidden" name="page" value="rmh-logs">
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::select().
			echo Ui::select( 'channel', $channels, Ui::get( 'channel' ) );
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
			echo Ui::select(
				'level',
				array(
					''        => __( 'All levels', 'rss-media-hub' ),
					'error'   => 'error',
					'warning' => 'warning',
					'info'    => 'info',
					'debug'   => 'debug',
				),
				Ui::get( 'level' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo Ui::select( 'feed_id', self::feed_names(), Ui::get( 'feed_id' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::select().
			// phpcs:enable
			?>
			<input type="search" name="s" value="<?php echo esc_attr( Ui::get( 's' ) ); ?>" placeholder="<?php esc_attr_e( 'Message contains…', 'rss-media-hub' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'rss-media-hub' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'rss-media-hub' ); ?></button>
		</form>
		<?php
		echo '<p>' . Ui::action_button( 'rmh_purge_logs', array(), __( 'Delete all logs', 'rss-media-hub' ), 'button button-link-delete', __( 'Delete every log entry?', 'rss-media-hub' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $res['rows'] ) {
			echo '<div class="rmh-empty"><p>' . esc_html__( 'No log entries match.', 'rss-media-hub' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped rmh-table"><thead><tr><th>' . esc_html__( 'Time', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Level', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Channel', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Message', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Refs', 'rss-media-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $res['rows'] as $r ) {
			$tone = array(
				'error'   => 'error',
				'warning' => 'warning',
				'info'    => 'new',
				'debug'   => 'skipped',
			);
			echo '<tr><td data-label="' . esc_attr__( 'Time', 'rss-media-hub' ) . '">' . Ui::time( $r->created_at ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td data-label="' . esc_attr__( 'Level', 'rss-media-hub' ) . '">' . Ui::pill( isset( $tone[ $r->level ] ) ? $tone[ $r->level ] : 'skipped', $r->level ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td data-label="' . esc_attr__( 'Channel', 'rss-media-hub' ) . '"><code>' . esc_html( $r->channel ) . '</code></td>';
			echo '<td data-label="' . esc_attr__( 'Message', 'rss-media-hub' ) . '">' . esc_html( $r->message );
			if ( $r->context ) {
				echo '<details><summary>' . esc_html__( 'Context', 'rss-media-hub' ) . '</summary><pre class="rmh-pre">' . esc_html( (string) wp_json_encode( json_decode( (string) $r->context, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
			}
			echo '</td><td data-label="' . esc_attr__( 'Refs', 'rss-media-hub' ) . '"><small>';
			echo $r->feed_id ? 'feed #' . (int) $r->feed_id . ' ' : '';
			echo $r->item_id ? '<a href="' . esc_url( Ui::url( 'queue', array( 'item' => $r->item_id ) ) ) . '">item #' . (int) $r->item_id . '</a> ' : '';
			echo $r->job_id ? 'job #' . (int) $r->job_id : '';
			echo '</small></td></tr>';
		}
		echo '</tbody></table>';
		Ui::pagination( $res['total'], 50, $page );
	}

	/**
	 * Import history tab.
	 */
	private static function tab_jobs() {
		$page  = Ui::paged();
		$res   = rmh()->get( 'jobs' )->query(
			array(
				'feed_id'  => absint( Ui::get( 'feed_id' ) ),
				'status'   => sanitize_key( Ui::get( 'status' ) ),
				'page'     => $page,
				'per_page' => 40,
			)
		);
		$names = self::feed_names();
		?>
		<form method="get" class="rmh-filters">
			<input type="hidden" name="page" value="rmh-logs"><input type="hidden" name="tab" value="jobs">
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::select().
			echo Ui::select( 'feed_id', $names, Ui::get( 'feed_id' ) );
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
			echo Ui::select(
				'status',
				array(
					''            => __( 'All results', 'rss-media-hub' ),
					'success'     => 'success',
					'partial'     => 'partial',
					'failed'      => 'failed',
					'skipped'     => 'skipped',
					'interrupted' => 'interrupted',
					'running'     => 'running',
				),
				Ui::get( 'status' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			// phpcs:enable
			?>
			<button class="button"><?php esc_html_e( 'Filter', 'rss-media-hub' ); ?></button>
		</form>
		<?php
		if ( ! $res['rows'] ) {
			echo '<div class="rmh-empty"><p>' . esc_html__( 'No import runs recorded yet.', 'rss-media-hub' ) . '</p></div>';
			return;
		}
		$cols = array(
			'items_fetched'       => __( 'Fetched', 'rss-media-hub' ),
			'items_new'           => __( 'New', 'rss-media-hub' ),
			'duplicates'          => __( 'Duplicates', 'rss-media-hub' ),
			'posts_created'       => __( 'Created', 'rss-media-hub' ),
			'posts_updated'       => __( 'Updated', 'rss-media-hub' ),
			'pending_review'      => __( 'Review', 'rss-media-hub' ),
			'media_found'         => __( 'Media', 'rss-media-hub' ),
			'extraction_failures' => __( 'Extract fail', 'rss-media-hub' ),
			'publish_failures'    => __( 'Publish fail', 'rss-media-hub' ),
		);
		echo '<table class="widefat striped rmh-table"><thead><tr><th>' . esc_html__( 'Run', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Feed', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Result', 'rss-media-hub' ) . '</th>';
		foreach ( $cols as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $res['rows'] as $j ) {
			$dur = $j->finished_at ? max( 0, strtotime( $j->finished_at . ' UTC' ) - strtotime( $j->started_at . ' UTC' ) ) : null;
			echo '<tr><td data-label="' . esc_attr__( 'Run', 'rss-media-hub' ) . '">#' . (int) $j->id . ' ' . Ui::time( $j->started_at ) . '<br><small>' . esc_html( $j->trigger_source ) . ( null !== $dur ? ' · ' . (int) $dur . 's' : '' ) . '</small></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td data-label="' . esc_attr__( 'Feed', 'rss-media-hub' ) . '">' . esc_html( isset( $names[ $j->feed_id ] ) ? $names[ $j->feed_id ] : '#' . $j->feed_id ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Result', 'rss-media-hub' ) . '">' . Ui::pill( $j->status ) . ' <code>' . esc_html( $j->outcome ) . '</code>' . ( $j->http_status ? ' <small>HTTP ' . (int) $j->http_status . '</small>' : '' ) . '<br><small>' . esc_html( (string) $j->message ) . '</small> <a href="' . esc_url( Ui::url( 'logs', array( 'job_id' => $j->id ) ) ) . '"><small>' . esc_html__( 'logs', 'rss-media-hub' ) . '</small></a></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( array_keys( $cols ) as $k ) {
				echo '<td data-label="' . esc_attr( $cols[ $k ] ) . '">' . (int) $j->$k . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		Ui::pagination( $res['total'], 40, $page );
	}

	/**
	 * Scheduling tab.
	 */
	private static function tab_scheduling() {
		$s = rmh()->get( 'scheduler' )->status();
		echo '<div class="rmh-panel"><table class="widefat rmh-kv"><tbody>';
		echo '<tr><th>' . esc_html__( 'Recurring run', 'rss-media-hub' ) . '</th><td>' . ( $s['scheduled'] ? Ui::pill( 'ok', __( 'scheduled', 'rss-media-hub' ) ) . ' ' . Ui::time( gmdate( 'Y-m-d H:i:s', $s['next_tick'] ) ) : Ui::pill( 'error', __( 'not scheduled', 'rss-media-hub' ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__( 'Last run', 'rss-media-hub' ) . '</th><td>' . ( $s['last_tick'] ? Ui::time( gmdate( 'Y-m-d H:i:s', $s['last_tick'] ) ) . ' · ' . esc_html( $s['last_source'] ) . ' · ' . esc_html( (string) $s['last_duration'] ) . 's' : esc_html__( 'never', 'rss-media-hub' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__( 'Overdue', 'rss-media-hub' ) . '</th><td>' . ( $s['overdue'] ? Ui::pill( 'warning', __( 'yes', 'rss-media-hub' ) ) : Ui::pill( 'ok', __( 'no', 'rss-media-hub' ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__( 'Overlap lock', 'rss-media-hub' ) . '</th><td>' . ( $s['lock_held'] ? Ui::pill( 'running', __( 'held', 'rss-media-hub' ) ) . ' ' . esc_html( sprintf( /* translators: %s: time */ __( 'expires %s', 'rss-media-hub' ), wp_date( 'H:i:s', $s['lock_expires'] ) ) ) . ' ' . Ui::action_button( 'rmh_release_lock', array(), __( 'Force release', 'rss-media-hub' ), 'button button-small', __( 'Only release the lock if no run is actually in progress. Continue?', 'rss-media-hub' ) ) : esc_html__( 'free', 'rss-media-hub' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__( 'Daily maintenance', 'rss-media-hub' ) . '</th><td>' . ( $s['daily_next'] ? Ui::time( gmdate( 'Y-m-d H:i:s', $s['daily_next'] ) ) : '&mdash;' ) . ' ' . Ui::action_button( 'rmh_run_maintenance', array(), __( 'Apply retention now', 'rss-media-hub' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>WP-Cron</th><td>' . ( $s['wp_cron_disabled'] ? esc_html__( 'DISABLE_WP_CRON is set: imports only run from a system cron job.', 'rss-media-hub' ) : esc_html__( 'Enabled (runs on site traffic). For reliable production scheduling use a system cron job.', 'rss-media-hub' ) ) . '</td></tr>';
		echo '</tbody></table></div>';
		echo '<div class="rmh-panel"><h2>' . esc_html__( 'Reliable scheduling with system cron', 'rss-media-hub' ) . '</h2>';
		echo '<ol><li>' . esc_html__( 'Add to wp-config.php:', 'rss-media-hub' ) . ' <code>define( \'DISABLE_WP_CRON\', true );</code></li>';
		echo '<li>' . esc_html__( 'Add a crontab entry that runs every 5 minutes, either with WP-CLI:', 'rss-media-hub' ) . '<pre class="rmh-pre">*/5 * * * * cd ' . esc_html( untrailingslashit( ABSPATH ) ) . ' &amp;&amp; wp rmh run --quiet</pre>';
		echo esc_html__( 'or without WP-CLI:', 'rss-media-hub' ) . '<pre class="rmh-pre">*/5 * * * * curl -fsS ' . esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' &gt;/dev/null</pre></li></ol>';
		echo '<p>' . esc_html__( 'Runs never overlap (global lock + per-feed leases), and re-running is idempotent.', 'rss-media-hub' ) . '</p></div>';
	}

	/**
	 * Diagnostics tab.
	 */
	private static function tab_diagnostics() {
		echo '<p>' . esc_html__( 'Copy this report into support requests. Feed URLs are redacted and no credentials, salts or visitor data are included.', 'rss-media-hub' ) . '</p>';
		echo '<p><button type="button" class="button rmh-copy" data-target="rmh-diag">' . esc_html__( 'Copy report', 'rss-media-hub' ) . '</button> <span class="rmh-copy-status" role="status" aria-live="polite"></span></p>';
		echo '<textarea id="rmh-diag" class="large-text code" rows="28" readonly>' . esc_textarea( Diagnostics::report() ) . '</textarea>';
	}

	/**
	 * URL tester tab.
	 */
	private static function tab_test() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rmh-filters">
			<input type="hidden" name="action" value="rmh_test_url">
			<?php wp_nonce_field( 'rmh_test_url', '_rmh_nonce' ); ?>
			<label for="rmh-test-url"><?php esc_html_e( 'Feed URL', 'rss-media-hub' ); ?></label>
			<input type="url" id="rmh-test-url" name="url" class="regular-text" required placeholder="https://example.com/feed">
			<button class="button button-primary"><?php esc_html_e( 'Test (dry run)', 'rss-media-hub' ); ?></button>
		</form>
		<?php
		$report = get_transient( 'rmh_test_' . get_current_user_id() );
		if ( $report ) {
			FeedsPage::report( $report );
		}
	}
}

<?php
/**
 * Admin: Dashboard.
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Overview of feeds, items, media, failures, activity and scheduling.
 */
final class DashboardPage {

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! current_user_can( 'rmh_moderate' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'rss-media-hub' ) );
		}
		$feeds   = rmh()->get( 'feeds' )->counts();
		$items   = rmh()->get( 'items' )->counts_by_status();
		$types   = rmh()->get( 'items' )->counts_by_media_type();
		$posts   = wp_count_posts( PostTypes::POST_TYPE );
		$sched   = rmh()->get( 'scheduler' )->status();
		$last_ok = rmh()->get( 'jobs' )->last_success_at();
		$reports = rmh()->get( 'reports' )->open_count();
		$failed  = $items['failed'] + $feeds['error'];
		$review  = $items['queued'] + (int) $posts->pending + (int) $posts->draft;
		$can     = current_user_can( 'rmh_manage_feeds' );

		$actions = '';
		if ( $can ) {
			$actions = Ui::action_button( 'rmh_run_due', array(), __( 'Run all feeds now', 'rss-media-hub' ), 'page-title-action' )
				. Ui::action_button( 'rmh_retry_failed', array(), __( 'Retry failed', 'rss-media-hub' ), 'page-title-action' );
		}
		Ui::header( __( 'RSS Media Hub', 'rss-media-hub' ), $actions );

		$cards = array(
			array( __( 'Total feeds', 'rss-media-hub' ), $feeds['total'], Ui::url( 'feeds' ) ),
			array( __( 'Active feeds', 'rss-media-hub' ), $feeds['active'], Ui::url( 'feeds', array( 'status' => 'active' ) ) ),
			array( __( 'Paused / disabled', 'rss-media-hub' ), $feeds['paused'] + $feeds['disabled'], Ui::url( 'feeds', array( 'status' => 'paused' ) ) ),
			array( __( 'Items discovered', 'rss-media-hub' ), $items['total'], Ui::url( 'queue', array( 'status' => 'all' ) ) ),
			array( __( 'Published posts', 'rss-media-hub' ), (int) $posts->publish, admin_url( 'edit.php?post_type=' . PostTypes::POST_TYPE . '&post_status=publish' ) ),
			array( __( 'Awaiting review', 'rss-media-hub' ), $review, Ui::url( 'queue' ) ),
			array( __( 'Failed imports', 'rss-media-hub' ), $failed, Ui::url( 'queue', array( 'status' => 'failed' ) ) ),
			array( __( 'Open reports', 'rss-media-hub' ), $reports, Ui::url( 'moderation' ) ),
		);
		echo '<div class="rmh-cards">';
		foreach ( $cards as $c ) {
			printf( '<a class="rmh-card" href="%1$s"><span class="rmh-card-num">%2$s</span><span class="rmh-card-label">%3$s</span></a>', esc_url( $c[2] ), esc_html( number_format_i18n( $c[1] ) ), esc_html( $c[0] ) );
		}
		echo '</div>';

		echo '<div class="rmh-columns"><div class="rmh-col">';

		// Scheduling.
		echo '<div class="rmh-panel"><h2>' . esc_html__( 'Scheduling', 'rss-media-hub' ) . '</h2><table class="widefat rmh-kv"><tbody>';
		echo '<tr><th>' . esc_html__( 'Last successful poll', 'rss-media-hub' ) . '</th><td>' . Ui::time( $last_ok ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<tr><th>' . esc_html__( 'Last run', 'rss-media-hub' ) . '</th><td>' . ( $sched['last_tick'] ? Ui::time( gmdate( 'Y-m-d H:i:s', $sched['last_tick'] ) ) . ' (' . esc_html( $sched['last_source'] ) . ')' : '&mdash;' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		echo '<tr><th>' . esc_html__( 'Next scheduled run', 'rss-media-hub' ) . '</th><td>' . ( $sched['next_tick'] ? Ui::time( gmdate( 'Y-m-d H:i:s', $sched['next_tick'] ) ) : Ui::pill( 'error', __( 'not scheduled', 'rss-media-hub' ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		echo '<tr><th>' . esc_html__( 'Status', 'rss-media-hub' ) . '</th><td>' . ( $sched['overdue'] ? Ui::pill( 'warning', __( 'overdue', 'rss-media-hub' ) ) : Ui::pill( 'ok', __( 'on schedule', 'rss-media-hub' ) ) ) . ( $sched['lock_held'] ? ' ' . Ui::pill( 'running', __( 'run in progress', 'rss-media-hub' ) ) : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		echo '<tr><th>' . esc_html__( 'Overdue feeds', 'rss-media-hub' ) . '</th><td>' . esc_html( (string) $feeds['overdue'] ) . '</td></tr>';
		echo '<tr><th>WP-Cron</th><td>' . ( $sched['wp_cron_disabled'] ? esc_html__( 'Disabled (DISABLE_WP_CRON) – a system cron job is required.', 'rss-media-hub' ) : esc_html__( 'Enabled', 'rss-media-hub' ) ) . '</td></tr>';
		echo '</tbody></table></div>';

		// Media breakdown.
		$labels = PostTypes::media_type_labels();
		$max    = $types ? max( $types ) : 0;
		echo '<div class="rmh-panel"><h2>' . esc_html__( 'Media types', 'rss-media-hub' ) . '</h2>';
		if ( ! $types ) {
			echo '<p>' . esc_html__( 'No imported items yet.', 'rss-media-hub' ) . '</p>';
		} else {
			echo '<ul class="rmh-bars">';
			foreach ( $labels as $k => $label ) {
				$n = isset( $types[ $k ] ) ? $types[ $k ] : 0;
				printf(
					'<li><span class="rmh-bar-label">%1$s</span><span class="rmh-bar"><span style="width:%2$s%%"></span></span><span class="rmh-bar-num">%3$s</span></li>',
					esc_html( $label ),
					esc_attr( (string) ( $max ? round( 100 * $n / $max ) : 0 ) ),
					esc_html( number_format_i18n( $n ) )
				);
			}
			echo '</ul>';
		}
		echo '</div>';

		echo '</div><div class="rmh-col">';

		// Recent activity.
		$jobs = rmh()->get( 'jobs' )->query( array( 'per_page' => 12 ) )['rows'];
		echo '<div class="rmh-panel"><h2>' . esc_html__( 'Recent import activity', 'rss-media-hub' ) . '</h2>';
		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'No imports have run yet. Add a feed in the Feed Manager; the first poll happens within a few minutes.', 'rss-media-hub' ) . '</p>';
		} else {
			echo '<table class="widefat striped rmh-table"><thead><tr><th>' . esc_html__( 'When', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Feed', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Outcome', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'New', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Created', 'rss-media-hub' ) . '</th></tr></thead><tbody>';
			$names = array();
			foreach ( $jobs as $j ) {
				if ( ! isset( $names[ $j->feed_id ] ) ) {
					$f                    = rmh()->get( 'feeds' )->find( $j->feed_id );
					$names[ $j->feed_id ] = $f ? $f->name : '#' . $j->feed_id;
				}
				echo '<tr><td data-label="' . esc_attr__( 'When', 'rss-media-hub' ) . '">' . Ui::time( $j->started_at ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
				echo '<td data-label="' . esc_attr__( 'Feed', 'rss-media-hub' ) . '">' . esc_html( $names[ $j->feed_id ] ) . '</td>';
				echo '<td data-label="' . esc_attr__( 'Outcome', 'rss-media-hub' ) . '">' . Ui::pill( $j->status ) . ' <code>' . esc_html( $j->outcome ) . '</code></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
				echo '<td data-label="' . esc_attr__( 'New', 'rss-media-hub' ) . '">' . (int) $j->items_new . '</td>';
				echo '<td data-label="' . esc_attr__( 'Created', 'rss-media-hub' ) . '">' . (int) $j->posts_created . '</td></tr>';
			}
			echo '</tbody></table><p><a href="' . esc_url( Ui::url( 'logs', array( 'tab' => 'jobs' ) ) ) . '">' . esc_html__( 'Full import history', 'rss-media-hub' ) . '</a></p>';
		}
		echo '</div>';

		// Failing feeds.
		$bad = rmh()->get( 'feeds' )->query(
			array(
				'health'   => 'error',
				'per_page' => 10,
			)
		)['rows'];
		if ( $bad ) {
			echo '<div class="rmh-panel rmh-panel--bad"><h2>' . esc_html__( 'Feeds with errors', 'rss-media-hub' ) . '</h2><ul>';
			foreach ( $bad as $f ) {
				echo '<li><a href="' . esc_url(
					Ui::url(
						'feeds',
						array(
							'action' => 'edit',
							'id'     => $f->id,
						)
					)
				) . '">' . esc_html( $f->name ) . '</a>: <code>' . esc_html( $f->last_outcome ) . '</code> ' . esc_html( mb_substr( (string) $f->last_error, 0, 160 ) ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '</div></div>';
		Ui::footer();
	}
}

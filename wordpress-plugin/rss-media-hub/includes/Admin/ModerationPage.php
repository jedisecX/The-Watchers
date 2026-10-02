<?php
/**
 * Admin: Reports & Moderation.
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

use RMH\Db\ReportRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Review visitor reports, removal requests and escalations.
 */
final class ModerationPage {

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! current_user_can( 'rmh_moderate' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'rss-media-hub' ) );
		}
		$status  = Ui::get( 'status' );
		$page    = Ui::paged();
		$res     = rmh()->get( 'reports' )->query(
			array(
				'status'   => $status,
				'reason'   => Ui::get( 'reason' ),
				'page'     => $page,
				'per_page' => 30,
			)
		);
		$reasons = ReportRepository::reasons();
		Ui::header( __( 'Reports & Moderation', 'rss-media-hub' ) );
		?>
		<div class="rmh-panel rmh-panel--info">
			<p><strong><?php esc_html_e( 'Escalation policy.', 'rss-media-hub' ); ?></strong>
			<?php esc_html_e( 'Reports of suspected child sexual abuse material or non-consensual content unpublish the post immediately and are marked "escalated". Do not delete escalated content until you have followed your legal reporting obligations (for example NCMEC CyberTipline in the US, the IWF in the UK, or your national hotline / law enforcement). Taking an item down unpublishes it and blocks its GUID from re-import while keeping the record.', 'rss-media-hub' ); ?></p>
		</div>
		<form method="get" class="rmh-filters">
			<input type="hidden" name="page" value="rmh-moderation">
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::select().
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
			echo Ui::select(
				'status',
				array(
					''          => __( 'All statuses', 'rss-media-hub' ),
					'open'      => __( 'Open', 'rss-media-hub' ),
					'escalated' => __( 'Escalated', 'rss-media-hub' ),
					'resolved'  => __( 'Resolved', 'rss-media-hub' ),
					'dismissed' => __( 'Dismissed', 'rss-media-hub' ),
				),
				$status
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo Ui::select( 'reason', array( '' => __( 'All reasons', 'rss-media-hub' ) ) + $reasons, Ui::get( 'reason' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::select().
			// phpcs:enable
			?>
			<button class="button"><?php esc_html_e( 'Filter', 'rss-media-hub' ); ?></button>
		</form>
		<?php
		if ( ! $res['rows'] ) {
			echo '<div class="rmh-empty"><p>' . esc_html__( 'No reports.', 'rss-media-hub' ) . '</p></div>';
			Ui::footer();
			return;
		}
		?>
		<table class="widefat striped rmh-table">
			<thead><tr>
				<th><?php esc_html_e( 'Report', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Post', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Details', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Status', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Decision', 'rss-media-hub' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $res['rows'] as $r ) : ?>
				<?php $post = get_post( (int) $r->post_id ); ?>
				<tr>
					<td data-label="<?php esc_attr_e( 'Report', 'rss-media-hub' ); ?>">#<?php echo (int) $r->id; ?> <strong><?php echo esc_html( isset( $reasons[ $r->reason ] ) ? $reasons[ $r->reason ] : $r->reason ); ?></strong><br><small><?php echo Ui::time( $r->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small></td>
					<td data-label="<?php esc_attr_e( 'Post', 'rss-media-hub' ); ?>">
						<?php if ( $post ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a><br>
							<?php echo Ui::pill( 'publish' === $post->post_status ? 'published' : $post->post_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php $hidden = (string) get_post_meta( $post->ID, '_rmh_hidden_reason', true ); ?>
							<?php
							if ( '' !== $hidden ) :
								?>
								<br><small><?php echo esc_html( sprintf( /* translators: %s: reason */ __( 'Hidden: %s', 'rss-media-hub' ), $hidden ) ); ?></small><?php endif; ?>
						<?php else : ?>
							<?php esc_html_e( '(post deleted)', 'rss-media-hub' ); ?>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Details', 'rss-media-hub' ); ?>"><?php echo esc_html( (string) $r->details ); ?><?php echo $r->contact ? '<br><small>' . esc_html__( 'Contact:', 'rss-media-hub' ) . ' ' . esc_html( $r->contact ) . '</small>' : ''; ?><?php echo $r->resolution ? '<br><small><em>' . esc_html( $r->resolution ) . '</em></small>' : ''; ?></td>
					<td data-label="<?php esc_attr_e( 'Status', 'rss-media-hub' ); ?>"><?php echo Ui::pill( $r->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td data-label="<?php esc_attr_e( 'Decision', 'rss-media-hub' ); ?>">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rmh-decision" data-rmh-confirm-ops="takedown,block_source" data-rmh-confirm="<?php esc_attr_e( 'Apply this decision?', 'rss-media-hub' ); ?>">
							<input type="hidden" name="action" value="rmh_report_action">
							<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
							<?php wp_nonce_field( 'rmh_report_action', '_rmh_nonce' ); ?>
							<label class="screen-reader-text" for="rmh-op-<?php echo (int) $r->id; ?>"><?php esc_html_e( 'Decision', 'rss-media-hub' ); ?></label>
							<?php
							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
							echo Ui::select(
								'op',
								array(
									'resolve'      => __( 'Resolve (no change)', 'rss-media-hub' ),
									'dismiss'      => __( 'Dismiss (restore if auto-hidden)', 'rss-media-hub' ),
									'escalate'     => __( 'Escalate (hide + notify)', 'rss-media-hub' ),
									'takedown'     => __( 'Take down item', 'rss-media-hub' ),
									'restore'      => __( 'Restore post', 'rss-media-hub' ),
									'block_source' => __( 'Block source (disable feed + domain)', 'rss-media-hub' ),
								),
								'resolve',
								'rmh-op-' . (int) $r->id
							);
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
							<input type="text" name="note" placeholder="<?php esc_attr_e( 'Note (optional)', 'rss-media-hub' ); ?>" maxlength="500">
							<button class="button button-small"><?php esc_html_e( 'Apply', 'rss-media-hub' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		Ui::pagination( $res['total'], 30, $page );
		Ui::footer();
	}
}

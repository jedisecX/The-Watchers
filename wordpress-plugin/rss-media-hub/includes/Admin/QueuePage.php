<?php
/**
 * Admin: Import Queue.
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

use RMH\Db\ItemRepository;
use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Review discovered items, preview metadata and media, approve/reject/retry.
 */
final class QueuePage {

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! current_user_can( 'rmh_moderate' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'rss-media-hub' ) );
		}
		$single   = absint( Ui::get( 'item' ) );
		$status   = Ui::get( 'status' );
		$status   = '' === $status ? 'review' : $status;
		$groups   = array(
			'review' => array( 'queued', 'pending', 'discovered', 'failed' ),
			'all'    => array(),
		);
		$statuses = isset( $groups[ $status ] ) ? $groups[ $status ] : array( sanitize_key( $status ) );
		$page     = Ui::paged();
		$args     = array(
			'feed_id'    => absint( Ui::get( 'feed_id' ) ),
			'status'     => $statuses,
			'media_type' => Ui::get( 'media_type' ),
			'search'     => Ui::get( 's' ),
			'date_from'  => Ui::get( 'date_from' ),
			'date_to'    => Ui::get( 'date_to' ),
			'page'       => $page,
			'per_page'   => 25,
		);
		$res      = $single ? array(
			'rows'  => array_filter( array( rmh()->get( 'items' )->find( $single ) ) ),
			'total' => 1,
		) : rmh()->get( 'items' )->query( $args );
		$counts   = rmh()->get( 'items' )->counts_by_status();
		$feeds    = array( '' => __( 'All feeds', 'rss-media-hub' ) );
		foreach ( rmh()->get( 'feeds' )->query( array( 'per_page' => 500 ) )['rows'] as $f ) {
			$feeds[ $f->id ] = $f->name;
		}

		Ui::header( __( 'Import Queue', 'rss-media-hub' ) );

		$tabs = array(
			'review'    => __( 'Needs attention', 'rss-media-hub' ),
			'queued'    => __( 'Awaiting approval', 'rss-media-hub' ),
			'pending'   => __( 'Pending posts', 'rss-media-hub' ),
			'published' => __( 'Published', 'rss-media-hub' ),
			'failed'    => __( 'Failed', 'rss-media-hub' ),
			'duplicate' => __( 'Duplicates', 'rss-media-hub' ),
			'skipped'   => __( 'Skipped', 'rss-media-hub' ),
			'blocked'   => __( 'Blocked', 'rss-media-hub' ),
			'rejected'  => __( 'Rejected', 'rss-media-hub' ),
			'removed'   => __( 'Removed', 'rss-media-hub' ),
			'all'       => __( 'All', 'rss-media-hub' ),
		);
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $tabs as $k => $label ) {
			$n       = 'review' === $k ? $counts['queued'] + $counts['pending'] + $counts['discovered'] + $counts['failed'] : ( 'all' === $k ? $counts['total'] : ( isset( $counts[ $k ] ) ? $counts[ $k ] : 0 ) );
			$links[] = '<li><a href="' . esc_url( Ui::url( 'queue', array( 'status' => $k ) ) ) . '"' . ( $status === $k ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $n ) ) . ')</span></a>';
		}
		echo implode( ' |</li>', $links ) . '</li></ul><br class="clear">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		?>
		<form method="get" class="rmh-filters">
			<input type="hidden" name="page" value="rmh-queue">
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<label class="screen-reader-text" for="rmh-q-feed"><?php esc_html_e( 'Feed', 'rss-media-hub' ); ?></label>
			<?php echo Ui::select( 'feed_id', $feeds, Ui::get( 'feed_id' ), 'rmh-q-feed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<label class="screen-reader-text" for="rmh-q-type"><?php esc_html_e( 'Media type', 'rss-media-hub' ); ?></label>
			<?php echo Ui::select( 'media_type', array( '' => __( 'All media types', 'rss-media-hub' ) ) + PostTypes::media_type_labels(), Ui::get( 'media_type' ), 'rmh-q-type' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<label><?php esc_html_e( 'From', 'rss-media-hub' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( Ui::get( 'date_from' ) ); ?>"></label>
			<label><?php esc_html_e( 'To', 'rss-media-hub' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( Ui::get( 'date_to' ) ); ?>"></label>
			<input type="search" name="s" value="<?php echo esc_attr( Ui::get( 's' ) ); ?>" placeholder="<?php esc_attr_e( 'Title or URL', 'rss-media-hub' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'rss-media-hub' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'rss-media-hub' ); ?></button>
		</form>
		<?php
		if ( ! $res['rows'] ) {
			echo '<div class="rmh-empty"><p>' . esc_html__( 'No items in this view.', 'rss-media-hub' ) . '</p></div>';
			Ui::footer();
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rmh-bulk">
			<input type="hidden" name="action" value="rmh_item_bulk">
			<?php wp_nonce_field( 'rmh_item_bulk', '_rmh_nonce' ); ?>
			<div class="tablenav top"><div class="alignleft actions bulkactions">
				<label class="screen-reader-text" for="rmh-bulk-op"><?php esc_html_e( 'Bulk action', 'rss-media-hub' ); ?></label>
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
				echo Ui::select(
					'bulk',
					array(
						''        => __( 'Bulk actions', 'rss-media-hub' ),
						'approve' => __( 'Approve & publish', 'rss-media-hub' ),
						'reject'  => __( 'Reject', 'rss-media-hub' ),
						'retry'   => __( 'Retry', 'rss-media-hub' ),
					),
					'',
					'rmh-bulk-op'
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<button class="button"><?php esc_html_e( 'Apply', 'rss-media-hub' ); ?></button>
			</div></div>
		</form>
		<table class="widefat striped rmh-table">
			<thead><tr>
				<td class="check-column"><input type="checkbox" class="rmh-check-all" aria-label="<?php esc_attr_e( 'Select all', 'rss-media-hub' ); ?>"></td>
				<th><?php esc_html_e( 'Item', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Feed', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Status', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Media', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Discovered', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'rss-media-hub' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $res['rows'] as $item ) : ?>
				<?php
				$media = rmh()->get( 'media' )->for_item( $item->id );
				$data  = isset( $item->data['item'] ) ? $item->data['item'] : array();
				?>
				<tr>
					<th class="check-column" scope="row"><input type="checkbox" name="ids[]" value="<?php echo (int) $item->id; ?>" form="rmh-bulk" aria-label="<?php esc_attr_e( 'Select item', 'rss-media-hub' ); ?>"></th>
					<td data-label="<?php esc_attr_e( 'Item', 'rss-media-hub' ); ?>">
						<strong><?php echo esc_html( $item->title ); ?></strong>
						<?php if ( $item->post_id && get_post( $item->post_id ) ) : ?>
							<br><a href="<?php echo esc_url( get_edit_post_link( $item->post_id ) ); ?>"><?php esc_html_e( 'Edit post', 'rss-media-hub' ); ?></a> · <a href="<?php echo esc_url( get_permalink( $item->post_id ) ); ?>"><?php esc_html_e( 'View', 'rss-media-hub' ); ?></a>
						<?php endif; ?>
						<?php if ( $item->duplicate_of ) : ?>
							<br><small><?php echo esc_html( sprintf( /* translators: %d: item id */ __( 'Duplicate of item #%d (same canonical URL)', 'rss-media-hub' ), (int) $item->duplicate_of ) ); ?></small>
						<?php endif; ?>
						<?php if ( $item->last_error ) : ?>
							<br><small class="rmh-error-text"><?php echo esc_html( mb_substr( (string) $item->last_error, 0, 200 ) ); ?></small>
						<?php endif; ?>
						<details class="rmh-preview">
							<summary><?php esc_html_e( 'Preview metadata and media', 'rss-media-hub' ); ?></summary>
							<table class="rmh-kv"><tbody>
								<tr><th><?php esc_html_e( 'Source URL', 'rss-media-hub' ); ?></th><td><?php echo Ui::short_url( $item->source_url, 80 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
								<tr><th>GUID</th><td><code><?php echo esc_html( mb_substr( (string) $item->guid, 0, 200 ) ); ?></code></td></tr>
								<tr><th><?php esc_html_e( 'Published (source)', 'rss-media-hub' ); ?></th><td><?php echo $item->source_published_at ? Ui::time( $item->source_published_at, false ) : Ui::pill( 'warning', __( 'missing or invalid', 'rss-media-hub' ) ) . ( ! empty( $data['date_raw'] ) ? ' <code>' . esc_html( $data['date_raw'] ) . '</code>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
								<tr><th><?php esc_html_e( 'Authors', 'rss-media-hub' ); ?></th><td><?php echo esc_html( implode( ', ', isset( $data['authors'] ) ? (array) $data['authors'] : array() ) ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Categories', 'rss-media-hub' ); ?></th><td><?php echo esc_html( implode( ', ', isset( $data['categories'] ) ? (array) $data['categories'] : array() ) ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Rating', 'rss-media-hub' ); ?></th><td><?php echo esc_html( $item->content_rating ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Summary', 'rss-media-hub' ); ?></th><td><?php echo esc_html( wp_trim_words( wp_strip_all_tags( isset( $data['summary'] ) ? (string) $data['summary'] : '' ), 60 ) ); ?></td></tr>
								<?php if ( ! empty( $item->data['notes'] ) ) : ?>
									<tr><th><?php esc_html_e( 'Extraction notes', 'rss-media-hub' ); ?></th><td><?php echo esc_html( implode( ' ', (array) $item->data['notes'] ) ); ?></td></tr>
								<?php endif; ?>
								<tr><th><?php esc_html_e( 'Media', 'rss-media-hub' ); ?></th><td>
									<?php if ( ! $media ) : ?>
										<?php esc_html_e( 'None', 'rss-media-hub' ); ?>
									<?php else : ?>
										<ul class="rmh-plain">
											<?php foreach ( $media as $m ) : ?>
												<li><code><?php echo esc_html( $m->media_type ); ?></code> <?php echo Ui::pill( $m->validation_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <small><?php echo esc_html( $m->extraction_method . ( $m->provider ? ' / ' . $m->provider : '' ) . ( $m->mime_type ? ' / ' . $m->mime_type : '' ) ); ?></small><br><small><?php echo Ui::short_url( $m->url, 70 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small></li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</td></tr>
							</tbody></table>
						</details>
					</td>
					<td data-label="<?php esc_attr_e( 'Feed', 'rss-media-hub' ); ?>"><?php echo esc_html( isset( $feeds[ $item->feed_id ] ) ? $feeds[ $item->feed_id ] : '#' . $item->feed_id ); ?></td>
					<td data-label="<?php esc_attr_e( 'Status', 'rss-media-hub' ); ?>"><?php echo Ui::pill( $item->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $item->attempts ? ' <small>' . esc_html( sprintf( /* translators: 1: attempts, 2: max */ __( 'attempt %1$d/%2$d', 'rss-media-hub' ), (int) $item->attempts, ItemRepository::MAX_ATTEMPTS ) ) . '</small>' : ''; ?></td>
					<td data-label="<?php esc_attr_e( 'Media', 'rss-media-hub' ); ?>"><?php echo esc_html( $item->media_type ); ?> <small>(<?php echo count( $media ); ?>)</small></td>
					<td data-label="<?php esc_attr_e( 'Discovered', 'rss-media-hub' ); ?>"><?php echo Ui::time( $item->discovered_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td data-label="<?php esc_attr_e( 'Actions', 'rss-media-hub' ); ?>" class="rmh-actions">
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::action_button().
						if ( in_array( $item->status, array( 'queued', 'pending', 'discovered', 'failed', 'rejected', 'skipped', 'duplicate' ), true ) ) {
							echo Ui::action_button(
								'rmh_item_action',
								array(
									'id' => $item->id,
									'op' => 'approve',
								),
								__( 'Approve & publish', 'rss-media-hub' ),
								'button button-small button-primary'
							);
						}
						if ( ! in_array( $item->status, array( 'rejected', 'removed' ), true ) ) {
							echo Ui::action_button(
								'rmh_item_action',
								array(
									'id' => $item->id,
									'op' => 'reject',
								),
								__( 'Reject', 'rss-media-hub' )
							);
						}
						if ( in_array( $item->status, array( 'failed', 'discovered' ), true ) ) {
							echo Ui::action_button(
								'rmh_item_action',
								array(
									'id' => $item->id,
									'op' => 'retry',
								),
								__( 'Retry', 'rss-media-hub' )
							);
						}
						echo Ui::action_button(
							'rmh_item_action',
							array(
								'id' => $item->id,
								'op' => 'reprocess',
							),
							__( 'Re-extract media', 'rss-media-hub' )
						);
						if ( 'removed' !== $item->status ) {
							echo Ui::action_button(
								'rmh_item_action',
								array(
									'id' => $item->id,
									'op' => 'remove',
								),
								__( 'Take down', 'rss-media-hub' ),
								'button button-small button-link-delete',
								__( 'Take this item down and block it from being re-imported?', 'rss-media-hub' )
							);
						}
						// phpcs:enable
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		Ui::pagination( $res['total'], 25, $page );
		Ui::footer();
	}
}

<?php
/**
 * Admin: Media Library (imported media assets).
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

use RMH\Db\MediaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Lists media records with validation status and per-asset actions.
 */
final class MediaPage {

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! current_user_can( 'rmh_moderate' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'rss-media-hub' ) );
		}
		$page   = Ui::paged();
		$type   = Ui::get( 'media_type' );
		$vstat  = Ui::get( 'validation_status' );
		$res    = rmh()->get( 'media' )->query(
			array(
				'media_type'        => $type,
				'validation_status' => $vstat,
				'local_only'        => '1' === Ui::get( 'local' ),
				'search'            => Ui::get( 's' ),
				'page'              => $page,
				'per_page'          => 30,
			)
		);
		$counts = rmh()->get( 'media' )->counts_by_validation();
		$used   = rmh()->get( 'media' )->local_bytes_total();
		$quota  = MB_IN_BYTES * (int) rmh()->get( 'settings' )->get( 'storage_quota_mb' );

		Ui::header( __( 'Media Library', 'rss-media-hub' ) );
		echo '<p class="rmh-muted">';
		foreach ( $counts as $k => $n ) {
			echo Ui::pill( $k, $k . ': ' . number_format_i18n( $n ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		/* translators: 1: used, 2: quota */
		echo ' · ' . esc_html( sprintf( __( 'Local storage: %1$s of %2$s', 'rss-media-hub' ), size_format( $used ), size_format( $quota ) ) ) . '</p>';
		?>
		<form method="get" class="rmh-filters">
			<input type="hidden" name="page" value="rmh-media">
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::select().
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
			echo Ui::select(
				'media_type',
				array(
					''      => __( 'All types', 'rss-media-hub' ),
					'video' => __( 'Video', 'rss-media-hub' ),
					'audio' => __( 'Audio', 'rss-media-hub' ),
					'image' => __( 'Image / gallery', 'rss-media-hub' ),
					'embed' => __( 'External embed', 'rss-media-hub' ),
					'link'  => __( 'External link', 'rss-media-hub' ),
				),
				$type
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo Ui::select( 'validation_status', array( '' => __( 'Any validation status', 'rss-media-hub' ) ) + array_combine( MediaRepository::VALIDATION, MediaRepository::VALIDATION ), $vstat ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::select().
			// phpcs:enable
			?>
			<label><input type="checkbox" name="local" value="1" <?php checked( '1' === Ui::get( 'local' ) ); ?>> <?php esc_html_e( 'Stored locally', 'rss-media-hub' ); ?></label>
			<input type="search" name="s" value="<?php echo esc_attr( Ui::get( 's' ) ); ?>" placeholder="<?php esc_attr_e( 'URL contains…', 'rss-media-hub' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'rss-media-hub' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'rss-media-hub' ); ?></button>
		</form>
		<?php
		if ( ! $res['rows'] ) {
			echo '<div class="rmh-empty"><p>' . esc_html__( 'No media records match.', 'rss-media-hub' ) . '</p></div>';
			Ui::footer();
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rmh-mbulk">
			<input type="hidden" name="action" value="rmh_media_bulk">
			<?php wp_nonce_field( 'rmh_media_bulk', '_rmh_nonce' ); ?>
			<div class="tablenav top"><div class="alignleft actions"><button class="button"><?php esc_html_e( 'Recheck selected (max 25)', 'rss-media-hub' ); ?></button></div></div>
		</form>
		<table class="widefat striped rmh-table">
			<thead><tr>
				<td class="check-column"><input type="checkbox" class="rmh-check-all" aria-label="<?php esc_attr_e( 'Select all', 'rss-media-hub' ); ?>"></td>
				<th><?php esc_html_e( 'Preview', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Media', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Validation', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Post', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'rss-media-hub' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $res['rows'] as $m ) : ?>
				<?php $preview = 'image' === $m->media_type ? $m->url : $m->thumbnail_url; ?>
				<tr>
					<th class="check-column" scope="row"><input type="checkbox" name="ids[]" value="<?php echo (int) $m->id; ?>" form="rmh-mbulk" aria-label="<?php esc_attr_e( 'Select media', 'rss-media-hub' ); ?>"></th>
					<td data-label="<?php esc_attr_e( 'Preview', 'rss-media-hub' ); ?>" class="rmh-thumbcell">
						<?php if ( $preview && preg_match( '#^https?://#', $preview ) ) : ?>
							<img src="<?php echo esc_url( $m->attachment_id && 'image' === $m->media_type ? (string) wp_get_attachment_url( $m->attachment_id ) : $preview ); ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
						<?php else : ?>
							<span class="dashicons dashicons-format-<?php echo esc_attr( 'audio' === $m->media_type ? 'audio' : ( 'image' === $m->media_type ? 'image' : 'video' ) ); ?>" aria-hidden="true"></span>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Media', 'rss-media-hub' ); ?>">
						<code><?php echo esc_html( $m->media_type ); ?></code> <?php echo esc_html( $m->mime_type ); ?> <?php echo $m->provider ? '<small>(' . esc_html( $m->provider ) . ')</small>' : ''; ?><br>
						<small><?php echo Ui::short_url( $m->url, 70 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small><br>
						<small class="rmh-muted"><?php echo esc_html( sprintf( /* translators: %s: method */ __( 'Extracted via %s', 'rss-media-hub' ), $m->extraction_method ) ); ?><?php echo $m->width ? esc_html( ' · ' . $m->width . '×' . $m->height ) : ''; ?><?php echo $m->duration ? esc_html( ' · ' . gmdate( $m->duration >= 3600 ? 'H:i:s' : 'i:s', $m->duration ) ) : ''; ?><?php echo $m->filesize ? esc_html( ' · ' . size_format( $m->filesize ) ) : ''; ?></small>
						<?php
						if ( $m->attachment_id ) :
							?>
							<br><small><?php echo esc_html( sprintf( /* translators: %s: size */ __( 'Stored locally (%s)', 'rss-media-hub' ), size_format( $m->local_bytes ) ) ); ?></small><?php endif; ?>
						<?php
						if ( $m->download_status && 'done' !== $m->download_status ) :
							?>
							<br><small><?php echo esc_html( sprintf( /* translators: %s: status */ __( 'Download: %s', 'rss-media-hub' ), $m->download_status ) ); ?></small><?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Validation', 'rss-media-hub' ); ?>"><?php echo Ui::pill( $m->validation_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><br><small><?php echo esc_html( $m->validation_message ); ?></small><br><small><?php echo Ui::time( $m->checked_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small></td>
					<td data-label="<?php esc_attr_e( 'Post', 'rss-media-hub' ); ?>"><?php echo $m->post_id && get_post( $m->post_id ) ? '<a href="' . esc_url( get_edit_post_link( $m->post_id ) ) . '">' . esc_html( get_the_title( $m->post_id ) ) . '</a>' : '&mdash;'; ?></td>
					<td data-label="<?php esc_attr_e( 'Actions', 'rss-media-hub' ); ?>" class="rmh-actions">
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::action_button().
						if ( in_array( $m->media_type, array( 'video', 'audio', 'image' ), true ) ) {
							echo Ui::action_button(
								'rmh_media_action',
								array(
									'id' => $m->id,
									'op' => 'recheck',
								),
								__( 'Recheck URL', 'rss-media-hub' )
							);
						}
						echo Ui::action_button(
							'rmh_media_action',
							array(
								'id' => $m->id,
								'op' => 'reprocess',
							),
							__( 'Retry extraction', 'rss-media-hub' )
						);
						if ( $m->attachment_id ) {
							echo Ui::action_button(
								'rmh_media_action',
								array(
									'id' => $m->id,
									'op' => 'remove_local',
								),
								__( 'Remove local file', 'rss-media-hub' ),
								'button button-small button-link-delete',
								__( 'Delete the locally stored copy? The remote reference is kept.', 'rss-media-hub' )
							);
						} elseif ( in_array( $m->media_type, array( 'video', 'audio', 'image' ), true ) ) {
							echo Ui::action_button(
								'rmh_media_action',
								array(
									'id' => $m->id,
									'op' => 'queue_download',
								),
								__( 'Import locally', 'rss-media-hub' )
							);
						}
						if ( 'blocked' !== $m->validation_status ) {
							echo Ui::action_button(
								'rmh_media_action',
								array(
									'id' => $m->id,
									'op' => 'block',
								),
								__( 'Block URL', 'rss-media-hub' ),
								'button button-small button-link-delete',
								__( 'Block this media URL everywhere?', 'rss-media-hub' )
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
		Ui::pagination( $res['total'], 30, $page );
		Ui::footer();
	}
}

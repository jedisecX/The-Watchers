<?php
/**
 * Admin: Source restrictions (blocklist) and removal requests.
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Manage blocked domains, URLs, GUIDs and title keywords; process removal requests by URL.
 */
final class RestrictionsPage {

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! current_user_can( 'rmh_manage_feeds' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'rss-media-hub' ) );
		}
		$type  = Ui::get( 'type' );
		$rows  = rmh()->get( 'blocklist' )->all( in_array( $type, \RMH\Db\Blocklist::TYPES, true ) ? $type : '' );
		$types = array(
			'domain'  => __( 'Domain (includes subdomains)', 'rss-media-hub' ),
			'url'     => __( 'Exact URL', 'rss-media-hub' ),
			'guid'    => __( 'Item GUID', 'rss-media-hub' ),
			'keyword' => __( 'Title keyword', 'rss-media-hub' ),
		);
		Ui::header( __( 'Source Restrictions', 'rss-media-hub' ) );
		?>
		<div class="rmh-columns">
			<div class="rmh-col">
				<div class="rmh-panel">
					<h2><?php esc_html_e( 'Add restriction', 'rss-media-hub' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Blocked sources are never fetched, matching items are not imported, and matching media is never embedded or downloaded.', 'rss-media-hub' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rmh_block_add">
						<?php wp_nonce_field( 'rmh_block_add', '_rmh_nonce' ); ?>
						<p><label for="rmh-b-type"><?php esc_html_e( 'Type', 'rss-media-hub' ); ?></label><br><?php echo Ui::select( 'type', $types, 'domain', 'rmh-b-type' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
						<p><label for="rmh-b-value"><?php esc_html_e( 'Value', 'rss-media-hub' ); ?></label><br><input type="text" id="rmh-b-value" name="value" class="regular-text" required></p>
						<p><label for="rmh-b-reason"><?php esc_html_e( 'Reason (internal)', 'rss-media-hub' ); ?></label><br><input type="text" id="rmh-b-reason" name="reason" class="regular-text"></p>
						<p><button class="button button-primary"><?php esc_html_e( 'Add', 'rss-media-hub' ); ?></button></p>
					</form>
				</div>
			</div>
			<div class="rmh-col">
				<div class="rmh-panel">
					<h2><?php esc_html_e( 'Removal request / disputed content', 'rss-media-hub' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Enter the original source URL from a removal request (e.g. a copyright notice). Every imported item with that canonical URL is unpublished, its GUID and URL are blocked from re-import, and the action is logged.', 'rss-media-hub' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-rmh-confirm="<?php esc_attr_e( 'Take down all items with this source URL?', 'rss-media-hub' ); ?>">
						<input type="hidden" name="action" value="rmh_takedown_url">
						<?php wp_nonce_field( 'rmh_takedown_url', '_rmh_nonce' ); ?>
						<p><label for="rmh-t-url"><?php esc_html_e( 'Source URL', 'rss-media-hub' ); ?></label><br><input type="url" id="rmh-t-url" name="url" class="regular-text" required></p>
						<p><label for="rmh-t-reason"><?php esc_html_e( 'Reference / reason', 'rss-media-hub' ); ?></label><br><input type="text" id="rmh-t-reason" name="reason" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. DMCA notice 2026-10-02', 'rss-media-hub' ); ?>"></p>
						<p><button class="button"><?php esc_html_e( 'Take down', 'rss-media-hub' ); ?></button></p>
					</form>
				</div>
			</div>
		</div>

		<h2><?php esc_html_e( 'Current restrictions', 'rss-media-hub' ); ?></h2>
		<form method="get" class="rmh-filters">
			<input type="hidden" name="page" value="rmh-restrictions">
			<?php echo Ui::select( 'type', array( '' => __( 'All types', 'rss-media-hub' ) ) + $types, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<button class="button"><?php esc_html_e( 'Filter', 'rss-media-hub' ); ?></button>
		</form>
		<?php if ( ! $rows ) : ?>
			<div class="rmh-empty"><p><?php esc_html_e( 'No restrictions defined.', 'rss-media-hub' ); ?></p></div>
		<?php else : ?>
			<table class="widefat striped rmh-table">
				<thead><tr><th><?php esc_html_e( 'Type', 'rss-media-hub' ); ?></th><th><?php esc_html_e( 'Value', 'rss-media-hub' ); ?></th><th><?php esc_html_e( 'Reason', 'rss-media-hub' ); ?></th><th><?php esc_html_e( 'Added', 'rss-media-hub' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Type', 'rss-media-hub' ); ?>"><code><?php echo esc_html( $r->type ); ?></code></td>
						<td data-label="<?php esc_attr_e( 'Value', 'rss-media-hub' ); ?>"><?php echo esc_html( mb_substr( (string) $r->value, 0, 200 ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Reason', 'rss-media-hub' ); ?>"><?php echo esc_html( (string) $r->reason ); ?></td>
						<td data-label="<?php esc_attr_e( 'Added', 'rss-media-hub' ); ?>"><?php echo Ui::time( $r->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo Ui::action_button( 'rmh_block_remove', array( 'id' => $r->id ), __( 'Remove', 'rss-media-hub' ), 'button button-small button-link-delete' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		Ui::footer();
	}
}

<?php
/**
 * Admin: Feed Manager (list, add/edit, test results).
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

use RMH\Db\FeedConfig;
use RMH\PostTypes;
use RMH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Feed CRUD screens. All mutations go through Actions (nonce + capability).
 */
final class FeedsPage {

	/**
	 * Render the requested view.
	 */
	public static function render() {
		if ( ! current_user_can( 'rmh_manage_feeds' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'rss-media-hub' ) );
		}
		$action = Ui::get( 'action' );
		if ( 'new' === $action || 'edit' === $action ) {
			self::form( absint( Ui::get( 'id' ) ) );
		} elseif ( 'test' === $action ) {
			self::test_result( absint( Ui::get( 'id' ) ) );
		} else {
			self::table();
		}
	}

	/**
	 * Feed list.
	 */
	private static function table() {
		$status = Ui::get( 'status' );
		$health = Ui::get( 'health' );
		$search = Ui::get( 's' );
		$page   = Ui::paged();
		$res    = rmh()->get( 'feeds' )->query(
			array(
				'status'   => $status,
				'health'   => $health,
				'search'   => $search,
				'page'     => $page,
				'per_page' => 30,
				'orderby'  => Ui::get( 'orderby' ),
				'order'    => Ui::get( 'order' ),
			)
		);
		Ui::header( __( 'Feed Manager', 'rss-media-hub' ), '<a class="page-title-action" href="' . esc_url( Ui::url( 'feeds', array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add feed', 'rss-media-hub' ) . '</a>' );
		?>
		<form method="get" class="rmh-filters">
			<input type="hidden" name="page" value="rmh-feeds">
			<label class="screen-reader-text" for="rmh-f-status"><?php esc_html_e( 'Status', 'rss-media-hub' ); ?></label>
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
			echo Ui::select(
				'status',
				array(
					''         => __( 'All statuses', 'rss-media-hub' ),
					'active'   => __( 'Active', 'rss-media-hub' ),
					'paused'   => __( 'Paused', 'rss-media-hub' ),
					'disabled' => __( 'Disabled', 'rss-media-hub' ),
				),
				$status,
				'rmh-f-status'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<label class="screen-reader-text" for="rmh-f-health"><?php esc_html_e( 'Health', 'rss-media-hub' ); ?></label>
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
			echo Ui::select(
				'health',
				array(
					''        => __( 'Any health', 'rss-media-hub' ),
					'ok'      => 'OK',
					'warning' => __( 'Warning', 'rss-media-hub' ),
					'error'   => __( 'Error', 'rss-media-hub' ),
					'new'     => __( 'Not polled yet', 'rss-media-hub' ),
				),
				$health,
				'rmh-f-health'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<label class="screen-reader-text" for="rmh-f-s"><?php esc_html_e( 'Search', 'rss-media-hub' ); ?></label>
			<input type="search" id="rmh-f-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name or URL', 'rss-media-hub' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'rss-media-hub' ); ?></button>
		</form>
		<?php
		if ( ! $res['rows'] ) {
			echo '<div class="rmh-empty"><p>' . esc_html__( 'No feeds match. Add your first RSS or Atom feed to start importing.', 'rss-media-hub' ) . '</p><p><a class="button button-primary" href="' . esc_url( Ui::url( 'feeds', array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add feed', 'rss-media-hub' ) . '</a></p></div>';
			Ui::footer();
			return;
		}
		?>
		<table class="widefat striped rmh-table">
			<thead><tr>
				<th><?php esc_html_e( 'Feed', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Status', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Health', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Last success', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Next poll', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Items', 'rss-media-hub' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'rss-media-hub' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $res['rows'] as $f ) : ?>
				<?php $cfg = FeedConfig::effective( $f, rmh()->get( 'settings' ) ); ?>
				<tr>
					<td data-label="<?php esc_attr_e( 'Feed', 'rss-media-hub' ); ?>">
						<strong><a href="
						<?php
						echo esc_url(
							Ui::url(
								'feeds',
								array(
									'action' => 'edit',
									'id'     => $f->id,
								)
							)
						);
						?>
											"><?php echo esc_html( $f->name ); ?></a></strong><br>
						<small><?php echo Ui::short_url( $f->url, 55 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?></small><br>
						<small class="rmh-muted"><?php echo esc_html( sprintf( '%s · %s · %s', $cfg['import_mode'], $cfg['content_mode'], $cfg['content_rating'] ) ); ?></small>
						<?php if ( 'error' === $f->health || 'warning' === $f->health ) : ?>
							<br><small class="rmh-error-text"><code><?php echo esc_html( $f->last_outcome ); ?></code> <?php echo esc_html( mb_substr( (string) $f->last_error, 0, 180 ) ); ?></small>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Status', 'rss-media-hub' ); ?>"><?php echo Ui::pill( $f->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td data-label="<?php esc_attr_e( 'Health', 'rss-media-hub' ); ?>"><?php echo Ui::pill( $f->health ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $f->consecutive_failures ? ' <small>×' . (int) $f->consecutive_failures . '</small>' : ''; ?></td>
					<td data-label="<?php esc_attr_e( 'Last success', 'rss-media-hub' ); ?>"><?php echo Ui::time( $f->last_success_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td data-label="<?php esc_attr_e( 'Next poll', 'rss-media-hub' ); ?>"><?php echo 'active' === $f->status ? Ui::time( $f->next_poll_at ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td data-label="<?php esc_attr_e( 'Items', 'rss-media-hub' ); ?>"><a href="
					<?php
					echo esc_url(
						Ui::url(
							'queue',
							array(
								'feed_id' => $f->id,
								'status'  => 'all',
							)
						)
					);
					?>
									"><?php echo esc_html( number_format_i18n( (int) $f->items_total ) ); ?></a></td>
					<td data-label="<?php esc_attr_e( 'Actions', 'rss-media-hub' ); ?>" class="rmh-actions">
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui::action_button().
						echo Ui::action_button(
							'rmh_feed_action',
							array(
								'id' => $f->id,
								'op' => 'test',
							),
							__( 'Test', 'rss-media-hub' )
						);
						echo Ui::action_button(
							'rmh_feed_action',
							array(
								'id' => $f->id,
								'op' => 'run',
							),
							__( 'Run now', 'rss-media-hub' )
						);
						if ( 'active' === $f->status ) {
							echo Ui::action_button(
								'rmh_feed_action',
								array(
									'id' => $f->id,
									'op' => 'pause',
								),
								__( 'Pause', 'rss-media-hub' )
							);
						} else {
							echo Ui::action_button(
								'rmh_feed_action',
								array(
									'id' => $f->id,
									'op' => 'resume',
								),
								__( 'Resume', 'rss-media-hub' )
							);
						}
						echo Ui::action_button(
							'rmh_feed_action',
							array(
								'id' => $f->id,
								'op' => 'delete',
							),
							__( 'Remove', 'rss-media-hub' ),
							'button button-small button-link-delete',
							__( 'Remove this feed? Imported posts are kept.', 'rss-media-hub' )
						);
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

	/**
	 * Add/edit form.
	 *
	 * @param int $id Feed id (0 = new).
	 */
	private static function form( $id ) {
		$feed = $id ? rmh()->get( 'feeds' )->find( $id ) : null;
		if ( $id && ! $feed ) {
			Ui::header( __( 'Feed not found', 'rss-media-hub' ) );
			echo '<p><a href="' . esc_url( Ui::url( 'feeds' ) ) . '">' . esc_html__( 'Back to feeds', 'rss-media-hub' ) . '</a></p>';
			Ui::footer();
			return;
		}
		$failed = get_transient( 'rmh_feed_form_' . get_current_user_id() );
		delete_transient( 'rmh_feed_form_' . get_current_user_id() );
		$c            = $feed ? $feed->config : FeedConfig::sanitize( is_array( $failed ) && isset( $failed['config'] ) ? (array) $failed['config'] : array() );
		$v            = array(
			'url'           => $feed ? $feed->url : ( is_array( $failed ) ? (string) $failed['url'] : '' ),
			'name'          => $feed ? $feed->name : ( is_array( $failed ) ? (string) $failed['name'] : '' ),
			'site_url'      => $feed ? (string) $feed->site_url : '',
			'status'        => $feed ? $feed->status : 'active',
			'poll_interval' => $feed ? (int) $feed->poll_interval : 0,
		);
		$inherit      = array( 'inherit' => __( 'Use global setting', 'rss-media-hub' ) );
		$intervals    = array(
			'0'      => __( 'Use global default', 'rss-media-hub' ),
			'900'    => __( 'Every 15 minutes', 'rss-media-hub' ),
			'1800'   => __( 'Every 30 minutes', 'rss-media-hub' ),
			'3600'   => __( 'Hourly', 'rss-media-hub' ),
			'10800'  => __( 'Every 3 hours', 'rss-media-hub' ),
			'21600'  => __( 'Every 6 hours', 'rss-media-hub' ),
			'43200'  => __( 'Twice daily', 'rss-media-hub' ),
			'86400'  => __( 'Daily', 'rss-media-hub' ),
			'custom' => __( 'Custom…', 'rss-media-hub' ),
		);
		$interval_sel = isset( $intervals[ (string) $v['poll_interval'] ] ) ? (string) $v['poll_interval'] : 'custom';
		$cats         = get_terms(
			array(
				'taxonomy'   => PostTypes::TAX_CATEGORY,
				'hide_empty' => false,
				'number'     => 300,
			)
		);
		Ui::header( $feed ? sprintf( /* translators: %s: feed name */ __( 'Edit feed: %s', 'rss-media-hub' ), $feed->name ) : __( 'Add feed', 'rss-media-hub' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rmh-form">
			<input type="hidden" name="action" value="rmh_save_feed">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'rmh_save_feed', '_rmh_nonce' ); ?>

			<h2><?php esc_html_e( 'Source', 'rss-media-hub' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="rmh-url"><?php esc_html_e( 'Feed URL', 'rss-media-hub' ); ?></label></th>
					<td><input type="url" id="rmh-url" name="url" class="large-text" required value="<?php echo esc_attr( $v['url'] ); ?>" placeholder="https://example.com/feed.xml">
					<p class="description"><?php esc_html_e( 'RSS 2.0, RSS 1.0/RDF or Atom. Private, loopback and link-local destinations are refused.', 'rss-media-hub' ); ?></p></td></tr>
				<tr><th scope="row"><label for="rmh-name"><?php esc_html_e( 'Name', 'rss-media-hub' ); ?></label></th>
					<td><input type="text" id="rmh-name" name="name" class="regular-text" value="<?php echo esc_attr( $v['name'] ); ?>"><p class="description"><?php esc_html_e( 'Shown in source attribution. Defaults to the host name.', 'rss-media-hub' ); ?></p></td></tr>
				<tr><th scope="row"><label for="rmh-site"><?php esc_html_e( 'Website URL', 'rss-media-hub' ); ?></label></th>
					<td><input type="url" id="rmh-site" name="site_url" class="regular-text" value="<?php echo esc_attr( $v['site_url'] ); ?>"><p class="description"><?php esc_html_e( 'Optional; detected from the feed when empty.', 'rss-media-hub' ); ?></p></td></tr>
				<tr><th scope="row"><label for="rmh-status"><?php esc_html_e( 'Status', 'rss-media-hub' ); ?></label></th>
					<td>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
					echo Ui::select(
						'status',
						array(
							'active'   => __( 'Active', 'rss-media-hub' ),
							'paused'   => __( 'Paused', 'rss-media-hub' ),
							'disabled' => __( 'Disabled', 'rss-media-hub' ),
						),
						$v['status'],
						'rmh-status'
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					</td></tr>
				<tr><th scope="row"><label for="rmh-interval"><?php esc_html_e( 'Polling interval', 'rss-media-hub' ); ?></label></th>
					<td><?php echo Ui::select( 'poll_interval', $intervals, $interval_sel, 'rmh-interval' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="rmh-custom-interval"><label><input type="number" min="5" max="10080" name="poll_minutes" value="<?php echo esc_attr( (string) max( 5, (int) round( $v['poll_interval'] / 60 ) ) ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'rss-media-hub' ); ?></label></span></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Authentication', 'rss-media-hub' ); ?></th>
					<td><label><?php esc_html_e( 'User', 'rss-media-hub' ); ?> <input type="text" name="config[auth_user]" value="<?php echo esc_attr( (string) $c['auth_user'] ); ?>" autocomplete="off"></label>
						<label><?php esc_html_e( 'Password', 'rss-media-hub' ); ?> <input type="password" name="config[auth_pass]" value="" autocomplete="new-password" placeholder="<?php echo '' !== (string) $c['auth_pass'] ? esc_attr__( '(saved – leave blank to keep)', 'rss-media-hub' ) : ''; ?>"></label>
						<?php
						if ( '' !== (string) $c['auth_pass'] ) :
							?>
							<label><input type="checkbox" name="config[auth_pass_clear]" value="1"> <?php esc_html_e( 'Clear saved password', 'rss-media-hub' ); ?></label><?php endif; ?>
						<p class="description"><?php esc_html_e( 'Optional HTTP Basic credentials, stored encrypted and only sent to the feed\'s own origin. Never bypass access restrictions you are not authorized to use.', 'rss-media-hub' ); ?></p></td></tr>
			</tbody></table>

			<h2><?php esc_html_e( 'Import and publishing', 'rss-media-hub' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Import mode', 'rss-media-hub' ); ?></th>
					<td>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
					echo Ui::select(
						'config[import_mode]',
						$inherit + array(
							'auto'    => __( 'Automatic publishing', 'rss-media-hub' ),
							'pending' => __( 'Pending review (create draft/pending posts)', 'rss-media-hub' ),
							'manual'  => __( 'Manual (queue only, publish on approval)', 'rss-media-hub' ),
						),
						(string) $c['import_mode']
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Content mode', 'rss-media-hub' ); ?></th>
					<td>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
					echo Ui::select(
						'config[content_mode]',
						$inherit + array(
							'article_media' => __( 'Articles with or without media', 'rss-media-hub' ),
							'media_only'    => __( 'Media only (skip items without qualifying media)', 'rss-media-hub' ),
						),
						(string) $c['content_mode']
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Article text', 'rss-media-hub' ); ?></th>
					<td>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
					echo Ui::select(
						'config[content_policy]',
						$inherit + array(
							'excerpt' => __( 'Excerpt only (recommended)', 'rss-media-hub' ),
							'full'    => __( 'Full content (only with republishing permission)', 'rss-media-hub' ),
						),
						(string) $c['content_policy']
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'When items change', 'rss-media-hub' ); ?></th>
					<td>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
					echo Ui::select(
						'config[update_policy]',
						$inherit + array(
							'none'     => __( 'Never update', 'rss-media-hub' ),
							'metadata' => __( 'Update title, excerpt and media (keep editor changes)', 'rss-media-hub' ),
							'full'     => __( 'Also update content (keep editor changes)', 'rss-media-hub' ),
						),
						(string) $c['update_policy']
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Max items per run', 'rss-media-hub' ); ?></th>
					<td><input type="number" min="0" max="200" name="config[max_items]" value="<?php echo esc_attr( (string) (int) $c['max_items'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( '0 = global setting', 'rss-media-hub' ); ?></span></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Categories', 'rss-media-hub' ); ?></th>
					<td>
						<?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
							<fieldset class="rmh-checklist">
								<?php foreach ( $cats as $t ) : ?>
									<label><input type="checkbox" name="config[categories][]" value="<?php echo (int) $t->term_id; ?>" <?php checked( in_array( (int) $t->term_id, (array) $c['categories'], true ) ); ?>> <?php echo esc_html( $t->name ); ?></label>
								<?php endforeach; ?>
							</fieldset>
						<?php else : ?>
							<p class="description"><a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . PostTypes::TAX_CATEGORY . '&post_type=' . PostTypes::POST_TYPE ) ); ?>"><?php esc_html_e( 'Create media categories', 'rss-media-hub' ); ?></a></p>
						<?php endif; ?>
					</td></tr>
				<tr><th scope="row"><label for="rmh-tags"><?php esc_html_e( 'Tags', 'rss-media-hub' ); ?></label></th>
					<td><input type="text" id="rmh-tags" name="config[tags]" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) $c['tags'] ) ); ?>"><p class="description"><?php esc_html_e( 'Comma-separated tags added to every item from this feed.', 'rss-media-hub' ); ?></p></td></tr>
				<tr><th scope="row"><label for="rmh-attr"><?php esc_html_e( 'Attribution template', 'rss-media-hub' ); ?></label></th>
					<td><input type="text" id="rmh-attr" name="config[attribution_template]" class="large-text" value="<?php echo esc_attr( (string) $c['attribution_template'] ); ?>" placeholder="<?php echo esc_attr( (string) rmh()->get( 'settings' )->get( 'attribution_template' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Empty = global template. Placeholders: {feed_name} {site_url} {source_url} {source_host} {author} {author_suffix} {date}. Allowed HTML: a, strong, em, span, br.', 'rss-media-hub' ); ?></p></td></tr>
			</tbody></table>

			<h2><?php esc_html_e( 'Media', 'rss-media-hub' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Allowed media types', 'rss-media-hub' ); ?></th>
					<td><fieldset class="rmh-checklist">
						<?php foreach ( PostTypes::media_type_labels() as $k => $label ) : ?>
							<label><input type="checkbox" name="config[allowed_media_types][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $c['allowed_media_types'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset><p class="description"><?php esc_html_e( 'None checked = global setting. A feed can only narrow the global allowlist.', 'rss-media-hub' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Media handling', 'rss-media-hub' ); ?></th>
					<td>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
					echo Ui::select(
						'config[media_handling]',
						$inherit + array(
							'remote_embed' => __( 'Remote embedding (play from source)', 'rss-media-hub' ),
							'remote_link'  => __( 'Remote linking (thumbnail + link only)', 'rss-media-hub' ),
							'hybrid'       => __( 'Hybrid (local thumbnails, remote media)', 'rss-media-hub' ),
							'local_import' => __( 'Authorized local import (download media)', 'rss-media-hub' ),
						),
						(string) $c['media_handling']
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Extraction rules', 'rss-media-hub' ); ?></th>
					<td><fieldset>
						<label><input type="checkbox" name="config[extract_html]" value="1" <?php checked( ! empty( $c['extract_html'] ) ); ?>> <?php esc_html_e( 'Find media in item HTML (description/content)', 'rss-media-hub' ); ?></label><br>
						<label><input type="checkbox" name="config[allow_embeds]" value="1" <?php checked( ! empty( $c['allow_embeds'] ) ); ?>> <?php esc_html_e( 'Allow trusted provider embeds for this feed', 'rss-media-hub' ); ?></label><br>
						<label><?php esc_html_e( 'Fetch linked article page for media:', 'rss-media-hub' ); ?>
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
						echo Ui::select(
							'config[fetch_article]',
							$inherit + array(
								'yes' => __( 'Yes', 'rss-media-hub' ),
								'no'  => __( 'No', 'rss-media-hub' ),
							),
							(string) $c['fetch_article']
						);
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						</label>
					</fieldset></td></tr>
			</tbody></table>

			<h2><?php esc_html_e( 'Rights and content classification', 'rss-media-hub' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Content rating', 'rss-media-hub' ); ?></th>
					<td>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select() escapes everything it outputs.
					echo Ui::select(
						'config[content_rating]',
						$inherit + array(
							'unknown' => __( 'Unknown / not classified', 'rss-media-hub' ),
							'general' => __( 'General audience', 'rss-media-hub' ),
							'adult'   => __( 'Adult (explicit)', 'rss-media-hub' ),
						),
						(string) $c['content_rating']
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<p class="description"><?php esc_html_e( 'Classification is your decision; it is never inferred from the feed URL or domain. Individual posts can be reclassified by moderators.', 'rss-media-hub' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Permission', 'rss-media-hub' ); ?></th>
					<td><label><input type="checkbox" name="config[rights_confirmed]" value="1" <?php checked( ! empty( $c['rights_confirmed'] ) ); ?>> <?php esc_html_e( 'I have the rights or written permission to store this source\'s media locally / republish its content.', 'rss-media-hub' ); ?></label>
					<p class="description"><?php esc_html_e( 'Required for local import, hybrid thumbnails and full-content publishing to be meaningful. Public RSS availability alone does not grant republishing rights.', 'rss-media-hub' ); ?></p>
					<textarea name="config[rights_note]" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Licence / permission reference (internal note)', 'rss-media-hub' ); ?>"><?php echo esc_textarea( (string) $c['rights_note'] ); ?></textarea></td></tr>
			</tbody></table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save feed', 'rss-media-hub' ); ?></button>
				<button type="submit" class="button" name="test_after" value="1"><?php esc_html_e( 'Save and test', 'rss-media-hub' ); ?></button>
				<a class="button-link" href="<?php echo esc_url( Ui::url( 'feeds' ) ); ?>"><?php esc_html_e( 'Cancel', 'rss-media-hub' ); ?></a>
			</p>
		</form>
		<?php
		if ( $feed ) {
			echo '<div class="rmh-panel"><h2>' . esc_html__( 'Health', 'rss-media-hub' ) . '</h2><table class="widefat rmh-kv"><tbody>';
			echo '<tr><th>' . esc_html__( 'Health', 'rss-media-hub' ) . '</th><td>' . Ui::pill( $feed->health ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<tr><th>' . esc_html__( 'Last poll', 'rss-media-hub' ) . '</th><td>' . Ui::time( $feed->last_polled_at ) . ' (HTTP ' . (int) $feed->last_http_status . ', ' . esc_html( $feed->last_outcome ) . ')</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<tr><th>' . esc_html__( 'Last success', 'rss-media-hub' ) . '</th><td>' . Ui::time( $feed->last_success_at ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<tr><th>' . esc_html__( 'Next poll', 'rss-media-hub' ) . '</th><td>' . Ui::time( $feed->next_poll_at ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<tr><th>' . esc_html__( 'Consecutive failures', 'rss-media-hub' ) . '</th><td>' . (int) $feed->consecutive_failures . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Last error', 'rss-media-hub' ) . '</th><td>' . esc_html( (string) $feed->last_error ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Conditional requests', 'rss-media-hub' ) . '</th><td>ETag: <code>' . esc_html( $feed->etag ? $feed->etag : '—' ) . '</code> Last-Modified: <code>' . esc_html( $feed->last_modified ? $feed->last_modified : '—' ) . '</code></td></tr>';
			echo '</tbody></table><p><a href="' . esc_url( Ui::url( 'logs', array( 'feed_id' => $feed->id ) ) ) . '">' . esc_html__( 'Logs for this feed', 'rss-media-hub' ) . '</a> · <a href="' . esc_url(
				Ui::url(
					'logs',
					array(
						'tab'     => 'jobs',
						'feed_id' => $feed->id,
					)
				)
			) . '">' . esc_html__( 'Import history', 'rss-media-hub' ) . '</a></p></div>';
		}
		Ui::footer();
	}

	/**
	 * Show the stored test report.
	 *
	 * @param int $id Feed id.
	 */
	private static function test_result( $id ) {
		$report = get_transient( 'rmh_test_' . get_current_user_id() );
		Ui::header( __( 'Feed test', 'rss-media-hub' ) );
		echo '<p><a href="' . esc_url(
			Ui::url(
				'feeds',
				array(
					'action' => 'edit',
					'id'     => $id,
				)
			)
		) . '">&larr; ' . esc_html__( 'Back to feed', 'rss-media-hub' ) . '</a></p>';
		self::report( $report );
		Ui::footer();
	}

	/**
	 * Render a test report.
	 *
	 * @param mixed $report Report array.
	 */
	public static function report( $report ) {
		if ( ! is_array( $report ) ) {
			echo '<p>' . esc_html__( 'No test result available. Run a test again.', 'rss-media-hub' ) . '</p>';
			return;
		}
		echo '<div class="rmh-panel ' . ( $report['ok'] ? '' : 'rmh-panel--bad' ) . '"><h2>' . ( $report['ok'] ? esc_html__( 'Feed is valid', 'rss-media-hub' ) : esc_html__( 'Feed test failed', 'rss-media-hub' ) ) . '</h2>';
		echo '<table class="widefat rmh-kv"><tbody>';
		echo '<tr><th>URL</th><td>' . esc_html( $report['url'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Outcome', 'rss-media-hub' ) . '</th><td><code>' . esc_html( $report['outcome'] ) . '</code> ' . esc_html( $report['message'] ) . '</td></tr>';
		echo '<tr><th>HTTP</th><td>' . (int) $report['http_status'] . ( $report['redirects'] ? ' (' . (int) $report['redirects'] . ' ' . esc_html__( 'redirects', 'rss-media-hub' ) . ')' : '' ) . '</td></tr>';
		if ( $report['ok'] ) {
			echo '<tr><th>' . esc_html__( 'Format', 'rss-media-hub' ) . '</th><td>' . esc_html( strtoupper( $report['format'] ) ) . ' – ' . esc_html( $report['title'] ) . '</td></tr>';
		}
		foreach ( $report['warnings'] as $w ) {
			echo '<tr><th>' . esc_html__( 'Warning', 'rss-media-hub' ) . '</th><td>' . esc_html( $w ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $report['items'] ) ) {
			echo '<h3>' . esc_html__( 'First items (nothing was imported)', 'rss-media-hub' ) . '</h3><table class="widefat striped rmh-table"><thead><tr><th>' . esc_html__( 'Title', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Date', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Classified as', 'rss-media-hub' ) . '</th><th>' . esc_html__( 'Media found', 'rss-media-hub' ) . '</th></tr></thead><tbody>';
			foreach ( $report['items'] as $i ) {
				echo '<tr><td data-label="' . esc_attr__( 'Title', 'rss-media-hub' ) . '">' . esc_html( $i['title'] ) . '<br><small>' . Ui::short_url( $i['link'], 60 ) . '</small></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td data-label="' . esc_attr__( 'Date', 'rss-media-hub' ) . '">' . ( $i['date_valid'] ? esc_html( (string) $i['published'] ) : Ui::pill( 'warning', __( 'missing/invalid', 'rss-media-hub' ) ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td data-label="' . esc_attr__( 'Classified as', 'rss-media-hub' ) . '">' . esc_html( $i['media_type'] ) . '</td><td data-label="' . esc_attr__( 'Media found', 'rss-media-hub' ) . '"><ul class="rmh-plain">';
				foreach ( $i['media'] as $m ) {
					echo '<li><code>' . esc_html( $m['type'] ) . '</code> <small>' . esc_html( $m['method'] . ( $m['provider'] ? ' / ' . $m['provider'] : '' ) . ( $m['mime'] ? ' / ' . $m['mime'] : '' ) ) . '</small><br><small>' . Ui::short_url( $m['url'], 50 ) . '</small></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</ul></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}

<?php
/**
 * Uninstall routine. Behaviour is controlled by Settings → "On uninstall":
 *   keep_all     (default) – nothing is deleted; reinstalling resumes where you left off.
 *   keep_content – imported posts, terms and attachments are kept; plugin tables,
 *                  options, capabilities and scheduled events are removed.
 *   delete_all   – additionally deletes every imported post (and attachments the
 *                  plugin downloaded) and the plugin's taxonomy terms.
 *
 * @package RSSMediaHub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'rmh_uninstall_site' ) ) :
	/**
	 * Uninstall a single site.
	 */
	function rmh_uninstall_site() {
		global $wpdb;
		$settings = get_option( 'rmh_settings', array() );
		$mode     = is_array( $settings ) && isset( $settings['uninstall_mode'] ) ? $settings['uninstall_mode'] : 'keep_all';

		// Scheduled events never outlive the plugin.
		foreach ( array( 'rmh_tick', 'rmh_daily', 'rmh_tick_now' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_unschedule_hook( 'rmh_run_feed' );

		if ( 'keep_all' === $mode ) {
			return;
		}

		if ( 'delete_all' === $mode ) {
			// Delete imported posts in bounded batches (attachments created by the plugin first).
			do {
				$ids = get_posts(
					array(
						'post_type'      => 'rmh_item',
						'post_status'    => 'any',
						'fields'         => 'ids',
						'posts_per_page' => 200,
						'no_found_rows'  => true,
					)
				);
				foreach ( $ids as $id ) {
					$attachments = get_posts(
						array(
							'post_type'      => 'attachment',
							'post_parent'    => $id,
							'fields'         => 'ids',
							'posts_per_page' => -1,
							'meta_key'       => '_rmh_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
						)
					);
					foreach ( $attachments as $att ) {
							wp_delete_attachment( $att, true );
					}
					wp_delete_post( $id, true );
				}
			} while ( $ids );

			foreach ( array( 'rmh_category', 'rmh_tag', 'rmh_media_type', 'rmh_source' ) as $taxonomy ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$term_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) );
				foreach ( $term_ids as $term_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->delete(
						$wpdb->term_taxonomy,
						array(
							'term_id'  => (int) $term_id,
							'taxonomy' => $taxonomy,
						)
					);
					$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}

		foreach ( array( 'feeds', 'items', 'media', 'jobs', 'logs', 'reports', 'blocklist' ) as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rmh_{$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		foreach ( array( 'rmh_settings', 'rmh_db_version', 'rmh_installed_at', 'rmh_last_tick', 'rmh_last_tick_done', 'rmh_flush_rewrites', 'rmh_lock_tick' ) as $opt ) {
			delete_option( $opt );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_rmh\_%' OR option_name LIKE '\_transient\_timeout\_rmh\_%'" );
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( 'rmh_manage_feeds' );
			$role->remove_cap( 'rmh_moderate' );
		}
	}
endif;

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 500,
		)
	) as $rmh_site ) {
		switch_to_blog( $rmh_site );
		rmh_uninstall_site();
		restore_current_blog();
	}
} else {
	rmh_uninstall_site();
}

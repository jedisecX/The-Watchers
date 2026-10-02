<?php
/**
 * Copyable, redacted diagnostic report.
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

use RMH\Security\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a plain-text report for support requests. URLs are redacted, no
 * credentials, cookies, salts or personal data are included.
 */
final class Diagnostics {

	/**
	 * Build the report.
	 *
	 * @return string
	 */
	public static function report() {
		global $wpdb, $wp_version;
		$s     = rmh()->get( 'settings' )->all();
		$sched = rmh()->get( 'scheduler' )->status();
		$lines = array();

		$lines[] = '=== RSS Media Hub diagnostics (' . gmdate( 'c' ) . ') ===';
		$lines[] = 'Plugin: ' . RMH_VERSION . ' (db schema ' . get_option( \RMH\Installer::DB_OPTION ) . ')';
		$lines[] = 'WordPress: ' . $wp_version . ( is_multisite() ? ' (multisite)' : '' );
		$lines[] = 'PHP: ' . PHP_VERSION . ' | memory_limit ' . ini_get( 'memory_limit' ) . ' | max_execution_time ' . ini_get( 'max_execution_time' );
		$lines[] = 'Database: ' . ( method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : 'unknown' ) . ( defined( 'DB_ENGINE' ) ? ' engine=' . DB_ENGINE : '' );
		$lines[] = 'Extensions: curl=' . ( function_exists( 'curl_version' ) ? curl_version()['version'] : 'no' ) . ' libxml=' . LIBXML_DOTTED_VERSION . ' dom=' . ( class_exists( 'DOMDocument' ) ? 'yes' : 'no' ) . ' fileinfo=' . ( function_exists( 'finfo_open' ) ? 'yes' : 'no' ) . ' sodium=' . ( function_exists( 'sodium_crypto_secretbox' ) ? 'yes' : 'no' );
		$lines[] = 'Object cache: ' . ( wp_using_ext_object_cache() ? 'external' : 'none' );
		$lines[] = 'Page cache plugins: ' . implode( ', ', self::cache_plugins() );
		$lines[] = 'Theme: ' . wp_get_theme()->get( 'Name' ) . ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ? ' (block theme)' : ' (classic)' );
		$lines[] = '';
		$lines[] = '--- Scheduling ---';
		$lines[] = 'WP-Cron disabled: ' . ( $sched['wp_cron_disabled'] ? 'yes' : 'no' ) . ' | alternate cron: ' . ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ? 'yes' : 'no' );
		$lines[] = 'Next tick: ' . ( $sched['next_tick'] ? gmdate( 'c', $sched['next_tick'] ) : 'NOT SCHEDULED' ) . ' | last tick: ' . ( $sched['last_tick'] ? gmdate( 'c', $sched['last_tick'] ) . ' (' . $sched['last_source'] . ', ' . $sched['last_duration'] . 's)' : 'never' );
		$lines[] = 'Overdue: ' . ( $sched['overdue'] ? 'YES' : 'no' ) . ' | lock held: ' . ( $sched['lock_held'] ? 'yes until ' . gmdate( 'c', $sched['lock_expires'] ) : 'no' );
		$lines[] = '';
		$lines[] = '--- Settings (non-sensitive) ---';
		foreach ( $s as $k => $v ) {
			if ( in_array( $k, array( 'moderation_email', 'custom_providers', 'attribution_template', 'content_warning', 'age_gate_message' ), true ) ) {
				$v = '' === (string) ( is_array( $v ) ? implode( ',', $v ) : $v ) ? '(empty)' : '(set)';
			}
			$lines[] = $k . ' = ' . ( is_array( $v ) ? implode( ',', $v ) : ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v ) );
		}
		$lines[] = '';
		$lines[] = '--- Feeds ---';
		$lines[] = (string) wp_json_encode( rmh()->get( 'feeds' )->counts() );
		foreach ( rmh()->get( 'feeds' )->query(
			array(
				'per_page' => 50,
				'orderby'  => 'health',
			)
		)['rows'] as $f ) {
			$lines[] = sprintf(
				'#%d %s | %s/%s | http %d %s | fails %d | last ok %s | next %s | auth %s%s',
				$f->id,
				Redactor::url( $f->url ),
				$f->status,
				$f->health,
				$f->last_http_status,
				$f->last_outcome,
				$f->consecutive_failures,
				$f->last_success_at ? $f->last_success_at : '-',
				$f->next_poll_at ? $f->next_poll_at : '-',
				'' !== (string) $f->config['auth_user'] ? 'yes' : 'no',
				$f->last_error ? ' | error: ' . Redactor::text( mb_substr( (string) $f->last_error, 0, 200 ) ) : ''
			);
		}
		$lines[] = '';
		$lines[] = '--- Items / media ---';
		$lines[] = 'Items: ' . wp_json_encode( rmh()->get( 'items' )->counts_by_status() );
		$lines[] = 'Media validation: ' . wp_json_encode( rmh()->get( 'media' )->counts_by_validation() );
		$lines[] = 'Local storage bytes: ' . rmh()->get( 'media' )->local_bytes_total();
		$lines[] = '';
		$lines[] = '--- Recent errors ---';
		foreach ( rmh()->get( 'logger' )->query(
			array(
				'level'    => 'error',
				'per_page' => 25,
			)
		)['rows'] as $row ) {
			$lines[] = sprintf( '%s [%s] feed=%d item=%d %s', $row->created_at, $row->channel, $row->feed_id, $row->item_id, Redactor::text( $row->message ) );
		}
		$lines[] = '';
		$lines[] = '--- Recent jobs ---';
		foreach ( rmh()->get( 'jobs' )->query( array( 'per_page' => 15 ) )['rows'] as $j ) {
			$lines[] = sprintf( '%s %s feed=%d %s/%s http=%d new=%d dup=%d created=%d updated=%d review=%d media=%d xfail=%d pfail=%d', $j->started_at, $j->type, $j->feed_id, $j->status, $j->outcome, $j->http_status, $j->items_new, $j->duplicates, $j->posts_created, $j->posts_updated, $j->pending_review, $j->media_found, $j->extraction_failures, $j->publish_failures );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Detected page-cache plugins.
	 *
	 * @return string[]
	 */
	public static function cache_plugins() {
		$found = array();
		$map   = array(
			'wp_cache_post_change'      => 'WP Super Cache',
			'w3tc_flush_post'           => 'W3 Total Cache',
			'rocket_clean_post'         => 'WP Rocket',
			'sg_cachepress_purge_cache' => 'SiteGround Optimizer',
		);
		foreach ( $map as $fn => $name ) {
			if ( function_exists( $fn ) ) {
				$found[] = $name;
			}
		}
		if ( defined( 'LSCWP_V' ) ) {
			$found[] = 'LiteSpeed Cache';
		}
		if ( defined( 'WP_CACHE' ) && WP_CACHE && ! $found ) {
			$found[] = 'WP_CACHE enabled (unknown drop-in)';
		}
		return $found ? $found : array( 'none detected' );
	}
}

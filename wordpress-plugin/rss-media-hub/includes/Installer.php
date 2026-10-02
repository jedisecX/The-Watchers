<?php
/**
 * Installation, schema migrations, capabilities, activation and deactivation.
 *
 * @package RSSMediaHub
 */

namespace RMH;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables. All schema changes are
 * additive (dbDelta) and never touch posts, terms or attachments.
 */
final class Installer {

	const DB_OPTION = 'rmh_db_version';

	/**
	 * Custom capabilities and the roles that receive them on activation.
	 */
	const CAPS = array(
		'rmh_manage_feeds' => array( 'administrator' ),
		'rmh_moderate'     => array( 'administrator', 'editor' ),
	);

	/**
	 * Table names (unprefixed).
	 */
	const TABLES = array( 'rmh_feeds', 'rmh_items', 'rmh_media', 'rmh_jobs', 'rmh_logs', 'rmh_reports', 'rmh_blocklist' );

	/**
	 * Prefixed table name.
	 *
	 * @param string $name Short name, e.g. 'feeds'.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'rmh_' . $name;
	}

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 500,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Activate on the current site.
	 */
	public static function activate_site() {
		self::install();
		self::add_caps();
		( new PostTypes() )->register();
		Frontend\Frontend::add_rewrites();
		flush_rewrite_rules( false );
		Cron\Scheduler::ensure_scheduled();
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
	}

	/**
	 * Deactivation: stop scheduled work, keep every row and post.
	 */
	public static function deactivate() {
		Cron\Scheduler::unschedule_all();
		flush_rewrite_rules( false );
	}

	/**
	 * Run install/migrations when the stored schema version is behind.
	 */
	public static function maybe_upgrade() {
		$stored = (string) get_option( self::DB_OPTION, '0' );
		if ( version_compare( $stored, RMH_DB_VERSION, '>=' ) ) {
			return;
		}
		self::install();
		self::add_caps();
		Cron\Scheduler::ensure_scheduled();
		update_option( 'rmh_flush_rewrites', 1, false );
	}

	/**
	 * Create/upgrade tables and run versioned data migrations.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$from = (string) get_option( self::DB_OPTION, '0' );
		foreach ( self::schema( $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		foreach ( self::migrations() as $version => $callback ) {
			if ( version_compare( $from, (string) $version, '<' ) ) {
				call_user_func( $callback );
			}
		}
		update_option( self::DB_OPTION, RMH_DB_VERSION, false );
		update_option( 'rmh_installed_at', get_option( 'rmh_installed_at', gmdate( 'c' ) ), false );
	}

	/**
	 * Versioned data migrations (keyed by the db version that introduces them).
	 * Each callback must be idempotent.
	 *
	 * @return array<string,callable>
	 */
	public static function migrations() {
		return apply_filters(
			'rmh_migrations',
			array(
				'1' => array( __CLASS__, 'migrate_1_seed_media_type_terms' ),
			)
		);
	}

	/**
	 * Migration 1: make sure the fixed media-type terms exist.
	 */
	public static function migrate_1_seed_media_type_terms() {
		( new PostTypes() )->register();
		PostTypes::ensure_media_type_terms();
	}

	/**
	 * dbDelta-compatible schema.
	 *
	 * @param string $collate Charset/collation clause.
	 * @return string[]
	 */
	public static function schema( $collate ) {
		$feeds     = self::table( 'feeds' );
		$items     = self::table( 'items' );
		$media     = self::table( 'media' );
		$jobs      = self::table( 'jobs' );
		$logs      = self::table( 'logs' );
		$reports   = self::table( 'reports' );
		$blocklist = self::table( 'blocklist' );

		return array(
			"CREATE TABLE {$feeds} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL DEFAULT '',
  url text NOT NULL,
  url_hash char(40) NOT NULL,
  site_url text NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  health varchar(20) NOT NULL DEFAULT 'new',
  poll_interval int(10) unsigned NOT NULL DEFAULT 0,
  next_poll_at datetime NULL DEFAULT NULL,
  last_polled_at datetime NULL DEFAULT NULL,
  last_success_at datetime NULL DEFAULT NULL,
  last_http_status smallint(5) unsigned NOT NULL DEFAULT 0,
  last_outcome varchar(40) NOT NULL DEFAULT '',
  last_error text NULL,
  consecutive_failures int(10) unsigned NOT NULL DEFAULT 0,
  etag varchar(255) NOT NULL DEFAULT '',
  last_modified varchar(100) NOT NULL DEFAULT '',
  lease_token varchar(40) NOT NULL DEFAULT '',
  lease_until datetime NULL DEFAULT NULL,
  settings longtext NULL,
  items_total int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY status_next (status,next_poll_at)
) {$collate};",
			"CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  feed_id bigint(20) unsigned NOT NULL,
  identity_hash char(40) NOT NULL,
  guid_hash char(40) NOT NULL DEFAULT '',
  url_hash char(40) NOT NULL DEFAULT '',
  guid text NULL,
  source_url text NULL,
  title text NULL,
  status varchar(20) NOT NULL DEFAULT 'discovered',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  media_type varchar(20) NOT NULL DEFAULT 'text',
  content_rating varchar(20) NOT NULL DEFAULT 'unknown',
  payload longtext NULL,
  content_hash char(40) NOT NULL DEFAULT '',
  source_published_at datetime NULL DEFAULT NULL,
  discovered_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  last_error text NULL,
  next_attempt_at datetime NULL DEFAULT NULL,
  duplicate_of bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY identity_hash (identity_hash),
  KEY feed_status (feed_id,status),
  KEY url_hash (url_hash),
  KEY guid_hash (guid_hash),
  KEY status_next (status,next_attempt_at),
  KEY post_id (post_id)
) {$collate};",
			"CREATE TABLE {$media} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  position smallint(5) unsigned NOT NULL DEFAULT 0,
  media_type varchar(20) NOT NULL,
  url text NOT NULL,
  url_hash char(40) NOT NULL,
  mime_type varchar(100) NOT NULL DEFAULT '',
  thumbnail_url text NULL,
  width int(10) unsigned NOT NULL DEFAULT 0,
  height int(10) unsigned NOT NULL DEFAULT 0,
  duration int(10) unsigned NOT NULL DEFAULT 0,
  filesize bigint(20) unsigned NOT NULL DEFAULT 0,
  provider varchar(50) NOT NULL DEFAULT '',
  embed_url text NULL,
  extraction_method varchar(30) NOT NULL DEFAULT '',
  priority tinyint(3) unsigned NOT NULL DEFAULT 9,
  validation_status varchar(20) NOT NULL DEFAULT 'unchecked',
  validation_message varchar(255) NOT NULL DEFAULT '',
  checked_at datetime NULL DEFAULT NULL,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  local_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  download_status varchar(20) NOT NULL DEFAULT '',
  title text NULL,
  description text NULL,
  credit varchar(255) NOT NULL DEFAULT '',
  retrieved_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY item_id (item_id),
  KEY post_id (post_id),
  KEY validation (validation_status,checked_at),
  KEY media_type (media_type),
  KEY url_hash (url_hash),
  KEY download_status (download_status)
) {$collate};",
			"CREATE TABLE {$jobs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL DEFAULT 'poll',
  feed_id bigint(20) unsigned NOT NULL DEFAULT 0,
  trigger_source varchar(20) NOT NULL DEFAULT 'cron',
  status varchar(20) NOT NULL DEFAULT 'running',
  outcome varchar(40) NOT NULL DEFAULT '',
  http_status smallint(5) unsigned NOT NULL DEFAULT 0,
  started_at datetime NOT NULL,
  finished_at datetime NULL DEFAULT NULL,
  items_fetched int(10) unsigned NOT NULL DEFAULT 0,
  items_new int(10) unsigned NOT NULL DEFAULT 0,
  duplicates int(10) unsigned NOT NULL DEFAULT 0,
  posts_created int(10) unsigned NOT NULL DEFAULT 0,
  posts_updated int(10) unsigned NOT NULL DEFAULT 0,
  pending_review int(10) unsigned NOT NULL DEFAULT 0,
  media_found int(10) unsigned NOT NULL DEFAULT 0,
  extraction_failures int(10) unsigned NOT NULL DEFAULT 0,
  publish_failures int(10) unsigned NOT NULL DEFAULT 0,
  skipped int(10) unsigned NOT NULL DEFAULT 0,
  message text NULL,
  PRIMARY KEY  (id),
  KEY feed_started (feed_id,started_at),
  KEY status_started (status,started_at)
) {$collate};",
			"CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  level varchar(10) NOT NULL DEFAULT 'info',
  channel varchar(20) NOT NULL DEFAULT 'import',
  feed_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  job_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message text NOT NULL,
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY channel_level (channel,level),
  KEY feed_id (feed_id)
) {$collate};",
			"CREATE TABLE {$reports} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reason varchar(30) NOT NULL,
  details text NULL,
  contact varchar(200) NOT NULL DEFAULT '',
  reporter_hash char(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'open',
  created_at datetime NOT NULL,
  resolved_at datetime NULL DEFAULT NULL,
  resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  resolution text NULL,
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY post_id (post_id)
) {$collate};",
			"CREATE TABLE {$blocklist} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL,
  value text NOT NULL,
  value_hash char(40) NOT NULL,
  reason text NULL,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY type_hash (type,value_hash)
) {$collate};",
		);
	}

	/**
	 * Grant custom capabilities.
	 */
	public static function add_caps() {
		foreach ( self::CAPS as $cap => $roles ) {
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );
				if ( $role && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Remove custom capabilities from every role.
	 */
	public static function remove_caps() {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( array_keys( self::CAPS ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}

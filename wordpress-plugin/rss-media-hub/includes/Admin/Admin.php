<?php
/**
 * Admin menu, assets and notices.
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "RSS Media Hub" menu. Every submenu renders a working page.
 */
class Admin {

	/**
	 * Hook suffixes of plugin pages (assets are only loaded there).
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( SettingsPage::class, 'register' ) );
		add_action( 'admin_notices', array( $this, 'global_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RMH_FILE ), array( $this, 'plugin_links' ) );
		add_action( 'add_meta_boxes_' . PostTypes::POST_TYPE, array( $this, 'meta_box' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hooks[] = add_menu_page(
			__( 'RSS Media Hub', 'rss-media-hub' ),
			__( 'RSS Media Hub', 'rss-media-hub' ),
			'rmh_moderate',
			'rmh-dashboard',
			array( DashboardPage::class, 'render' ),
			'dashicons-rss',
			26
		);
		$pages         = array(
			array( 'rmh-dashboard', __( 'Dashboard', 'rss-media-hub' ), 'rmh_moderate', array( DashboardPage::class, 'render' ) ),
			array( 'rmh-feeds', __( 'Feed Manager', 'rss-media-hub' ), 'rmh_manage_feeds', array( FeedsPage::class, 'render' ) ),
			array( 'rmh-queue', __( 'Import Queue', 'rss-media-hub' ), 'rmh_moderate', array( QueuePage::class, 'render' ) ),
			array( 'rmh-media', __( 'Media Library', 'rss-media-hub' ), 'rmh_moderate', array( MediaPage::class, 'render' ) ),
			array( 'rmh-moderation', __( 'Reports & Moderation', 'rss-media-hub' ), 'rmh_moderate', array( ModerationPage::class, 'render' ) ),
			array( 'rmh-restrictions', __( 'Source Restrictions', 'rss-media-hub' ), 'rmh_manage_feeds', array( RestrictionsPage::class, 'render' ) ),
			array( 'rmh-settings', __( 'Settings', 'rss-media-hub' ), 'manage_options', array( SettingsPage::class, 'render' ) ),
			array( 'rmh-logs', __( 'Logs & Diagnostics', 'rss-media-hub' ), 'rmh_manage_feeds', array( LogsPage::class, 'render' ) ),
		);
		foreach ( $pages as $p ) {
			$this->hooks[] = add_submenu_page( 'rmh-dashboard', $p[1], $p[1], $p[2], $p[0], $p[3] );
		}
	}

	/**
	 * Assets only on plugin screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = in_array( $hook, $this->hooks, true ) || ( $screen && PostTypes::POST_TYPE === $screen->post_type );
		if ( ! $ours ) {
			return;
		}
		wp_enqueue_style( 'rmh-admin', RMH_URL . 'assets/css/admin.css', array(), RMH_VERSION );
		wp_enqueue_script( 'rmh-admin', RMH_URL . 'assets/js/admin.js', array(), RMH_VERSION, true );
		wp_localize_script(
			'rmh-admin',
			'RMHAdmin',
			array(
				'copied' => __( 'Copied to clipboard', 'rss-media-hub' ),
			)
		);
	}

	/**
	 * Queue a one-time notice for the current user.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message (plain text).
	 */
	public static function flash( $type, $message ) {
		$key     = 'rmh_notices_' . get_current_user_id();
		$queue   = get_transient( $key );
		$queue   = is_array( $queue ) ? $queue : array();
		$queue[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => (string) $message,
		);
		set_transient( $key, array_slice( $queue, -10 ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print and clear queued notices.
	 */
	public static function render_notices() {
		$key   = 'rmh_notices_' . get_current_user_id();
		$queue = get_transient( $key );
		if ( ! is_array( $queue ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $queue as $n ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $n['type'] ), esc_html( $n['message'] ) );
		}
	}

	/**
	 * Global warnings on plugin screens.
	 */
	public function global_notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'rmh-' ) ) {
			return;
		}
		$status = rmh()->get( 'scheduler' )->status();
		if ( $status['overdue'] && rmh()->get( 'feeds' )->counts()['active'] > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Scheduled imports look overdue or unscheduled. If WP-Cron is disabled, configure a system cron job (see Logs & Diagnostics → Scheduling).', 'rss-media-hub' ) . '</p></div>';
		}
		$settings = rmh()->get( 'settings' );
		if ( $settings->get( 'age_gate_enabled' ) && ! rmh()->get( 'age_gate' )->verifier()->is_legal_verification() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'The age gate uses self-declaration, which is a content warning only and is not legally compliant age verification in jurisdictions that require it. Integrate a verification provider (see docs/AGE-VERIFICATION.md).', 'rss-media-hub' ) . '</p></div>';
		}
	}

	/**
	 * Plugins screen links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( Ui::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'rss-media-hub' ) . '</a>' );
		return $links;
	}

	/**
	 * Source/provenance box on the post editor.
	 */
	public function meta_box() {
		add_meta_box( 'rmh-provenance', __( 'Import provenance', 'rss-media-hub' ), array( $this, 'meta_box_render' ), PostTypes::POST_TYPE, 'side' );
	}

	/**
	 * Render provenance box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function meta_box_render( $post ) {
		$item = rmh()->get( 'items' )->find_by_post( $post->ID );
		if ( ! $item ) {
			echo '<p>' . esc_html__( 'Not created by the importer.', 'rss-media-hub' ) . '</p>';
			return;
		}
		$feed = rmh()->get( 'feeds' )->find( $item->feed_id );
		echo '<p><strong>' . esc_html__( 'Feed:', 'rss-media-hub' ) . '</strong> ' . esc_html( $feed ? $feed->name : '#' . $item->feed_id ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Source:', 'rss-media-hub' ) . '</strong> ' . Ui::short_url( $item->source_url, 40 ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<p><strong>' . esc_html__( 'GUID:', 'rss-media-hub' ) . '</strong> <code>' . esc_html( mb_substr( (string) $item->guid, 0, 80 ) ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Discovered:', 'rss-media-hub' ) . '</strong> ' . Ui::time( $item->discovered_at ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo '<p><strong>' . esc_html__( 'Media type:', 'rss-media-hub' ) . '</strong> ' . esc_html( $item->media_type ) . '</p>';
		$hidden = (string) get_post_meta( $post->ID, '_rmh_hidden_reason', true );
		if ( '' !== $hidden ) {
			echo '<p class="rmh-pill rmh-pill--bad">' . esc_html( sprintf( /* translators: %s: reason */ __( 'Hidden: %s', 'rss-media-hub' ), $hidden ) ) . '</p>';
		}
		echo '<p><a href="' . esc_url( Ui::url( 'queue', array( 'item' => $item->id ) ) ) . '">' . esc_html__( 'View in import queue', 'rss-media-hub' ) . '</a></p>';
	}
}

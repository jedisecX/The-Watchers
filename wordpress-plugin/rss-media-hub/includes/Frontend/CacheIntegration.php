<?php
/**
 * Page-cache plugin integration.
 *
 * @package RSSMediaHub
 */

namespace RMH\Frontend;

use RMH\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Purges cached pages when imported content changes, using the public APIs of
 * common caching plugins when they are active, and keeps dynamic endpoints
 * out of page caches.
 */
class CacheIntegration {

	/**
	 * Purges performed in this request (for diagnostics/tests).
	 *
	 * @var array
	 */
	public static $purged = array();

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		foreach ( array( 'rmh_post_created', 'rmh_post_updated', 'rmh_post_hidden', 'rmh_post_restored' ) as $hook ) {
			add_action( $hook, array( $this, 'purge_post' ), 20, 1 );
		}
		add_action( 'rmh_item_removed', array( $this, 'purge_removed' ), 20, 2 );
		add_action( 'rmh_media_validated', array( $this, 'purge_post' ), 20, 1 );
		add_filter( 'rest_post_dispatch', array( $this, 'rest_no_store' ), 10, 3 );
	}

	/**
	 * Purge after removal.
	 *
	 * @param int $item_id Item.
	 * @param int $post_id Post.
	 */
	public function purge_removed( $item_id, $post_id ) {
		if ( $post_id ) {
			$this->purge_post( $post_id );
		}
	}

	/**
	 * Purge one post plus listing pages.
	 *
	 * @param int $post_id Post.
	 */
	public function purge_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || PostTypes::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		clean_post_cache( $post_id );
		wp_cache_delete( 'rmh_related_' . $post_id . '_' . (int) rmh()->get( 'settings' )->get( 'related_count' ), 'rmh' );

		$done = array( 'core' );
		if ( function_exists( 'wp_cache_post_change' ) ) { // WP Super Cache.
			wp_cache_post_change( $post_id );
			$done[] = 'wp-super-cache';
		}
		if ( function_exists( 'w3tc_flush_post' ) ) { // W3 Total Cache.
			w3tc_flush_post( $post_id );
			$done[] = 'w3-total-cache';
		}
		if ( function_exists( 'rocket_clean_post' ) ) { // WP Rocket.
			rocket_clean_post( $post_id );
			$done[] = 'wp-rocket';
		}
		if ( has_action( 'litespeed_purge_post' ) || defined( 'LSCWP_V' ) ) { // LiteSpeed Cache.
			do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public purge hook.
			$done[] = 'litespeed';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) { // SiteGround Optimizer.
			sg_cachepress_purge_cache( get_permalink( $post_id ) );
			$done[] = 'sg-optimizer';
		}
		/**
		 * Purge custom/edge caches (CDN, Varnish, ...).
		 *
		 * @param int   $post_id Post.
		 * @param array $urls    URLs affected.
		 */
		do_action( 'rmh_purge_post_cache', $post_id, array( get_permalink( $post_id ), get_post_type_archive_link( PostTypes::POST_TYPE ), home_url( '/' ) ) );
		self::$purged[ $post_id ] = $done;
	}

	/**
	 * Plugin REST responses are dynamic: never store them in shared caches.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public function rest_no_store( $response, $server, $request ) {
		if ( 0 === strpos( (string) $request->get_route(), '/rmh/v1' ) && $response instanceof \WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, private' );
		}
		return $response;
	}
}

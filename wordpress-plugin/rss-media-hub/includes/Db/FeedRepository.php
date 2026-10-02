<?php
/**
 * Feed configuration and polling state.
 *
 * @package RSSMediaHub
 */

namespace RMH\Db;

use RMH\Http\UrlNormalizer;
use RMH\Installer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

/**
 * CRUD, scheduling queries and atomic leases for feeds.
 */
class FeedRepository {

	const STATUSES = array( 'active', 'paused', 'disabled' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table() {
		return Installer::table( 'feeds' );
	}

	/**
	 * Current UTC MySQL datetime.
	 *
	 * @return string
	 */
	private function now() {
		return rmh()->get( 'clock' )->mysql();
	}

	/**
	 * Decode settings JSON into ->config.
	 *
	 * @param object|null $row Row.
	 * @return object|null
	 */
	private function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$decoded     = json_decode( (string) $row->settings, true );
		$row->config = FeedConfig::from_stored( is_array( $decoded ) ? $decoded : array() );
		$row->id     = (int) $row->id;
		return $row;
	}

	/**
	 * Validate a feed URL syntactically (network checks happen at fetch time).
	 *
	 * @param string $url URL.
	 * @return string|\WP_Error Normalized URL.
	 */
	public static function validate_url( $url ) {
		$url = trim( (string) $url );
		if ( strlen( $url ) > 2048 ) {
			return new \WP_Error( 'rmh_url_too_long', __( 'The feed URL is too long.', 'rss-media-hub' ) );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'rmh_url_invalid', __( 'Enter an absolute http:// or https:// feed URL.', 'rss-media-hub' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'rmh_url_credentials', __( 'Do not put credentials in the URL; use the feed authentication fields instead.', 'rss-media-hub' ) );
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Create a feed.
	 *
	 * @param array $data name, url, site_url, status, poll_interval, config(array).
	 * @return int|\WP_Error Feed id.
	 */
	public function create( array $data ) {
		global $wpdb;
		$url = self::validate_url( isset( $data['url'] ) ? $data['url'] : '' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$hash = sha1( UrlNormalizer::normalize( $url ) );
		if ( $this->find_by_url( $url ) ) {
			return new \WP_Error( 'rmh_feed_exists', __( 'This feed URL is already registered.', 'rss-media-hub' ) );
		}
		$settings = rmh()->get( 'settings' );
		$interval = isset( $data['poll_interval'] ) ? (int) $data['poll_interval'] : 0;
		$interval = $interval > 0 ? max( 300, min( 604800, $interval ) ) : 0;
		$eff_int  = $interval ? $interval : (int) $settings->get( 'default_poll_interval' );
		$now      = rmh()->get( 'clock' )->now();
		// Stagger first polls across a fraction of the interval to avoid request spikes.
		$first  = $now + wp_rand( 0, (int) min( 900, $eff_int / 4 ) );
		$name   = isset( $data['name'] ) && '' !== trim( (string) $data['name'] ) ? sanitize_text_field( $data['name'] ) : UrlNormalizer::host( $url );
		$config = FeedConfig::sanitize( isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array() );

		$ok = $wpdb->insert(
			$this->table(),
			array(
				'name'          => mb_substr( $name, 0, 200 ),
				'url'           => $url,
				'url_hash'      => $hash,
				'site_url'      => isset( $data['site_url'] ) ? esc_url_raw( (string) $data['site_url'], array( 'http', 'https' ) ) : '',
				'status'        => isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'active',
				'poll_interval' => $interval,
				'next_poll_at'  => gmdate( 'Y-m-d H:i:s', $first ),
				'settings'      => wp_json_encode( $config ),
				'created_at'    => $this->now(),
				'updated_at'    => $this->now(),
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'rmh_db_error', __( 'The feed could not be saved.', 'rss-media-hub' ) );
		}
		$id = (int) $wpdb->insert_id;
		do_action( 'rmh_feed_created', $id );
		return $id;
	}

	/**
	 * Update a feed's editable fields.
	 *
	 * @param int   $id   Feed id.
	 * @param array $data Fields.
	 * @return true|\WP_Error
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$feed = $this->find( $id );
		if ( ! $feed ) {
			return new \WP_Error( 'rmh_feed_missing', __( 'Feed not found.', 'rss-media-hub' ) );
		}
		$row = array( 'updated_at' => $this->now() );
		if ( isset( $data['url'] ) ) {
			$url = self::validate_url( $data['url'] );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$other = $this->find_by_url( $url );
			if ( $other && (int) $other->id !== (int) $id ) {
				return new \WP_Error( 'rmh_feed_exists', __( 'This feed URL is already registered.', 'rss-media-hub' ) );
			}
			if ( $url !== $feed->url ) {
				$row['url']           = $url;
				$row['url_hash']      = sha1( UrlNormalizer::normalize( $url ) );
				$row['etag']          = '';
				$row['last_modified'] = '';
			}
		}
		if ( isset( $data['name'] ) ) {
			$row['name'] = mb_substr( sanitize_text_field( $data['name'] ), 0, 200 );
		}
		if ( isset( $data['site_url'] ) ) {
			$row['site_url'] = esc_url_raw( (string) $data['site_url'], array( 'http', 'https' ) );
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ) {
			$row['status'] = $data['status'];
			if ( 'active' === $data['status'] && 'active' !== $feed->status ) {
				$row['next_poll_at']         = $this->now();
				$row['consecutive_failures'] = 0;
			}
		}
		if ( isset( $data['poll_interval'] ) ) {
			$interval             = (int) $data['poll_interval'];
			$row['poll_interval'] = $interval > 0 ? max( 300, min( 604800, $interval ) ) : 0;
		}
		if ( isset( $data['config'] ) && is_array( $data['config'] ) ) {
			$row['settings'] = wp_json_encode( FeedConfig::sanitize( $data['config'], $feed->config ) );
		}
		$wpdb->update( $this->table(), $row, array( 'id' => (int) $id ) );
		do_action( 'rmh_feed_updated', (int) $id );
		return true;
	}

	/**
	 * Delete a feed. Imported posts are kept; item rows are kept for dedup history.
	 *
	 * @param int $id Feed id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		$ok = (bool) $wpdb->delete( $this->table(), array( 'id' => (int) $id ) );
		if ( $ok ) {
			do_action( 'rmh_feed_deleted', (int) $id );
		}
		return $ok;
	}

	/**
	 * Find by id.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public function find( $id ) {
		global $wpdb;
		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * Find by URL (normalized).
	 *
	 * @param string $url URL.
	 * @return object|null
	 */
	public function find_by_url( $url ) {
		global $wpdb;
		$hash = UrlNormalizer::hash( $url );
		if ( '' === $hash ) {
			return null;
		}
		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE url_hash = %s", $hash ) ) );
	}

	/**
	 * List feeds.
	 *
	 * @param array $args status, search, health, orderby, order, per_page, page.
	 * @return array{rows:object[],total:int}
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['health'] ) ) {
			$where[]  = 'health = %s';
			$params[] = sanitize_key( $args['health'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$orderable = array( 'name', 'status', 'health', 'last_success_at', 'next_poll_at', 'created_at', 'items_total', 'id' );
		$orderby   = isset( $args['orderby'] ) && in_array( $args['orderby'], $orderable, true ) ? $args['orderby'] : 'name';
		$order     = isset( $args['order'] ) && 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$per_page  = isset( $args['per_page'] ) ? max( 1, min( 1000, (int) $args['per_page'] ) ) : 50;
		$page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$w         = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$w}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $count_sql holds only table names and placeholders; values are bound by prepare() whenever present.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders and values are assembled together from the same filter list.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE {$w} ORDER BY {$orderby} {$order}, id ASC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			)
		);
		return array(
			'rows'  => array_map( array( $this, 'hydrate' ), $rows ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Counts by status and health.
	 *
	 * @return array
	 */
	public function counts() {
		global $wpdb;
		$out = array(
			'total'    => 0,
			'active'   => 0,
			'paused'   => 0,
			'disabled' => 0,
			'error'    => 0,
			'warning'  => 0,
			'overdue'  => 0,
		);
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status" ) as $r ) {
			$out[ $r->status ] = (int) $r->n;
			$out['total']     += (int) $r->n;
		}
		foreach ( (array) $wpdb->get_results( "SELECT health, COUNT(*) AS n FROM {$this->table()} WHERE status = 'active' GROUP BY health" ) as $r ) {
			if ( isset( $out[ $r->health ] ) ) {
				$out[ $r->health ] = (int) $r->n;
			}
		}
		$out['overdue'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE status = 'active' AND next_poll_at < %s",
				gmdate( 'Y-m-d H:i:s', rmh()->get( 'clock' )->now() - 30 * MINUTE_IN_SECONDS )
			)
		);
		return $out;
	}

	/**
	 * Feeds due for polling, oldest first.
	 *
	 * @param int $limit Max feeds.
	 * @return object[]
	 */
	public function due( $limit ) {
		global $wpdb;
		$now  = $this->now();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE status = 'active' AND (next_poll_at IS NULL OR next_poll_at <= %s) AND (lease_until IS NULL OR lease_until < %s) ORDER BY next_poll_at ASC, id ASC LIMIT %d",
				$now,
				$now,
				max( 1, (int) $limit )
			)
		);
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Atomically lease a feed so concurrent runners never process it twice.
	 *
	 * @param int $id  Feed id.
	 * @param int $ttl Lease seconds.
	 * @return string|false Lease token.
	 */
	public function claim( $id, $ttl ) {
		global $wpdb;
		$token = wp_generate_password( 32, false, false );
		$clock = rmh()->get( 'clock' );
		$rows  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET lease_token = %s, lease_until = %s WHERE id = %d AND (lease_until IS NULL OR lease_until < %s)",
				$token,
				gmdate( 'Y-m-d H:i:s', $clock->now() + max( 30, (int) $ttl ) ),
				(int) $id,
				$clock->mysql()
			)
		);
		return 1 === (int) $rows ? $token : false;
	}

	/**
	 * Release a lease held with $token.
	 *
	 * @param int    $id    Feed id.
	 * @param string $token Token.
	 */
	public function release( $id, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET lease_token = '', lease_until = NULL WHERE id = %d AND lease_token = %s", (int) $id, (string) $token ) );
	}

	/**
	 * Persist poll outcome and compute the next poll time (with backoff on failure).
	 *
	 * @param object $feed   Feed row.
	 * @param array  $result success(bool), temporary(bool), http_status, outcome, error, etag, last_modified, retry_after, new_items.
	 */
	public function record_poll( $feed, array $result ) {
		global $wpdb;
		$clock    = rmh()->get( 'clock' );
		$settings = rmh()->get( 'settings' );
		$now      = $clock->now();
		$interval = ! empty( $feed->poll_interval ) ? (int) $feed->poll_interval : (int) $settings->get( 'default_poll_interval' );
		$row      = array(
			'last_polled_at'   => $clock->mysql( $now ),
			'last_http_status' => isset( $result['http_status'] ) ? (int) $result['http_status'] : 0,
			'last_outcome'     => isset( $result['outcome'] ) ? substr( (string) $result['outcome'], 0, 40 ) : '',
			'updated_at'       => $clock->mysql( $now ),
		);
		if ( ! empty( $result['success'] ) ) {
			$row['last_success_at']      = $clock->mysql( $now );
			$row['consecutive_failures'] = 0;
			$row['last_error']           = '';
			$row['health']               = empty( $result['warning'] ) ? 'ok' : 'warning';
			if ( isset( $result['etag'] ) ) {
				$row['etag'] = substr( (string) $result['etag'], 0, 255 );
			}
			if ( isset( $result['last_modified'] ) ) {
				$row['last_modified'] = substr( (string) $result['last_modified'], 0, 100 );
			}
			// +/-10% jitter keeps feeds staggered over time.
			$jitter              = (int) round( $interval * 0.1 );
			$row['next_poll_at'] = $clock->mysql( $now + $interval + wp_rand( -$jitter, $jitter ) );
			if ( ! empty( $result['new_items'] ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET items_total = items_total + %d WHERE id = %d", (int) $result['new_items'], (int) $feed->id ) );
			}
		} else {
			$failures                    = (int) $feed->consecutive_failures + 1;
			$row['consecutive_failures'] = $failures;
			$row['last_error']           = mb_substr( \RMH\Security\Redactor::text( (string) ( isset( $result['error'] ) ? $result['error'] : '' ) ), 0, 1000 );
			$row['health']               = $failures >= 3 || empty( $result['temporary'] ) ? 'error' : 'warning';
			$max_backoff                 = HOUR_IN_SECONDS * (int) $settings->get( 'max_backoff_hours' );
			// Exponential backoff from 5 minutes (temporary) or the normal interval (permanent), capped.
			$base  = ! empty( $result['temporary'] ) ? 5 * MINUTE_IN_SECONDS : $interval;
			$delay = (int) min( $max_backoff, $base * pow( 2, min( 10, $failures - 1 ) ) );
			if ( ! empty( $result['retry_after'] ) ) {
				$delay = (int) min( $max_backoff, max( $delay, (int) $result['retry_after'] ) );
			}
			$row['next_poll_at'] = $clock->mysql( $now + $delay + wp_rand( 0, (int) ( $delay * 0.1 ) ) );
		}
		$wpdb->update( $this->table(), $row, array( 'id' => (int) $feed->id ) );
	}

	/**
	 * Force a feed to be due now (manual run).
	 *
	 * @param int $id Feed id.
	 */
	public function mark_due( $id ) {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'next_poll_at' => $this->now() ), array( 'id' => (int) $id ) );
	}
}

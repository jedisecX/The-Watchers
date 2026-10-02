<?php
/**
 * Feed import orchestration.
 *
 * @package RSSMediaHub
 */

namespace RMH\Import;

use RMH\Db\FeedConfig;
use RMH\Db\ItemRepository;
use RMH\Http\HttpResponse;
use RMH\Plugin;
use RMH\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Two-phase, idempotent import:
 *   1. fetch + parse + dedupe + extract -> rows in rmh_items (status "discovered")
 *   2. publish step per item according to the feed's import mode
 * Every step is bounded; failures are isolated per feed and per item, and a
 * re-run never creates duplicate posts (unique identity hash + post adoption).
 */
class Importer {

	/**
	 * Container.
	 *
	 * @var Plugin
	 */
	private $c;

	/**
	 * Constructor.
	 *
	 * @param Plugin $container Container.
	 */
	public function __construct( Plugin $container ) {
		$this->c = $container;
	}

	/**
	 * Empty statistics.
	 *
	 * @return array
	 */
	public static function empty_stats() {
		return array_fill_keys( \RMH\Db\JobRepository::STAT_FIELDS, 0 ) + array( 'unsupported' => 0 );
	}

	/**
	 * Run one feed.
	 *
	 * @param int|object $feed     Feed id or row.
	 * @param string     $source   cron|manual|cli|rest.
	 * @param float|null $deadline microtime() deadline for the publish phase.
	 * @return array {job_id, status, outcome, stats, message}
	 */
	public function run_feed( $feed, $source = 'cron', $deadline = null ) {
		$feeds = $this->c->get( 'feeds' );
		$jobs  = $this->c->get( 'jobs' );
		$log   = $this->c->get( 'logger' );
		$feed  = is_object( $feed ) ? $feed : $feeds->find( (int) $feed );
		if ( ! $feed ) {
			return array(
				'job_id'  => 0,
				'status'  => 'failed',
				'outcome' => 'missing_feed',
				'stats'   => self::empty_stats(),
				'message' => 'Feed not found.',
			);
		}
		$settings = $this->c->get( 'settings' );
		$ttl      = max( 120, 3 * (int) $settings->get( 'max_runtime' ) + 2 * (int) $settings->get( 'request_timeout' ) * ( 1 + (int) $settings->get( 'retry_limit' ) ) );
		$token    = $feeds->claim( $feed->id, $ttl );
		if ( ! $token ) {
			$log->info( 'schedule', 'Feed is already being processed by another run; skipped.', array( 'feed_id' => $feed->id ) );
			return array(
				'job_id'  => 0,
				'status'  => 'skipped',
				'outcome' => 'locked',
				'stats'   => self::empty_stats(),
				'message' => 'Feed is locked by another run.',
			);
		}

		$job_id   = $jobs->start( 'poll', $feed->id, $source );
		$stats    = self::empty_stats();
		$deadline = null === $deadline ? microtime( true ) + (int) $settings->get( 'max_runtime' ) : $deadline;
		$result   = array(
			'status'  => 'failed',
			'outcome' => 'error',
			'http'    => 0,
			'message' => '',
		);

		try {
			$result = $this->poll( $feed, $job_id, $stats, $deadline );
		} catch ( \Throwable $e ) {
			$result['message'] = 'Unhandled error: ' . $e->getMessage();
			$log->error(
				'import',
				$result['message'],
				array(
					'feed_id' => $feed->id,
					'job_id'  => $job_id,
					'file'    => basename( $e->getFile() ) . ':' . $e->getLine(),
				)
			);
			$feeds->record_poll(
				$feed,
				array(
					'success'   => false,
					'temporary' => true,
					'outcome'   => 'error',
					'error'     => $result['message'],
				)
			);
		}
		$feeds->release( $feed->id, $token );

		$jobs->finish( $job_id, $result['status'], $result['outcome'], $stats, $result['http'], $result['message'] );
		$log->log(
			'failed' === $result['status'] ? 'error' : 'info',
			'import',
			sprintf( 'Feed "%s": %s (%s). New %d, duplicates %d, created %d, updated %d, review %d, media %d, failures %d/%d.', $feed->name, $result['outcome'], $result['status'], $stats['items_new'], $stats['duplicates'], $stats['posts_created'], $stats['posts_updated'], $stats['pending_review'], $stats['media_found'], $stats['extraction_failures'], $stats['publish_failures'] ),
			array(
				'feed_id' => $feed->id,
				'job_id'  => $job_id,
				'source'  => $source,
			)
		);
		do_action( 'rmh_feed_processed', $feed->id, $job_id, $result, $stats );

		return array(
			'job_id'  => $job_id,
			'status'  => $result['status'],
			'outcome' => $result['outcome'],
			'stats'   => $stats,
			'message' => $result['message'],
		);
	}

	/**
	 * Fetch, parse, ingest and publish.
	 *
	 * @param object $feed     Feed.
	 * @param int    $job_id   Job.
	 * @param array  $stats    Stats (by ref).
	 * @param float  $deadline Deadline.
	 * @return array {status,outcome,http,message}
	 */
	private function poll( $feed, $job_id, array &$stats, $deadline ) {
		$feeds    = $this->c->get( 'feeds' );
		$log      = $this->c->get( 'logger' );
		$settings = $this->c->get( 'settings' );
		$cfg      = FeedConfig::effective( $feed, $settings );
		$ctx      = array(
			'feed_id' => $feed->id,
			'job_id'  => $job_id,
		);

		if ( $this->c->get( 'blocklist' )->is_url_blocked( $feed->url ) ) {
			$msg = 'Feed URL matches the source blocklist; not fetched.';
			$log->warning( 'security', $msg, $ctx );
			$feeds->record_poll(
				$feed,
				array(
					'success' => false,
					'outcome' => 'blocked_source',
					'error'   => $msg,
				)
			);
			return array(
				'status'  => 'skipped',
				'outcome' => 'blocked_source',
				'http'    => 0,
				'message' => $msg,
			);
		}

		$resp = $this->fetch( $feed->url, $cfg, $feed->etag, $feed->last_modified );
		if ( ! $resp->ok() ) {
			return $this->fetch_failed( $feed, $resp, $ctx );
		}
		if ( 304 === $resp->status ) {
			$feeds->record_poll(
				$feed,
				array(
					'success'     => true,
					'http_status' => 304,
					'outcome'     => 'not_modified',
				)
			);
			// Still give pending retries a chance.
			$this->publish_due( $feed->id, $stats, $deadline, $job_id );
			return array(
				'status'  => 'success',
				'outcome' => 'not_modified',
				'http'    => 304,
				'message' => 'Not modified since last poll (conditional request).',
			);
		}

		$max_bytes = MB_IN_BYTES * (int) $settings->get( 'max_feed_mb' );
		$doc       = $this->c->get( 'parser' )->parse( $resp->body, $resp->final_url, $max_bytes );
		if ( is_wp_error( $doc ) ) {
			$msg = $doc->get_error_message();
			$log->error( 'parse', 'Invalid feed: ' . $msg, $ctx + array( 'content_type' => $resp->content_type() ) );
			$feeds->record_poll(
				$feed,
				array(
					'success'     => false,
					'temporary'   => false,
					'http_status' => $resp->status,
					'outcome'     => 'invalid_feed',
					'error'       => $msg,
				)
			);
			return array(
				'status'  => 'failed',
				'outcome' => 'invalid_feed',
				'http'    => $resp->status,
				'message' => $msg,
			);
		}
		foreach ( $doc['warnings'] as $w ) {
			$log->warning( 'parse', $w, $ctx );
		}

		// Remember the site URL the first time we see it.
		if ( empty( $feed->site_url ) && ! empty( $doc['link'] ) ) {
			$feeds->update( $feed->id, array( 'site_url' => $doc['link'] ) );
		}

		$items     = $doc['items'];
		$pages     = 1;
		$max_pages = (int) $settings->get( 'max_pages' );
		$total     = count( $items );
		while ( '' !== (string) $doc['next_page'] && $pages < $max_pages && $total < 500 && microtime( true ) < $deadline ) {
			$next = $this->fetch( $doc['next_page'], $cfg, '', '' );
			if ( ! $next->ok() || 304 === $next->status ) {
				$log->warning( 'http', 'Next page fetch failed: ' . $next->describe(), $ctx );
				break;
			}
			$doc = $this->c->get( 'parser' )->parse( $next->body, $next->final_url, $max_bytes );
			if ( is_wp_error( $doc ) ) {
				$log->warning( 'parse', 'Next page invalid: ' . $doc->get_error_message(), $ctx );
				break;
			}
			$items = array_merge( $items, $doc['items'] );
			$total = count( $items );
			++$pages;
		}

		$stats['items_fetched'] = count( $items );
		$this->ingest( $feed, $cfg, $items, $stats, $job_id );
		$this->publish_due( $feed->id, $stats, $deadline, $job_id );

		$outcome = 'ok';
		if ( 0 === $stats['items_fetched'] ) {
			$outcome = 'empty_feed';
		} elseif ( $stats['publish_failures'] > 0 && 0 === $stats['posts_created'] + $stats['pending_review'] ) {
			$outcome = 'publish_failure';
		} elseif ( 0 === $stats['items_new'] ) {
			if ( $stats['skipped'] > 0 && $stats['unsupported'] > 0 ) {
				$outcome = 'unsupported_media';
			} elseif ( $stats['skipped'] > 0 && 0 === $stats['duplicates'] ) {
				$outcome = 'all_filtered';
			} else {
				$outcome = 'all_duplicates';
			}
		} elseif ( 0 === $stats['media_found'] && $stats['unsupported'] > 0 ) {
			$outcome = 'unsupported_media';
		}
		$status = ( $stats['publish_failures'] > 0 || $stats['extraction_failures'] > 0 ) ? 'partial' : 'success';
		if ( 'publish_failure' === $outcome ) {
			$status = 'failed';
		}

		$feeds->record_poll(
			$feed,
			array(
				'success'       => true,
				'warning'       => ! empty( $doc['warnings'] ) || 'success' !== $status,
				'http_status'   => $resp->status,
				'outcome'       => $outcome,
				'etag'          => $resp->header( 'etag' ),
				'last_modified' => $resp->header( 'last-modified' ),
				'new_items'     => $stats['items_new'],
			)
		);

		$messages = array(
			'ok'                => 'Imported new items.',
			'empty_feed'        => 'The feed is valid but contains no items.',
			'all_duplicates'    => 'The feed only contains previously imported items.',
			'unsupported_media' => 'New items contain only unsupported media types.',
			'all_filtered'      => 'All new items were filtered out by import rules (media types, blocklist).',
			'publish_failure'   => 'Items were found but could not be published; see the item queue.',
		);
		return array(
			'status'  => $status,
			'outcome' => $outcome,
			'http'    => $resp->status,
			'message' => $messages[ $outcome ],
		);
	}

	/**
	 * Guarded fetch of a feed URL.
	 *
	 * @param string $url           URL.
	 * @param array  $cfg           Effective config.
	 * @param string $etag          ETag.
	 * @param string $last_modified Last-Modified.
	 * @return HttpResponse
	 */
	private function fetch( $url, array $cfg, $etag, $last_modified ) {
		return $this->c->get( 'http' )->get(
			$url,
			array(
				'accept'        => 'application/rss+xml, application/atom+xml, application/rdf+xml;q=0.9, application/xml;q=0.8, text/xml;q=0.8, */*;q=0.1',
				'etag'          => (string) $etag,
				'last_modified' => (string) $last_modified,
				'auth_user'     => $cfg['auth_user'],
				'auth_pass'     => $cfg['auth_pass'],
			)
		);
	}

	/**
	 * Record a failed fetch and classify it.
	 *
	 * @param object       $feed Feed.
	 * @param HttpResponse $resp Response.
	 * @param array        $ctx  Log context.
	 * @return array
	 */
	private function fetch_failed( $feed, HttpResponse $resp, array $ctx ) {
		$code = $resp->error ? $resp->error->get_error_code() : '';
		if ( 'rmh_blocked_destination' === $code ) {
			$outcome = 'blocked_destination';
			$channel = 'security';
		} elseif ( $resp->error && 'rmh_too_large' !== $code ) {
			$outcome = 'unreachable';
			$channel = 'http';
		} elseif ( 'rmh_too_large' === $code ) {
			$outcome = 'too_large';
			$channel = 'http';
		} else {
			$outcome = 'http_error';
			$channel = 'http';
		}
		$msg = sprintf( 'Fetch failed (%s): %s', $outcome, $resp->describe() );
		if ( $resp->redirects ) {
			$msg .= ' after ' . count( $resp->redirects ) . ' redirect(s)';
		}
		$this->c->get( 'logger' )->error( $channel, $msg, $ctx + array( 'attempts' => $resp->attempts ) );
		$this->c->get( 'feeds' )->record_poll(
			$feed,
			array(
				'success'     => false,
				'temporary'   => $resp->is_temporary_failure(),
				'http_status' => $resp->status,
				'outcome'     => $outcome,
				'error'       => $msg,
				'retry_after' => $resp->retry_after,
			)
		);
		if ( $resp->attempts > 1 ) {
			$this->c->get( 'logger' )->info( 'retry', sprintf( 'Gave up after %d attempts.', $resp->attempts ), $ctx );
		}
		return array(
			'status'  => 'failed',
			'outcome' => $outcome,
			'http'    => $resp->status,
			'message' => $msg,
		);
	}

	/**
	 * Phase 1: store new items and refresh known ones.
	 *
	 * @param object $feed   Feed.
	 * @param array  $cfg    Config.
	 * @param array  $items  Normalized items.
	 * @param array  $stats  Stats (by ref).
	 * @param int    $job_id Job.
	 */
	private function ingest( $feed, array $cfg, array $items, array &$stats, $job_id ) {
		$repo      = $this->c->get( 'items' );
		$extractor = $this->c->get( 'extractor' );
		$log       = $this->c->get( 'logger' );
		$settings  = $this->c->get( 'settings' );
		$extractor->set_fetch_budget( (int) $settings->get( 'max_article_fetches' ) );

		// Newest first so the per-run cap keeps the most recent items.
		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $b['published'] <=> (int) $a['published'];
			}
		);
		$items = array_slice( $items, 0, max( 1, (int) $cfg['max_items'] ) );
		$seen  = array();

		foreach ( $items as $item ) {
			$id  = ItemRepository::identity( $feed->id, $item );
			$ctx = array(
				'feed_id' => $feed->id,
				'job_id'  => $job_id,
			);
			if ( isset( $seen[ $id['identity'] ] ) ) {
				++$stats['duplicates']; // Same GUID twice inside one document.
				continue;
			}
			$seen[ $id['identity'] ] = true;

			try {
				$existing = $repo->find_by_identity( $id['identity'] );
				if ( $existing ) {
					++$stats['duplicates'];
					if ( $this->refresh_existing( $existing, $feed, $cfg, $item ) ) {
						++$stats['posts_updated'];
					}
					continue;
				}

				$rule = $this->c->get( 'blocklist' )->match_item( $item );
				if ( '' !== $rule ) {
					$repo->insert(
						$this->row(
							$feed,
							$item,
							$id,
							array(
								'media'     => array(),
								'type'      => 'text',
								'thumbnail' => '',
							),
							$cfg,
							'blocked'
						)
					);
					++$stats['skipped'];
					$log->info( 'security', 'Item skipped by blocklist rule: ' . $rule, $ctx );
					continue;
				}

				if ( $settings->get( 'cross_feed_dedupe' ) && '' !== $id['url_hash'] ) {
					$dupe = $repo->find_by_url_hash( $id['url_hash'] );
					if ( $dupe ) {
						$row                 = $this->row(
							$feed,
							$item,
							$id,
							array(
								'media'     => array(),
								'type'      => 'text',
								'thumbnail' => '',
							),
							$cfg,
							'duplicate'
						);
						$row['duplicate_of'] = $dupe->id;
						$repo->insert( $row );
						++$stats['duplicates'];
						continue;
					}
				}

				$media                         = $extractor->extract( $item, $cfg );
				$stats['extraction_failures'] += (int) $media['failures'];
				$stats['unsupported']         += (int) $media['unsupported'];

				$status = 'discovered';
				if ( 'media_only' === $cfg['content_mode'] && 'text' === $media['type'] ) {
					$status = 'skipped';
				} elseif ( ! in_array( $media['type'], $cfg['allowed_media_types'], true ) ) {
					$status = 'skipped';
				}

				$item_id = $repo->insert( $this->row( $feed, $item, $id, $media, $cfg, $status ) );
				if ( ! $item_id ) {
					++$stats['duplicates']; // Lost a race with a concurrent run: unique key held.
					continue;
				}
				if ( 'skipped' === $status ) {
					++$stats['skipped'];
					$log->debug( 'import', sprintf( 'Skipped "%s": media type "%s" not imported by this feed.', $item['title'], $media['type'] ), $ctx + array( 'item_id' => $item_id ) );
					continue;
				}
				++$stats['items_new'];
				$this->c->get( 'media' )->replace_for_item( $item_id, 0, $media['media'] );
				foreach ( $media['media'] as $m ) {
					if ( 'link' !== $m['type'] ) {
						++$stats['media_found'];
					}
				}
				if ( ! empty( $media['notes'] ) ) {
					$log->debug( 'media', implode( ' ', array_unique( $media['notes'] ) ), $ctx + array( 'item_id' => $item_id ) );
				}
				if ( null === $item['published'] && '' !== (string) $item['date_raw'] ) {
					$log->warning( 'parse', 'Invalid publication date "' . $item['date_raw'] . '"; using discovery time.', $ctx + array( 'item_id' => $item_id ) );
				}
			} catch ( \Throwable $e ) {
				++$stats['extraction_failures'];
				$log->error( 'media', 'Item extraction failed: ' . $e->getMessage(), $ctx );
			}
		}
	}

	/**
	 * Build an items row.
	 *
	 * @param object $feed   Feed.
	 * @param array  $item   Item.
	 * @param array  $id     Identity.
	 * @param array  $media  Extraction result.
	 * @param array  $cfg    Config.
	 * @param string $status Status.
	 * @return array
	 */
	private function row( $feed, array $item, array $id, array $media, array $cfg, $status ) {
		// Store a bounded, sanitized snapshot: raw HTML never reaches the database.
		$item['summary'] = mb_substr( Sanitizer::content( $item['summary'], $item['link'] ), 0, 20000 );
		$item['content'] = mb_substr( Sanitizer::content( $item['content'], $item['link'] ), 0, 100000 );
		$item['media']   = array_slice( $item['media'], 0, 50 );
		return array(
			'feed_id'             => (int) $feed->id,
			'identity_hash'       => $id['identity'],
			'guid_hash'           => $id['guid_hash'],
			'url_hash'            => $id['url_hash'],
			'guid'                => mb_substr( (string) $item['guid'], 0, 2000 ),
			'source_url'          => mb_substr( (string) $item['link'], 0, 2048 ),
			'title'               => mb_substr( (string) $item['title'], 0, 500 ),
			'status'              => $status,
			'media_type'          => $media['type'],
			'content_rating'      => (string) $cfg['content_rating'],
			'payload'             => array(
				'item'      => $item,
				'thumbnail' => $media['thumbnail'],
				'notes'     => isset( $media['notes'] ) ? array_slice( (array) $media['notes'], 0, 10 ) : array(),
			),
			'content_hash'        => $this->content_hash( $item ),
			'source_published_at' => ! empty( $item['published'] ) ? gmdate( 'Y-m-d H:i:s', (int) $item['published'] ) : null,
		);
	}

	/**
	 * Hash of the source fields that matter for updates.
	 *
	 * @param array $item Item.
	 * @return string
	 */
	private function content_hash( array $item ) {
		return sha1(
			wp_json_encode(
				array(
					$item['title'],
					$item['link'],
					Sanitizer::text( $item['summary'], 20000 ),
					Sanitizer::text( $item['content'], 100000 ),
					wp_list_pluck( (array) $item['enclosures'], 'url' ),
					wp_list_pluck( (array) $item['media'], 'url' ),
					$item['published'],
					$item['categories'],
				)
			)
		);
	}

	/**
	 * Re-encountered item: update according to the feed's update policy.
	 *
	 * @param object $existing Item row.
	 * @param object $feed     Feed.
	 * @param array  $cfg      Config.
	 * @param array  $item     Fresh normalized item.
	 * @return bool Post updated.
	 */
	private function refresh_existing( $existing, $feed, array $cfg, array $item ) {
		if ( 'none' === $cfg['update_policy'] || in_array( $existing->status, array( 'rejected', 'removed', 'blocked', 'duplicate', 'skipped' ), true ) ) {
			return false;
		}
		$hash = $this->content_hash( $item );
		if ( $hash === $existing->content_hash ) {
			return false;
		}
		$media = $this->c->get( 'extractor' )->extract( $item, $cfg );
		$row   = $this->row( $feed, $item, ItemRepository::identity( $feed->id, $item ), $media, $cfg, $existing->status );
		unset( $row['identity_hash'], $row['status'], $row['feed_id'] );
		$row['content_rating'] = $existing->content_rating; // Keep moderator overrides.
		$this->c->get( 'items' )->update( $existing->id, $row );
		$this->c->get( 'media' )->replace_for_item( $existing->id, (int) $existing->post_id, $media['media'] );

		if ( $existing->post_id ) {
			$fresh = $this->c->get( 'items' )->find( $existing->id );
			return $this->c->get( 'publisher' )->update( $existing->post_id, $fresh, $feed, $cfg );
		}
		return false;
	}

	/**
	 * Phase 2: run the publish step for discovered items (new or due for retry).
	 *
	 * @param int   $feed_id  Feed (0 = all feeds).
	 * @param array $stats    Stats (by ref).
	 * @param float $deadline Deadline.
	 * @param int   $job_id   Job.
	 * @return int Items processed.
	 */
	public function publish_due( $feed_id, array &$stats, $deadline, $job_id = 0 ) {
		$repo      = $this->c->get( 'items' );
		$processed = 0;
		$limit     = max( 1, (int) $this->c->get( 'settings' )->get( 'max_items_per_run' ) );
		foreach ( $repo->due_for_processing( $limit, $feed_id ) as $item ) {
			if ( microtime( true ) >= $deadline ) {
				$this->c->get( 'logger' )->info(
					'schedule',
					'Time budget reached; remaining items will be processed in the next run.',
					array(
						'feed_id' => $feed_id,
						'job_id'  => $job_id,
					)
				);
				break;
			}
			$this->publish_item( $item, $stats, $job_id );
			++$processed;
		}
		return $processed;
	}

	/**
	 * Apply the import mode to one discovered item.
	 *
	 * @param object $item   Item row.
	 * @param array  $stats  Stats (by ref).
	 * @param int    $job_id Job.
	 * @param string $force  Force a mode ('' = feed config).
	 * @return bool Success.
	 */
	public function publish_item( $item, array &$stats, $job_id = 0, $force = '' ) {
		$repo = $this->c->get( 'items' );
		$feed = $this->feed_or_stub( $item->feed_id );
		$cfg  = FeedConfig::effective( $feed, $this->c->get( 'settings' ) );
		$mode = '' !== $force ? $force : $cfg['import_mode'];
		$ctx  = array(
			'feed_id' => $item->feed_id,
			'item_id' => $item->id,
			'job_id'  => $job_id,
		);
		try {
			/**
			 * Fires before an item is published. Throwing aborts this item only.
			 *
			 * @param object $item Item row.
			 * @param string $mode Import mode.
			 */
			do_action( 'rmh_before_publish_item', $item, $mode );

			/**
			 * Publishing rule extension point. Return a mode (auto|pending|manual) or 'reject'.
			 *
			 * @param string $mode Mode.
			 * @param object $item Item.
			 * @param array  $cfg  Effective feed config.
			 */
			$mode = (string) apply_filters( 'rmh_publish_mode', $mode, $item, $cfg );

			if ( 'reject' === $mode ) {
				$repo->update(
					$item->id,
					array(
						'status'     => 'rejected',
						'last_error' => 'Rejected by publishing rule.',
					)
				);
				++$stats['skipped'];
				return true;
			}
			if ( 'manual' === $mode ) {
				$repo->update(
					$item->id,
					array(
						'status'          => 'queued',
						'last_error'      => '',
						'next_attempt_at' => null,
					)
				);
				++$stats['pending_review'];
				return true;
			}
			$post_status = 'auto' === $mode ? 'publish' : (string) $this->c->get( 'settings' )->get( 'pending_status' );
			$post_id     = $this->c->get( 'publisher' )->create( $item, $feed, $cfg, $post_status );
			if ( is_wp_error( $post_id ) ) {
				throw new \RuntimeException( $post_id->get_error_message() );
			}
			$repo->update(
				$item->id,
				array(
					'status'          => 'publish' === $post_status ? 'published' : 'pending',
					'post_id'         => $post_id,
					'last_error'      => '',
					'next_attempt_at' => null,
				)
			);
			++$stats['posts_created'];
			if ( 'publish' !== $post_status ) {
				++$stats['pending_review'];
			}
			return true;
		} catch ( \Throwable $e ) {
			++$stats['publish_failures'];
			$retry = $repo->record_failure( $item, $e->getMessage() );
			$this->c->get( 'logger' )->error( 'publish', 'Publishing failed: ' . $e->getMessage() . ( $retry ? ' (will retry)' : ' (giving up)' ), $ctx );
			if ( $retry ) {
				$this->c->get( 'logger' )->info( 'retry', 'Item scheduled for retry.', $ctx );
			}
			return false;
		}
	}

	/**
	 * Feed row, or a stub with defaults when the feed was deleted.
	 *
	 * @param int $feed_id Feed id.
	 * @return object
	 */
	private function feed_or_stub( $feed_id ) {
		$feed = $this->c->get( 'feeds' )->find( $feed_id );
		if ( $feed ) {
			return $feed;
		}
		return (object) array(
			'id'            => (int) $feed_id,
			'name'          => __( 'Removed feed', 'rss-media-hub' ),
			'url'           => '',
			'poll_interval' => 0,
			'config'        => FeedConfig::sanitize( array() ),
		);
	}

	/* ------------------------------------------------------------------ moderation actions */

	/**
	 * Approve a queued/pending item (publishes it).
	 *
	 * @param int $item_id Item.
	 * @return true|\WP_Error
	 */
	public function approve( $item_id ) {
		$repo = $this->c->get( 'items' );
		$item = $repo->find( $item_id );
		if ( ! $item ) {
			return new \WP_Error( 'rmh_item_missing', __( 'Item not found.', 'rss-media-hub' ) );
		}
		if ( $item->post_id && get_post( $item->post_id ) ) {
			wp_update_post(
				array(
					'ID'          => $item->post_id,
					'post_status' => 'publish',
				)
			);
			$repo->update( $item->id, array( 'status' => 'published' ) );
			do_action( 'rmh_item_approved', $item->id, $item->post_id );
			return true;
		}
		if ( ! in_array( $item->status, array( 'queued', 'discovered', 'failed', 'rejected', 'pending', 'skipped', 'duplicate' ), true ) ) {
			return new \WP_Error( 'rmh_item_state', __( 'This item cannot be approved in its current state.', 'rss-media-hub' ) );
		}
		$stats         = self::empty_stats();
		$item->post_id = 0;
		if ( ! $this->publish_item( $item, $stats, 0, 'auto' ) ) {
			$fresh = $repo->find( $item_id );
			return new \WP_Error( 'rmh_publish_failed', $fresh ? (string) $fresh->last_error : __( 'Publishing failed.', 'rss-media-hub' ) );
		}
		$fresh = $repo->find( $item_id );
		do_action( 'rmh_item_approved', $item->id, $fresh ? $fresh->post_id : 0 );
		return true;
	}

	/**
	 * Reject an item (trashes its post if one exists).
	 *
	 * @param int $item_id Item.
	 * @return true|\WP_Error
	 */
	public function reject( $item_id ) {
		$repo = $this->c->get( 'items' );
		$item = $repo->find( $item_id );
		if ( ! $item ) {
			return new \WP_Error( 'rmh_item_missing', __( 'Item not found.', 'rss-media-hub' ) );
		}
		if ( $item->post_id && get_post( $item->post_id ) ) {
			wp_trash_post( $item->post_id );
		}
		$repo->update( $item->id, array( 'status' => 'rejected' ) );
		do_action( 'rmh_item_rejected', $item->id );
		return true;
	}

	/**
	 * Retry a failed item now.
	 *
	 * @param int $item_id Item.
	 * @return true|\WP_Error
	 */
	public function retry( $item_id ) {
		$repo = $this->c->get( 'items' );
		$item = $repo->find( $item_id );
		if ( ! $item ) {
			return new \WP_Error( 'rmh_item_missing', __( 'Item not found.', 'rss-media-hub' ) );
		}
		if ( ! in_array( $item->status, array( 'failed', 'discovered' ), true ) ) {
			return new \WP_Error( 'rmh_item_state', __( 'Only failed items can be retried.', 'rss-media-hub' ) );
		}
		$repo->update(
			$item->id,
			array(
				'status'          => 'discovered',
				'attempts'        => 0,
				'next_attempt_at' => null,
			)
		);
		$stats = self::empty_stats();
		$ok    = $this->publish_item( $repo->find( $item_id ), $stats );
		return $ok ? true : new \WP_Error( 'rmh_publish_failed', (string) $repo->find( $item_id )->last_error );
	}

	/**
	 * Re-run media extraction from the stored item snapshot.
	 *
	 * @param int $item_id Item.
	 * @return true|\WP_Error
	 */
	public function reprocess( $item_id ) {
		$repo = $this->c->get( 'items' );
		$item = $repo->find( $item_id );
		if ( ! $item || empty( $item->data['item'] ) ) {
			return new \WP_Error( 'rmh_item_missing', __( 'Item not found.', 'rss-media-hub' ) );
		}
		$feed = $this->feed_or_stub( $item->feed_id );
		$cfg  = FeedConfig::effective( $feed, $this->c->get( 'settings' ) );
		$this->c->get( 'extractor' )->set_fetch_budget( 1 );
		$media             = $this->c->get( 'extractor' )->extract( $item->data['item'], $cfg );
		$data              = $item->data;
		$data['thumbnail'] = $media['thumbnail'];
		$repo->update(
			$item->id,
			array(
				'media_type' => $media['type'],
				'payload'    => $data,
			)
		);
		$this->c->get( 'media' )->replace_for_item( $item->id, $item->post_id, $media['media'] );
		if ( $item->post_id ) {
			$this->c->get( 'publisher' )->update( $item->post_id, $repo->find( $item->id ), $feed, $cfg );
		}
		$this->c->get( 'logger' )->info(
			'media',
			sprintf( 'Media re-extracted: %d asset(s), type %s.', count( $media['media'] ), $media['type'] ),
			array(
				'item_id' => $item->id,
				'feed_id' => $item->feed_id,
			)
		);
		return true;
	}

	/**
	 * Take an item down (removal request / moderation). The post is unpublished
	 * (not deleted, to preserve evidence and provenance) and its GUID blocklisted
	 * so it is not re-imported.
	 *
	 * @param int    $item_id Item (or 0 with $post_id).
	 * @param string $reason  Reason.
	 * @param int    $post_id Post id alternative.
	 * @return true|\WP_Error
	 */
	public function remove( $item_id, $reason, $post_id = 0 ) {
		$repo = $this->c->get( 'items' );
		$item = $item_id ? $repo->find( $item_id ) : ( $post_id ? $repo->find_by_post( $post_id ) : null );
		$pid  = $item ? (int) $item->post_id : (int) $post_id;
		if ( ! $item && ! $pid ) {
			return new \WP_Error( 'rmh_item_missing', __( 'Item not found.', 'rss-media-hub' ) );
		}
		if ( $pid && get_post( $pid ) ) {
			wp_update_post(
				array(
					'ID'          => $pid,
					'post_status' => 'draft',
				)
			);
			update_post_meta( $pid, '_rmh_hidden_reason', sanitize_text_field( $reason ) );
		}
		if ( $item ) {
			$repo->update(
				$item->id,
				array(
					'status'     => 'removed',
					'last_error' => 'Removed: ' . sanitize_text_field( $reason ),
				)
			);
			if ( ! empty( $item->guid ) ) {
				$this->c->get( 'blocklist' )->add( 'guid', $item->guid, 'Removed: ' . $reason );
			}
		}
		$this->c->get( 'logger' )->warning(
			'moderation',
			'Content removed: ' . $reason,
			array(
				'item_id' => $item ? $item->id : 0,
				'post_id' => $pid,
			)
		);
		do_action( 'rmh_item_removed', $item ? $item->id : 0, $pid, $reason );
		return true;
	}

	/* ------------------------------------------------------------------ dry run */

	/**
	 * Test a feed URL without writing anything (except a 'test' log entry).
	 *
	 * @param string $url    Feed URL.
	 * @param array  $config Optional per-feed config overrides.
	 * @return array Report.
	 */
	public function test_feed( $url, array $config = array() ) {
		$stub   = (object) array(
			'id'            => 0,
			'name'          => '',
			'url'           => $url,
			'poll_interval' => 0,
			'config'        => FeedConfig::sanitize( $config, $config ),
		);
		$cfg    = FeedConfig::effective( $stub, $this->c->get( 'settings' ) );
		$report = array(
			'url'         => \RMH\Security\Redactor::url( $url ),
			'ok'          => false,
			'outcome'     => '',
			'http_status' => 0,
			'final_url'   => '',
			'redirects'   => 0,
			'format'      => '',
			'title'       => '',
			'warnings'    => array(),
			'items'       => array(),
			'message'     => '',
		);
		if ( $this->c->get( 'blocklist' )->is_url_blocked( $url ) ) {
			$report['outcome'] = 'blocked_source';
			$report['message'] = __( 'This URL matches the source blocklist.', 'rss-media-hub' );
			return $report;
		}
		$resp                  = $this->fetch( $url, $cfg, '', '' );
		$report['http_status'] = $resp->status;
		$report['final_url']   = \RMH\Security\Redactor::url( $resp->final_url );
		$report['redirects']   = count( $resp->redirects );
		if ( ! $resp->ok() || 304 === $resp->status ) {
			$code              = $resp->error ? $resp->error->get_error_code() : '';
			$report['outcome'] = 'rmh_blocked_destination' === $code ? 'blocked_destination' : ( $resp->error ? 'unreachable' : 'http_error' );
			$report['message'] = $resp->describe();
			$this->c->get( 'logger' )->warning( 'test', 'Feed test failed: ' . $report['message'], array( 'url' => $url ) );
			return $report;
		}
		$doc = $this->c->get( 'parser' )->parse( $resp->body, $resp->final_url, MB_IN_BYTES * (int) $this->c->get( 'settings' )->get( 'max_feed_mb' ) );
		if ( is_wp_error( $doc ) ) {
			$report['outcome'] = 'invalid_feed';
			$report['message'] = $doc->get_error_message();
			$this->c->get( 'logger' )->warning( 'test', 'Feed test: invalid feed: ' . $report['message'], array( 'url' => $url ) );
			return $report;
		}
		$report['ok']       = true;
		$report['format']   = $doc['format'];
		$report['title']    = $doc['title'];
		$report['warnings'] = $doc['warnings'];
		$report['outcome']  = $doc['items'] ? 'ok' : 'empty_feed';
		$this->c->get( 'extractor' )->set_fetch_budget( 0 );
		foreach ( array_slice( $doc['items'], 0, 10 ) as $item ) {
			$media             = $this->c->get( 'extractor' )->extract( $item, array_merge( $cfg, array( 'fetch_article' => false ) ) );
			$report['items'][] = array(
				'title'      => $item['title'],
				'link'       => $item['link'],
				'guid'       => $item['guid'],
				'published'  => $item['published'] ? gmdate( 'c', $item['published'] ) : null,
				'date_valid' => null !== $item['published'],
				'media_type' => $media['type'],
				'media'      => array_map(
					static function ( $m ) {
						return array(
							'type'     => $m['type'],
							'method'   => $m['method'],
							'url'      => $m['url'],
							'mime'     => $m['mime'],
							'provider' => $m['provider'],
						);
					},
					$media['media']
				),
				'thumbnail'  => $media['thumbnail'],
			);
		}
		$report['message'] = sprintf( /* translators: 1: format, 2: count */ __( 'Valid %1$s feed with %2$d item(s).', 'rss-media-hub' ), strtoupper( $doc['format'] ), count( $doc['items'] ) );
		$this->c->get( 'logger' )->info( 'test', 'Feed test succeeded: ' . $report['message'], array( 'url' => $url ) );
		return $report;
	}
}

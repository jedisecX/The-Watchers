<?php
/**
 * Public reports, removal requests and moderation workflow.
 *
 * @package RSSMediaHub
 */

namespace RMH\Compliance;

use RMH\Db\ReportRepository;
use RMH\PostTypes;
use RMH\Security\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts reports from visitors and applies moderation decisions.
 *
 * Suspected child sexual abuse material ("minor") and non-consensual content
 * are hidden immediately on report and escalated to the moderation contact.
 * Posts are unpublished rather than deleted so that evidence and provenance are
 * preserved for reporting to the appropriate authority (see docs/MODERATION.md).
 */
class Reports {

	/**
	 * Submit a report.
	 *
	 * @param array $input post_id, reason, details, contact, website (honeypot).
	 * @return int|\WP_Error Report id.
	 */
	public static function submit( array $input ) {
		$settings = rmh()->get( 'settings' );
		if ( ! $settings->get( 'reports_enabled' ) ) {
			return new \WP_Error( 'rmh_reports_disabled', __( 'Reporting is disabled.', 'rss-media-hub' ), array( 'status' => 403 ) );
		}
		if ( ! empty( $input['website'] ) ) {
			return new \WP_Error( 'rmh_spam', __( 'Report rejected.', 'rss-media-hub' ), array( 'status' => 400 ) );
		}
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || PostTypes::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'rmh_report_post', __( 'Unknown post.', 'rss-media-hub' ), array( 'status' => 404 ) );
		}
		$reason  = isset( $input['reason'] ) ? sanitize_key( $input['reason'] ) : '';
		$reasons = ReportRepository::reasons();
		if ( ! isset( $reasons[ $reason ] ) ) {
			return new \WP_Error( 'rmh_report_reason', __( 'Choose a valid reason.', 'rss-media-hub' ), array( 'status' => 400 ) );
		}
		if ( ! RateLimiter::hit( 'report', 5, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'rmh_rate_limited', __( 'Too many reports. Please try again later.', 'rss-media-hub' ), array( 'status' => 429 ) );
		}
		$hash = RateLimiter::client_hash();
		$repo = rmh()->get( 'reports' );
		if ( $repo->is_duplicate( $post_id, $reason, $hash ) ) {
			return 0; // Already reported by this client; accept silently.
		}
		$urgent = in_array( $reason, ReportRepository::URGENT, true );
		$item   = rmh()->get( 'items' )->find_by_post( $post_id );
		$id     = $repo->create(
			array(
				'post_id'       => $post_id,
				'item_id'       => $item ? $item->id : 0,
				'reason'        => $reason,
				'details'       => isset( $input['details'] ) ? (string) $input['details'] : '',
				'contact'       => isset( $input['contact'] ) ? (string) $input['contact'] : '',
				'reporter_hash' => $hash,
				'status'        => $urgent ? 'escalated' : 'open',
			)
		);

		if ( $urgent ) {
			self::hide_post( $post_id, 'report:' . $reason );
		}
		if ( 'broken_media' === $reason ) {
			// Revalidate in the next background run (never synchronously from a public request).
			foreach ( rmh()->get( 'media' )->for_post( $post_id ) as $m ) {
				rmh()->get( 'media' )->update( $m->id, array( 'checked_at' => null ) );
			}
		}
		if ( $urgent || 'copyright' === $reason ) {
			self::notify( $id, $post_id, $reason );
		}
		rmh()->get( 'logger' )->log(
			$urgent ? 'warning' : 'info',
			'moderation',
			sprintf( 'Report #%d (%s) for post #%d%s.', $id, $reason, $post_id, $urgent ? '; post hidden pending review' : '' ),
			array( 'item_id' => $item ? $item->id : 0 )
		);
		do_action( 'rmh_report_created', $id, $post_id, $reason );
		return $id;
	}

	/**
	 * Unpublish a post pending moderation (keeps the record).
	 *
	 * @param int    $post_id Post.
	 * @param string $reason  Reason.
	 */
	public static function hide_post( $post_id, $reason ) {
		if ( 'publish' === get_post_status( $post_id ) ) {
			wp_update_post(
				array(
					'ID'          => (int) $post_id,
					'post_status' => 'draft',
				)
			);
		}
		update_post_meta( $post_id, '_rmh_hidden_reason', sanitize_text_field( $reason ) );
		do_action( 'rmh_post_hidden', (int) $post_id, $reason );
	}

	/**
	 * Republish a hidden post.
	 *
	 * @param int $post_id Post.
	 */
	public static function restore_post( $post_id ) {
		delete_post_meta( $post_id, '_rmh_hidden_reason' );
		wp_update_post(
			array(
				'ID'          => (int) $post_id,
				'post_status' => 'publish',
			)
		);
		$item = rmh()->get( 'items' )->find_by_post( $post_id );
		if ( $item ) {
			rmh()->get( 'items' )->update( $item->id, array( 'status' => 'published' ) );
		}
		do_action( 'rmh_post_restored', (int) $post_id );
	}

	/**
	 * Apply a moderation decision to a report.
	 *
	 * @param int    $report_id Report.
	 * @param string $action    resolve|dismiss|escalate|takedown|restore|block_source.
	 * @param string $note      Note.
	 * @return true|\WP_Error
	 */
	public static function moderate( $report_id, $action, $note = '' ) {
		$repo   = rmh()->get( 'reports' );
		$report = $repo->find( $report_id );
		if ( ! $report ) {
			return new \WP_Error( 'rmh_report_missing', __( 'Report not found.', 'rss-media-hub' ) );
		}
		$post_id = (int) $report->post_id;
		switch ( $action ) {
			case 'resolve':
				$repo->set_status( $report_id, 'resolved', $note );
				break;
			case 'dismiss':
				$repo->set_status( $report_id, 'dismissed', $note );
				if ( '' !== (string) get_post_meta( $post_id, '_rmh_hidden_reason', true ) && 0 === strpos( (string) get_post_meta( $post_id, '_rmh_hidden_reason', true ), 'report:' ) ) {
					self::restore_post( $post_id );
				}
				break;
			case 'escalate':
				$repo->set_status( $report_id, 'escalated', $note );
				self::hide_post( $post_id, 'escalated' );
				self::notify( $report_id, $post_id, $report->reason );
				break;
			case 'takedown':
				$result = rmh()->get( 'importer' )->remove( (int) $report->item_id, 'report #' . (int) $report_id . ' (' . $report->reason . ')', $post_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$repo->set_status( $report_id, 'resolved', trim( 'Taken down. ' . $note ) );
				break;
			case 'restore':
				self::restore_post( $post_id );
				$repo->set_status( $report_id, 'dismissed', trim( 'Restored. ' . $note ) );
				break;
			case 'block_source':
				$item = $report->item_id ? rmh()->get( 'items' )->find( $report->item_id ) : null;
				if ( ! $item && $post_id ) {
					$item = rmh()->get( 'items' )->find_by_post( $post_id );
				}
				// Provenance is also stored on the post itself, so this works even if item rows were purged.
				$feed_id    = $item ? (int) $item->feed_id : (int) get_post_meta( $post_id, '_rmh_feed_id', true );
				$source_url = $item ? (string) $item->source_url : (string) get_post_meta( $post_id, '_rmh_source_url', true );
				if ( ! $feed_id && '' === $source_url ) {
					return new \WP_Error( 'rmh_report_item', __( 'The report is not linked to an imported item.', 'rss-media-hub' ) );
				}
				if ( $feed_id ) {
					rmh()->get( 'feeds' )->update( $feed_id, array( 'status' => 'disabled' ) );
				}
				$host = wp_parse_url( $source_url, PHP_URL_HOST );
				if ( $host ) {
					rmh()->get( 'blocklist' )->add( 'domain', $host, 'Blocked from report #' . (int) $report_id );
				}
				rmh()->get( 'importer' )->remove( $item ? $item->id : 0, 'source blocked (report #' . (int) $report_id . ')', $post_id );
				$repo->set_status( $report_id, 'resolved', trim( 'Source blocked. ' . $note ) );
				break;
			default:
				return new \WP_Error( 'rmh_report_action', __( 'Unknown moderation action.', 'rss-media-hub' ) );
		}
		rmh()->get( 'logger' )->info( 'moderation', sprintf( 'Report #%d: %s by user #%d.', $report_id, $action, get_current_user_id() ) );
		return true;
	}

	/**
	 * Email the moderation contact.
	 *
	 * @param int    $report_id Report.
	 * @param int    $post_id   Post.
	 * @param string $reason    Reason.
	 */
	private static function notify( $report_id, $post_id, $reason ) {
		$to = (string) rmh()->get( 'settings' )->get( 'moderation_email' );
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return;
		}
		$reasons = ReportRepository::reasons();
		$subject = sprintf( '[%s] %s: %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), __( 'Content report requires review', 'rss-media-hub' ), isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : $reason );
		$body    = sprintf(
			"%s\n\n%s: #%d\n%s: %s\n%s: %s\n\n%s",
			__( 'A content report requires moderator review.', 'rss-media-hub' ),
			__( 'Report', 'rss-media-hub' ),
			$report_id,
			__( 'Post', 'rss-media-hub' ),
			get_the_title( $post_id ),
			__( 'Review', 'rss-media-hub' ),
			admin_url( 'admin.php?page=rmh-moderation' ),
			in_array( $reason, ReportRepository::URGENT, true ) ? __( 'The post has been unpublished automatically. Do not delete it: follow your escalation procedure (e.g. report suspected CSAM to NCMEC/CyberTipline, the IWF or your national hotline/law enforcement) before removing evidence.', 'rss-media-hub' ) : ''
		);
		wp_mail( $to, $subject, $body );
	}
}

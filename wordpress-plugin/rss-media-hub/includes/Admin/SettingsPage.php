<?php
/**
 * Admin: Settings (WordPress Settings API).
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

use RMH\PostTypes;
use RMH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Schema-driven settings screen. Saving goes through options.php (core nonce
 * and capability checks) with Settings::sanitize() as the sanitize callback.
 */
final class SettingsPage {

	const GROUP = 'rmh_settings_group';

	/**
	 * Sections.
	 *
	 * @return array<string,string>
	 */
	public static function sections() {
		return array(
			'publishing' => __( 'Publishing defaults', 'rss-media-hub' ),
			'polling'    => __( 'Polling and batches', 'rss-media-hub' ),
			'network'    => __( 'Network and retries', 'rss-media-hub' ),
			'media'      => __( 'Media', 'rss-media-hub' ),
			'embeds'     => __( 'Embed providers', 'rss-media-hub' ),
			'reader'     => __( 'Reader and templates', 'rss-media-hub' ),
			'compliance' => __( 'Access and compliance (adult sites)', 'rss-media-hub' ),
			'retention'  => __( 'Retention, logging and uninstall', 'rss-media-hub' ),
		);
	}

	/**
	 * Field labels/descriptions: key => [section, label, description].
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'import_mode'             => array( 'publishing', __( 'Default import mode', 'rss-media-hub' ), __( 'Automatic: publish immediately. Pending: create draft/pending posts for review. Manual: keep items in the queue until approved.', 'rss-media-hub' ) ),
			'content_mode'            => array( 'publishing', __( 'Content mode', 'rss-media-hub' ), __( 'Media only skips items without qualifying media.', 'rss-media-hub' ) ),
			'content_policy'          => array( 'publishing', __( 'Article text', 'rss-media-hub' ), __( 'Excerpt only is recommended: full articles should only be republished with permission.', 'rss-media-hub' ) ),
			'pending_status'          => array( 'publishing', __( 'Post status for review', 'rss-media-hub' ), '' ),
			'excerpt_words'           => array( 'publishing', __( 'Excerpt length (words)', 'rss-media-hub' ), '' ),
			'use_source_date'         => array( 'publishing', __( 'Use source publication date', 'rss-media-hub' ), __( 'Falls back to import time when the date is missing, invalid or in the future.', 'rss-media-hub' ) ),
			'source_categories'       => array( 'publishing', __( 'Source categories', 'rss-media-hub' ), __( 'Map feed categories to media tags, media categories, or ignore them.', 'rss-media-hub' ) ),
			'update_policy'           => array( 'publishing', __( 'When items change', 'rss-media-hub' ), __( 'Fields edited by an editor are never overwritten.', 'rss-media-hub' ) ),
			'cross_feed_dedupe'       => array( 'publishing', __( 'Cross-feed duplicate detection', 'rss-media-hub' ), __( 'Skip items whose canonical URL was already imported (from any feed).', 'rss-media-hub' ) ),
			'post_author'             => array( 'publishing', __( 'Post author (user ID)', 'rss-media-hub' ), __( '0 = no author.', 'rss-media-hub' ) ),
			'default_poll_interval'   => array( 'polling', __( 'Default polling interval (seconds)', 'rss-media-hub' ), __( 'Minimum 300. Feeds are staggered automatically.', 'rss-media-hub' ) ),
			'max_items_per_run'       => array( 'polling', __( 'Max items per feed per run', 'rss-media-hub' ), '' ),
			'feeds_per_tick'          => array( 'polling', __( 'Max feeds per run', 'rss-media-hub' ), __( 'Runs happen every 5 minutes.', 'rss-media-hub' ) ),
			'max_runtime'             => array( 'polling', __( 'Max processing time per run (seconds)', 'rss-media-hub' ), '' ),
			'max_pages'               => array( 'polling', __( 'Max feed pages per poll', 'rss-media-hub' ), __( 'Follows RFC 5005 / atom:link rel="next" paging when > 1.', 'rss-media-hub' ) ),
			'request_timeout'         => array( 'network', __( 'Request timeout (seconds)', 'rss-media-hub' ), '' ),
			'max_feed_mb'             => array( 'network', __( 'Max feed size (MB)', 'rss-media-hub' ), '' ),
			'retry_limit'             => array( 'network', __( 'Immediate retries for temporary errors', 'rss-media-hub' ), __( 'Further failures back off exponentially per feed.', 'rss-media-hub' ) ),
			'max_backoff_hours'       => array( 'network', __( 'Max backoff (hours)', 'rss-media-hub' ), '' ),
			'max_redirects'           => array( 'network', __( 'Max redirects', 'rss-media-hub' ), __( 'Every redirect target is re-validated (no private/internal destinations).', 'rss-media-hub' ) ),
			'allowed_ports'           => array( 'network', __( 'Allowed ports', 'rss-media-hub' ), __( 'Comma-separated. Default 80,443.', 'rss-media-hub' ) ),
			'user_agent'              => array( 'network', __( 'User agent', 'rss-media-hub' ), __( 'Empty = RSSMediaHub/version (+site URL).', 'rss-media-hub' ) ),
			'allowed_media_types'     => array( 'media', __( 'Supported media types', 'rss-media-hub' ), '' ),
			'media_handling'          => array( 'media', __( 'Media handling', 'rss-media-hub' ), __( 'Local import only happens for feeds where you confirmed rights. Remote media is never downloaded by default.', 'rss-media-hub' ) ),
			'fetch_article_html'      => array( 'media', __( 'Fetch linked article pages', 'rss-media-hub' ), __( 'Look for media (og:video, <video>, provider players) on the item\'s page when the feed has none.', 'rss-media-hub' ) ),
			'max_article_fetches'     => array( 'media', __( 'Max article fetches per feed run', 'rss-media-hub' ), '' ),
			'max_download_mb'         => array( 'media', __( 'Max download size (MB)', 'rss-media-hub' ), '' ),
			'storage_quota_mb'        => array( 'media', __( 'Local storage quota (MB)', 'rss-media-hub' ), '' ),
			'validate_media'          => array( 'media', __( 'Validate media URLs in the background', 'rss-media-hub' ), '' ),
			'revalidate_days'         => array( 'media', __( 'Recheck media every (days)', 'rss-media-hub' ), '' ),
			'revalidate_batch'        => array( 'media', __( 'Media checks per run', 'rss-media-hub' ), '' ),
			'embed_providers'         => array( 'embeds', __( 'Allowed providers', 'rss-media-hub' ), __( 'Only these providers are ever framed; iframe markup from feeds is never used.', 'rss-media-hub' ) ),
			'custom_providers'        => array( 'embeds', __( 'Custom providers', 'rss-media-hub' ), __( 'One per line: Label | exact.host.example | /embed/path-prefix/ (https only).', 'rss-media-hub' ) ),
			'send_frame_csp'          => array( 'embeds', __( 'Send frame-src Content-Security-Policy', 'rss-media-hub' ), __( 'On media pages, only allowed provider origins can be framed.', 'rss-media-hub' ) ),
			'attribution_template'    => array( 'reader', __( 'Attribution template', 'rss-media-hub' ), __( 'Placeholders: {feed_name} {site_url} {source_url} {source_host} {author} {author_suffix} {date}.', 'rss-media-hub' ) ),
			'per_page'                => array( 'reader', __( 'Items per page', 'rss-media-hub' ), '' ),
			'default_view'            => array( 'reader', __( 'Default view', 'rss-media-hub' ), '' ),
			'show_on_home'            => array( 'reader', __( 'Show the media reader on the home page', 'rss-media-hub' ), __( 'When the home page shows latest posts. Alternatively use the [rmh_reader] shortcode on any page.', 'rss-media-hub' ) ),
			'show_related'            => array( 'reader', __( 'Show related posts', 'rss-media-hub' ), '' ),
			'related_count'           => array( 'reader', __( 'Related posts count', 'rss-media-hub' ), '' ),
			'show_share'              => array( 'reader', __( 'Show share links', 'rss-media-hub' ), '' ),
			'adult_site'              => array( 'compliance', __( 'This is an adult-content site', 'rss-media-hub' ), __( 'Adds rating meta tags to media pages.', 'rss-media-hub' ) ),
			'rta_label'               => array( 'compliance', __( 'Add RTA label', 'rss-media-hub' ), __( 'Restricted To Adults meta label for parental-control software.', 'rss-media-hub' ) ),
			'default_content_rating'  => array( 'compliance', __( 'Default content rating', 'rss-media-hub' ), __( 'Applied to feeds without their own classification. Never inferred from URLs.', 'rss-media-hub' ) ),
			'content_warning'         => array( 'compliance', __( 'Content warning on adult posts', 'rss-media-hub' ), '' ),
			'age_gate_enabled'        => array( 'compliance', __( 'Enable age gate', 'rss-media-hub' ), '' ),
			'age_gate_mode'           => array( 'compliance', __( 'Age gate mode', 'rss-media-hub' ), __( 'Server: content is not sent until the visitor passes (gated pages are never cached). Overlay: cache-friendly JavaScript overlay – a deterrent only.', 'rss-media-hub' ) ),
			'age_gate_scope'          => array( 'compliance', __( 'Gate applies to', 'rss-media-hub' ), '' ),
			'age_verifier'            => array( 'compliance', __( 'Age verification method', 'rss-media-hub' ), __( 'Self-declaration is NOT legal age verification. Register a provider adapter (rmh_age_verifiers filter) where verification is legally required.', 'rss-media-hub' ) ),
			'minimum_age'             => array( 'compliance', __( 'Minimum age', 'rss-media-hub' ), '' ),
			'age_gate_ttl_days'       => array( 'compliance', __( 'Remember verification for (days)', 'rss-media-hub' ), '' ),
			'age_gate_message'        => array( 'compliance', __( 'Age gate message', 'rss-media-hub' ), '' ),
			'blocked_countries'       => array( 'compliance', __( 'Blocked jurisdictions', 'rss-media-hub' ), __( 'ISO 3166 codes, comma-separated (e.g. "US-UT, GB"). Visitors get HTTP 451 on media pages. Requires a country source below. When only the country is known, a subdivision rule (US-UT) conservatively blocks the whole country; supply "US-UT"-style codes via the rmh_visitor_country filter for precise regional rules.', 'rss-media-hub' ) ),
			'country_header'          => array( 'compliance', __( 'Trusted country header', 'rss-media-hub' ), __( 'Request header set by your CDN/proxy, e.g. CF-IPCountry. Only use a header your edge overwrites. Or provide the country via the rmh_visitor_country filter.', 'rss-media-hub' ) ),
			'reports_enabled'         => array( 'compliance', __( 'Allow visitor reports', 'rss-media-hub' ), '' ),
			'moderation_email'        => array( 'compliance', __( 'Moderation contact email', 'rss-media-hub' ), __( 'Receives escalations and removal requests. Empty = site admin email.', 'rss-media-hub' ) ),
			'analytics_enabled'       => array( 'compliance', __( 'Privacy-conscious view counts', 'rss-media-hub' ), __( 'Aggregate per-post counter; no IPs, cookies or identifiers are stored.', 'rss-media-hub' ) ),
			'log_level'               => array( 'retention', __( 'Log level', 'rss-media-hub' ), '' ),
			'log_retention_days'      => array( 'retention', __( 'Keep logs (days)', 'rss-media-hub' ), '' ),
			'job_retention_days'      => array( 'retention', __( 'Keep import history (days)', 'rss-media-hub' ), '' ),
			'rejected_retention_days' => array( 'retention', __( 'Keep rejected/skipped items (days)', 'rss-media-hub' ), '' ),
			'uninstall_mode'          => array( 'retention', __( 'On uninstall', 'rss-media-hub' ), __( 'Keep everything (default), keep imported posts but remove plugin data, or delete everything including imported posts.', 'rss-media-hub' ) ),
		);
	}

	/**
	 * Labels for enum values.
	 *
	 * @return array
	 */
	private static function enum_labels() {
		return array(
			'auto'          => __( 'Automatic publishing', 'rss-media-hub' ),
			'pending'       => __( 'Pending review', 'rss-media-hub' ),
			'manual'        => __( 'Manual approval', 'rss-media-hub' ),
			'article_media' => __( 'Articles with or without media', 'rss-media-hub' ),
			'media_only'    => __( 'Media only', 'rss-media-hub' ),
			'excerpt'       => __( 'Excerpt only', 'rss-media-hub' ),
			'full'          => __( 'Full content', 'rss-media-hub' ),
			'draft'         => __( 'Draft', 'rss-media-hub' ),
			'tags'          => __( 'As media tags', 'rss-media-hub' ),
			'categories'    => __( 'As media categories', 'rss-media-hub' ),
			'ignore'        => __( 'Ignore', 'rss-media-hub' ),
			'none'          => __( 'Never', 'rss-media-hub' ),
			'metadata'      => __( 'Update metadata', 'rss-media-hub' ),
			'remote_embed'  => __( 'Remote embedding', 'rss-media-hub' ),
			'remote_link'   => __( 'Remote linking', 'rss-media-hub' ),
			'local_import'  => __( 'Authorized local import', 'rss-media-hub' ),
			'hybrid'        => __( 'Hybrid (local thumbnails)', 'rss-media-hub' ),
			'grid'          => __( 'Grid', 'rss-media-hub' ),
			'list'          => __( 'Compact reader list', 'rss-media-hub' ),
			'unknown'       => __( 'Unknown', 'rss-media-hub' ),
			'general'       => __( 'General audience', 'rss-media-hub' ),
			'adult'         => __( 'Adult', 'rss-media-hub' ),
			'server'        => __( 'Server (strict)', 'rss-media-hub' ),
			'overlay'       => __( 'Overlay (cache-friendly deterrent)', 'rss-media-hub' ),
			'adult_only'    => __( 'Posts rated adult', 'rss-media-hub' ),
			'all'           => __( 'All media pages', 'rss-media-hub' ),
			'debug'         => 'debug',
			'info'          => 'info',
			'warning'       => 'warning',
			'error'         => 'error',
			'keep_all'      => __( 'Keep everything', 'rss-media-hub' ),
			'keep_content'  => __( 'Keep imported posts, remove plugin data', 'rss-media-hub' ),
			'delete_all'    => __( 'Delete everything including imported posts', 'rss-media-hub' ),
		);
	}

	/**
	 * Register with the Settings API.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
		foreach ( self::sections() as $id => $title ) {
			add_settings_section( 'rmh_' . $id, $title, '__return_false', 'rmh-settings' );
		}
		foreach ( self::fields() as $key => $f ) {
			add_settings_field(
				'rmh_' . $key,
				$f[1],
				array( __CLASS__, 'field' ),
				'rmh-settings',
				'rmh_' . $f[0],
				array(
					'key'       => $key,
					'label_for' => 'rmh-' . $key,
				)
			);
		}
	}

	/**
	 * Sanitize callback: unchecked checkboxes are absent from the POST body, so
	 * on form submissions missing booleans/sets mean "off"/"none".
	 *
	 * @param mixed $input Raw.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! empty( $input['__form'] ) ) {
			foreach ( Settings::schema() as $key => $def ) {
				if ( 'bool' === $def[0] && ! isset( $input[ $key ] ) ) {
					$input[ $key ] = false;
				}
				if ( 'set' === $def[0] && ! isset( $input[ $key ] ) ) {
					$input[ $key ] = array();
				}
			}
		}
		unset( $input['__form'] );
		$clean = Settings::sanitize( array_merge( rmh()->get( 'settings' )->all(), $input ) );
		rmh()->get( 'settings' )->flush();
		rmh()->get( 'embeds' )->flush();
		return $clean;
	}

	/**
	 * Render one field.
	 *
	 * @param array $args Args.
	 */
	public static function field( $args ) {
		$key    = $args['key'];
		$schema = Settings::schema();
		$def    = $schema[ $key ];
		$value  = rmh()->get( 'settings' )->get( $key );
		$name   = Settings::OPTION . '[' . $key . ']';
		$id     = 'rmh-' . $key;
		$fields = self::fields();
		$labels = self::enum_labels();

		switch ( $def[0] ) {
			case 'bool':
				printf( '<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>', esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ), esc_html__( 'Enabled', 'rss-media-hub' ) );
				break;
			case 'int':
				printf( '<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" class="small-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), esc_attr( (string) $def[2][0] ), esc_attr( (string) min( $def[2][1], 1048576 ) ) );
				break;
			case 'enum':
				$opts = array();
				foreach ( $def[2] as $v ) {
					$opts[ $v ] = isset( $labels[ $v ] ) ? $labels[ $v ] : $v;
				}
				if ( 'age_verifier' === $key ) {
					break;
				}
				echo Ui::select( $name, $opts, (string) $value, $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'set':
				$set_labels = 'embed_providers' === $key ? array(
					'youtube'     => 'YouTube',
					'vimeo'       => 'Vimeo',
					'dailymotion' => 'Dailymotion',
				) : PostTypes::media_type_labels();
				echo '<fieldset id="' . esc_attr( $id ) . '" class="rmh-checklist">';
				foreach ( $def[2] as $v ) {
					printf( '<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>', esc_attr( $name ), esc_attr( $v ), checked( in_array( $v, (array) $value, true ), true, false ), esc_html( isset( $set_labels[ $v ] ) ? $set_labels[ $v ] : $v ) );
				}
				echo '</fieldset>';
				break;
			case 'textarea':
				printf( '<textarea id="%1$s" name="%2$s" rows="3" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
				break;
			case 'html':
				printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="large-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;
			case 'email':
				printf( '<input type="email" id="%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;
			default:
				if ( 'age_verifier' === $key ) {
					$opts = array();
					foreach ( rmh()->get( 'age_gate' )->verifiers() as $vid => $verifier ) {
						$opts[ $vid ] = $verifier->label() . ( $verifier->is_legal_verification() ? '' : ' ⚠' );
					}
					echo Ui::select( $name, $opts, (string) $value, $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				}
				printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
		}
		if ( ! empty( $fields[ $key ][2] ) ) {
			echo '<p class="description">' . esc_html( $fields[ $key ][2] ) . '</p>';
		}
	}

	/**
	 * Render page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'rss-media-hub' ) );
		}
		Ui::header( __( 'RSS Media Hub Settings', 'rss-media-hub' ) );
		settings_errors();
		echo '<nav class="rmh-toc" aria-label="' . esc_attr__( 'Settings sections', 'rss-media-hub' ) . '">';
		foreach ( self::sections() as $id => $title ) {
			echo '<a href="#rmh-sec-' . esc_attr( $id ) . '">' . esc_html( $title ) . '</a> ';
		}
		echo '</nav>';
		echo '<form method="post" action="options.php" class="rmh-settings-form">';
		settings_fields( self::GROUP );
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION ) . '[__form]" value="1">';
		global $wp_settings_sections, $wp_settings_fields;
		foreach ( self::sections() as $id => $title ) {
			echo '<h2 id="rmh-sec-' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>';
			if ( isset( $wp_settings_fields['rmh-settings'][ 'rmh_' . $id ] ) ) {
				echo '<table class="form-table" role="presentation">';
				do_settings_fields( 'rmh-settings', 'rmh_' . $id );
				echo '</table>';
			}
		}
		submit_button();
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'System cron: set DISABLE_WP_CRON to true in wp-config.php and run "wp rmh run" (WP-CLI) or "wp cron event run --due-now" every 5 minutes.', 'rss-media-hub' ) . '</p>';
		Ui::footer();
	}
}

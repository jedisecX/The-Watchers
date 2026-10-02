<?php
/**
 * Media validation, fallbacks, safe embeds, sanitization in output, access control.
 * Scenarios 17, 18, 19, 20, 27, 30 (markup).
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests\Integration;

use RMH\Compliance\AccessControl;
use RMH\Tests\TestCase;

/**
 * Rendering and validation.
 */
class MediaRenderTest extends TestCase {

	/**
	 * Import the video fixture and return the post.
	 *
	 * @param array $config Feed config.
	 * @return \WP_Post
	 */
	private function video_post( array $config = array() ) {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$this->run_feed( $this->add_feed( 'https://alpha.example/feed', $config ) );
		return $this->posts()[0];
	}

	public function test_scenario_19_unexpected_mime_marks_media_invalid_and_renders_fallback() {
		$post = $this->video_post();
		$this->http->on( 'https://cdn.alpha.example/media/clip-one.mp4', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'text/html; charset=utf-8' ) ) );
		$media  = $this->media_for( $post->ID )[0];
		$result = rmh()->get( 'validator' )->validate( $media );
		$this->assertSame( 'invalid', $result['status'] );
		$this->assertStringContainsString( 'text/html', $result['message'] );
		$this->assertSame( 'HEAD', $this->http->requests[ count( $this->http->requests ) - 1 ][1]['method'] );

		$html = $this->render_single( $post->ID );
		$this->assertStringNotContainsString( '<video', $html );
		$this->assertStringContainsString( 'rmh-media--fallback', $html );
		$this->assertStringContainsString( 'This media is currently unavailable at its source.', $html );
	}

	public function test_valid_media_is_marked_valid_only_after_a_real_check() {
		$post  = $this->video_post();
		$media = $this->media_for( $post->ID )[0];
		$this->assertSame( 'unchecked', $media->validation_status );
		$this->http->on( 'https://cdn.alpha.example/media/clip-one.mp4', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'video/mp4', 'Content-Length' => '10485760' ) ) );
		$this->assertSame( 'valid', rmh()->get( 'validator' )->validate( $media )['status'] );
		$this->assertSame( 'valid', $this->media_for( $post->ID )[0]->validation_status );
	}

	public function test_head_not_allowed_falls_back_to_ranged_get() {
		$post = $this->video_post();
		$this->http->on(
			'https://cdn.alpha.example/media/clip-one.mp4',
			static function ( $url, $args ) {
				if ( 'HEAD' === $args['method'] ) {
					return array( 'status' => 405 );
				}
				return array( 'status' => 206, 'headers' => array( 'Content-Type' => 'video/mp4', 'Content-Range' => 'bytes 0-1023/2048000' ), 'body' => str_repeat( 'x', 1024 ) );
			}
		);
		$r = rmh()->get( 'validator' )->validate( $this->media_for( $post->ID )[0] );
		$this->assertSame( 'valid', $r['status'] );
		$this->assertSame( 2048000, $this->media_for( $post->ID )[0]->filesize );
		$last = end( $this->http->requests );
		$this->assertSame( 'bytes=0-1023', $last[1]['headers']['Range'] );
	}

	public function test_scenario_20_and_27_disappeared_media_keeps_post_with_working_fallback() {
		$post = $this->video_post();
		$this->http->on( 'https://cdn.alpha.example/media/clip-one.mp4', array( 'status' => 404 ) );
		$n = rmh()->get( 'validator' )->run_batch( 10, 0, microtime( true ) + 10 );
		$this->assertSame( 1, $n );
		$this->assertSame( 'unavailable', $this->media_for( $post->ID )[0]->validation_status );
		$this->assertSame( 'publish', get_post_status( $post->ID ), 'post is kept, not deleted' );

		$html = $this->render_single( $post->ID );
		$this->assertStringContainsString( 'rmh-media--fallback', $html );
		$this->assertStringContainsString( 'href="https://alpha.example/clips/one?utm_source=rss"', $html, 'working link to the original' );
		$this->assertStringContainsString( 'View at Test Feed', $html );
		$this->assertStringContainsString( 'Source: <a', $html, 'attribution still visible' );

		// Restricted media (401/403) is reported unavailable and never bypassed.
		$this->http->on( 'https://cdn.alpha.example/media/clip-one.mp4', array( 'status' => 403 ) );
		$r = rmh()->get( 'validator' )->validate( $this->media_for( $post->ID )[0] );
		$this->assertSame( 'unavailable', $r['status'] );
		$this->assertStringContainsString( 'not bypassed', $r['message'] );
	}

	public function test_validator_refuses_private_media_hosts() {
		$post = $this->video_post();
		$this->dns->map['cdn.alpha.example'] = array( '10.0.0.8' );
		$r = rmh()->get( 'validator' )->validate( $this->media_for( $post->ID )[0] );
		$this->assertSame( 'blocked', $r['status'] );
		$this->assertSame( 0, $this->http->count( 'https://cdn.alpha.example/media/clip-one.mp4' ) );
	}

	public function test_scenario_17_only_allowlisted_embeds_render_with_sandbox() {
		$this->serve( 'https://embeds.example/feed', 'rss-iframe.xml' );
		$this->run_feed( $this->add_feed( 'https://embeds.example/feed' ) );
		$posts = $this->posts();
		$this->assertCount( 2, $posts );
		foreach ( $posts as $p ) {
			$html = $this->render_single( $p->ID );
			$this->assertStringNotContainsString( 'evil.example', $html );
			$this->assertStringNotContainsString( 'javascript:', $html );
			$this->assertStringNotContainsString( 'onload=', $html );
			if ( 'embed' === get_post_meta( $p->ID, '_rmh_media_type', true ) ) {
				$this->assertMatchesRegularExpression( '#<iframe class="rmh-embed"\s+src="https://www\.youtube-nocookie\.com/embed/aqz-KE-bpKQ\?rel=0"#', $html );
				$this->assertStringContainsString( 'sandbox="allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox"', $html );
				$this->assertStringContainsString( 'referrerpolicy="strict-origin-when-cross-origin"', $html );
				$this->assertStringContainsString( 'loading="lazy"', $html );
				$embed_post = $p;
			} else {
				$this->assertStringNotContainsString( '<iframe', $html );
			}
		}
		$this->assertTrue( isset( $embed_post ) );

		// Disabling the provider later removes the iframe at render time (revalidation).
		$this->settings( array( 'embed_providers' => array() ) );
		$html = $this->render_single( $embed_post->ID );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringContainsString( 'rmh-media--fallback', $html );

		// A tampered embed URL in the database is not rendered either.
		$this->settings( array( 'embed_providers' => array( 'youtube' ) ) );
		$m = $this->media_for( $embed_post->ID )[0];
		rmh()->get( 'media' )->update( $m->id, array( 'embed_url' => 'https://evil.example/embed' ) );
		$this->assertStringNotContainsString( 'evil.example', $this->render_single( $embed_post->ID ) );
	}

	public function test_frame_csp_lists_only_enabled_provider_origins() {
		$this->assertSame( array( 'https://www.youtube-nocookie.com', 'https://player.vimeo.com', 'https://www.dailymotion.com' ), rmh()->get( 'embeds' )->frame_origins() );
		$this->settings( array( 'embed_providers' => array( 'vimeo' ) ) );
		$this->assertSame( array( 'https://player.vimeo.com' ), rmh()->get( 'embeds' )->frame_origins() );
	}

	public function test_scenario_18_malicious_html_never_reaches_the_page() {
		$this->serve( 'https://xss.example/feed', 'rss-xss.xml' );
		$this->run_feed( $this->add_feed( 'https://xss.example/feed', array( 'content_policy' => 'full' ) ) );
		$post = $this->posts()[0];
		$html = $this->render_single( $post->ID );
		$main = substr( $html, strpos( $html, '<main id="rmh-main"' ) );
		foreach ( array( 'alert(', 'onerror=', 'onclick=', 'javascript:', 'evil.example', 'phish.example', '<object', 'data:text/html' ) as $bad ) {
			$this->assertStringNotContainsString( $bad, $main, $bad );
		}
		$this->assertStringContainsString( 'Hello', $post->post_title );

		global $wpdb;
		$payload = $wpdb->get_var( "SELECT payload FROM {$wpdb->prefix}rmh_items LIMIT 1" );
		$this->assertStringNotContainsString( '<script', $payload, 'raw markup is never stored' );
	}

	public function test_scenario_30_markup_is_responsive_and_accessible() {
		$this->serve( 'https://mrss.example/feed', 'mrss-video.xml' );
		$this->run_feed( $this->add_feed( 'https://mrss.example/feed' ) );
		$html = $this->render_single( $this->posts()[0]->ID );
		$this->assertStringContainsString( 'name="viewport"', $html, 'theme header provides a viewport' );
		$this->assertMatchesRegularExpression( '#<video[^>]+playsinline#', $html, 'inline playback on iOS' );
		$this->assertMatchesRegularExpression( '#<video[^>]+aria-label=#', $html );
		$this->assertStringContainsString( 'rss-media-hub/assets/css/frontend.css', $html );
		$css = file_get_contents( RMH_DIR . 'assets/css/frontend.css' ); // phpcs:ignore
		$this->assertStringContainsString( 'aspect-ratio: var(--rmh-ratio, 16 / 9)', $css );
		$this->assertStringContainsString( 'min-height: 44px', $css, 'touch-size targets' );
	}

	public function test_remote_link_mode_shows_thumbnail_and_link_only() {
		$post = $this->video_post( array( 'media_handling' => 'remote_link' ) );
		$html = $this->render_single( $post->ID );
		$this->assertStringNotContainsString( '<video', $html );
		$this->assertStringContainsString( 'This media is hosted by the original publisher.', $html );
	}

	public function test_local_import_requires_confirmed_rights() {
		$post = $this->video_post( array( 'media_handling' => 'local_import' ) );
		$this->assertSame( '', $this->media_for( $post->ID )[0]->download_status, 'nothing is queued without permission' );
		$this->assertSame( 0, rmh()->get( 'downloader' )->run_batch( 5, microtime( true ) + 10 ) );

		rmh()->get( 'media' )->update( $this->media_for( $post->ID )[0]->id, array( 'download_status' => 'queued' ) );
		$r = rmh()->get( 'downloader' )->download_media( $this->media_for( $post->ID )[0] );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( 'refused', $this->media_for( $post->ID )[0]->download_status );
		$this->assertSame( 0, $this->http->count( 'https://cdn.alpha.example/media/clip-one.mp4' ) );
	}

	public function test_authorized_local_import_verifies_bytes() {
		$this->serve( 'https://photos.example/feed', 'rss-images.xml' );
		$feed = $this->add_feed( 'https://photos.example/feed', array( 'media_handling' => 'local_import', 'rights_confirmed' => true ) );
		$this->run_feed( $feed );
		$post  = $this->posts()[0];
		$media = $this->media_for( $post->ID );
		$this->assertSame( 'queued', $media[0]->download_status );

		// A real PNG for one URL, an HTML page disguised as .jpg for another.
		$img = imagecreatetruecolor( 4, 4 );
		ob_start();
		imagepng( $img );
		$png = ob_get_clean();
		$this->http->on( 'https://img.photos.example/7/d.png', array( 'headers' => array( 'Content-Type' => 'image/png' ), 'body' => $png ) );
		$this->http->on( 'https://img.photos.example/7/a.jpg', array( 'headers' => array( 'Content-Type' => 'image/jpeg' ), 'body' => '<html><script>alert(1)</script></html>' ) );

		$ok = rmh()->get( 'downloader' )->download_media( rmh()->get( 'media' )->find( $media[0]->id ) );
		$this->assertIsInt( $ok );
		$this->assertSame( 'image/png', get_post_mime_type( $ok ) );
		$a_jpg = array_values( array_filter( $media, static function ( $m ) { return 'https://img.photos.example/7/a.jpg' === $m->url; } ) )[0];
		$bad   = rmh()->get( 'downloader' )->download_media( $a_jpg );
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'rmh_mime', $bad->get_error_code() );

		// Removing the local copy keeps the remote record.
		$this->assertTrue( rmh()->get( 'downloader' )->remove_local( $media[0]->id ) );
		$this->assertNull( get_post( $ok ) );
		$this->assertNotNull( rmh()->get( 'media' )->find( $media[0]->id ) );
	}

	public function test_age_gate_server_mode_blocks_unverified_visitors() {
		$post = $this->video_post( array( 'content_rating' => 'adult' ) );
		$this->settings( array( 'age_gate_enabled' => true, 'age_gate_mode' => 'server' ) );
		$this->go_single( $post->ID );
		$ac = new AccessControl();
		$this->assertSame( 'age_gate', $ac->decide() );

		// Proof cookie issued by the verifier lets the visitor through; tampering does not.
		$gate = rmh()->get( 'age_gate' );
		$gate->set_proof( 'self_declaration' );
		$cookie = $this->proof_cookie( $gate );
		rmh()->reset();
		rmh()->set( 'transport', $this->http );
		rmh()->set( 'resolver', $this->dns );
		rmh()->set( 'clock', $this->clock );
		$_COOKIE['rmh_age_ok'] = $cookie;
		$this->assertSame( 'ok', ( new AccessControl() )->decide() );
		$_COOKIE['rmh_age_ok'] = preg_replace( '/.$/', '0' === substr( $cookie, -1 ) ? '1' : '0', $cookie );
		$this->assertSame( 'age_gate', ( new AccessControl() )->decide() );
		$_COOKIE['rmh_age_ok'] = 'self_declaration.' . ( time() + 999999 ) . '.forged';
		$this->assertSame( 'age_gate', ( new AccessControl() )->decide() );

		// Non-adult posts are not gated in "adult_only" scope.
		$this->serve( 'https://news.example/feed', 'rss-no-media.xml' );
		$this->run_feed( $this->add_feed( 'https://news.example/feed', array( 'content_rating' => 'general' ) ) );
		$general = get_posts( array( 'post_type' => 'rmh_item', 'title' => 'Industry news roundup' ) )[0];
		$_COOKIE = array();
		$this->go_single( $general->ID );
		$this->assertSame( 'ok', ( new AccessControl() )->decide() );
	}

	public function test_geo_rules_return_451() {
		$post = $this->video_post();
		$this->settings( array( 'blocked_countries' => 'GB,US-UT', 'country_header' => 'CF-IPCountry' ) );
		$this->go_single( $post->ID );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'GB';
		$this->assertSame( 'geo_block', ( new AccessControl() )->decide() );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->assertSame( 'ok', ( new AccessControl() )->decide() );
		$this->hook( 'rmh_visitor_country', static function () { return 'US'; } );
		$this->assertSame( 'geo_block', ( new AccessControl() )->decide(), 'subdivision rule matches country-level signal' );
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	}

	public function test_gate_page_renders_without_media_and_is_uncacheable() {
		$post = $this->video_post( array( 'content_rating' => 'adult' ) );
		$this->settings( array( 'age_gate_enabled' => true, 'age_gate_mode' => 'server' ) );
		$this->go_single( $post->ID );
		ob_start();
		( new AccessControl() )->enforce();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'rmh-agegate', $html );
		$this->assertStringContainsString( 'name="_rmh_age_nonce"', $html );
		$this->assertStringNotContainsString( 'clip-one.mp4', $html, 'no media URL is sent before verification' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'page caches told not to store the response' );
	}

	/**
	 * Point the main query at a single post.
	 *
	 * @param int $post_id Post.
	 */
	private function go_single( $post_id ) {
		global $wp_query, $wp_the_query;
		$wp_query              = new \WP_Query( array( 'p' => $post_id, 'post_type' => 'rmh_item' ) );
		$wp_the_query          = $wp_query;
		$wp_query->is_singular = true;
		$wp_query->is_single   = true;
		$wp_query->queried_object    = get_post( $post_id );
		$wp_query->queried_object_id = $post_id;
	}

	/**
	 * Read the proof value the gate issued in this request.
	 *
	 * @param \RMH\Compliance\AgeGate $gate Gate.
	 * @return string
	 */
	private function proof_cookie( $gate ) {
		$r = new \ReflectionProperty( $gate, 'fresh_proof' );
		$r->setAccessible( true );
		return (string) $r->getValue( $gate );
	}
}

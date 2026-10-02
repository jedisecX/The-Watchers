<?php
/**
 * Media discovery, classification and provider allowlist (scenarios 1-4, 6, 17).
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests\Unit;

use RMH\Feed\FeedParser;
use RMH\Settings;
use RMH\Tests\TestCase;

/**
 * MediaExtractor behaviour.
 */
class MediaExtractionTest extends TestCase {

	/**
	 * Extract the first (or nth) item of a fixture.
	 *
	 * @param string $fixture Fixture.
	 * @param int    $n       Index.
	 * @param array  $cfg     Config overrides.
	 * @return array
	 */
	private function extract( $fixture, $n = 0, array $cfg = array() ) {
		$doc = ( new FeedParser() )->parse( $this->fixture( $fixture ), 'https://feed.test/rss' );
		return rmh()->get( 'extractor' )->extract(
			$doc['items'][ $n ],
			array_merge(
				array(
					'allowed_media_types' => Settings::MEDIA_TYPES,
					'allow_embeds'        => true,
					'extract_html'        => true,
					'fetch_article'       => false,
				),
				$cfg
			)
		);
	}

	public function test_video_enclosure() {
		$r = $this->extract( 'rss-video.xml' );
		$this->assertSame( 'video', $r['type'] );
		$this->assertSame( 'enclosure', $r['media'][0]['method'] );
		$this->assertSame( 'video/mp4', $r['media'][0]['mime'] );
		$this->assertTrue( $r['media'][0]['playable'] );
	}

	public function test_audio_enclosure_with_artwork() {
		$r = $this->extract( 'rss-audio.xml' );
		$this->assertSame( 'audio', $r['type'] );
		$this->assertSame( 'audio/mpeg', $r['media'][0]['mime'] );
		$this->assertSame( 'https://cdn.audio.example/ep12.jpg', $r['thumbnail'] );
	}

	public function test_multiple_images_become_a_gallery() {
		$r    = $this->extract( 'rss-images.xml' );
		$urls = wp_list_pluck( $r['media'], 'url' );
		$this->assertSame( 'gallery', $r['type'] );
		$this->assertContains( 'https://img.photos.example/7/d.png', $urls );
		$this->assertContains( 'https://img.photos.example/7/a.jpg', $urls );
		$this->assertContains( 'https://photos.example/7/c.webp', $urls, 'relative image resolved' );
		$this->assertNotContains( 'https://tracker.photos.example/pixel.gif', $urls, '1x1 tracking pixel skipped' );
		$this->assertSame( 'feed_media', $r['media'][0]['method'], 'explicit feed media ranks first' );
	}

	public function test_gallery_disallowed_keeps_a_single_image() {
		$r = $this->extract( 'rss-images.xml', 0, array( 'allowed_media_types' => array( 'image', 'text' ) ) );
		$this->assertSame( 'image', $r['type'] );
		$this->assertCount( 1, array_filter( $r['media'], static function ( $m ) { return 'image' === $m['type']; } ) );
	}

	public function test_media_rss_video_with_thumbnail_and_player() {
		$r = $this->extract( 'mrss-video.xml' );
		$this->assertSame( 'video', $r['type'] );
		$this->assertSame( 'https://cdn.mrss.example/feature/poster.jpg', $r['thumbnail'] );
		$types = wp_list_pluck( $r['media'], 'type' );
		$this->assertSame( array( 'video', 'video', 'link' ), $types, 'unknown player becomes a link, never an iframe' );
		$this->assertSame( 754, $r['media'][0]['duration'] );
		$this->assertSame( 'MRSS Studio', $r['media'][0]['credit'] );
	}

	public function test_no_media_gets_fallback_link() {
		$r = $this->extract( 'rss-no-media.xml' );
		$this->assertSame( 'text', $r['type'] );
		$this->assertSame( 'fallback_link', $r['media'][0]['method'] );
		$this->assertSame( 'https://news.example/roundup', $r['media'][0]['url'] );
	}

	public function test_scenario_17_untrusted_iframes_are_not_embedded() {
		$evil = $this->extract( 'rss-iframe.xml', 0 );
		foreach ( $evil['media'] as $m ) {
			$this->assertNotSame( 'embed', $m['type'] );
			$this->assertStringNotContainsString( 'evil.example', (string) $m['embed_url'] );
		}
		$yt = $this->extract( 'rss-iframe.xml', 1 );
		$this->assertSame( 'embed', $yt['type'] );
		$this->assertSame( 'youtube', $yt['media'][0]['provider'] );
		$this->assertSame( 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?rel=0', $yt['media'][0]['embed_url'], 'embed URL is rebuilt by the adapter, not copied' );

		// Provider disabled => no embed.
		$this->settings( array( 'embed_providers' => array( 'vimeo' ) ) );
		$off = $this->extract( 'rss-iframe.xml', 1 );
		$this->assertNotSame( 'embed', $off['type'] );
	}

	public function test_embeds_disabled_per_feed_become_links() {
		$r = $this->extract( 'rss-iframe.xml', 1, array( 'allow_embeds' => false ) );
		$this->assertSame( 'link', $r['media'][0]['type'] );
	}

	public function test_blocklisted_media_is_dropped() {
		rmh()->get( 'blocklist' )->add( 'domain', 'cdn.alpha.example' );
		$r = $this->extract( 'rss-video.xml' );
		$this->assertSame( 'text', $r['type'] );
	}

	public function test_providers() {
		$reg = rmh()->get( 'embeds' );
		$this->assertSame( 'youtube', $reg->match( 'https://youtu.be/aqz-KE-bpKQ' )['provider'] );
		$this->assertSame( 'youtube', $reg->match( 'https://m.youtube.com/watch?v=aqz-KE-bpKQ&t=10' )['provider'] );
		$this->assertNull( $reg->match( 'https://www.youtube.com/watch?v=short' ) );
		$this->assertNull( $reg->match( 'https://youtube.com.evil.test/watch?v=aqz-KE-bpKQ' ) );
		$this->assertNull( $reg->match( 'javascript:alert(1)//youtube.com/embed/aqz-KE-bpKQ' ) );
		$this->assertSame( 'https://player.vimeo.com/video/76979871?dnt=1', $reg->match( 'https://vimeo.com/76979871' )['embed_url'] );
		$this->assertSame( 'https://www.dailymotion.com/embed/video/x8abc12', $reg->match( 'https://dai.ly/x8abc12' )['embed_url'] );

		$this->settings( array( 'custom_providers' => "Partner | player.partner.test | /embed/\nBroken line\nWhole site | partner.test | /" ) );
		$m = $reg->match( 'https://player.partner.test/embed/123?autoplay=0' );
		$this->assertSame( 'https://player.partner.test/embed/123?autoplay=0', $m['embed_url'] );
		$this->assertNull( $reg->match( 'http://player.partner.test/embed/123' ), 'custom providers are https only' );
		$this->assertNull( $reg->match( 'https://player.partner.test/other/123' ) );
		$this->assertNull( $reg->match( 'https://partner.test/anything' ), 'a bare "/" prefix is rejected' );
		$this->assertContains( 'https://player.partner.test', $reg->frame_origins() );
	}
}

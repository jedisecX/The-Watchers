<?php
/**
 * End-to-end import into real WordPress posts.
 * Scenarios 1–8, 12–14, 25, 26.
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests\Integration;

use RMH\PostTypes;
use RMH\Tests\TestCase;

/**
 * Import pipeline.
 */
class ImportTest extends TestCase {

	public function test_scenario_1_video_enclosure_creates_published_post_with_player() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		$res  = $this->run_feed( $feed );

		$this->assertSame( 'success', $res['status'] );
		$this->assertSame( 'ok', $res['outcome'] );
		$this->assertSame( 1, $res['stats']['items_new'] );
		$this->assertSame( 1, $res['stats']['posts_created'] );
		$this->assertSame( 1, $res['stats']['media_found'] );

		$posts = $this->posts( 'publish' );
		$this->assertCount( 1, $posts );
		$p = $posts[0];
		$this->assertSame( 'Clip one: behind the scenes', $p->post_title );
		$this->assertSame( 'A short behind-the-scenes clip from the studio.', $p->post_excerpt );
		$this->assertSame( '2025-06-10 14:30:00', $p->post_date_gmt, 'source publication date used' );
		$this->assertSame( 'https://alpha.example/clips/one?utm_source=rss', get_post_meta( $p->ID, '_rmh_source_url', true ) );
		$this->assertSame( 'alpha-clip-0001', get_post_meta( $p->ID, '_rmh_guid', true ) );
		$this->assertSame( 'video', get_post_meta( $p->ID, '_rmh_media_type', true ) );
		$this->assertSame( 'Jane Producer', get_post_meta( $p->ID, '_rmh_author', true ) );
		$this->assertSame( array( 'video' ), wp_get_post_terms( $p->ID, PostTypes::TAX_TYPE, array( 'fields' => 'slugs' ) ) );
		$this->assertEqualsCanonicalizing( array( 'Behind the scenes', 'Studio' ), wp_get_post_terms( $p->ID, PostTypes::TAX_TAG, array( 'fields' => 'names' ) ) );
		$this->assertSame( array( 'Test Feed' ), wp_get_post_terms( $p->ID, PostTypes::TAX_SOURCE, array( 'fields' => 'names' ) ) );

		// Stable permalink under /media/.
		$this->assertStringContainsString( '/media/clip-one-behind-the-scenes/', get_permalink( $p ) );

		// Media stored separately from the post.
		$media = $this->media_for( $p->ID );
		$this->assertCount( 1, $media );
		$this->assertSame( 'https://cdn.alpha.example/media/clip-one.mp4', $media[0]->url );
		$this->assertSame( 'enclosure', $media[0]->extraction_method );
		$this->assertSame( 'unchecked', $media[0]->validation_status, 'never claimed verified without a check' );
		$this->assertSame( 10485760, $media[0]->filesize );

		$html = $this->render_single( $p->ID );
		$this->assertMatchesRegularExpression( '#<video[^>]+controls[^>]+playsinline#s', $html );
		$this->assertStringContainsString( '<source src="https://cdn.alpha.example/media/clip-one.mp4" type="video/mp4">', $html );
		$this->assertStringContainsString( 'Read the original at Test Feed', $html );
		$this->assertStringContainsString( 'Source: <a href="https://alpha.example/clips/one?utm_source=rss"', $html, 'attribution visible' );
	}

	public function test_scenario_2_audio_enclosure_renders_audio_player() {
		$this->serve( 'https://audio.example/feed', 'rss-audio.xml' );
		$this->run_feed( $this->add_feed( 'https://audio.example/feed' ) );
		$p    = $this->posts( 'publish' )[0];
		$html = $this->render_single( $p->ID );
		$this->assertSame( 'audio', get_post_meta( $p->ID, '_rmh_media_type', true ) );
		$this->assertMatchesRegularExpression( '#<audio controls preload="none"#', $html );
		$this->assertStringContainsString( 'src="https://cdn.audio.example/ep12.mp3" type="audio/mpeg"', $html );
		$this->assertStringContainsString( 'https://cdn.audio.example/ep12.jpg', get_post_meta( $p->ID, '_rmh_thumbnail_url', true ) );
	}

	public function test_scenario_3_multiple_images_render_gallery() {
		$this->serve( 'https://photos.example/feed', 'rss-images.xml' );
		$this->run_feed( $this->add_feed( 'https://photos.example/feed' ) );
		$p    = $this->posts( 'publish' )[0];
		$html = $this->render_single( $p->ID );
		$this->assertSame( 'gallery', get_post_meta( $p->ID, '_rmh_media_type', true ) );
		$this->assertStringContainsString( 'rmh-media--gallery', $html );
		$this->assertSame( 4, substr_count( $html, 'data-rmh-lightbox=' ) );
		$this->assertStringContainsString( 'alt="Frame A"', $html );
		$this->assertMatchesRegularExpression( '#loading="lazy"#', $html );
	}

	public function test_scenario_4_media_rss_video_with_thumbnail() {
		$this->serve( 'https://mrss.example/feed', 'mrss-video.xml' );
		$this->run_feed( $this->add_feed( 'https://mrss.example/feed' ) );
		$p    = $this->posts( 'publish' )[0];
		$html = $this->render_single( $p->ID );
		$this->assertStringContainsString( 'poster="https://cdn.mrss.example/feature/poster.jpg"', $html );
		$this->assertStringContainsString( 'type="video/webm"', $html );
		$this->assertStringContainsString( 'type="video/mp4"', $html );
		$this->assertStringContainsString( '--rmh-ratio:1920 / 1080', $html );
		$this->assertStringNotContainsString( 'player.mrss.example', $html, 'non-allowlisted player never framed' );
		$media = $this->media_for( $p->ID );
		$this->assertSame( 'MRSS Studio', $media[0]->credit );
		$this->assertSame( 754, $media[0]->duration );
	}

	public function test_scenario_5_atom_feed_import() {
		$this->serve( 'https://atom.example/feed.atom', 'atom.xml', array( 'content-type' => 'application/atom+xml' ) );
		$res = $this->run_feed( $this->add_feed( 'https://atom.example/feed.atom', array( 'content_policy' => 'full' ) ) );
		$this->assertSame( 2, $res['stats']['posts_created'] );
		$posts = $this->posts( 'publish' );
		$titles = array_map( static function ( $p ) { return html_entity_decode( $p->post_title, ENT_QUOTES ); }, $posts );
		$one    = $posts[ array_search( 'Episode one & more', $titles, true ) ];
		$this->assertContains( 'Episode one & more', $titles, 'HTML title type rendered to plain text' );
		$this->assertStringContainsString( '<strong>Atom</strong>', $one->post_content );
		$this->assertStringNotContainsString( 'alert(1)', $one->post_content );
		$this->assertSame( 'video', get_post_meta( $one->ID, '_rmh_media_type', true ) );
	}

	public function test_scenario_6_item_without_media() {
		$this->serve( 'https://news.example/feed', 'rss-no-media.xml' );
		$this->run_feed( $this->add_feed( 'https://news.example/feed' ) );
		$text = get_posts( array( 'post_type' => PostTypes::POST_TYPE, 'title' => 'Industry news roundup', 'post_status' => 'any' ) )[0];
		$this->assertSame( 'text', get_post_meta( $text->ID, '_rmh_media_type', true ) );
		$html = $this->render_single( $text->ID );
		$this->assertStringNotContainsString( '<video', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringContainsString( 'href="https://news.example/roundup"', $html );
		$this->assertSame( 'fallback_link', $this->media_for( $text->ID )[0]->extraction_method );
	}

	public function test_scenario_7_missing_optional_metadata_still_imports() {
		$this->serve( 'https://min.example/feed', 'rss-minimal.xml' );
		$res = $this->run_feed( $this->add_feed( 'https://min.example/feed' ) );
		$this->assertSame( 2, $res['stats']['posts_created'] );
		// Re-run: identities without GUIDs (link / fingerprint) are stable, so no duplicates.
		$again = $this->run_feed( rmh()->get( 'feeds' )->find_by_url( 'https://min.example/feed' )->id );
		$this->assertSame( 0, $again['stats']['posts_created'] );
		$this->assertSame( 2, $again['stats']['duplicates'] );
		$this->assertCount( 2, $this->posts() );
	}

	public function test_scenario_8_invalid_date_uses_import_time_and_is_logged() {
		$this->serve( 'https://dates.example/feed', 'rss-bad-date.xml' );
		$res = $this->run_feed( $this->add_feed( 'https://dates.example/feed' ) );
		$this->assertSame( 2, $res['stats']['posts_created'] );
		foreach ( $this->posts() as $p ) {
			$this->assertLessThan( 120, abs( time() - strtotime( $p->post_date_gmt . ' UTC' ) ), 'falls back to now' );
			$this->assertSame( '0', (string) get_post_meta( $p->ID, '_rmh_source_published', true ) );
		}
		$logs = rmh()->get( 'logger' )->query( array( 'channel' => 'parse', 'level' => 'warning' ) );
		$this->assertCount( 2, array_filter( $logs['rows'], static function ( $r ) { return false !== strpos( $r->message, 'Invalid publication date' ); } ) );
	}

	public function test_scenario_12_duplicate_guids_create_one_post() {
		$this->serve( 'https://dupe.example/feed', 'rss-dup-guid.xml' );
		$res = $this->run_feed( $this->add_feed( 'https://dupe.example/feed' ) );
		$this->assertSame( 1, $res['stats']['posts_created'] );
		$this->assertSame( 1, $res['stats']['duplicates'] );
		$this->assertCount( 1, $this->posts() );
	}

	public function test_scenario_13_duplicate_canonical_urls_create_one_post() {
		$this->serve( 'https://dupe.example/urls', 'rss-dup-url.xml' );
		$res = $this->run_feed( $this->add_feed( 'https://dupe.example/urls' ) );
		$this->assertSame( 1, $res['stats']['posts_created'] );
		$this->assertCount( 1, $this->posts() );
		$dupes = rmh()->get( 'items' )->query( array( 'status' => array( 'duplicate' ) ) );
		$this->assertSame( 1, $dupes['total'] );
		$this->assertNotSame( 0, (int) $dupes['rows'][0]->duplicate_of );

		// Same article syndicated by a second feed is also detected.
		$this->serve( 'https://mirror.example/feed', 'rss-dup-url.xml' );
		$res2 = $this->run_feed( $this->add_feed( 'https://mirror.example/feed' ) );
		$this->assertSame( 0, $res2['stats']['posts_created'] );
		$this->assertCount( 1, $this->posts() );
	}

	public function test_scenario_14_updates_never_duplicate_and_respect_editor_changes() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		$this->run_feed( $feed );
		$p = $this->posts()[0];

		// Unchanged feed: nothing happens.
		$same = $this->run_feed( $feed );
		$this->assertSame( 0, $same['stats']['posts_created'] );
		$this->assertSame( 0, $same['stats']['posts_updated'] );
		$this->assertSame( 'all_duplicates', $same['outcome'] );

		// Source changes: same post updated.
		$this->serve( 'https://alpha.example/feed', 'rss-video-updated.xml' );
		$upd = $this->run_feed( $feed );
		$this->assertSame( 0, $upd['stats']['posts_created'] );
		$this->assertSame( 1, $upd['stats']['posts_updated'] );
		$this->assertCount( 1, $this->posts() );
		$this->assertSame( 'Clip one: behind the scenes (updated)', get_post( $p->ID )->post_title );

		// Editor changes the title; the next source change must not overwrite it, but the excerpt still syncs.
		wp_update_post( array( 'ID' => $p->ID, 'post_title' => 'Editor title' ) );
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$this->run_feed( $feed );
		$after = get_post( $p->ID );
		$this->assertSame( 'Editor title', $after->post_title );
		$this->assertSame( 'A short behind-the-scenes clip from the studio.', $after->post_excerpt );
		$this->assertCount( 1, $this->posts() );
	}

	public function test_update_policy_none_leaves_posts_untouched() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$feed = $this->add_feed( 'https://alpha.example/feed', array( 'update_policy' => 'none' ) );
		$this->run_feed( $feed );
		$this->serve( 'https://alpha.example/feed', 'rss-video-updated.xml' );
		$res = $this->run_feed( $feed );
		$this->assertSame( 0, $res['stats']['posts_updated'] );
		$this->assertSame( 'Clip one: behind the scenes', $this->posts()[0]->post_title );
	}

	public function test_scenario_25_manual_review_feed() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$res = $this->run_feed( $this->add_feed( 'https://alpha.example/feed', array( 'import_mode' => 'manual' ) ) );
		$this->assertSame( 0, $res['stats']['posts_created'] );
		$this->assertSame( 1, $res['stats']['pending_review'] );
		$this->assertCount( 0, $this->posts() );
		$item = rmh()->get( 'items' )->query( array( 'status' => array( 'queued' ) ) )['rows'][0];

		$this->assertTrue( rmh()->get( 'importer' )->approve( $item->id ) );
		$this->assertCount( 1, $this->posts( 'publish' ) );
		$this->assertSame( 'published', rmh()->get( 'items' )->find( $item->id )->status );
		$this->assertTrue( rmh()->get( 'importer' )->approve( $item->id ), 'approving twice is idempotent' );
		$this->assertCount( 1, $this->posts() );
	}

	public function test_pending_review_mode_creates_pending_posts() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$res = $this->run_feed( $this->add_feed( 'https://alpha.example/feed', array( 'import_mode' => 'pending' ) ) );
		$this->assertSame( 1, $res['stats']['posts_created'] );
		$this->assertSame( 1, $res['stats']['pending_review'] );
		$this->assertCount( 1, $this->posts( 'pending' ) );
		$item = rmh()->get( 'items' )->query( array( 'status' => array( 'pending' ) ) )['rows'][0];
		rmh()->get( 'importer' )->reject( $item->id );
		$this->assertCount( 0, $this->posts( 'pending' ) );
		$this->assertCount( 1, $this->posts( 'trash' ), 'rejection is recoverable (trash)' );
	}

	public function test_scenario_26_media_only_feed() {
		$this->serve( 'https://news.example/feed', 'rss-no-media.xml' );
		$res = $this->run_feed( $this->add_feed( 'https://news.example/feed', array( 'content_mode' => 'media_only' ) ) );
		$this->assertSame( 1, $res['stats']['posts_created'] );
		$this->assertSame( 1, $res['stats']['skipped'] );
		$posts = $this->posts();
		$this->assertCount( 1, $posts );
		$this->assertSame( 'Video of the week', $posts[0]->post_title );
	}

	public function test_excerpt_policy_does_not_republish_full_articles() {
		$this->serve( 'https://xss.example/feed', 'rss-xss.xml' );
		$this->run_feed( $this->add_feed( 'https://xss.example/feed' ) );
		$p = $this->posts()[0];
		$this->assertStringNotContainsString( 'Full', $p->post_content, 'content:encoded not copied under excerpt policy' );
	}

	public function test_categories_and_tags_mapping() {
		$term = wp_insert_term( 'Featured', PostTypes::TAX_CATEGORY );
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$this->settings( array( 'source_categories' => 'categories' ) );
		$this->run_feed( $this->add_feed( 'https://alpha.example/feed', array( 'categories' => array( $term['term_id'] ), 'tags' => 'house, promo' ) ) );
		$p = $this->posts()[0];
		$this->assertEqualsCanonicalizing( array( 'Featured', 'Behind the scenes', 'Studio' ), wp_get_post_terms( $p->ID, PostTypes::TAX_CATEGORY, array( 'fields' => 'names' ) ) );
		$this->assertEqualsCanonicalizing( array( 'house', 'promo' ), wp_get_post_terms( $p->ID, PostTypes::TAX_TAG, array( 'fields' => 'names' ) ) );
	}

	public function test_conditional_requests_use_etag_and_last_modified() {
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml', array( 'ETag' => '"v1"', 'Last-Modified' => 'Tue, 10 Jun 2025 14:30:00 GMT' ) );
		$feed = $this->add_feed( 'https://alpha.example/feed' );
		$this->run_feed( $feed );
		$this->http->on( 'https://alpha.example/feed', array( 'status' => 304 ) );
		$res = $this->run_feed( $feed );
		$this->assertSame( 'not_modified', $res['outcome'] );
		$last = end( $this->http->requests );
		$this->assertSame( '"v1"', $last[1]['headers']['If-None-Match'] );
		$this->assertSame( 'Tue, 10 Jun 2025 14:30:00 GMT', $last[1]['headers']['If-Modified-Since'] );
		$this->assertSame( 'ok', rmh()->get( 'feeds' )->find( $feed )->health );
	}

	public function test_max_items_per_run_keeps_newest() {
		$this->serve( 'https://news.example/feed', 'rss-no-media.xml' );
		$this->run_feed( $this->add_feed( 'https://news.example/feed', array( 'max_items' => 1 ) ) );
		$posts = $this->posts();
		$this->assertCount( 1, $posts );
		$this->assertSame( 'Video of the week', $posts[0]->post_title, 'newest item kept' );
	}

	public function test_empty_feed_outcome() {
		$this->http->on( 'https://empty.example/feed', array( 'body' => '<?xml version="1.0"?><rss version="2.0"><channel><title>Empty</title></channel></rss>' ) );
		$res = $this->run_feed( $this->add_feed( 'https://empty.example/feed' ) );
		$this->assertSame( 'empty_feed', $res['outcome'] );
		$this->assertSame( 'success', $res['status'] );
	}

	public function test_unsupported_media_outcome() {
		$xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>Docs</title><item><title>Spec</title><guid>doc-1</guid><enclosure url="https://docs.example/spec.pdf" type="application/pdf" length="1"/></item></channel></rss>';
		$this->http->on( 'https://docs.example/feed', array( 'body' => $xml ) );
		$res = $this->run_feed( $this->add_feed( 'https://docs.example/feed', array( 'content_mode' => 'media_only' ) ) );
		$this->assertSame( 'unsupported_media', $res['outcome'], (string) wp_json_encode( $res['stats'] ) );
		$this->assertCount( 0, $this->posts() );
	}

	public function test_blocklisted_feed_and_items() {
		rmh()->get( 'blocklist' )->add( 'keyword', 'behind the scenes' );
		$this->serve( 'https://alpha.example/feed', 'rss-video.xml' );
		$res = $this->run_feed( $this->add_feed( 'https://alpha.example/feed' ) );
		$this->assertSame( 0, $res['stats']['posts_created'] );
		$this->assertSame( 1, rmh()->get( 'items' )->query( array( 'status' => array( 'blocked' ) ) )['total'] );

		rmh()->get( 'blocklist' )->add( 'domain', 'audio.example' );
		$this->serve( 'https://audio.example/feed', 'rss-audio.xml' );
		$res2 = $this->run_feed( $this->add_feed( 'https://audio.example/feed' ) );
		$this->assertSame( 'blocked_source', $res2['outcome'] );
		$this->assertSame( 0, $this->http->count( 'https://audio.example/feed' ) );
	}
}

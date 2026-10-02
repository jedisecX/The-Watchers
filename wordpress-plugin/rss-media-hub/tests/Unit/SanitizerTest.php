<?php
/**
 * HTML sanitization and redaction (scenario 18 + log safety).
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests\Unit;

use RMH\Feed\FeedParser;
use RMH\Security\Redactor;
use RMH\Security\Sanitizer;
use RMH\Tests\TestCase;

/**
 * Sanitizer / Redactor.
 */
class SanitizerTest extends TestCase {

	public function test_malicious_description_is_neutralized() {
		$doc  = ( new FeedParser() )->parse( $this->fixture( 'rss-xss.xml' ), 'https://xss.example/' );
		$html = Sanitizer::content( $doc['items'][0]['summary'], 'https://xss.example/1' );
		foreach ( array( '<script', 'onerror', 'onclick', 'javascript:', '<style', '<svg', '<form', '<input', '<object', 'data:text', 'expression(', 'srcset' ) as $bad ) {
			$this->assertStringNotContainsStringIgnoringCase( $bad, $html, $bad );
		}
		$this->assertStringContainsString( 'href="https://ok.example/"', $html );
		$this->assertMatchesRegularExpression( '/rel="[^"]*nofollow[^"]*noopener/', $html );
		$this->assertStringContainsString( 'src="https://xss.example/ok.jpg"', $html );
		$this->assertStringContainsString( 'src="https://xss.example/x"', $html, 'relative src absolutized against the source, not this site' );

		$content = Sanitizer::content( $doc['items'][0]['content'], 'https://xss.example/1' );
		$this->assertStringNotContainsString( '<iframe', $content );
		$this->assertStringNotContainsString( '<base', $content );
		$this->assertStringNotContainsString( '<meta', $content );
		$this->assertStringContainsString( '<em>content</em>', $content );

		$this->assertSame( 'Hello', Sanitizer::text( $doc['items'][0]['title'] ), 'script element and its content removed from titles' );
		$this->assertStringNotContainsString( '<script', Sanitizer::text( $doc['items'][0]['title'] ) );
	}

	public function test_relative_links_without_base_are_dropped() {
		$this->assertStringNotContainsString( 'href', Sanitizer::content( '<a href="/wp-admin/">x</a>' ) );
	}

	public function test_redaction() {
		$this->assertSame( 'https://[redacted]@feed.test/rss?token=[redacted]&page=2', Redactor::url( 'https://user:secret@feed.test/rss?token=abc123&page=2' ) );
		$this->assertStringNotContainsString( 'hunter2', Redactor::text( 'password=hunter2 failed' ) );
		$this->assertStringNotContainsString( 'eyJabc', Redactor::text( 'Authorization: Bearer eyJabc.def' ) );
		$data = Redactor::data( array( 'author' => 'Jane', 'api_key' => 'k', 'nested' => array( 'auth' => 'x', 'url' => 'https://a.test/?sig=1' ) ) );
		$this->assertSame( 'Jane', $data['author'] );
		$this->assertSame( '[redacted]', $data['api_key'] );
		$this->assertSame( '[redacted]', $data['nested']['auth'] );
		$this->assertSame( 'https://a.test/?sig=[redacted]', $data['nested']['url'] );
	}

	public function test_logger_never_stores_secrets() {
		$id = rmh()->get( 'logger' )->error( 'http', 'Fetch https://u:p@feed.test/x?apikey=SECRET1 failed', array( 'password' => 'SECRET2', 'feed_id' => 3 ) );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}rmh_logs WHERE id = %d", $id ) );
		$this->assertStringNotContainsString( 'SECRET1', $row->message );
		$this->assertStringNotContainsString( 'SECRET2', $row->context );
		$this->assertStringNotContainsString( 'u:p@', $row->message );
		$this->assertSame( '3', (string) $row->feed_id );
	}

	public function test_feed_credentials_are_encrypted_at_rest() {
		$id = rmh()->get( 'feeds' )->create( array( 'url' => 'https://private.test/feed', 'config' => array( 'auth_user' => 'bob', 'auth_pass' => 'S3cr3t!' ) ) );
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$wpdb->prefix}rmh_feeds WHERE id = %d", $id ) );
		$this->assertStringNotContainsString( 'S3cr3t!', $raw );
		$feed = rmh()->get( 'feeds' )->find( $id );
		$cfg  = \RMH\Db\FeedConfig::effective( $feed, rmh()->get( 'settings' ) );
		$this->assertSame( 'S3cr3t!', $cfg['auth_pass'] );
		// Updating without a new password keeps it.
		rmh()->get( 'feeds' )->update( $id, array( 'config' => array( 'auth_pass' => '', 'tags' => 'a,b' ) ) );
		$this->assertSame( 'S3cr3t!', \RMH\Db\FeedConfig::effective( rmh()->get( 'feeds' )->find( $id ), rmh()->get( 'settings' ) )['auth_pass'] );
		$out = \RMH\Rest\RestController::feed_out( rmh()->get( 'feeds' )->find( $id ) );
		$this->assertArrayNotHasKey( 'auth_pass', $out['config'] );
		$this->assertTrue( $out['config']['has_auth'] );
	}

	public function test_settings_sanitize_rejects_invalid_values() {
		$clean = \RMH\Settings::sanitize( array( 'import_mode' => 'drop tables', 'default_poll_interval' => 5, 'allowed_media_types' => array( 'video', 'exe' ), 'blocked_countries' => 'gb, us-ut, <script>, x', 'attribution_template' => '<a href="{source_url}" onclick="x">s</a><script>1</script>' ) );
		$this->assertSame( 'pending', $clean['import_mode'] );
		$this->assertSame( 300, $clean['default_poll_interval'] );
		$this->assertSame( array( 'video' ), $clean['allowed_media_types'] );
		$this->assertSame( 'GB,US-UT', $clean['blocked_countries'] );
		$this->assertStringNotContainsString( 'onclick', $clean['attribution_template'] );
		$this->assertStringNotContainsString( '<script', $clean['attribution_template'] );
	}

	public function test_feed_can_only_narrow_global_media_types() {
		$this->settings( array( 'allowed_media_types' => array( 'video', 'text' ) ) );
		$id  = rmh()->get( 'feeds' )->create( array( 'url' => 'https://narrow.test/feed', 'config' => array( 'allowed_media_types' => array( 'video', 'image' ) ) ) );
		$cfg = \RMH\Db\FeedConfig::effective( rmh()->get( 'feeds' )->find( $id ), rmh()->get( 'settings' ) );
		$this->assertSame( array( 'video' ), $cfg['allowed_media_types'] );
	}
}

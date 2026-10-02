<?php
/**
 * Feed parsing (scenarios 5, 7, 8, 9 + XXE/entity expansion).
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests\Unit;

use RMH\Feed\DateParser;
use RMH\Feed\FeedParser;
use RMH\Tests\TestCase;

/**
 * Adapters, hardening and normalization.
 */
class ParserTest extends TestCase {

	/**
	 * Parse a fixture.
	 *
	 * @param string $name Fixture.
	 * @param string $base Base URL.
	 * @return array|\WP_Error
	 */
	private function parse( $name, $base = 'https://feed.test/rss' ) {
		return ( new FeedParser() )->parse( $this->fixture( $name ), $base );
	}

	public function test_rss2_with_enclosure_and_dublin_core() {
		$doc = $this->parse( 'rss-video.xml' );
		$this->assertSame( 'rss2', $doc['format'] );
		$this->assertSame( 'Studio Alpha Clips', $doc['title'] );
		$i = $doc['items'][0];
		$this->assertSame( 'alpha-clip-0001', $i['guid'] );
		$this->assertSame( 'Clip one: behind the scenes', $i['title'] );
		$this->assertSame( array( 'Jane Producer' ), $i['authors'] );
		$this->assertSame( array( 'Behind the scenes', 'Studio' ), $i['categories'] );
		$this->assertSame( gmmktime( 14, 30, 0, 6, 10, 2025 ), $i['published'] );
		$this->assertSame( 'https://cdn.alpha.example/media/clip-one.mp4', $i['enclosures'][0]['url'] );
		$this->assertSame( 'video/mp4', $i['enclosures'][0]['type'] );
		$this->assertSame( 10485760, $i['enclosures'][0]['length'] );
	}

	public function test_rss_author_email_is_not_exposed() {
		$doc = $this->parse( 'rss-audio.xml' );
		$this->assertSame( array( 'The Host' ), $doc['items'][0]['authors'] );
		$this->assertSame( array( 'https://cdn.audio.example/ep12.jpg' ), $doc['items'][0]['thumbnails'] );
	}

	public function test_scenario_5_atom_entries() {
		$doc = $this->parse( 'atom.xml', 'https://atom.example/feed.atom' );
		$this->assertSame( 'atom', $doc['format'] );
		$this->assertCount( 2, $doc['items'] );
		$one = $doc['items'][0];
		$this->assertSame( 'tag:atom.example,2025:1', $one['guid'] );
		$this->assertSame( 'Episode one & more', $one['title'] );
		$this->assertSame( 'https://atom.example/posts/one', $one['link'], 'relative alternate link resolved against feed link' );
		$this->assertSame( gmmktime( 7, 30, 0, 6, 5, 2025 ), $one['published'], 'timezone offset honoured' );
		$this->assertSame( array( 'Alex Writer' ), $one['authors'] );
		$this->assertSame( array( 'Creators' ), $one['categories'] );
		$this->assertSame( 'https://cdn.atom.example/one.webm', $one['enclosures'][0]['url'] );
		$this->assertStringContainsString( '&lt;tags&gt;', $one['summary'], 'type="text" summary is escaped, not parsed as HTML' );
		$this->assertStringContainsString( '<strong>Atom</strong>', $one['content'] );
		$two = $doc['items'][1];
		$this->assertSame( array( 'Atom Team' ), $two['authors'], 'feed-level author inherited' );
		$this->assertSame( gmmktime( 8, 0, 0, 6, 4, 2025 ), $two['published'], 'falls back to <updated>' );
	}

	public function test_rss1_rdf() {
		$doc = $this->parse( 'rss1.xml' );
		$this->assertSame( 'rss1', $doc['format'] );
		$this->assertSame( 'https://rdf.example/a', $doc['items'][0]['guid'] );
		$this->assertSame( array( 'RDF Author' ), $doc['items'][0]['authors'] );
		$this->assertSame( array( 'Archive' ), $doc['items'][0]['categories'] );
	}

	public function test_media_rss_group() {
		$doc = $this->parse( 'mrss-video.xml' );
		$m   = $doc['items'][0]['media'];
		$this->assertCount( 3, $m );
		$this->assertSame( 'https://cdn.mrss.example/feature/1080.webm', $m[0]['url'] );
		$this->assertSame( 'video', $m[0]['medium'] );
		$this->assertSame( 754, $m[0]['duration'] );
		$this->assertSame( 'https://cdn.mrss.example/feature/poster.jpg', $m[0]['thumbnail'], 'group thumbnail inherited' );
		$this->assertSame( 'MRSS Studio', $m[0]['credit'] );
		$this->assertSame( 'media_player', $m[2]['source'] );
	}

	public function test_scenario_7_missing_optional_metadata() {
		$doc = $this->parse( 'rss-minimal.xml' );
		$this->assertCount( 2, $doc['items'] );
		$this->assertSame( 'https://min.example/only-a-link', $doc['items'][0]['link'] );
		$this->assertSame( '', $doc['items'][0]['guid'] );
		$this->assertNotSame( '', $doc['items'][0]['title'] );
		$this->assertNull( $doc['items'][0]['published'] );
		$this->assertStringStartsWith( 'No title, no link', $doc['items'][1]['title'], 'title derived from description' );
	}

	public function test_scenario_8_invalid_and_future_dates() {
		$doc = $this->parse( 'rss-bad-date.xml' );
		$this->assertNull( $doc['items'][0]['published'] );
		$this->assertSame( 'the 32nd of Smarch, sometime', $doc['items'][0]['date_raw'] );
		$this->assertNull( $doc['items'][1]['published'], 'far-future dates are implausible' );
		$this->assertSame( gmmktime( 10, 0, 0, 3, 4, 2025 ), DateParser::parse( 'Mon, 04 Mar 2025 10:00:00 GMT' ), 'wrong weekday tolerated' );
		$this->assertSame( gmmktime( 0, 0, 0, 1, 2, 2025 ), DateParser::parse( '2025-01-02' ) );
		$this->assertSame( gmmktime( 10, 0, 0, 3, 3, 2025 ), DateParser::parse( '2025-03-03T12:00:00+02:00' ) );
		$this->assertNull( DateParser::parse( '1969-01-01T00:00:00Z' ) );
	}

	public function test_scenario_9_malformed_xml_is_rejected() {
		$r = $this->parse( 'malformed.xml' );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( 'rmh_invalid_xml', $r->get_error_code() );
		$this->assertStringContainsString( 'line', $r->get_error_message() );
	}

	public function test_truncated_feed_is_recovered_with_warning() {
		$doc = $this->parse( 'truncated.xml' );
		$this->assertIsArray( $doc );
		$this->assertSame( 'Complete item', $doc['items'][0]['title'] );
		$this->assertStringContainsString( 'recovery mode', implode( ' ', $doc['warnings'] ) );
	}

	public function test_recoverable_xml_is_repaired_with_warning() {
		$doc = $this->parse( 'malformed-recoverable.xml' );
		$this->assertIsArray( $doc );
		$this->assertSame( 'Rock & Roll © 2025', $doc['items'][0]['title'] );
		$this->assertSame( 'https://recover.example/a?x=1&y=2', $doc['items'][0]['link'] );
		$this->assertNotEmpty( $doc['warnings'] );
	}

	public function test_xxe_and_entity_expansion_are_refused() {
		foreach ( array( 'xxe.xml', 'billion-laughs.xml' ) as $f ) {
			$r = $this->parse( $f );
			$this->assertInstanceOf( \WP_Error::class, $r, $f );
			$this->assertSame( 'rmh_xml_entities', $r->get_error_code() );
		}
	}

	public function test_non_feed_documents() {
		$p = new FeedParser();
		$this->assertSame( 'rmh_not_xml', $p->parse( '<!DOCTYPE html><html><body>Not found</body></html>', 'https://x.test/' )->get_error_code() );
		$this->assertSame( 'rmh_empty_document', $p->parse( "  \n", 'https://x.test/' )->get_error_code() );
		$this->assertSame( 'rmh_unknown_format', $p->parse( '<?xml version="1.0"?><note><to>x</to></note>', 'https://x.test/' )->get_error_code() );
		$this->assertSame( 'rmh_too_large', $p->parse( '<rss>' . str_repeat( ' ', 2000 ) . '</rss>', 'https://x.test/', 1000 )->get_error_code() );
	}

	public function test_custom_adapter_extension_point() {
		$adapter = new class() extends \RMH\Feed\AbstractAdapter {
			public function id() {
				return 'custom';
			}
			public function supports( \DOMDocument $dom ) {
				return 'catalog' === $dom->documentElement->localName;
			}
			public function parse( \DOMDocument $dom, $base_url ) {
				$items = array();
				foreach ( $dom->getElementsByTagName( 'video' ) as $v ) {
					$items[] = $this->item( array( 'guid' => $v->getAttribute( 'id' ), 'title' => $v->getAttribute( 'name' ), 'enclosures' => array( array( 'url' => $v->getAttribute( 'src' ), 'type' => 'video/mp4', 'length' => 0 ) ) ), $base_url );
				}
				return array( 'format' => 'custom', 'title' => 'Catalog', 'link' => '', 'description' => '', 'next_page' => '', 'warnings' => array(), 'items' => $items );
			}
		};
		$this->hook(
			'rmh_feed_adapters',
			static function ( $list ) use ( $adapter ) {
				$list[] = $adapter;
				return $list;
			}
		);
		$doc = ( new FeedParser() )->parse( '<catalog><video id="v1" name="Custom" src="https://c.test/v1.mp4"/></catalog>', 'https://c.test/' );
		$this->assertSame( 'custom', $doc['format'] );
		$this->assertSame( 'Custom', $doc['items'][0]['title'] );
	}
}

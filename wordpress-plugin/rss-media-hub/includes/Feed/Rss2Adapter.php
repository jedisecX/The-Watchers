<?php
/**
 * RSS 0.9x / 2.0 adapter.
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Parses <rss><channel><item> documents including content:encoded,
 * Dublin Core, enclosures, iTunes images and Media RSS.
 */
class Rss2Adapter extends AbstractAdapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'rss2';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \DOMDocument $dom DOM.
	 * @return bool
	 */
	public function supports( \DOMDocument $dom ) {
		$root = $dom->documentElement;
		return $root && 'rss' === strtolower( $root->localName );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \DOMDocument $dom      DOM.
	 * @param string       $base_url Base.
	 * @return array
	 */
	public function parse( \DOMDocument $dom, $base_url ) {
		$root    = $dom->documentElement;
		$channel = Dom::child( $root, 'channel' );
		$scope   = $channel ? $channel : $root;

		$link = Dom::text( $scope, 'link', Dom::NS_NONE );
		$doc  = array(
			'format'      => 'rss2',
			'title'       => Dom::plain( Dom::text( $scope, 'title', Dom::NS_NONE ) ),
			'link'        => $this->abs( $link, $base_url ),
			'description' => Dom::plain( Dom::text( $scope, 'description', Dom::NS_NONE ) ),
			'next_page'   => '',
			'warnings'    => array(),
			'items'       => array(),
		);
		foreach ( Dom::children( $scope, 'link', Dom::NS_ATOM ) as $al ) {
			if ( 'next' === strtolower( Dom::attr( $al, 'rel' ) ) ) {
				$doc['next_page'] = $this->abs( Dom::attr( $al, 'href' ), $base_url );
			}
		}

		$items = Dom::children( $scope, 'item' );
		if ( ! $items && $channel ) {
			$items = Dom::children( $root, 'item' ); // RSS 0.9x variants with items beside <channel>.
		}
		foreach ( $items as $el ) {
			$doc['items'][] = $this->parse_item( $el, '' !== $doc['link'] ? $doc['link'] : $base_url );
		}
		return $doc;
	}

	/**
	 * Parse one <item>.
	 *
	 * @param \DOMElement $el   Item.
	 * @param string      $base Base URL.
	 * @return array
	 */
	private function parse_item( \DOMElement $el, $base ) {
		$guid_el = Dom::child( $el, 'guid', Dom::NS_NONE );
		$guid    = $guid_el ? trim( $guid_el->textContent ) : '';
		$link    = Dom::text( $el, 'link', Dom::NS_NONE );
		if ( '' === $link ) {
			foreach ( Dom::children( $el, 'link', Dom::NS_ATOM ) as $al ) {
				$rel = strtolower( Dom::attr( $al, 'rel' ) );
				if ( '' === $rel || 'alternate' === $rel ) {
					$link = Dom::attr( $al, 'href' );
					break;
				}
			}
		}
		if ( '' === $link && $guid_el && 'false' !== strtolower( Dom::attr( $guid_el, 'isPermaLink' ) ) ) {
			$link = $guid;
		}

		$authors = array();
		foreach ( array( array( 'author', Dom::NS_NONE ), array( 'creator', Dom::NS_DC ), array( 'author', Dom::NS_ITUNES ) ) as $spec ) {
			foreach ( Dom::children( $el, $spec[0], $spec[1] ) as $a ) {
				$name = trim( $a->textContent );
				// RSS <author> is "email (Name)"; keep the name, drop the address.
				if ( preg_match( '/\(([^)]+)\)\s*$/', $name, $m ) ) {
					$name = $m[1];
				} elseif ( is_email( $name ) ) {
					$name = '';
				}
				$authors[] = $name;
			}
		}

		$categories = array();
		foreach ( Dom::children( $el, 'category', Dom::NS_NONE ) as $c ) {
			$categories[] = $c->textContent;
		}
		foreach ( Dom::children( $el, 'subject', Dom::NS_DC ) as $c ) {
			$categories[] = $c->textContent;
		}

		$enclosures = array();
		foreach ( Dom::children( $el, 'enclosure', Dom::NS_NONE ) as $enc ) {
			$enclosures[] = array(
				'url'    => Dom::attr( $enc, 'url' ),
				'type'   => strtolower( Dom::attr( $enc, 'type' ) ),
				'length' => (int) Dom::attr( $enc, 'length' ),
			);
		}

		$mrss   = MediaRss::extract( $el );
		$thumbs = $mrss['thumbnails'];
		$itimg  = Dom::child( $el, 'image', Dom::NS_ITUNES );
		if ( $itimg && '' !== Dom::attr( $itimg, 'href' ) ) {
			$thumbs[] = Dom::attr( $itimg, 'href' );
		}

		$content_el = Dom::child( $el, 'encoded', Dom::NS_CONTENT );

		return $this->item(
			array_merge(
				array(
					'guid'              => $guid,
					'title'             => Dom::text( $el, 'title', Dom::NS_NONE ),
					'link'              => $link,
					'summary'           => Dom::text( $el, 'description', Dom::NS_NONE ),
					'content'           => $content_el ? trim( $content_el->textContent ) : '',
					'authors'           => $authors,
					'categories'        => $categories,
					'enclosures'        => $enclosures,
					'media'             => $mrss['media'],
					'thumbnails'        => $thumbs,
					'media_description' => $mrss['description'],
					'media_credit'      => $mrss['credit'],
				),
				$this->dates( Dom::text( $el, 'pubDate', Dom::NS_NONE ) ?: Dom::text( $el, 'date', Dom::NS_DC ), '' )
			),
			$base
		);
	}
}

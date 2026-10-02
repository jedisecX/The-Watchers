<?php
/**
 * RSS 1.0 (RDF) adapter.
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Parses <rdf:RDF> documents with RSS 1.0 channel/items, Dublin Core and modules.
 */
class Rss1Adapter extends AbstractAdapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'rss1';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \DOMDocument $dom DOM.
	 * @return bool
	 */
	public function supports( \DOMDocument $dom ) {
		$root = $dom->documentElement;
		return $root && 'RDF' === $root->localName && Dom::in_ns( $root, Dom::NS_RDF );
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
		$channel = Dom::child( $root, 'channel', Dom::NS_RSS1 );
		$link    = $channel ? Dom::text( $channel, 'link', Dom::NS_RSS1 ) : '';
		$doc     = array(
			'format'      => 'rss1',
			'title'       => $channel ? Dom::plain( Dom::text( $channel, 'title', Dom::NS_RSS1 ) ) : '',
			'link'        => $this->abs( $link, $base_url ),
			'description' => $channel ? Dom::plain( Dom::text( $channel, 'description', Dom::NS_RSS1 ) ) : '',
			'next_page'   => '',
			'warnings'    => array(),
			'items'       => array(),
		);
		$base    = '' !== $doc['link'] ? $doc['link'] : $base_url;

		foreach ( Dom::children( $root, 'item', Dom::NS_RSS1 ) as $el ) {
			$about = '';
			foreach ( array( 'about', 'rdf:about' ) as $attr ) {
				if ( $el->hasAttributeNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about' ) ) {
					$about = $el->getAttributeNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about' );
					break;
				}
				if ( $el->hasAttribute( $attr ) ) {
					$about = $el->getAttribute( $attr );
					break;
				}
			}
			$categories = array();
			foreach ( Dom::children( $el, 'subject', Dom::NS_DC ) as $c ) {
				$categories[] = $c->textContent;
			}
			$authors = array();
			foreach ( Dom::children( $el, 'creator', Dom::NS_DC ) as $a ) {
				$authors[] = $a->textContent;
			}
			$mrss       = MediaRss::extract( $el );
			$content_el = Dom::child( $el, 'encoded', Dom::NS_CONTENT );

			$doc['items'][] = $this->item(
				array_merge(
					array(
						'guid'              => $about,
						'title'             => Dom::text( $el, 'title', Dom::NS_RSS1 ),
						'link'              => Dom::text( $el, 'link', Dom::NS_RSS1 ),
						'summary'           => Dom::text( $el, 'description', Dom::NS_RSS1 ),
						'content'           => $content_el ? trim( $content_el->textContent ) : '',
						'authors'           => $authors,
						'categories'        => $categories,
						'media'             => $mrss['media'],
						'thumbnails'        => $mrss['thumbnails'],
						'media_description' => $mrss['description'],
						'media_credit'      => $mrss['credit'],
					),
					$this->dates( Dom::text( $el, 'date', Dom::NS_DC ), '' )
				),
				$base
			);
		}
		return $doc;
	}
}

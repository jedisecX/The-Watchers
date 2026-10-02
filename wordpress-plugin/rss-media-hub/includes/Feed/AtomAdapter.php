<?php
/**
 * Atom 1.0 (and legacy 0.3) adapter.
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Parses <feed><entry> documents: text/html/xhtml constructs, alternate and
 * enclosure links, authors, categories, RFC 5005 paging and Media RSS.
 */
class AtomAdapter extends AbstractAdapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'atom';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \DOMDocument $dom DOM.
	 * @return bool
	 */
	public function supports( \DOMDocument $dom ) {
		$root = $dom->documentElement;
		return $root && 'feed' === $root->localName && Dom::in_ns( $root, Dom::NS_ATOM );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \DOMDocument $dom      DOM.
	 * @param string       $base_url Base.
	 * @return array
	 */
	public function parse( \DOMDocument $dom, $base_url ) {
		$root = $dom->documentElement;
		$doc  = array(
			'format'      => 'atom',
			'title'       => Dom::plain( $this->text_construct( Dom::child( $root, 'title', Dom::NS_ATOM ) ) ),
			'link'        => '',
			'description' => Dom::plain( $this->text_construct( Dom::child( $root, 'subtitle', Dom::NS_ATOM ) ) ),
			'next_page'   => '',
			'warnings'    => array(),
			'items'       => array(),
		);
		foreach ( Dom::children( $root, 'link', Dom::NS_ATOM ) as $l ) {
			$rel = strtolower( Dom::attr( $l, 'rel' ) );
			if ( ( '' === $rel || 'alternate' === $rel ) && '' === $doc['link'] ) {
				$doc['link'] = $this->abs( Dom::attr( $l, 'href' ), $base_url );
			} elseif ( 'next' === $rel ) {
				$doc['next_page'] = $this->abs( Dom::attr( $l, 'href' ), $base_url );
			}
		}
		$feed_authors = $this->authors( $root );

		foreach ( Dom::children( $root, 'entry', Dom::NS_ATOM ) as $el ) {
			$link       = '';
			$enclosures = array();
			foreach ( Dom::children( $el, 'link', Dom::NS_ATOM ) as $l ) {
				$rel  = strtolower( Dom::attr( $l, 'rel' ) );
				$href = Dom::attr( $l, 'href' );
				if ( ( '' === $rel || 'alternate' === $rel ) && '' === $link ) {
					$link = $href;
				} elseif ( 'enclosure' === $rel ) {
					$enclosures[] = array(
						'url'    => $href,
						'type'   => strtolower( Dom::attr( $l, 'type' ) ),
						'length' => (int) Dom::attr( $l, 'length' ),
					);
				}
			}
			$content_el = Dom::child( $el, 'content', Dom::NS_ATOM );
			$content    = '';
			if ( $content_el ) {
				$src = Dom::attr( $content_el, 'src' );
				if ( '' !== $src ) {
					// Out-of-line content (e.g. a media file).
					$enclosures[] = array(
						'url'    => $src,
						'type'   => strtolower( Dom::attr( $content_el, 'type' ) ),
						'length' => 0,
					);
				} else {
					$content = $this->text_construct( $content_el, true );
				}
			}
			$categories = array();
			foreach ( Dom::children( $el, 'category', Dom::NS_ATOM ) as $c ) {
				$label        = Dom::attr( $c, 'label' );
				$categories[] = '' !== $label ? $label : Dom::attr( $c, 'term' );
			}
			$authors = $this->authors( $el );
			$mrss    = MediaRss::extract( $el );

			$doc['items'][] = $this->item(
				array_merge(
					array(
						'guid'              => Dom::text( $el, 'id', Dom::NS_ATOM ),
						'title'             => $this->text_construct( Dom::child( $el, 'title', Dom::NS_ATOM ) ),
						'link'              => $link,
						'summary'           => $this->text_construct( Dom::child( $el, 'summary', Dom::NS_ATOM ), true ),
						'content'           => $content,
						'authors'           => $authors ? $authors : $feed_authors,
						'categories'        => $categories,
						'enclosures'        => $enclosures,
						'media'             => $mrss['media'],
						'thumbnails'        => $mrss['thumbnails'],
						'media_description' => $mrss['description'],
						'media_credit'      => $mrss['credit'],
					),
					$this->dates(
						Dom::text( $el, array( 'published', 'issued' ), Dom::NS_ATOM ),
						Dom::text( $el, array( 'updated', 'modified' ), Dom::NS_ATOM )
					)
				),
				'' !== $doc['link'] ? $doc['link'] : $base_url
			);
		}
		return $doc;
	}

	/**
	 * Author names under an element.
	 *
	 * @param \DOMElement $el Element.
	 * @return string[]
	 */
	private function authors( \DOMElement $el ) {
		$out = array();
		foreach ( Dom::children( $el, 'author', Dom::NS_ATOM ) as $a ) {
			$name = Dom::text( $a, 'name', Dom::NS_ATOM );
			if ( '' !== $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Read an Atom text construct.
	 *
	 * @param \DOMElement|null $el      Element.
	 * @param bool             $as_html Return HTML (escaped text for type=text).
	 * @return string
	 */
	private function text_construct( $el, $as_html = false ) {
		if ( ! $el ) {
			return '';
		}
		$type = strtolower( Dom::attr( $el, 'type' ) );
		if ( 'xhtml' === $type ) {
			$div = Dom::child( $el, 'div' );
			return trim( Dom::inner_xml( $div ? $div : $el ) );
		}
		$text = trim( $el->textContent );
		if ( $as_html && ( '' === $type || 'text' === $type ) ) {
			return esc_html( $text );
		}
		return $text;
	}
}

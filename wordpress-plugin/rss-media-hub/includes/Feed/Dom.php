<?php
/**
 * Namespace-tolerant DOM helpers for feed parsing.
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Small helpers that match elements by local name and namespace family,
 * tolerating common namespace URI variants (trailing slashes, http/https).
 */
final class Dom {

	const NS_ATOM    = 'atom';
	const NS_MEDIA   = 'media';
	const NS_CONTENT = 'content';
	const NS_DC      = 'dc';
	const NS_RSS1    = 'rss1';
	const NS_RDF     = 'rdf';
	const NS_ITUNES  = 'itunes';
	const NS_NONE    = 'none';

	/**
	 * Namespace families => accepted URIs (normalized: lowercase, no scheme, no trailing slash/hash).
	 *
	 * @return array<string,string[]>
	 */
	private static function namespace_families() {
		return array(
			self::NS_ATOM    => array( 'www.w3.org/2005/atom', 'purl.org/atom/ns' ),
			self::NS_MEDIA   => array( 'search.yahoo.com/mrss', 'video.search.yahoo.com/mrss' ),
			self::NS_CONTENT => array( 'purl.org/rss/1.0/modules/content' ),
			self::NS_DC      => array( 'purl.org/dc/elements/1.1', 'purl.org/dc/terms' ),
			self::NS_RSS1    => array( 'purl.org/rss/1.0' ),
			self::NS_RDF     => array( 'www.w3.org/1999/02/22-rdf-syntax-ns' ),
			self::NS_ITUNES  => array( 'www.itunes.com/dtds/podcast-1.0.dtd', 'www.itunes.com/dtds/podcast-1.0' ),
		);
	}

	/**
	 * Does a node belong to a namespace family?
	 *
	 * @param \DOMNode $node   Node.
	 * @param string   $family Family id.
	 * @return bool
	 */
	public static function in_ns( \DOMNode $node, $family ) {
		$uri = (string) $node->namespaceURI;
		if ( self::NS_NONE === $family ) {
			return '' === $uri;
		}
		$norm       = strtolower( preg_replace( '#^https?://#i', '', rtrim( $uri, '/#' ) ) );
		$family_map = self::namespace_families();
		return isset( $family_map[ $family ] ) && in_array( $norm, $family_map[ $family ], true );
	}

	/**
	 * Direct child elements matching local name(s) and namespace family.
	 *
	 * @param \DOMNode        $node Parent.
	 * @param string|string[] $names  Local name(s).
	 * @param string|null     $family Namespace family, or null for any.
	 * @return \DOMElement[]
	 */
	public static function children( \DOMNode $node, $names, $family = null ) {
		$names = (array) $names;
		$out   = array();
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			if ( ! in_array( $child->localName, $names, true ) ) {
				continue;
			}
			if ( null !== $family && ! self::in_ns( $child, $family ) ) {
				continue;
			}
			$out[] = $child;
		}
		return $out;
	}

	/**
	 * First matching child.
	 *
	 * @param \DOMNode        $node Parent.
	 * @param string|string[] $names  Names.
	 * @param string|null     $family Family.
	 * @return \DOMElement|null
	 */
	public static function child( \DOMNode $node, $names, $family = null ) {
		$c = self::children( $node, $names, $family );
		return $c ? $c[0] : null;
	}

	/**
	 * Trimmed text content of the first matching child.
	 *
	 * @param \DOMNode        $node Parent.
	 * @param string|string[] $names  Names.
	 * @param string|null     $family Family.
	 * @return string
	 */
	public static function text( \DOMNode $node, $names, $family = null ) {
		$c = self::child( $node, $names, $family );
		return $c ? trim( $c->textContent ) : '';
	}

	/**
	 * Attribute value (namespace-less attribute).
	 *
	 * @param \DOMElement $el   Element.
	 * @param string      $name Attribute.
	 * @return string
	 */
	public static function attr( \DOMElement $el, $name ) {
		return $el->hasAttribute( $name ) ? trim( $el->getAttribute( $name ) ) : '';
	}

	/**
	 * Inner XML of an element (used for Atom type="xhtml" content).
	 *
	 * @param \DOMElement $el Element.
	 * @return string
	 */
	public static function inner_xml( \DOMElement $el ) {
		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $el->ownerDocument->saveXML( $child );
		}
		return $html;
	}

	/**
	 * Plain text from a possibly HTML-encoded string.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function plain( $value ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string) $value );
		return trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
	}
}

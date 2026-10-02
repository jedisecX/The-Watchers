<?php
/**
 * Hardened XML loading (no external entities, no DTD expansion, no network).
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Loads untrusted XML safely and attempts limited repair of common feed defects.
 */
final class XmlLoader {

	/**
	 * Load XML.
	 *
	 * @param string $xml       Raw document.
	 * @param int    $max_bytes Size limit.
	 * @return array|\WP_Error {dom: \DOMDocument, warnings: string[]}
	 */
	public static function load( $xml, $max_bytes = 10485760 ) {
		$xml = (string) $xml;
		if ( '' === trim( $xml ) ) {
			return new \WP_Error( 'rmh_empty_document', __( 'The response body is empty.', 'rss-media-hub' ) );
		}
		if ( strlen( $xml ) > $max_bytes ) {
			return new \WP_Error( 'rmh_too_large', __( 'The feed document is too large.', 'rss-media-hub' ) );
		}
		// Strip BOM and leading whitespace before the XML declaration.
		$xml = preg_replace( '/^(\xEF\xBB\xBF|\s)+/', '', $xml );

		// Entity declarations enable XXE and exponential expansion ("billion laughs"). Feeds never need them.
		if ( preg_match( '/<!ENTITY/i', $xml ) ) {
			return new \WP_Error( 'rmh_xml_entities', __( 'The document declares XML entities, which are not allowed.', 'rss-media-hub' ) );
		}
		// Remove any DOCTYPE (external DTDs are never loaded).
		$xml = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $xml, 1 );

		// Not XML at all (e.g. an HTML error page)?
		if ( ! preg_match( '/^\s*<(\?xml|[a-zA-Z_][\w.-]*:?[\w.-]*[\s>\/])/', $xml ) || preg_match( '/^\s*<(!doctype\s+html|html)[\s>]/i', $xml ) ) {
			return new \WP_Error( 'rmh_not_xml', __( 'The response is not an XML feed (it looks like HTML or plain text).', 'rss-media-hub' ) );
		}

		$warnings = array();
		$clean    = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xml );
		if ( $clean !== $xml ) {
			$warnings[] = 'Removed invalid control characters.';
		}

		$dom = self::parse( $clean, false, $errors );
		if ( $dom ) {
			return array(
				'dom'      => $dom,
				'warnings' => $warnings,
			);
		}

		// Repair: escape stray ampersands and translate HTML named entities.
		$repaired = self::repair( $clean );
		$dom      = self::parse( $repaired, false, $errors2 );
		if ( $dom ) {
			$warnings[] = 'Malformed XML was repaired (unescaped ampersands or HTML entities).';
			return array(
				'dom'      => $dom,
				'warnings' => $warnings,
			);
		}

		// Last resort: libxml recovery mode, accepted only if the result looks like a feed.
		// Only truncation-style damage (e.g. an interrupted download) is recoverable; mismatched
		// tags mean the structure is corrupt and items could be silently mis-assembled.
		$dom        = self::parse( $repaired, true, $errors3 );
		$structural = array_filter(
			(array) $errors3,
			static function ( $e ) {
				return (bool) preg_match( '/mismatch|not well-formed|StartTag|attributes construct|Unescaped/i', $e );
			}
		);
		if ( $dom && ! $structural && $dom->documentElement && in_array( strtolower( $dom->documentElement->localName ), array( 'rss', 'feed', 'rdf' ), true ) && self::has_entries( $dom ) ) {
			$warnings[] = 'Malformed XML parsed in recovery mode; some content may be missing.';
			return array(
				'dom'      => $dom,
				'warnings' => $warnings,
			);
		}

		$first = $errors ? $errors[0] : 'unknown parser error';
		return new \WP_Error(
			'rmh_invalid_xml',
			/* translators: %s: libxml error */
			sprintf( __( 'Invalid XML: %s', 'rss-media-hub' ), $first )
		);
	}

	/**
	 * Parse with hardened flags.
	 *
	 * @param string   $xml     XML.
	 * @param bool     $recover Recovery mode.
	 * @param string[] $errors  Collected error messages (out).
	 * @return \DOMDocument|null
	 */
	private static function parse( $xml, $recover, &$errors ) {
		$errors   = array();
		$previous = libxml_use_internal_errors( true );
		$loader   = null;
		if ( \PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
			$loader = libxml_disable_entity_loader( true ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated,Generic.PHP.DeprecatedFunctions.Deprecated -- only on PHP < 8.0, where it is required.
		}
		libxml_clear_errors();

		$dom                     = new \DOMDocument();
		$dom->resolveExternals   = false;
		$dom->substituteEntities = false;
		$dom->validateOnParse    = false;
		$flags                   = LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT;
		if ( $recover ) {
			$dom->recover = true;
		}
		$ok = $dom->loadXML( $xml, $flags );

		foreach ( libxml_get_errors() as $e ) {
			if ( $e->level >= LIBXML_ERR_ERROR ) {
				$errors[] = trim( $e->message ) . ' (line ' . $e->line . ')';
			}
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( null !== $loader ) {
			libxml_disable_entity_loader( $loader ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated,Generic.PHP.DeprecatedFunctions.Deprecated -- only on PHP < 8.0, where it is required.
		}

		if ( ! $ok || ! $dom->documentElement ) {
			return null;
		}
		if ( ! $recover && $errors ) {
			return null;
		}
		return $dom;
	}

	/**
	 * Fix common defects: bare '&' and HTML-only named entities.
	 *
	 * @param string $xml XML.
	 * @return string
	 */
	private static function repair( $xml ) {
		$xml   = preg_replace( '/&(?!(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#[0-9]{1,7}|#x[0-9a-fA-F]{1,6});)/', '&amp;', $xml );
		$table = get_html_translation_table( HTML_ENTITIES, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$named = array();
		foreach ( $table as $char => $entity ) {
			$named[ substr( $entity, 1, -1 ) ] = $char;
		}
		return preg_replace_callback(
			'/&([a-zA-Z][a-zA-Z0-9]{1,31});/',
			static function ( $m ) use ( $named ) {
				if ( in_array( $m[1], array( 'amp', 'lt', 'gt', 'quot', 'apos' ), true ) ) {
					return $m[0];
				}
				if ( isset( $named[ $m[1] ] ) ) {
					$cp = mb_ord( $named[ $m[1] ], 'UTF-8' );
					return false !== $cp ? '&#' . $cp . ';' : '';
				}
				return '&amp;' . $m[1] . ';';
			},
			$xml
		);
	}

	/**
	 * Does the document contain any item/entry element?
	 *
	 * @param \DOMDocument $dom DOM.
	 * @return bool
	 */
	private static function has_entries( \DOMDocument $dom ) {
		$xp = new \DOMXPath( $dom );
		return $xp->query( '//*[local-name()="item" or local-name()="entry"]' )->length > 0;
	}
}

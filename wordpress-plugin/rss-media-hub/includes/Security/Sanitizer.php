<?php
/**
 * Sanitization of untrusted feed HTML and text.
 *
 * @package RSSMediaHub
 */

namespace RMH\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Converts untrusted feed markup into a minimal, script-free HTML subset.
 * No iframes, objects, forms, styles, event handlers or non-http(s) URLs survive.
 */
final class Sanitizer {

	/**
	 * Allowed tags/attributes for imported article content.
	 *
	 * @return array
	 */
	public static function content_allowed_html() {
		$common  = array(
			'title' => true,
			'lang'  => true,
			'dir'   => true,
		);
		$allowed = array(
			'a'          => array_merge( $common, array( 'href' => true ) ),
			'abbr'       => $common,
			'b'          => $common,
			'blockquote' => $common,
			'br'         => array(),
			'caption'    => $common,
			'cite'       => $common,
			'code'       => $common,
			'dd'         => $common,
			'del'        => $common,
			'dl'         => $common,
			'dt'         => $common,
			'em'         => $common,
			'figcaption' => $common,
			'figure'     => $common,
			'h2'         => $common,
			'h3'         => $common,
			'h4'         => $common,
			'h5'         => $common,
			'h6'         => $common,
			'hr'         => array(),
			'i'          => $common,
			'img'        => array(
				'src'    => true,
				'alt'    => true,
				'title'  => true,
				'width'  => true,
				'height' => true,
			),
			'ins'        => $common,
			'li'         => $common,
			'ol'         => $common,
			'p'          => $common,
			'pre'        => $common,
			'q'          => $common,
			's'          => $common,
			'small'      => $common,
			'span'       => $common,
			'strong'     => $common,
			'sub'        => $common,
			'sup'        => $common,
			'table'      => $common,
			'tbody'      => $common,
			'td'         => array_merge(
				$common,
				array(
					'colspan' => true,
					'rowspan' => true,
				)
			),
			'th'         => array_merge(
				$common,
				array(
					'colspan' => true,
					'rowspan' => true,
					'scope'   => true,
				)
			),
			'thead'      => $common,
			'tr'         => $common,
			'u'          => $common,
			'ul'         => $common,
		);
		return apply_filters( 'rmh_content_allowed_html', $allowed );
	}

	/**
	 * Allowed HTML in attribution templates.
	 *
	 * @return array
	 */
	public static function attribution_allowed_html() {
		return array(
			'a'      => array(
				'href'   => true,
				'rel'    => true,
				'target' => true,
				'title'  => true,
			),
			'strong' => array(),
			'em'     => array(),
			'span'   => array( 'class' => true ),
			'br'     => array(),
		);
	}

	/**
	 * Sanitize imported HTML content.
	 *
	 * @param string $html Untrusted HTML.
	 * @param string $base Base URL used to absolutize relative links/images ('' drops them).
	 * @return string Safe HTML.
	 */
	public static function content( $html, $base = '' ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}
		// Remove elements whose *content* must never survive as text either.
		$html = preg_replace( '#<(script|style|iframe|object|embed|noscript|template|svg|math|form|textarea|select|button|frameset|frame|applet|video|audio|canvas)\b[^>]*>.*?</\1\s*>#is', '', $html );
		$html = preg_replace( '#<(script|style|iframe|object|embed|noscript|template|svg|math|form|frameset|frame|applet|video|audio|canvas|link|meta|base|input)\b[^>]*/?>#is', '', $html );
		$html = wp_kses( $html, self::content_allowed_html(), array( 'http', 'https' ) );
		// Relative references would resolve against this site: absolutize against the source or drop.
		$html = preg_replace_callback(
			'/\s(src|href)=(["\'])(.*?)\2/i',
			static function ( $m ) use ( $base ) {
				$abs = \RMH\Http\UrlNormalizer::absolutize( html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' ), $base );
				$abs = '' !== $abs ? self::url( $abs ) : '';
				return '' === $abs ? '' : ' ' . strtolower( $m[1] ) . '="' . esc_attr( $abs ) . '"';
			},
			$html
		);
		$html = preg_replace( '/<img\b(?![^>]*\ssrc=)[^>]*>/i', '', $html );
		// Imported links are untrusted: nofollow + ugc, and never opener access.
		$html = wp_unslash( wp_rel_ugc( wp_slash( $html ) ) );
		$html = preg_replace( '/<a\s/i', '<a target="_blank" ', $html );
		$html = preg_replace_callback(
			'/<a\b([^>]*)\brel="([^"]*)"/i',
			static function ( $m ) {
				$rels = array_unique( array_merge( preg_split( '/\s+/', trim( $m[2] ) ), array( 'noopener', 'noreferrer' ) ) );
				return '<a' . $m[1] . 'rel="' . esc_attr( implode( ' ', array_filter( $rels ) ) ) . '"';
			},
			$html
		);
		$html = force_balance_tags( $html );
		return trim( $html );
	}

	/**
	 * Plain text (for titles, excerpts, labels).
	 *
	 * @param string $text Untrusted.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function text( $text, $max = 1000 ) {
		return mb_substr( \RMH\Feed\Dom::plain( $text ), 0, $max );
	}

	/**
	 * Plain-text excerpt limited to N words.
	 *
	 * @param string $html  Source HTML.
	 * @param int    $words Words.
	 * @return string
	 */
	public static function excerpt( $html, $words ) {
		return wp_trim_words( \RMH\Feed\Dom::plain( $html ), max( 5, (int) $words ), '…' );
	}

	/**
	 * Validate an http(s) URL for output in href/src (returns '' if unsafe).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}
}

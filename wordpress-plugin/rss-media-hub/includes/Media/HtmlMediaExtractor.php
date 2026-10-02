<?php
/**
 * Finds media references inside untrusted HTML without rendering it.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media;

use RMH\Http\UrlNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Collects <video>/<audio>/<source>/<img> sources, links to media files,
 * iframe/embed sources (for provider matching only) and Open Graph / Twitter
 * card media from an HTML string. Nothing from the HTML is ever output as-is.
 */
final class HtmlMediaExtractor {

	const MAX_HTML = 2097152;

	/**
	 * Extract references.
	 *
	 * @param string $html HTML.
	 * @param string $base Base URL for relative references.
	 * @return array{video:array,audio:array,image:array,links:string[],frames:string[],og:array}
	 */
	public static function extract( $html, $base ) {
		$out  = array(
			'video'  => array(),
			'audio'  => array(),
			'image'  => array(),
			'links'  => array(),
			'frames' => array(),
			'og'     => array(),
		);
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return $out;
		}
		$html = substr( $html, 0, self::MAX_HTML );

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		// The XML encoding hint makes libxml treat the fragment as UTF-8. LIBXML_NONET blocks network access.
		$loaded = $dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return $out;
		}
		$abs = static function ( $u ) use ( $base ) {
			$a = UrlNormalizer::absolutize( (string) $u, (string) $base );
			return ( '' !== $a && strlen( $a ) <= 2048 ) ? $a : '';
		};

		foreach ( array( 'video', 'audio' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $el ) {
				$poster  = $abs( $el->getAttribute( 'poster' ) );
				$sources = array();
				if ( $el->getAttribute( 'src' ) ) {
					$sources[] = array( $el->getAttribute( 'src' ), $el->getAttribute( 'type' ) );
				}
				foreach ( $el->getElementsByTagName( 'source' ) as $s ) {
					$sources[] = array( $s->getAttribute( 'src' ), $s->getAttribute( 'type' ) );
				}
				foreach ( $sources as $s ) {
					$u = $abs( $s[0] );
					if ( '' !== $u ) {
						$out[ $tag ][] = array(
							'url'       => $u,
							'mime'      => strtolower( trim( $s[1] ) ),
							'thumbnail' => $poster,
							'width'     => (int) $el->getAttribute( 'width' ),
							'height'    => (int) $el->getAttribute( 'height' ),
						);
					}
				}
			}
		}

		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
				$src = $img->getAttribute( 'data-src' );
			}
			$u = $abs( $src );
			if ( '' === $u ) {
				continue;
			}
			$w = (int) $img->getAttribute( 'width' );
			$h = (int) $img->getAttribute( 'height' );
			// Skip tracking pixels and tiny icons.
			if ( ( $w && $w < 50 ) || ( $h && $h < 50 ) ) {
				continue;
			}
			$out['image'][] = array(
				'url'    => $u,
				'mime'   => '',
				'alt'    => mb_substr( trim( $img->getAttribute( 'alt' ) ), 0, 300 ),
				'width'  => $w,
				'height' => $h,
			);
		}

		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$u = $abs( $a->getAttribute( 'href' ) );
			if ( '' !== $u ) {
				$out['links'][] = $u;
			}
		}

		foreach ( array( 'iframe', 'embed' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $f ) {
				$u = $abs( $f->getAttribute( 'src' ) ? $f->getAttribute( 'src' ) : $f->getAttribute( 'data-src' ) );
				if ( '' !== $u ) {
					$out['frames'][] = $u;
				}
			}
		}

		foreach ( $dom->getElementsByTagName( 'meta' ) as $m ) {
			$key = strtolower( $m->getAttribute( 'property' ) ? $m->getAttribute( 'property' ) : $m->getAttribute( 'name' ) );
			if ( in_array( $key, array( 'og:video', 'og:video:url', 'og:video:secure_url', 'og:video:type', 'og:image', 'og:image:secure_url', 'og:audio', 'og:audio:secure_url', 'og:audio:type', 'twitter:player', 'twitter:image', 'twitter:player:stream' ), true ) ) {
				$val = trim( $m->getAttribute( 'content' ) );
				if ( '' !== $val && ! isset( $out['og'][ $key ] ) ) {
					$out['og'][ $key ] = false !== strpos( $key, ':type' ) ? strtolower( $val ) : $abs( $val );
				}
			}
		}

		foreach ( array( 'video', 'audio', 'image' ) as $k ) {
			$seen      = array();
			$out[ $k ] = array_values(
				array_filter(
					$out[ $k ],
					static function ( $e ) use ( &$seen ) {
						if ( isset( $seen[ $e['url'] ] ) ) {
							return false;
						}
						$seen[ $e['url'] ] = true;
						return true;
					}
				)
			);
		}
		$out['links']  = array_values( array_unique( $out['links'] ) );
		$out['frames'] = array_values( array_unique( $out['frames'] ) );
		return $out;
	}
}

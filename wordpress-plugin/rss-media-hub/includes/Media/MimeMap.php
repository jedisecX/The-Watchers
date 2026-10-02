<?php
/**
 * MIME type and extension knowledge.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Maps extensions and MIME types to media categories and browser playability.
 */
final class MimeMap {

	/**
	 * Extension => [mime, category, playable-in-HTML5].
	 */
	const EXT = array(
		'mp4'  => array( 'video/mp4', 'video', true ),
		'm4v'  => array( 'video/mp4', 'video', true ),
		'webm' => array( 'video/webm', 'video', true ),
		'ogv'  => array( 'video/ogg', 'video', true ),
		'mov'  => array( 'video/quicktime', 'video', false ),
		'm3u8' => array( 'application/vnd.apple.mpegurl', 'video', false ),
		'mkv'  => array( 'video/x-matroska', 'video', false ),
		'avi'  => array( 'video/x-msvideo', 'video', false ),
		'wmv'  => array( 'video/x-ms-wmv', 'video', false ),
		'flv'  => array( 'video/x-flv', 'video', false ),
		'mp3'  => array( 'audio/mpeg', 'audio', true ),
		'm4a'  => array( 'audio/mp4', 'audio', true ),
		'aac'  => array( 'audio/aac', 'audio', true ),
		'oga'  => array( 'audio/ogg', 'audio', true ),
		'ogg'  => array( 'audio/ogg', 'audio', true ),
		'opus' => array( 'audio/ogg', 'audio', true ),
		'wav'  => array( 'audio/wav', 'audio', true ),
		'flac' => array( 'audio/flac', 'audio', true ),
		'jpg'  => array( 'image/jpeg', 'image', true ),
		'jpeg' => array( 'image/jpeg', 'image', true ),
		'jpe'  => array( 'image/jpeg', 'image', true ),
		'png'  => array( 'image/png', 'image', true ),
		'gif'  => array( 'image/gif', 'image', true ),
		'webp' => array( 'image/webp', 'image', true ),
		'avif' => array( 'image/avif', 'image', true ),
	);

	/**
	 * Additional MIME aliases => category.
	 */
	const MIME_ALIASES = array(
		'audio/mp3'             => 'audio',
		'audio/x-mp3'           => 'audio',
		'audio/x-m4a'           => 'audio',
		'audio/x-wav'           => 'audio',
		'audio/wave'            => 'audio',
		'audio/vorbis'          => 'audio',
		'audio/opus'            => 'audio',
		'audio/webm'            => 'audio',
		'video/x-m4v'           => 'video',
		'image/jpg'             => 'image',
		'image/pjpeg'           => 'image',
		'application/x-mpegurl' => 'video',
	);

	/**
	 * MIME types that a browser can play natively with <video>/<audio>/<img>.
	 */
	const PLAYABLE = array( 'video/mp4', 'video/webm', 'video/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/wav', 'audio/flac', 'audio/webm', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );

	/**
	 * File extension of a URL path (lowercase, '' if none).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function extension( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( preg_match( '/\.([a-z0-9]{2,5})$/i', $path, $m ) ) {
			return strtolower( $m[1] );
		}
		return '';
	}

	/**
	 * Category for a MIME type: video|audio|image|'' (unknown / non-media).
	 *
	 * @param string $mime MIME.
	 * @return string
	 */
	public static function category_of_mime( $mime ) {
		$mime = strtolower( trim( explode( ';', (string) $mime )[0] ) );
		if ( '' === $mime ) {
			return '';
		}
		if ( isset( self::MIME_ALIASES[ $mime ] ) ) {
			return self::MIME_ALIASES[ $mime ];
		}
		if ( 'image/svg+xml' === $mime ) {
			return ''; // Scriptable; never treated as an image.
		}
		foreach ( array( 'video', 'audio', 'image' ) as $cat ) {
			if ( 0 === strpos( $mime, $cat . '/' ) ) {
				return $cat;
			}
		}
		if ( 'application/vnd.apple.mpegurl' === $mime ) {
			return 'video';
		}
		return '';
	}

	/**
	 * Category from the URL extension.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function category_of_url( $url ) {
		$ext = self::extension( $url );
		return isset( self::EXT[ $ext ] ) ? self::EXT[ $ext ][1] : '';
	}

	/**
	 * Guess MIME from URL extension.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function mime_of_url( $url ) {
		$ext = self::extension( $url );
		return isset( self::EXT[ $ext ] ) ? self::EXT[ $ext ][0] : '';
	}

	/**
	 * Normalize aliases to canonical MIME.
	 *
	 * @param string $mime MIME.
	 * @return string
	 */
	public static function canonical( $mime ) {
		$mime = strtolower( trim( explode( ';', (string) $mime )[0] ) );
		$map  = array(
			'audio/mp3'   => 'audio/mpeg',
			'audio/x-mp3' => 'audio/mpeg',
			'audio/x-m4a' => 'audio/mp4',
			'audio/x-wav' => 'audio/wav',
			'audio/wave'  => 'audio/wav',
			'video/x-m4v' => 'video/mp4',
			'image/jpg'   => 'image/jpeg',
			'image/pjpeg' => 'image/jpeg',
		);
		return isset( $map[ $mime ] ) ? $map[ $mime ] : $mime;
	}

	/**
	 * Can the browser play/display this MIME natively?
	 *
	 * @param string $mime MIME.
	 * @return bool
	 */
	public static function is_playable( $mime ) {
		return in_array( self::canonical( $mime ), self::PLAYABLE, true );
	}

	/**
	 * Is a server-reported Content-Type compatible with the expected category?
	 * Generic binary types are tolerated (many CDNs send them).
	 *
	 * @param string $content_type Reported.
	 * @param string $category     Expected video|audio|image.
	 * @return bool
	 */
	public static function compatible( $content_type, $category ) {
		$ct = self::canonical( $content_type );
		if ( '' === $ct || in_array( $ct, array( 'application/octet-stream', 'binary/octet-stream', 'application/binary' ), true ) ) {
			return true;
		}
		$actual = self::category_of_mime( $ct );
		if ( 'video' === $category && 'audio' === $actual ) {
			return true; // e.g. audio/mp4 vs video/mp4 containers.
		}
		return $actual === $category;
	}
}

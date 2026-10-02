<?php
/**
 * Media RSS (http://search.yahoo.com/mrss/) extraction shared by all adapters.
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Reads media:content, media:group, media:thumbnail, media:player,
 * media:title, media:description and media:credit from an item/entry element.
 */
final class MediaRss {

	/**
	 * Extract Media RSS entries and item-level thumbnails.
	 *
	 * @param \DOMElement $item Item or entry element.
	 * @return array{media:array,thumbnails:string[],description:string,credit:string}
	 */
	public static function extract( \DOMElement $item ) {
		$item_ctx = self::context( $item, array() );
		$media    = array();

		foreach ( Dom::children( $item, 'group', Dom::NS_MEDIA ) as $group ) {
			$group_ctx = self::context( $group, $item_ctx );
			foreach ( Dom::children( $group, 'content', Dom::NS_MEDIA ) as $content ) {
				$entry = self::content( $content, $group_ctx, 'media_group' );
				if ( $entry ) {
					$media[] = $entry;
				}
			}
			if ( '' !== $group_ctx['player'] ) {
				$media[] = self::player_entry( $group_ctx );
			}
		}
		foreach ( Dom::children( $item, 'content', Dom::NS_MEDIA ) as $content ) {
			$entry = self::content( $content, $item_ctx, 'media_content' );
			if ( $entry ) {
				$media[] = $entry;
			}
		}
		if ( '' !== $item_ctx['player'] ) {
			$media[] = self::player_entry( $item_ctx );
		}

		return array(
			'media'       => $media,
			'thumbnails'  => $item_ctx['thumbnails'],
			'description' => $item_ctx['description'],
			'credit'      => $item_ctx['credit'],
		);
	}

	/**
	 * Read inheritable metadata at a level (item, group or content).
	 *
	 * @param \DOMElement $el     Element.
	 * @param array       $inherited Parent context.
	 * @return array
	 */
	private static function context( \DOMElement $el, array $inherited ) {
		$thumbs = array();
		foreach ( Dom::children( $el, 'thumbnail', Dom::NS_MEDIA ) as $t ) {
			$url = Dom::attr( $t, 'url' );
			if ( '' !== $url ) {
				$thumbs[] = $url;
			}
		}
		$player_el = Dom::child( $el, 'player', Dom::NS_MEDIA );
		$credit    = Dom::child( $el, 'credit', Dom::NS_MEDIA );
		return array(
			'thumbnails'  => $thumbs ? $thumbs : ( isset( $inherited['thumbnails'] ) ? $inherited['thumbnails'] : array() ),
			'title'       => Dom::text( $el, 'title', Dom::NS_MEDIA ) ?: ( isset( $inherited['title'] ) ? $inherited['title'] : '' ),
			'description' => Dom::text( $el, 'description', Dom::NS_MEDIA ) ?: ( isset( $inherited['description'] ) ? $inherited['description'] : '' ),
			'credit'      => $credit ? trim( $credit->textContent ) : ( isset( $inherited['credit'] ) ? $inherited['credit'] : '' ),
			'player'      => $player_el ? Dom::attr( $player_el, 'url' ) : '',
			'player_w'    => $player_el ? (int) Dom::attr( $player_el, 'width' ) : 0,
			'player_h'    => $player_el ? (int) Dom::attr( $player_el, 'height' ) : 0,
		);
	}

	/**
	 * Build an entry from a media:content element.
	 *
	 * @param \DOMElement $el     media:content.
	 * @param array       $inherited Parent context.
	 * @param string      $source Source label.
	 * @return array|null
	 */
	private static function content( \DOMElement $el, array $inherited, $source ) {
		$ctx = self::context( $el, $inherited );
		$url = Dom::attr( $el, 'url' );
		if ( '' === $url && '' !== $ctx['player'] ) {
			$entry           = self::player_entry( $ctx );
			$entry['source'] = $source;
			return $entry;
		}
		if ( '' === $url ) {
			return null;
		}
		return array(
			'url'         => $url,
			'mime'        => strtolower( Dom::attr( $el, 'type' ) ),
			'medium'      => strtolower( Dom::attr( $el, 'medium' ) ),
			'width'       => (int) Dom::attr( $el, 'width' ),
			'height'      => (int) Dom::attr( $el, 'height' ),
			'duration'    => (int) round( (float) Dom::attr( $el, 'duration' ) ),
			'filesize'    => (int) Dom::attr( $el, 'fileSize' ),
			'is_default'  => 'true' === strtolower( Dom::attr( $el, 'isDefault' ) ),
			'thumbnail'   => $ctx['thumbnails'] ? $ctx['thumbnails'][0] : '',
			'title'       => $ctx['title'],
			'description' => $ctx['description'],
			'credit'      => $ctx['credit'],
			'player'      => $ctx['player'],
			'source'      => $source,
		);
	}

	/**
	 * Entry for a bare media:player.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	private static function player_entry( array $ctx ) {
		return array(
			'url'         => $ctx['player'],
			'mime'        => 'text/html',
			'medium'      => 'player',
			'width'       => $ctx['player_w'],
			'height'      => $ctx['player_h'],
			'duration'    => 0,
			'filesize'    => 0,
			'is_default'  => false,
			'thumbnail'   => $ctx['thumbnails'] ? $ctx['thumbnails'][0] : '',
			'title'       => $ctx['title'],
			'description' => $ctx['description'],
			'credit'      => $ctx['credit'],
			'player'      => $ctx['player'],
			'source'      => 'media_player',
		);
	}
}

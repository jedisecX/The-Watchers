<?php
/**
 * Lenient date parsing for feed timestamps.
 *
 * @package RSSMediaHub
 */

namespace RMH\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Parses RFC 822/2822, RFC 3339/ISO 8601 and W3CDTF dates. Returns null
 * for anything unparseable or implausible so callers can flag it.
 */
final class DateParser {

	/**
	 * Parse a date string into a UTC unix timestamp.
	 *
	 * @param string   $value Raw date.
	 * @param int|null $now   Reference time.
	 * @return int|null
	 */
	public static function parse( $value, $now = null ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 64 ) {
			return null;
		}
		$now = null === $now ? time() : (int) $now;

		// Normalize common oddities: "GMT+0000", "UT", trailing "Z" variants, extra whitespace.
		$clean = preg_replace( '/\s+/', ' ', $value );
		$clean = preg_replace( '/\bUT\b/', 'UTC', $clean );
		$clean = preg_replace( '/GMT([+-]\d{4})/', '$1', $clean );

		// A leading day name is redundant and often wrong; PHP would silently move the
		// date to the next matching weekday, so drop it before parsing.
		$clean = preg_replace( '/^[A-Za-z]{3,9},?\s+(?=\d)/', '', $clean );

		$formats = array(
			'd M Y H:i:s O',
			'd M Y H:i:s T',
			'd M Y H:i O',
			'd M Y H:i T',
			'd M y H:i:s O',
			'd M y H:i:s T',
			'd M Y H:i:s',
			DATE_ATOM,
			'Y-m-d\TH:i:s.uP',
			'Y-m-d\TH:i:sP',
			'Y-m-d\TH:iP',
			'Y-m-d\TH:i:s\Z',
			'Y-m-d\TH:i:s.u\Z',
			'Y-m-d H:i:s',
			'!Y-m-d',
			'!Y-m',
		);
		$ts      = null;
		foreach ( $formats as $format ) {
			$dt = \DateTime::createFromFormat( $format, $clean, new \DateTimeZone( 'UTC' ) );
			if ( $dt instanceof \DateTime ) {
				$errors = \DateTime::getLastErrors();
				if ( ! $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) {
					$ts = $dt->getTimestamp();
					break;
				}
			}
		}
		if ( null === $ts ) {
			if ( ! preg_match( '/\d/', $clean ) ) {
				return null;
			}
			$parsed = strtotime( $clean . ( preg_match( '/([+-]\d{2}:?\d{2}|[A-Z]{1,5}|Z)$/', $clean ) ? '' : ' UTC' ) );
			if ( false === $parsed ) {
				return null;
			}
			$ts = $parsed;
		}
		// Plausibility: after 1990-01-01 and no more than 2 days in the future.
		if ( $ts < 631152000 || $ts > $now + 2 * DAY_IN_SECONDS ) {
			return null;
		}
		return $ts;
	}
}

<?php
/**
 * Minimal POT generator (no WP-CLI/gettext required): php bin/make-pot.php > languages/rss-media-hub.pot
 * Extracts literal strings from __, _e, _x, _n, esc_html__, esc_html_e, esc_attr__, esc_attr_e for the plugin text domain.
 *
 * @package RSSMediaHub
 */

$root    = dirname( __DIR__ );
$entries = array();
$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$path = (string) $file;
	if ( '.php' !== substr( $path, -4 ) || preg_match( '#/(tests|bin|vendor)/#', $path ) ) {
		continue;
	}
	$tokens = token_get_all( file_get_contents( $path ) );
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] ) {
			continue;
		}
		$fn = $tokens[ $i ][1];
		if ( ! in_array( $fn, array( '__', '_e', '_n', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' ), true ) ) {
			continue;
		}
		$args  = array();
		$depth = 0;
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( '(' === $t ) {
				++$depth;
				continue;
			}
			if ( ')' === $t ) {
				if ( 0 === --$depth ) {
					break;
				}
				continue;
			}
			if ( 1 === $depth && is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				$args[] = stripcslashes( substr( $t[1], 1, -1 ) );
			} elseif ( 1 === $depth && ',' === $t && count( $args ) === 0 ) {
				$args[] = null; // Non-literal first argument.
			}
		}
		if ( ! $args || null === $args[0] || 'rss-media-hub' !== end( $args ) ) {
			continue;
		}
		$ref    = substr( $path, strlen( $root ) + 1 ) . ':' . $tokens[ $i ][2];
		$plural = '_n' === $fn && count( $args ) >= 3 ? $args[1] : null;
		$key    = $args[0] . "\0" . $plural;
		$entries[ $key ]['msgid']        = $args[0];
		$entries[ $key ]['plural']       = $plural;
		$entries[ $key ]['refs'][]       = $ref;
	}
}
$esc = static function ( $s ) {
	return '"' . str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', '\\n' ), $s ) . '"';
};
echo "# Copyright (C) The Watchers\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n";
echo "\"Project-Id-Version: RSS Media Hub 1.0.0\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"X-Domain: rss-media-hub\\n\"\n\n";
foreach ( $entries as $e ) {
	echo '#: ' . implode( ' ', array_slice( $e['refs'], 0, 5 ) ) . "\n";
	echo 'msgid ' . $esc( $e['msgid'] ) . "\n";
	if ( null !== $e['plural'] ) {
		echo 'msgid_plural ' . $esc( $e['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	} else {
		echo "msgstr \"\"\n\n";
	}
}

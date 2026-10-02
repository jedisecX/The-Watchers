<?php
/**
 * Exception thrown instead of wp_die() during tests.
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests;

/**
 * Carries the HTTP status passed to wp_die().
 */
class WPDieException extends \RuntimeException {
}

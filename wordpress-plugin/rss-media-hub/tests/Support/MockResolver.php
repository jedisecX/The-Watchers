<?php
/**
 * Deterministic DNS for tests.
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests;

use RMH\Http\ResolverInterface;

/**
 * Host => IPs map. Unknown hosts resolve to a public documentation-free address.
 */
class MockResolver implements ResolverInterface {

	/**
	 * Map.
	 *
	 * @var array<string,string[]>
	 */
	public $map = array();

	/**
	 * Lookups performed.
	 *
	 * @var string[]
	 */
	public $lookups = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param string $host Host.
	 * @return string[]
	 */
	public function resolve( $host ) {
		$this->lookups[] = $host;
		if ( isset( $this->map[ $host ] ) ) {
			return $this->map[ $host ];
		}
		return array( '93.184.215.14' ); // Public unicast; never contacted (transport is mocked).
	}
}

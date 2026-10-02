<?php
/**
 * SSRF protections (scenarios 15, 16 + DNS rebinding).
 *
 * @package RSSMediaHub
 */

namespace RMH\Tests\Unit;

use RMH\Http\IpRules;
use RMH\Tests\TestCase;

/**
 * URL guard and guarded HTTP client.
 */
class UrlGuardTest extends TestCase {

	/**
	 * Addresses that must never be contacted.
	 *
	 * @return array
	 */
	public static function blocked_ips() {
		return array(
			array( '127.0.0.1' ),
			array( '127.255.0.9' ),
			array( '10.1.2.3' ),
			array( '172.16.0.1' ),
			array( '172.31.255.255' ),
			array( '192.168.1.1' ),
			array( '169.254.169.254' ),
			array( '100.64.0.1' ),
			array( '0.0.0.0' ),
			array( '224.0.0.1' ),
			array( '255.255.255.255' ),
			array( '198.18.0.1' ),
			array( '::1' ),
			array( '::' ),
			array( 'fe80::1' ),
			array( 'fc00::1' ),
			array( 'fd12:3456::1' ),
			array( '::ffff:127.0.0.1' ),
			array( '::ffff:10.0.0.1' ),
			array( '64:ff9b::a9fe:a9fe' ),
			array( '2002:7f00:1::1' ),
			array( '2001:db8::1' ),
			array( 'ff02::1' ),
		);
	}

	/**
	 * @dataProvider blocked_ips
	 *
	 * @param string $ip IP.
	 */
	public function test_non_public_addresses_are_refused( $ip ) {
		$this->assertFalse( IpRules::is_public( $ip ), $ip );
		$url    = 'http://' . ( false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip ) . '/feed';
		$result = rmh()->get( 'url_guard' )->check( $url );
		$this->assertWPError( $result, $url );
		$this->assertSame( 'rmh_blocked_destination', $result->get_error_code() );
	}

	public function test_public_addresses_are_allowed() {
		foreach ( array( '93.184.215.14', '8.8.8.8', '2606:4700:4700::1111', '172.32.0.1', '100.128.0.1' ) as $ip ) {
			$this->assertTrue( IpRules::is_public( $ip ), $ip );
		}
	}

	public function test_hostnames_resolving_to_private_ranges_are_refused() {
		$this->dns->map['intranet-feed.test'] = array( '10.0.0.5' );
		$this->dns->map['mixed.test']         = array( '93.184.215.14', '192.168.0.10' ); // One bad answer taints the host.
		foreach ( array( 'https://intranet-feed.test/rss', 'https://mixed.test/rss' ) as $url ) {
			$r = rmh()->get( 'url_guard' )->check( $url );
			$this->assertWPError( $r );
			$this->assertSame( 'rmh_blocked_destination', $r->get_error_code() );
		}
	}

	public function test_malformed_and_internal_forms_are_refused() {
		$cases = array(
			'ftp://example.com/feed',
			'file:///etc/passwd',
			'gopher://example.com/',
			'http://user:pass@example.com/feed',
			'http://localhost/feed',
			'http://api.localhost/feed',
			'http://printer.local/feed',
			'http://metadata.google.internal/computeMetadata/v1/',
			'http://2130706433/',
			'http://0x7f000001/',
			'http://0177.0.0.1/',
			'http://017700000001/',
			'http://127.1/',
			'http://example.com:6379/',
			"http://example.com/\x00",
			'//example.com/feed',
		);
		foreach ( $cases as $url ) {
			$this->assertWPError( rmh()->get( 'url_guard' )->check( $url ), $url );
		}
	}

	public function test_connection_is_pinned_to_the_validated_ip() {
		$this->dns->map['feeds.test'] = array( '93.184.215.20' );
		$this->http->on( 'https://feeds.test/rss', array( 'body' => 'ok' ) );
		$resp = rmh()->get( 'http' )->get( 'https://feeds.test/rss' );
		$this->assertTrue( $resp->ok() );
		$this->assertSame( '93.184.215.20', $this->http->requests[0][2], 'Transport must receive the IP that was validated (anti DNS-rebinding).' );
	}

	public function test_scenario_15_private_feed_url_is_never_requested() {
		$this->dns->map['rebind.test'] = array( '127.0.0.1' );
		$feed = $this->add_feed( 'http://rebind.test/feed.xml' );
		$res  = $this->run_feed( $feed );
		$this->assertSame( 'blocked_destination', $res['outcome'] );
		$this->assertSame( 'failed', $res['status'] );
		$this->assertCount( 0, $this->http->requests, 'No request may be sent to a prohibited address.' );
		$f = rmh()->get( 'feeds' )->find( $feed );
		$this->assertSame( 'error', $f->health );
		$this->assertStringContainsString( 'non-public', $f->last_error );
	}

	public function test_scenario_16_redirect_to_prohibited_address_is_blocked() {
		$this->http->on(
			'https://public.test/feed',
			array(
				'status'  => 302,
				'headers' => array( 'Location' => 'http://169.254.169.254/latest/meta-data/' ),
			)
		);
		$this->http->on( 'http://169.254.169.254/latest/meta-data/', array( 'body' => 'SECRET' ) );
		$resp = rmh()->get( 'http' )->get( 'https://public.test/feed' );
		$this->assertFalse( $resp->ok() );
		$this->assertSame( 'rmh_blocked_destination', $resp->error->get_error_code() );
		$this->assertSame( 0, $this->http->count( 'http://169.254.169.254/latest/meta-data/' ) );

		// Redirect to a host that *resolves* privately is also refused, and too many hops stop.
		$this->dns->map['inside.test'] = array( '192.168.10.10' );
		$this->http->on( 'https://public.test/two', array( 'status' => 301, 'headers' => array( 'Location' => 'https://inside.test/x' ) ) );
		$this->assertSame( 'rmh_blocked_destination', rmh()->get( 'http' )->get( 'https://public.test/two' )->error->get_error_code() );

		$this->http->on( 'https://public.test/loop', array( 'status' => 302, 'headers' => array( 'Location' => '/loop' ) ) );
		$loop = rmh()->get( 'http' )->get( 'https://public.test/loop' );
		$this->assertSame( 'rmh_too_many_redirects', $loop->error->get_error_code() );
	}

	public function test_credentials_are_not_forwarded_cross_origin() {
		$this->http->on( 'https://auth.test/feed', array( 'status' => 302, 'headers' => array( 'Location' => 'https://other.test/feed' ) ) );
		$this->http->on( 'https://other.test/feed', array( 'body' => '<rss/>' ) );
		rmh()->get( 'http' )->get(
			'https://auth.test/feed',
			array(
				'auth_user' => 'u',
				'auth_pass' => 'p',
			)
		);
		$this->assertArrayHasKey( 'Authorization', $this->http->requests[0][1]['headers'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->http->requests[1][1]['headers'] );
	}

	public function test_response_size_limit() {
		$this->http->on( 'https://big.test/feed', array( 'body' => str_repeat( 'x', 3000 ) ) );
		$resp = rmh()->get( 'http' )->get( 'https://big.test/feed', array( 'max_bytes' => 1000 ) );
		$this->assertSame( 'rmh_too_large', $resp->error->get_error_code() );
		$this->http->on( 'https://big.test/declared', array( 'headers' => array( 'Content-Length' => '99999999' ), 'body' => 'x' ) );
		$this->assertSame( 'rmh_too_large', rmh()->get( 'http' )->get( 'https://big.test/declared', array( 'max_bytes' => 1000 ) )->error->get_error_code() );
	}

	/**
	 * Assert WP_Error.
	 *
	 * @param mixed  $value Value.
	 * @param string $msg   Message.
	 */
	private function assertWPError( $value, $msg = '' ) {
		$this->assertInstanceOf( \WP_Error::class, $value, $msg );
	}
}

<?php
/**
 * Plugin service container and bootstrap.
 *
 * @package RSSMediaHub
 */

namespace RMH;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily constructs services and wires hooks. Services can be replaced
 * (e.g. with mocks in tests) through set() or the `rmh_service_{id}` filter.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Built services.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Whether hooks were registered.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the container.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook entry point (plugins_loaded).
	 */
	public static function boot() {
		self::instance()->register_hooks();
	}

	/**
	 * Register all runtime hooks.
	 */
	public function register_hooks() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'rss-media-hub', false, dirname( plugin_basename( RMH_FILE ) ) . '/languages' );

		Installer::maybe_upgrade();

		( new PostTypes() )->register_hooks();
		$this->get( 'scheduler' )->register_hooks();
		( new Frontend\Frontend() )->register_hooks();
		( new Frontend\CacheIntegration() )->register_hooks();
		( new Compliance\AccessControl() )->register_hooks();
		( new Rest\RestController() )->register_hooks();

		if ( is_admin() ) {
			( new Admin\Admin() )->register_hooks();
			( new Admin\Actions() )->register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'rmh', Cli\Command::class );
		}

		/**
		 * Fires after RSS Media Hub has registered its hooks. Extensions should
		 * register adapters, extractors, embed providers and verifiers here.
		 *
		 * @param Plugin $plugin Container.
		 */
		do_action( 'rmh_loaded', $this );
	}

	/**
	 * Replace or inject a service (used by tests and extensions).
	 *
	 * @param string $id      Service id.
	 * @param object $service Instance.
	 */
	public function set( $id, $service ) {
		$this->services[ $id ] = $service;
	}

	/**
	 * Drop all built services (tests).
	 */
	public function reset() {
		$this->services = array();
	}

	/**
	 * Fetch a service, building it on first use.
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws \InvalidArgumentException When the id is unknown.
	 */
	public function get( $id ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$service = $this->build( $id );
			/**
			 * Filter a service instance as it is constructed.
			 *
			 * @param object $service Service.
			 * @param Plugin $plugin  Container.
			 */
			$this->services[ $id ] = apply_filters( "rmh_service_{$id}", $service, $this );
		}
		return $this->services[ $id ];
	}

	/**
	 * Construct a service.
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws \InvalidArgumentException When the id is unknown.
	 */
	private function build( $id ) {
		switch ( $id ) {
			case 'settings':
				return new Settings();
			case 'logger':
				return new Db\Logger();
			case 'feeds':
				return new Db\FeedRepository();
			case 'items':
				return new Db\ItemRepository();
			case 'media':
				return new Db\MediaRepository();
			case 'jobs':
				return new Db\JobRepository();
			case 'reports':
				return new Db\ReportRepository();
			case 'blocklist':
				return new Db\Blocklist();
			case 'resolver':
				return new Http\DnsResolver();
			case 'transport':
				return new Http\WpTransport();
			case 'url_guard':
				return new Http\UrlGuard( $this->get( 'resolver' ), $this->get( 'settings' ) );
			case 'http':
				return new Http\HttpClient( $this->get( 'transport' ), $this->get( 'url_guard' ), $this->get( 'settings' ) );
			case 'parser':
				return new Feed\FeedParser();
			case 'embeds':
				return new Media\EmbedRegistry( $this->get( 'settings' ) );
			case 'extractor':
				return new Media\MediaExtractor( $this->get( 'embeds' ), $this->get( 'http' ), $this->get( 'settings' ) );
			case 'publisher':
				return new Import\Publisher( $this->get( 'settings' ) );
			case 'importer':
				return new Import\Importer( $this );
			case 'validator':
				return new Media\MediaValidator( $this->get( 'http' ), $this->get( 'media' ), $this->get( 'logger' ) );
			case 'downloader':
				return new Media\MediaDownloader( $this );
			case 'scheduler':
				return new Cron\Scheduler( $this );
			case 'age_gate':
				return new Compliance\AgeGate( $this->get( 'settings' ) );
			case 'clock':
				return new Clock();
		}
		throw new \InvalidArgumentException( 'Unknown RMH service: ' . esc_html( $id ) );
	}
}

<?php
/**
 * Media discovery pipeline.
 *
 * @package RSSMediaHub
 */

namespace RMH\Media;

use RMH\Http\HttpClient;
use RMH\Http\UrlNormalizer;
use RMH\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers media for a normalized feed item in priority order:
 *   1. explicit media metadata in the feed (typed media:content)
 *   2. RSS/Atom enclosures
 *   3. other recognized Media RSS content (untyped, players, thumbnails)
 *   4. media referenced from the item's HTML / linked article
 *   5. trusted provider embeds (allowlist only)
 *   6. fallback link to the original article
 * and classifies the item by what was actually found.
 */
class MediaExtractor {

	const MAX_IMAGES = 40;
	const MAX_AV     = 10;

	/**
	 * Embeds.
	 *
	 * @var EmbedRegistry
	 */
	private $embeds;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Remaining article fetches for the current run.
	 *
	 * @var int
	 */
	private $fetch_budget = 0;

	/**
	 * Constructor.
	 *
	 * @param EmbedRegistry $embeds   Embeds.
	 * @param HttpClient    $http     HTTP.
	 * @param Settings      $settings Settings.
	 */
	public function __construct( EmbedRegistry $embeds, HttpClient $http, Settings $settings ) {
		$this->embeds   = $embeds;
		$this->http     = $http;
		$this->settings = $settings;
	}

	/**
	 * Set the number of article pages that may be fetched in this run.
	 *
	 * @param int $n Budget.
	 */
	public function set_fetch_budget( $n ) {
		$this->fetch_budget = max( 0, (int) $n );
	}

	/**
	 * Extract and classify.
	 *
	 * @param array $item   Normalized item.
	 * @param array $config Effective feed config (allowed_media_types, allow_embeds, extract_html, fetch_article).
	 * @return array{media:array,type:string,thumbnail:string,failures:int,unsupported:int,notes:string[]}
	 */
	public function extract( array $item, array $config ) {
		$found       = array();
		$notes       = array();
		$failures    = 0;
		$unsupported = 0;

		// 1 + 3: Media RSS.
		foreach ( (array) $item['media'] as $m ) {
			$c = $this->from_media_rss( $m );
			if ( $c ) {
				$found[] = $c;
			} else {
				++$unsupported;
			}
		}

		// 2: Enclosures.
		foreach ( (array) $item['enclosures'] as $enc ) {
			$c = $this->from_typed_url( $enc['url'], $enc['type'], 'enclosure', array( 'filesize' => (int) $enc['length'] ) );
			if ( $c ) {
				$found[] = $c;
			} else {
				++$unsupported;
				$notes[] = 'Unsupported enclosure type: ' . ( $enc['type'] ? $enc['type'] : MimeMap::extension( $enc['url'] ) );
			}
		}

		// 4 + 5: HTML in the item.
		if ( ! empty( $config['extract_html'] ) ) {
			$html  = (string) $item['summary'] . "\n" . (string) $item['content'];
			$found = array_merge( $found, $this->from_html( HtmlMediaExtractor::extract( $html, $item['link'] ) ) );
		}

		// 4: Linked article page (opt-in, bounded per run, guarded fetch).
		if ( ! empty( $config['fetch_article'] ) && $this->fetch_budget > 0 && '' !== $item['link'] && ! $this->has_type( $found, array( 'video', 'audio', 'embed' ) ) ) {
			--$this->fetch_budget;
			$resp = $this->http->get(
				$item['link'],
				array(
					'accept'    => 'text/html,application/xhtml+xml',
					'max_bytes' => HtmlMediaExtractor::MAX_HTML,
					'retries'   => 0,
				)
			);
			if ( $resp->ok() && false !== strpos( $resp->content_type(), 'html' ) ) {
				$found = array_merge( $found, $this->from_html( HtmlMediaExtractor::extract( $resp->body, $resp->final_url ), true ) );
			} else {
				++$failures;
				$notes[] = 'Article fetch failed: ' . $resp->describe();
			}
		}

		// 5: The item link itself may be a provider page.
		if ( '' !== $item['link'] ) {
			$m = $this->embeds->match( $item['link'] );
			if ( $m ) {
				$found[] = $this->embed_candidate( $m, 'provider_embed' );
			}
		}

		$result = $this->finalize( $found, $item, $config, $notes );

		$result['failures']    = $failures;
		$result['unsupported'] = $unsupported;
		return $result;
	}

	/**
	 * Candidate from a Media RSS entry.
	 *
	 * @param array $m Entry.
	 * @return array|null
	 */
	private function from_media_rss( array $m ) {
		$extra = array(
			'thumbnail'   => $m['thumbnail'],
			'width'       => $m['width'],
			'height'      => $m['height'],
			'duration'    => $m['duration'],
			'filesize'    => $m['filesize'],
			'title'       => $m['title'],
			'description' => $m['description'],
			'credit'      => $m['credit'],
		);
		foreach ( array( $m['url'], $m['player'] ) as $u ) {
			if ( '' === (string) $u ) {
				continue;
			}
			$embed = $this->embeds->match( $u );
			if ( $embed ) {
				return $this->embed_candidate( $embed, 'media_rss', $extra );
			}
		}
		if ( 'media_player' === $m['source'] || 'player' === $m['medium'] ) {
			// Player page that is not an allowed provider: link to it, never frame it.
			return '' !== $m['url'] ? MediaCandidate::make( 'link', $m['url'], 'media_rss', $extra ) : null;
		}
		$explicit = in_array( $m['medium'], array( 'video', 'audio', 'image' ), true ) ? $m['medium'] : MimeMap::category_of_mime( $m['mime'] );
		if ( '' !== $explicit ) {
			$mime = '' !== $m['mime'] ? MimeMap::canonical( $m['mime'] ) : MimeMap::mime_of_url( $m['url'] );
			return MediaCandidate::make(
				$explicit,
				$m['url'],
				'feed_media',
				array_merge(
					$extra,
					array(
						'mime'     => $mime,
						'playable' => MimeMap::is_playable( $mime ),
					)
				)
			);
		}
		$by_ext = MimeMap::category_of_url( $m['url'] );
		if ( '' !== $by_ext ) {
			$mime = MimeMap::mime_of_url( $m['url'] );
			return MediaCandidate::make(
				$by_ext,
				$m['url'],
				'media_rss',
				array_merge(
					$extra,
					array(
						'mime'     => $mime,
						'playable' => MimeMap::is_playable( $mime ),
					)
				)
			);
		}
		return null;
	}

	/**
	 * Candidate from a URL with an optional declared type.
	 *
	 * @param string $url    URL.
	 * @param string $type   Declared MIME.
	 * @param string $method Method.
	 * @param array  $extra  Extra fields.
	 * @return array|null
	 */
	private function from_typed_url( $url, $type, $method, array $extra = array() ) {
		if ( '' === (string) $url ) {
			return null;
		}
		$embed = $this->embeds->match( $url );
		if ( $embed ) {
			return $this->embed_candidate( $embed, $method, $extra );
		}
		$cat = MimeMap::category_of_mime( $type );
		if ( '' === $cat ) {
			$cat = MimeMap::category_of_url( $url );
		}
		if ( '' === $cat ) {
			return null;
		}
		$mime = '' !== $type && '' !== MimeMap::category_of_mime( $type ) ? MimeMap::canonical( $type ) : MimeMap::mime_of_url( $url );
		return MediaCandidate::make(
			$cat,
			$url,
			$method,
			array_merge(
				$extra,
				array(
					'mime'     => $mime,
					'playable' => MimeMap::is_playable( $mime ),
				)
			)
		);
	}

	/**
	 * Candidates from extracted HTML references.
	 *
	 * @param array $refs     From HtmlMediaExtractor.
	 * @param bool  $is_page  From a fetched article page (enables OG tags).
	 * @return array
	 */
	private function from_html( array $refs, $is_page = false ) {
		$out = array();
		foreach ( array( 'video', 'audio' ) as $kind ) {
			foreach ( $refs[ $kind ] as $r ) {
				$c = $this->from_typed_url(
					$r['url'],
					$r['mime'],
					'article_html',
					array(
						'thumbnail' => $r['thumbnail'],
						'width'     => $r['width'],
						'height'    => $r['height'],
					)
				);
				if ( $c ) {
					$out[] = $c;
				}
			}
		}
		foreach ( $refs['image'] as $r ) {
			if ( 'image' === MimeMap::category_of_url( $r['url'] ) || '' === MimeMap::extension( $r['url'] ) ) {
				$out[] = MediaCandidate::make(
					'image',
					$r['url'],
					'article_html',
					array(
						'mime'     => MimeMap::mime_of_url( $r['url'] ),
						'title'    => $r['alt'],
						'width'    => $r['width'],
						'height'   => $r['height'],
						'playable' => true,
					)
				);
			}
		}
		foreach ( $refs['links'] as $u ) {
			$cat = MimeMap::category_of_url( $u );
			if ( in_array( $cat, array( 'video', 'audio' ), true ) ) {
				$c = $this->from_typed_url( $u, '', 'article_html' );
				if ( $c ) {
					$out[] = $c;
				}
			}
		}
		foreach ( array_merge( $refs['frames'], $refs['links'] ) as $u ) {
			$m = $this->embeds->match( $u );
			if ( $m ) {
				$out[] = $this->embed_candidate( $m, 'provider_embed' );
			}
		}
		if ( $is_page && $refs['og'] ) {
			$og = $refs['og'];
			foreach ( array( 'og:video:secure_url', 'og:video:url', 'og:video', 'twitter:player:stream' ) as $k ) {
				if ( ! empty( $og[ $k ] ) ) {
					$c = $this->from_typed_url( $og[ $k ], isset( $og['og:video:type'] ) ? $og['og:video:type'] : '', 'article_html', array( 'thumbnail' => isset( $og['og:image'] ) ? $og['og:image'] : '' ) );
					if ( $c ) {
						$out[] = $c;
						break;
					}
				}
			}
			foreach ( array( 'og:audio:secure_url', 'og:audio' ) as $k ) {
				if ( ! empty( $og[ $k ] ) ) {
					$c = $this->from_typed_url( $og[ $k ], isset( $og['og:audio:type'] ) ? $og['og:audio:type'] : '', 'article_html' );
					if ( $c ) {
						$out[] = $c;
						break;
					}
				}
			}
			if ( ! empty( $og['twitter:player'] ) ) {
				$m = $this->embeds->match( $og['twitter:player'] );
				if ( $m ) {
					$out[] = $this->embed_candidate( $m, 'provider_embed' );
				}
			}
			foreach ( array( 'og:image:secure_url', 'og:image', 'twitter:image' ) as $k ) {
				if ( ! empty( $og[ $k ] ) ) {
					$out[] = MediaCandidate::make(
						'image',
						$og[ $k ],
						'article_html',
						array(
							'mime'     => MimeMap::mime_of_url( $og[ $k ] ),
							'playable' => true,
							'title'    => 'og:image',
						)
					);
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Embed candidate.
	 *
	 * @param array  $m      Provider match.
	 * @param string $method Method.
	 * @param array  $extra  Extra.
	 * @return array
	 */
	private function embed_candidate( array $m, $method, array $extra = array() ) {
		$extra = array_merge(
			$extra,
			array(
				'provider'  => $m['provider'],
				'embed_url' => $m['embed_url'],
				'mime'      => 'text/html',
				'thumbnail' => ! empty( $extra['thumbnail'] ) ? $extra['thumbnail'] : $m['thumbnail'],
				'playable'  => true,
			)
		);
		return MediaCandidate::make( 'embed', $m['page_url'], $method, $extra );
	}

	/**
	 * Does the list contain any candidate of these types?
	 *
	 * @param array    $found Candidates.
	 * @param string[] $types Types.
	 * @return bool
	 */
	private function has_type( array $found, array $types ) {
		foreach ( $found as $c ) {
			if ( in_array( $c['type'], $types, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Filter, deduplicate, order, classify.
	 *
	 * @param array $found  Candidates.
	 * @param array $item   Item.
	 * @param array $config Config.
	 * @param array $notes  Notes (by ref).
	 * @return array
	 */
	private function finalize( array $found, array $item, array $config, array &$notes ) {
		$allowed   = isset( $config['allowed_media_types'] ) ? (array) $config['allowed_media_types'] : Settings::MEDIA_TYPES;
		$blocklist = rmh()->get( 'blocklist' );

		usort(
			$found,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		$seen  = array();
		$kept  = array();
		$count = array(
			'image' => 0,
			'av'    => 0,
		);
		foreach ( $found as $c ) {
			$key = UrlNormalizer::normalize( 'embed' === $c['type'] ? $c['embed_url'] : $c['url'] );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( $blocklist->is_url_blocked( $c['url'] ) || ( '' !== $c['thumbnail'] && $blocklist->is_url_blocked( $c['thumbnail'] ) ) ) {
				$notes[] = 'Dropped blocklisted media URL.';
				continue;
			}
			if ( 'embed' === $c['type'] && ( empty( $config['allow_embeds'] ) || ! in_array( 'embed', $allowed, true ) ) ) {
				// Embeds disabled for this feed: keep as an external link instead.
				$c = MediaCandidate::make(
					'link',
					$c['url'],
					$c['method'],
					array(
						'thumbnail' => $c['thumbnail'],
						'provider'  => $c['provider'],
					)
				);
			}
			if ( in_array( $c['type'], array( 'video', 'audio' ), true ) ) {
				if ( ! in_array( $c['type'], $allowed, true ) || $count['av'] >= self::MAX_AV ) {
					continue;
				}
				++$count['av'];
			}
			if ( 'image' === $c['type'] ) {
				if ( ( ! in_array( 'image', $allowed, true ) && ! in_array( 'gallery', $allowed, true ) ) || $count['image'] >= self::MAX_IMAGES ) {
					continue;
				}
				++$count['image'];
			}
			$kept[] = $c;
		}

		// Gallery not allowed: keep a single image.
		$images = array_values(
			array_filter(
				$kept,
				static function ( $c ) {
					return 'image' === $c['type'];
				}
			)
		);
		if ( count( $images ) > 1 && ! in_array( 'gallery', $allowed, true ) ) {
			$first  = $images[0]['url'];
			$kept   = array_values(
				array_filter(
					$kept,
					static function ( $c ) use ( $first ) {
						return 'image' !== $c['type'] || $c['url'] === $first;
					}
				)
			);
			$images = array( $images[0] );
		}

		$type = 'text';
		if ( $this->has_type( $kept, array( 'video' ) ) ) {
			$type = 'video';
		} elseif ( $this->has_type( $kept, array( 'embed' ) ) ) {
			$type = 'embed';
		} elseif ( $this->has_type( $kept, array( 'audio' ) ) ) {
			$type = 'audio';
		} elseif ( count( $images ) > 1 ) {
			$type = 'gallery';
		} elseif ( 1 === count( $images ) ) {
			$type = in_array( 'image', $allowed, true ) ? 'image' : 'gallery';
		}

		// 6: fallback link to the original article.
		if ( 'text' === $type && '' !== $item['link'] && ! $this->has_type( $kept, array( 'link' ) ) ) {
			$kept[] = MediaCandidate::make( 'link', $item['link'], 'fallback_link' );
		}

		// Thumbnail: feed thumbnail, candidate thumbnails, then first image.
		$thumb = '';
		foreach ( (array) $item['thumbnails'] as $t ) {
			if ( '' !== $t && ! $blocklist->is_url_blocked( $t ) ) {
				$thumb = $t;
				break;
			}
		}
		if ( '' === $thumb ) {
			foreach ( $kept as $c ) {
				if ( '' !== $c['thumbnail'] ) {
					$thumb = $c['thumbnail'];
					break;
				}
			}
		}
		if ( '' === $thumb && $images ) {
			$thumb = $images[0]['url'];
		}

		return array(
			'media'     => array_values( $kept ),
			'type'      => $type,
			'thumbnail' => $thumb,
			'notes'     => $notes,
		);
	}
}

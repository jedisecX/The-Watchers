# RSS Media Hub

A WordPress plugin that aggregates RSS 2.0, RSS 1.0/RDF and Atom feeds, extracts media from them (direct video and audio, image galleries, trusted provider embeds), and publishes each item on its own page at `/media/{slug}/`. Every page carries source attribution, a working fallback, and moderation controls. It is built for lawful adult-content publishing, and nothing in it is adult-specific unless you configure it that way.

* **Requires:** WordPress 6.2+ and PHP 7.4+ with the `dom`, `libxml`, `sodium` and `fileinfo` extensions (`curl` recommended). Any database that WordPress supports works.
* **License:** GPL-2.0-or-later.
* **Tested on:** WordPress 6.8.3 and PHP 8.3, with a real browser (Chromium) at phone width.

---

## Contents

1. [Installation](#installation)
2. [Quick start](#quick-start)
3. [How it works](#how-it-works)
4. [Configuration](#configuration)
5. [Scheduling (WP-Cron and system cron)](#scheduling)
6. [Media handling and rights](#media-handling-and-rights)
7. [Adult-site controls](#adult-site-controls)
8. [Theming and templates](#theming-and-templates)
9. [REST API, WP-CLI and hooks](#rest-api-wp-cli-and-hooks)
10. [Security](#security)
11. [Testing](#testing)
12. [Scaling and limits](#scaling-and-limits)
13. [Known limitations](#known-limitations)
14. [File manifest](#file-manifest)

Detailed documents are in [`docs/`](docs/):

* [ARCHITECTURE](docs/ARCHITECTURE.md): data flow, database schema and migrations.
* [SECURITY](docs/SECURITY.md)
* [OPERATIONS](docs/OPERATIONS.md): cron, caching and scaling.
* [API](docs/API.md): REST, CLI, hooks and extension points.
* [AGE-VERIFICATION](docs/AGE-VERIFICATION.md)
* [MODERATION](docs/MODERATION.md): reports, takedowns and CSAM escalation.
* [TESTING](docs/TESTING.md): test-to-scenario map and results.
* [sample configuration](docs/sample-config.json)

---

## Installation

1. Download `rss-media-hub-1.0.0.zip` from `wordpress-plugin/dist/`. Check it against the `.sha256` file next to it if you want.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, then click **Install Now** and **Activate**.
3. Activation does the following:
   * creates 7 custom tables (`wp_rmh_*`);
   * registers the `rmh_item` post type and its taxonomies;
   * grants capabilities: `rmh_manage_feeds` to administrators, and `rmh_moderate` to administrators and editors;
   * schedules the 5-minute import tick and the daily maintenance task;
   * flushes rewrite rules.
4. *(Recommended for production)* Set up a real system cron job. See [Scheduling](#scheduling).

Upgrades are applied automatically when the plugin version changes; see `Installer::maybe_upgrade()`. Schema changes are additive (dbDelta) and never modify posts.

---

## Quick start

1. Go to **RSS Media Hub → Feed Manager → Add feed** and enter the feed URL. Choose an import mode:
   * **Automatic**: publishes items immediately.
   * **Pending review**: creates draft or pending posts.
   * **Manual**: keeps items in the queue until you approve them.
2. Click **Save and test**. This dry run fetches and parses the feed and shows the first 10 items with the media type detected for each. It imports nothing.
3. Within 5 minutes the scheduler polls the feed. To start immediately, click **Run now** on the feed list or **Run all feeds now** on the Dashboard. Both queue a background run; neither blocks the page.
4. New posts appear at `/media/`, which is a filterable reader with grid and list views. Each post has a permalink such as `/media/clip-title/`, and the source directory is at `/media/sources/`.
5. Items that need attention appear in **Import Queue**, and visitor reports appear in **Reports & Moderation**.

---

## How it works

```
 Scheduler (5-min tick, global lock)
   └─ due feeds (staggered next_poll_at, per-feed lease)
        └─ HttpClient ── UrlGuard (SSRF: DNS → IP checks, pin IP, re-check every redirect)
             └─ FeedParser ── XmlLoader (no DTD/entities) ── RSS2 / Atom / RSS1 / custom adapters
                  └─ for each item (newest first, capped):
                       identity hash (feed + GUID | canonical URL | fingerprint) → skip duplicates
                       blocklist → cross-feed canonical-URL dedupe
                       MediaExtractor (priority 1→6) → classification
                       store item (sanitized snapshot) + media rows  [status: discovered]
        └─ publish step per item (auto / pending / manual), retried with backoff on failure
   └─ background: media revalidation (HEAD / ranged GET), authorized downloads
```

Media is detected in this priority order:

1. Explicit feed media metadata (typed `media:content`).
2. Enclosures (RSS `<enclosure>`, Atom `rel="enclosure"`).
3. Other recognised Media RSS content (untyped entries, players).
4. Media found in the item's HTML or, if enabled, in the linked article page (`og:video`, `<video>`, `<img>`).
5. Embeds from allowlisted providers (YouTube via youtube-nocookie, Vimeo, Dailymotion, and any custom providers you define).
6. A fallback link to the original article.

Each item is then classified as `video`, `embed` (external video), `audio`, `gallery`, `image` or `text`, based on the media that was actually found.

---

## Configuration

**Settings** (`RSS Media Hub → Settings`, administrators only) controls the global defaults:

* **Publishing:** import mode, content mode, excerpt vs full text, how updates are handled, and how source categories are mapped.
* **Polling:** polling interval and batch sizes.
* **Network:** timeouts, retries, redirect limit and allowed ports.
* **Media:** supported media types, media handling, size limits and storage quota.
* **Embeds:** the provider allowlist.
* **Reader:** templates and display options.
* **Compliance:** age gate and jurisdiction rules.
* **Retention and uninstall** behaviour.

**Per-feed overrides** (Feed Manager → Edit) can set:

* import, content and update modes, and excerpt vs full text;
* media handling, and the allowed media types (a feed can narrow the global list but never widen it);
* categories, tags and an attribution template;
* extraction rules: whether to scan item HTML, fetch the article page, and allow embeds;
* content rating, rights confirmation, and HTTP Basic credentials (stored encrypted).

[`docs/sample-config.json`](docs/sample-config.json) shows every global setting. To apply it:

```bash
wp option update rmh_settings --format=json < docs/sample-config.json
```

---

<a id="scheduling"></a>
## Scheduling (WP-Cron and system cron)

* The `rmh_tick` event runs every 5 minutes. Each feed still keeps its own interval (minimum 5 minutes), plus jitter and exponential backoff.
* A tick processes at most **Max feeds per run** feeds within **Max processing time**. Any work left over waits for the next tick.
* A global options-table lock prevents overlapping ticks, and atomic per-feed leases prevent double processing when WP-Cron, system cron and WP-CLI all run.
* Jobs that crashed are marked `interrupted` and their items are retried.

**For production, use system cron:**

1. Add this to `wp-config.php`:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
2. Add one of these crontab entries:
   ```cron
   */5 * * * * cd /var/www/html && wp rmh run --quiet
   # or, without WP-CLI:
   */5 * * * * curl -fsS https://example.com/wp-cron.php >/dev/null
   ```

**Logs & Diagnostics → Scheduling** shows the next run, the last run and whether it is overdue, and lets you force-release the lock.

---

## Media handling and rights

| Mode | Behaviour |
|---|---|
| Remote embedding (default) | Plays the media from its source using HTML5 `<video>`/`<audio>` or an allowlisted provider iframe. |
| Remote linking | Shows only the thumbnail, metadata and a link to the source. |
| Hybrid | Imports the thumbnail locally as the featured image; media still plays from the source. Requires **rights confirmed**. |
| Authorized local import | Downloads the media into the Media Library. Requires **rights confirmed**. Files are checked by magic-byte MIME, size and quota. |

* Nothing is downloaded unless the feed is set to a local mode **and** an administrator has ticked *"I have the rights or permission…"* for that feed.
* Downloads run in the background batch, never during feed polling.
* If remote media disappears, the post stays published. Its media is marked `unavailable`, and the page shows a fallback with the thumbnail, attribution and a link to the original.
* The plugin never bypasses authentication, paywalls, DRM or anti-bot protections. A 401, 403 or 451 response is reported as *"Access restricted… not bypassed"*.

---

## Adult-site controls

* **Labeling:** an adult-site flag, the optional RTA meta label, per-feed and per-post content ratings, `18+` badges, and a configurable content warning.
* **Age gate:**
  * *Server mode*: content is not sent until the visitor passes. Gated pages are never cached.
  * *Overlay mode*: a cache-friendly JavaScript overlay that works only as a deterrent.
* The built-in **self-declaration** verifier is not legal age verification. Integrate a verification provider through `AgeVerifierInterface`; see [AGE-VERIFICATION.md](docs/AGE-VERIFICATION.md).
* **Jurisdiction rules:** HTTP 451 for configured country or subdivision codes. The visitor's country comes from a trusted header (for example `CF-IPCountry`) or from the `rmh_visitor_country` filter.
* **Classification is never inferred from a URL or domain.** You classify each source, and moderators can override individual posts.
* **Reports:** a report form on every post, rate-limited and with a honeypot. Suspected CSAM and non-consensual content are **hidden immediately and escalated**. See [MODERATION.md](docs/MODERATION.md).

---

## Theming and templates

* Templates in `templates/` work with both classic themes (`get_header()`/`get_footer()`) and block themes (header and footer template parts).
* To override any template, copy it to `yourtheme/rss-media-hub/`. For example, copy `parts/media-video.php` to `yourtheme/rss-media-hub/parts/media-video.php`.
* Shortcodes:
  * `[rmh_reader per_page="12" view="grid|list" type="video" source="feed-3" category="slug" tag="slug" sort="newest"]`
  * `[rmh_feed_directory]`
* The **Show the media reader on the home page** setting replaces the "latest posts" home page with the reader.
* The URL base `media` can be changed with the `rmh_permalink_base` filter. Flush permalinks after changing it.

---

## REST API, WP-CLI and hooks

* **REST** (namespace `rmh/v1`): feeds CRUD, test and run; items (approve, reject, retry, reprocess); media listing and revalidation; jobs and retry of failed items; the blocklist; reports; status; and a view counter. Authentication is cookie + `X-WP-Nonce` or application passwords.
* **WP-CLI:** `wp rmh run [--feed=<id>|--all]`, `wp rmh test <url>`, `wp rmh feeds list|add|pause|resume|delete`, `wp rmh revalidate`, `wp rmh retry_failed`, `wp rmh status`, `wp rmh maintenance`, `wp rmh diagnostics`.
* **Extension points** let you add feed formats, media extractors and embed providers, age verifiers, publishing rules, template paths and media validation, and hook into cache purges. See [API.md](docs/API.md).

---

## Security

All feed content, URLs, HTML and media references are treated as hostile. In summary:

* **SSRF:** DNS resolution, rejection of private, loopback, link-local, CGNAT, reserved and IPv6-embedded addresses, IP pinning against DNS rebinding, re-validation on every redirect, port allowlist, and size, time and redirect limits.
* **XML:** XXE and entity expansion are refused.
* **HTML:** whitelist sanitisation, output escaping everywhere, and provider-built iframes only (sandboxed, with a `frame-src` CSP).
* **Requests and data:** nonces and capabilities on every admin action, permission callbacks on every REST route, prepared SQL, encrypted feed credentials, and redacted logs and diagnostics.

See [docs/SECURITY.md](docs/SECURITY.md).

---

## Testing

```bash
tests/bin/install-wp-tests.sh /tmp/rmh-test        # disposable WP + SQLite with the plugin active
RMH_TEST_WP_PATH=/tmp/rmh-test/site vendor/bin/phpunit   # PHPUnit 10
phpcs                                               # WordPress-Extra + security + PHPCompatibility (7.4+)
# Browser E2E (Playwright + Chromium, 390×844 viewport):
php -S localhost:8089 -t /tmp/rmh-test/site /tmp/rmh-test/site/router.php &
cp tests/e2e/rmh-e2e-mocks.php /tmp/rmh-test/site/wp-content/mu-plugins/
node tests/e2e/run.mjs
```

All HTTP in the tests is mocked: no test contacts a real host or downloads real media. The latest results are recorded in [docs/TESTING.md](docs/TESTING.md):

* **PHPUnit:** 127 tests and 847 assertions, all passing.
* **Browser E2E:** 53 of 53 checks passing.
* **PHPCS:** 0 errors.

---

## Scaling and limits

* Work per tick is bounded by feeds per run, items per feed, runtime, article fetches, and validation and download batches.
* Look-ups use indexed hashes for identity, GUID and URL.
* Conditional GET (ETag and Last-Modified) means unchanged feeds cost one `304` response.
* With the defaults (10 feeds per 5-minute tick), roughly 2,800 polls a day are possible, which suits hundreds of hourly feeds.
* For thousands of feeds, raise *Max feeds per run*, run `wp rmh run` from system cron or several workers (leases make this safe), and use a persistent object cache.

See [OPERATIONS.md](docs/OPERATIONS.md#scaling) for the move to a queue-backed worker.

---

## Known limitations

* **Age verification:** only self-declaration is built in. A legally compliant provider must be integrated through the adapter interface.
* **IP pinning:** requires the cURL transport. With the fsockopen fallback, the DNS answer is validated but not pinned, which leaves a small TOCTOU window.
* **Stream formats:** HLS and DASH (`.m3u8`, `.mpd`) are detected and linked but not played inline, because that would need a JS player. `.mov` and `.mkv` fall back to links.
* **Feed formats:** JSON Feed is not built in. The adapter interface is DOM-based, so a JSON Feed adapter would need a small parser extension.
* **Geo rules:** these rely on a trusted header or filter. There is no GeoIP database bundled.
* **Transcoding:** none is provided. Locally imported media is stored as-is, after verification.
* **Uploads directory:** byte-range requests for local video are served by your web server, which must allow `Range` on the uploads directory (Apache and nginx do by default).

---

## File manifest

```
rss-media-hub.php            Plugin header, bootstrap, rmh() accessor
uninstall.php                Uninstall (keep_all | keep_content | delete_all)
readme.txt                   WordPress.org-style readme
CHANGELOG.md
includes/
  Autoloader.php Plugin.php Installer.php Settings.php PostTypes.php Clock.php
  Http/       UrlGuard IpRules UrlNormalizer HttpClient HttpResponse TransportInterface WpTransport ResolverInterface DnsResolver
  Feed/       FeedParser XmlLoader Dom DateParser MediaRss FeedAdapterInterface AbstractAdapter Rss2Adapter Rss1Adapter AtomAdapter
  Media/      MediaExtractor HtmlMediaExtractor MediaCandidate MimeMap EmbedRegistry EmbedProviderInterface MediaValidator MediaDownloader
              Providers/ AbstractProvider YouTube Vimeo Dailymotion CustomProvider
  Import/     Importer Publisher
  Cron/       Scheduler Lock
  Db/         FeedRepository FeedConfig ItemRepository MediaRepository JobRepository ReportRepository Blocklist Logger
  Frontend/   Frontend Reader Template CacheIntegration
  Compliance/ AgeGate AgeVerifierInterface SelfDeclarationVerifier AccessControl Reports
  Security/   Sanitizer Redactor Secrets RateLimiter
  Rest/       RestController
  Cli/        Command
  Admin/      Admin Actions Ui DashboardPage FeedsPage QueuePage MediaPage ModerationPage RestrictionsPage SettingsPage LogsPage Diagnostics
templates/    single archive directory age-gate blocked + parts/ (reader card media-video media-audio media-embed media-image media-gallery media-fallback report-form)
assets/       css/frontend.css css/admin.css js/frontend.js js/admin.js
languages/    rss-media-hub.pot
docs/         ARCHITECTURE SECURITY OPERATIONS API AGE-VERIFICATION MODERATION TESTING sample-config.json
tests/        bootstrap.php Support/ Unit/ Integration/ fixtures/ e2e/ bin/install-wp-tests.sh   (not in the ZIP)
bin/          build-zip.sh make-pot.php                                                         (not in the ZIP)
phpunit.xml.dist phpcs.xml.dist                                                                 (not in the ZIP)
```

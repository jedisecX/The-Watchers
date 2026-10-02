# Architecture

## Components

| Layer | Classes | Responsibility |
|---|---|---|
| Bootstrap | `Plugin` (service container), `Autoloader`, `Installer` | Wiring, lazy services (replaceable via `rmh()->set()` or the `rmh_service_{id}` filter), schema and migrations |
| Network | `UrlGuard`, `IpRules`, `HttpClient`, `WpTransport`, `DnsResolver` | Every outbound request: SSRF checks, IP pinning, manual redirects, retries, conditional GET, size limits |
| Parsing | `FeedParser`, `XmlLoader`, `Rss2Adapter`, `AtomAdapter`, `Rss1Adapter`, `MediaRss`, `DateParser` | Hardened XML into a normalised item array |
| Media | `MediaExtractor`, `HtmlMediaExtractor`, `EmbedRegistry` and providers, `MimeMap`, `MediaValidator`, `MediaDownloader` | Discovery, classification, allowlisted embeds, validation, authorized local storage |
| Import | `Importer`, `Publisher` | Two-phase idempotent import, publishing modes, editor-safe updates, moderation actions |
| Scheduling | `Scheduler`, `Lock` | 5-minute tick, staggering, backoff, recovery, retention |
| Data | `FeedRepository`, `FeedConfig`, `ItemRepository`, `MediaRepository`, `JobRepository`, `ReportRepository`, `Blocklist`, `Logger` | Custom tables with prepared SQL only |
| Delivery | `Frontend`, `Reader`, `Template`, `CacheIntegration` | Routing, theme integration, players, CSP, cache purges |
| Compliance | `AgeGate`, `AgeVerifierInterface`, `SelfDeclarationVerifier`, `AccessControl`, `Reports` | Age gate, geo rules (HTTP 451), reports, escalation |
| Interfaces | `Admin\*`, `RestController`, `Cli\Command` | Administration UI, REST API, WP-CLI |

## Import data flow

1. **Select.** `Scheduler::tick()` takes the global lock, recovers stale jobs, and selects due feeds:
   ```sql
   status='active' AND next_poll_at<=now AND lease expired
   ORDER BY next_poll_at
   LIMIT feeds_per_tick
   ```
2. **Lease and job.** `Importer::run_feed()` atomically claims a lease on the feed (a conditional `UPDATE … WHERE lease_until < now`) and starts a job row.
3. **Fetch.** `HttpClient` performs a conditional GET. A 304 is recorded as `not_modified`. Failures are classified as `unreachable`, `blocked_destination`, `http_error` or `too_large`, and back off exponentially.
4. **Parse.** `FeedParser` parses the document. A failure is classified as `invalid_feed`. Feed pagination (RFC 5005 `rel="next"`) is followed up to `max_pages`.
5. **Ingest.** Items are processed newest first, capped at `max_items`. For each item:
   * **Identity.** Compute `identity_hash = sha1(feed_id + (GUID | normalized URL | fingerprint))`. The UNIQUE key on this column makes inserts idempotent, even when runs race.
   * **Existing items.** If the item is already known, apply the update policy. `Publisher::update()` overwrites only fields whose stored fingerprint still matches what the importer last wrote, so editor changes survive.
   * **Blocklist.** A match on GUID, URL, domain or title keyword sets status `blocked`.
   * **Cross-feed duplicates.** If the same canonical URL already exists, set status `duplicate` and record `duplicate_of`.
   * **New items.** Run `MediaExtractor` to classify the item, apply the content mode and allowed media types, and store a sanitized snapshot plus the media rows with status `discovered`.
6. **Publish.** `Importer::publish_due()` runs the publishing mode for each discovered item:
   * `auto` creates a published post;
   * `pending` creates a pending or draft post;
   * `manual` sets status `queued` and creates no post;
   * the `rmh_publish_mode` filter can also return `reject`.

   A failure is recorded and retried after 1, 4, 16 and 64 minutes, up to 5 attempts, before the item is marked `failed`. Each item is isolated, so one exception cannot stop the batch. If a post exists for the item but the item row was never updated (a crash between the two writes), the post is adopted rather than recreated.
7. **Record.** The job row stores all run statistics, the feed row records its health and next poll time, and the run is logged.

## Database schema

All tables use the WordPress table prefix. Times are UTC `DATETIME`.

| Table | Purpose | Keys and indexes |
|---|---|---|
| `rmh_feeds` | Feed configuration (`settings` JSON with per-feed overrides; credentials encrypted), polling state (`next_poll_at`, `last_success_at`, `etag`, `last_modified`, `consecutive_failures`, `health`, `last_error`), lease (`lease_token`, `lease_until`) | `UNIQUE(url_hash)`, `(status, next_poll_at)` |
| `rmh_items` | One row per discovered item: identity hashes, source GUID and URL, status, `post_id`, media type, content rating, sanitized `payload` JSON, `content_hash`, source date, attempts and retry time, `duplicate_of` | `UNIQUE(identity_hash)`, `(feed_id,status)`, `url_hash`, `guid_hash`, `(status,next_attempt_at)`, `post_id` |
| `rmh_media` | Media assets per item and post: type, URL, MIME, thumbnail, dimensions, duration, size, provider and embed URL, extraction method and priority, validation status and message, `checked_at`, local `attachment_id` and bytes, download status, credit, `retrieved_at` | `item_id`, `post_id`, `(validation_status,checked_at)`, `media_type`, `url_hash`, `download_status` |
| `rmh_jobs` | One row per run: type, feed, trigger, status, outcome, HTTP status, timings and all counters | `(feed_id,started_at)`, `(status,started_at)` |
| `rmh_logs` | Redacted structured log (level, channel, feed, item and job ids, message, context JSON) | `created_at`, `(channel,level)`, `feed_id` |
| `rmh_reports` | Visitor reports and removal requests (reporter stored as a keyed hash only) | `(status,created_at)`, `post_id` |
| `rmh_blocklist` | Restrictions on domains, URLs, GUIDs and keywords | `UNIQUE(type,value_hash)` |

WordPress-native storage:

* the post type `rmh_item`, with permalinks `/media/{slug}/` and the archive at `/media/`;
* the taxonomies `rmh_category` (hierarchical), `rmh_tag`, `rmh_media_type` (fixed terms) and `rmh_source` (one term per feed);
* post meta `_rmh_*` holding provenance (item, feed, source URL, GUID, source date), media type, author, rating, thumbnail URL, sync fingerprints and the reason a post was hidden;
* options `rmh_settings`, `rmh_db_version`, `rmh_last_tick` and `rmh_last_tick_done`.

**Item statuses:** `discovered`, `queued`, `pending`, `published`, `rejected`, `skipped`, `duplicate`, `blocked`, `failed`, `removed`.

**Media validation statuses:** `unchecked`, `valid`, `invalid` (wrong content type), `unavailable` (404/410, or 401/402/403/451 access restricted), `blocked`, `error` (transient).

## Migrations and lifecycle

* **Upgrade.** `Installer::maybe_upgrade()` runs on `plugins_loaded` when `rmh_db_version` is older than the code's `RMH_DB_VERSION`. It runs `dbDelta()`, which is additive and idempotent, then runs the versioned callbacks from `Installer::migrations()` (filterable with `rmh_migrations`). Posts, terms and attachments are never modified by migrations. This is covered by a test that drops a table and a column, resets the version, upgrades, and verifies every post and setting survived.
* **Deactivation** removes scheduled events and keeps all data.
* **Uninstall** depends on Settings → *On uninstall*:

  | Mode | What is removed |
  |---|---|
  | `keep_all` (default) | Nothing is deleted. |
  | `keep_content` | Tables, options, capabilities and events are dropped; imported posts are kept. |
  | `delete_all` | Everything above, plus imported posts, plugin-downloaded attachments and the plugin's terms. |

* **Multisite.** Network activation and uninstall iterate over the network's sites, up to 500.

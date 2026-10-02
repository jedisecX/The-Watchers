# Operations

## Scheduling

| Event | Interval | Work |
|---|---|---|
| `rmh_tick` | 5 min | Recover stale jobs, poll due feeds, retry failed publishes, validate a batch of media, process a few authorized downloads |
| `rmh_daily` | daily | Retention: purge old logs, jobs and rejected/skipped items |
| `rmh_run_feed` (single) | on demand | **Run now** for one feed |
| `rmh_tick_now` (single) | on demand | **Run all feeds now** |

Production setup:

1. Add this to `wp-config.php`:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
2. Add one of these crontab entries:
   ```cron
   */5 * * * * cd /var/www/html && wp rmh run --quiet
   # or
   */5 * * * * curl -fsS https://example.com/wp-cron.php >/dev/null
   ```

Health:

* The **Dashboard** and **Logs & Diagnostics → Scheduling** show the next and last run, whether the scheduler is overdue (more than 30 minutes without a tick), and the lock state.
* The events re-create themselves on `init` if something removes them.

Overlap and recovery:

| Mechanism | How it works |
|---|---|
| Global lock | Atomic `INSERT` into the options table, with expiry and a conditional take-over. |
| Per-feed lease | Atomic `UPDATE`, so a feed is never processed twice. |
| Crashed runs | Jobs running longer than `max(15 min, 4 × max_runtime)` are marked `interrupted`. Their unfinished items stay `discovered` and are retried. |
| Idempotent re-runs | The unique identity key and adoption of orphaned posts mean a re-run never duplicates a post. |

## Diagnosing a feed

Each run records its outcome in **Logs & Diagnostics → Import history**:

| Outcome | Meaning |
|---|---|
| `ok` | New items were imported |
| `not_modified` | HTTP 304 (conditional request) |
| `empty_feed` | Valid feed with no items |
| `all_duplicates` | Only previously imported items |
| `all_filtered` | New items were all filtered out by rules (media types, blocklist) |
| `unsupported_media` | New items only carried unsupported media (e.g. PDF) |
| `unreachable` | DNS or connection failure, or timeout (temporary, backs off) |
| `blocked_destination` | The SSRF guard refused the URL or a redirect target |
| `http_error` | 4xx/5xx (`Retry-After` honoured) |
| `too_large` | Exceeded the max feed size |
| `invalid_feed` | Not XML, unknown format, or corrupt XML |
| `publish_failure` | Items were found but none could be published |
| `locked` | Another runner holds the feed's lease |
| `blocked_source` | The feed URL is on the blocklist |

Each run also records these counters: fetched, new, duplicates, created, updated, awaiting review, media found, extraction failures, publish failures and skipped.

* Logs can be filtered by channel: import, parse, http, media, publish, schedule, retry, security, db, moderation and test.
* **Diagnostic report** (Logs & Diagnostics, or `wp rmh diagnostics`) is copyable and redacted.
* **Test a feed URL** runs a dry run and writes nothing.

## Caching

* **Purging.** Imports, updates and moderation actions purge the affected post with `clean_post_cache()` and with whichever of these caching plugins is active:
  * WP Super Cache (`wp_cache_post_change`);
  * W3 Total Cache (`w3tc_flush_post`);
  * WP Rocket (`rocket_clean_post`);
  * LiteSpeed (`litespeed_purge_post`);
  * SiteGround Optimizer (`sg_cachepress_purge_cache`).

  For CDN or Varnish purges, hook `rmh_purge_post_cache( $post_id, $urls )`.
* **REST** responses under `rmh/v1` send `Cache-Control: no-store, private`. Admin screens are never cached.
* **Server-mode age gate:**
  * gated pages send `Cache-Control: private, no-store` and define `DONOTCACHEPAGE`, so the gate and the unlocked page are never stored in shared caches;
  * if you need full-page caching on gated pages, use **overlay mode** (a deterrent only), or configure your cache to bypass when the `rmh_age_ok` cookie is present.
* **Geo rules** add a `Vary: <country header>` response header. Configure your CDN to vary its cache on that header.

<a id="scaling"></a>
## Scaling

| Knob | Default | Effect |
|---|---|---|
| Max feeds per run | 10 | Feeds polled per 5-minute tick (about 2,880 polls a day) |
| Max items per feed per run | 25 | Bounds the parse and publish work for each feed |
| Max processing time | 40 s | Hard budget per tick; leftover work moves to the next tick |
| Max article fetches | 5 | Optional per-feed HTML fetches |
| Media checks per run | 20 | Background HEAD probes |
| Downloads per run | 3 | Authorized local imports |

Expected limits on a typical host (PHP-FPM, MySQL, persistent object cache):

* about 500–1,000 feeds polled hourly with `feeds_per_tick` set to 40–80 and system cron;
* archives of hundreds of thousands of posts. Archive queries use core indexes plus taxonomy filters; the `_rmh_source_published` sort uses a meta join, so prefer *Newest* on very large archives.

Moving to queue-backed workers:

1. **Several runners.** Run several `wp rmh run` processes. They are safe to run in parallel because of the per-feed leases, and the global lock only serialises *ticks*. To parallelise, call `Importer::run_feed()` per feed from your own workers.
2. **Queue service.** Replace the tick with a queue (Action Scheduler, SQS, Redis). The `rmh_feed_processed` action and the `Importer`/`Scheduler` services are the integration points. Enqueue feed IDs whose `next_poll_at` is due, and consume them with `rmh()->get('importer')->run_feed( $id, 'worker' )`.
3. **Validation and downloads.** Offload them to separate workers using `MediaValidator::run_batch()` and `MediaDownloader::run_batch()`.

## Backup and restore

Back up the WordPress database (the `wp_rmh_*` tables, posts and options) and the uploads directory if you use local import. Feed credentials are encrypted with keys derived from WordPress salts: if the salts change, re-enter the credentials, or pin the key with `define( 'RMH_SECRET_KEY', '…' )`.

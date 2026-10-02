# API reference

## REST API (`/wp-json/rmh/v1`)

Authentication: a logged-in cookie plus an `X-WP-Nonce` header (nonce action `wp_rest`), or [application passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/). All responses are `no-store`.

| Method | Route | Capability | Description |
|---|---|---|---|
| GET | `/status` | rmh_manage_feeds | Version, scheduler state, counts |
| GET | `/feeds?status=&search=&page=&per_page=` | rmh_manage_feeds | List feeds (credentials never returned; `config.has_auth`) |
| POST | `/feeds` `{url, name?, status?, poll_interval?, config?}` | rmh_manage_feeds | Create (201). Returns 409 for a duplicate normalized URL and 400 for an invalid or prohibited URL. |
| GET / PATCH / DELETE | `/feeds/{id}` | rmh_manage_feeds | Read, update, delete. Delete keeps the feed's posts. |
| POST | `/feeds/{id}/test` | rmh_manage_feeds | Dry run: format, warnings, first 10 items with detected media |
| POST | `/feeds/test` `{url}` | rmh_manage_feeds | Dry-run any URL |
| POST | `/feeds/{id}/run` | rmh_manage_feeds | Queue an import (202) |
| GET | `/items?feed_id=&status=a,b&media_type=&search=` | rmh_moderate | Imported and queued items (`X-WP-Total` header) |
| POST | `/items/{id}/approve\|reject\|retry\|reprocess` | rmh_moderate | Queue actions |
| GET | `/media?media_type=&validation_status=&post_id=` | rmh_moderate | Media metadata |
| POST | `/media/{id}/revalidate` | rmh_moderate | HEAD or ranged-GET check |
| GET | `/jobs?feed_id=&status=` | rmh_manage_feeds | Import history |
| POST | `/jobs/retry-failed` | rmh_manage_feeds | Requeue failed items and failing feeds |
| GET / POST | `/blocklist` `{type: domain\|url\|guid\|keyword, value, reason?}` | rmh_manage_feeds | Source restrictions |
| DELETE | `/blocklist/{id}` | rmh_manage_feeds | Remove a restriction |
| POST | `/reports` `{post_id, reason, details?, contact?}` | public (rate-limited) | Report a post or request removal (202) |
| GET | `/reports?status=` | rmh_moderate | List reports |
| POST | `/views/{id}` | public (opt-in, rate-limited) | Aggregate view counter |

Example:

```bash
curl -u admin:APP_PASSWORD -X POST https://example.com/wp-json/rmh/v1/feeds \
  -d url=https://example.org/feed.xml -d name=Example -d 'config[import_mode]=pending'
```

## WP-CLI

```
wp rmh run [--feed=<id>] [--all]       # run due feeds (same as the cron tick)
wp rmh test <url> [--format=json]       # dry run
wp rmh feeds list
wp rmh feeds add <url> [--name=] [--mode=auto|pending|manual] [--media-only]
wp rmh feeds pause|resume|delete <id>
wp rmh revalidate [--limit=50]
wp rmh retry_failed
wp rmh status
wp rmh maintenance
wp rmh diagnostics
```

## Extension points

Register extensions on `rmh_loaded` (after the plugin boots).

| Hook | Type | Use |
|---|---|---|
| `rmh_feed_adapters` | filter (`FeedAdapterInterface[]`) | Add feed formats (`supports(DOMDocument)` / `parse()`) |
| `rmh_parsed_feed` | filter | Post-process a parsed document |
| `rmh_embed_providers` | filter (`EmbedProviderInterface[]`) | Add or remove embed providers (`match($url)` returns `embed_url`, `page_url`, `thumbnail`; `frame_origins()`; `sandbox()`) |
| `rmh_age_verifiers` | filter (`AgeVerifierInterface[]`) | Age-verification providers |
| `rmh_age_verified` | filter (bool) | Final verification decision |
| `rmh_visitor_country` | filter (string) | Country (or `CC-SUB`) for geo rules, e.g. from GeoIP |
| `rmh_publish_mode` | filter (`auto\|pending\|manual\|reject`) | Publishing rules per item |
| `rmh_before_publish_item` | action (throw to fail an item) | Pre-publish validation |
| `rmh_post_fields` / `rmh_insert_post_args` | filter | Customise post title, excerpt and content, or `wp_insert_post` args |
| `rmh_post_created` / `rmh_post_updated` | action | After a post is created or synced |
| `rmh_item_approved` / `rmh_item_rejected` / `rmh_item_removed` | action | Moderation events |
| `rmh_report_created` / `rmh_post_hidden` / `rmh_post_restored` | action | Report workflow |
| `rmh_media_validated` | action | After a media check (`post_id`, `media_id`, `status`) |
| `rmh_feed_processed` | action | After every feed run (`feed_id`, `job_id`, result, stats) |
| `rmh_url_guard_verdict` | filter (`true\|WP_Error`) | Extra outbound URL policy |
| `rmh_content_allowed_html` | filter | Adjust the content allowlist (be careful) |
| `rmh_media_html` / `rmh_attribution_html` | filter | Alter rendered media or attribution |
| `rmh_template_path` | filter | Template resolution |
| `rmh_share_links` | filter | Share links |
| `rmh_purge_post_cache` | action (`post_id`, `urls`) | CDN or proxy purges |
| `rmh_permalink_base` | filter | URL base (default `media`) |
| `rmh_migrations` | filter | Add versioned data migrations |
| `rmh_service_{id}` | filter | Replace any service (e.g. `transport`, `resolver`, `validator`) |
| `rmh_logged` | action | Forward redacted log rows to an external sink |
| `rmh_client_ip` | filter | Client IP behind a trusted proxy (rate limiting) |

**Media validation providers.** Replace the `validator` service:

```php
add_filter( 'rmh_service_validator', fn() => new My\Validator( ... ) );
```

It must expose `validate( $media_row )` and `run_batch( $limit, $older_than, $deadline )`.

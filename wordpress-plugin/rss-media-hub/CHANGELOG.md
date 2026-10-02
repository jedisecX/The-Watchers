# Changelog

## 1.0.0 (2026-10-02)

Initial release.

### Ingestion

* **Formats:** RSS 2.0, RSS 1.0/RDF and Atom adapters behind an extensible adapter interface, with Media RSS, Dublin Core, content:encoded and iTunes support.
* **XML hardening:** loading with no DTDs or entities, repair of common defects, and recovery of truncated documents.
* **Media pipeline:** a six-level priority order, HTML and Open Graph extraction, and allowlisted embed providers (YouTube via youtube-nocookie, Vimeo, Dailymotion, and custom providers limited to an exact host and path prefix).

### Import and publishing

* **Two-phase, idempotent import:** a unique identity hash, cross-feed canonical-URL de-duplication, and adoption of orphaned posts after a crash.
* **Publishing modes:** automatic, pending, manual and media-only, each overridable per feed.
* **Updates** never overwrite fields an editor has changed.

### Scheduling

* A 5-minute tick with a global lock and per-feed leases.
* Staggered polling with jitter, conditional GET, exponential backoff with `Retry-After`, item retries, and recovery of stale jobs.

### Reader

* Reader at `/media/` with search and filters (type, source, category, tag, sort), grid and list views, pagination with load-more, and a source directory at `/media/sources/`.
* Single pages with an HTML5 video or audio player, a gallery with a keyboard-accessible lightbox, sandboxed embeds, and fallbacks.
* Related posts, previous/next navigation, share links and a report form.
* Works with block themes and classic themes; templates can be overridden from the theme.

### Media handling

* Remote, link-only, hybrid and authorized-local media handling.
* Background validation (HEAD, then a ranged GET) and verified downloads with a quota.

### Compliance and moderation

* Ratings, an RTA label and content warnings.
* An age gate (server or overlay mode) with a verifier interface.
* Geo rules returning HTTP 451.
* Reports with immediate escalation, takedowns, a blocklist and removal requests.

### Administration and APIs

* Admin pages: Dashboard, Feed Manager, Import Queue, Media Library, Reports & Moderation, Source Restrictions, Settings and Logs & Diagnostics, including a redacted diagnostic report.
* REST API `rmh/v1`, a WP-CLI `rmh` command, and extension hooks.

### Security and operations

* SSRF guard with IP pinning, strict sanitisation, frame-src CSP, nonces and capabilities, encrypted credentials, and redacted logs.
* Cache-plugin purges and no-store dynamic responses.
* Uninstall modes that keep content by default.

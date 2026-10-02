=== RSS Media Hub ===
Contributors: thewatchers
Tags: rss, atom, aggregator, media, video
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure RSS/Atom media aggregator and reader: imports feed items, extracts video, audio, galleries and trusted embeds, and publishes each item on its own page.

== Description ==

RSS Media Hub turns RSS 2.0, RSS 1.0/RDF and Atom feeds into a browsable media site.

* Media RSS, enclosures, HTML and optional article-page extraction, with classification as video, audio, image, gallery, external embed or article.
* Each item gets its own permalink (/media/slug/) with an HTML5 video or audio player, a gallery with lightbox, or a sandboxed embed from allowlisted providers, plus a working fallback.
* Import modes: automatic, pending review, manual approval, media-only, and per-feed overrides.
* Duplicate detection by GUID, canonical URL and fingerprint; updates never overwrite editor changes.
* Scheduled imports with WP-Cron or system cron, overlap locks, staggering, exponential backoff, retries and crash recovery.
* Admin screens: Dashboard, Feed Manager, Import Queue, Media Library, Reports & Moderation, Source Restrictions, Settings, Logs & Diagnostics.
* Adult-site controls: content ratings, warnings, RTA label, age gate with a verification-provider interface, jurisdiction rules (HTTP 451), reporting with immediate escalation of suspected CSAM or non-consensual content.
* Rights-aware defaults: excerpt only, remote embedding, visible attribution, and local downloads only with a confirmed permission.
* Security: SSRF protection with DNS pinning, XXE-safe parsing, strict HTML sanitisation, nonces, capabilities, prepared SQL and redacted logs.
* REST API (rmh/v1), WP-CLI (wp rmh), and extension hooks.

== Installation ==

1. Upload the ZIP via Plugins → Add New → Upload Plugin and activate it.
2. Open RSS Media Hub → Feed Manager → Add feed, then click "Save and test".
3. Recommended: set DISABLE_WP_CRON and run `wp rmh run` every 5 minutes from system cron.

== Frequently Asked Questions ==

= Does the age gate make my site legally compliant? =
No. The built-in self-declaration is a content warning. Integrate a real age-verification provider through the AgeVerifierInterface where the law requires it.

= Does it download videos? =
Only for feeds set to "Authorized local import" where an administrator has confirmed they hold the rights. By default media plays from its source.

= What happens on uninstall? =
By default nothing is deleted. Choose "Keep imported posts" or "Delete everything" in Settings before uninstalling to remove data.

== Changelog ==

= 1.0.0 =
* Initial release.

# Testing

## How to run

```bash
# 1. Disposable WordPress (SQLite, no MySQL needed) with the plugin symlinked and active
tests/bin/install-wp-tests.sh /tmp/rmh-test 6.8.3 localhost:8089

# 2. PHPUnit 10 (composer require --dev phpunit/phpunit:^10.5)
RMH_TEST_WP_PATH=/tmp/rmh-test/site vendor/bin/phpunit

# 3. Coding standards / static analysis (wp-coding-standards/wpcs ^3.1, phpcompatibility/phpcompatibility-wp)
vendor/bin/phpcs

# 4. Browser E2E (playwright-core + Chromium)
cp tests/e2e/rmh-e2e-mocks.php /tmp/rmh-test/site/wp-content/mu-plugins/
php -S localhost:8089 -t /tmp/rmh-test/site /tmp/rmh-test/site/router.php &
RMH_MEDIA_DIR=/path/to/sample-media node tests/e2e/run.mjs
```

The tests never contact a real host:

* **PHPUnit** swaps the HTTP transport and DNS resolver for in-memory mocks (`tests/Support`).
* **E2E** uses a must-use plugin that serves `tests/fixtures`. The browser's media requests to the fictional `*.example` hosts are answered by Playwright with synthetic clips and images generated with ffmpeg.

All fixtures are synthetic; no real adult content is used or downloaded. Tests run against a throwaway site and never publish to production.

## Results (recorded 2026-10-02, PHP 8.3.6, WordPress 6.8.3 on SQLite)

| Suite | Result |
|---|---|
| PHPUnit (`tests/Unit`, `tests/Integration`) | **127 tests, 847 assertions: all passing** |
| Browser E2E (`tests/e2e/run.mjs`, Chromium 141, 390×844 mobile viewport + 1280×900 admin) | **53/53 checks passing** |
| PHPCS (WordPress-Extra + WordPress.Security + PHPCompatibilityWP 7.4+) | **0 errors**, 9 advisory warnings (unused hook parameters, the intentional 5-minute cron schedule, a batch size of 200 in uninstall) |
| `php -l` on every file | clean |
| Same suite on a freshly built install (`install-wp-tests.sh`) | 127/127 passing |

Defects that the suites found during development and that were fixed:

* **Date parsing:** day names in RFC 822 dates silently shifted dates by up to 6 days.
* **Malformed XML:** libxml recovery mode accepted structurally corrupt XML.
* **URL guard:** a trailing NUL byte was trimmed instead of rejected.
* **Outcome classification:** a feed whose new items were all skipped was reported as `all_duplicates` instead of `unsupported_media` or `all_filtered`.
* **CSP:** the `child-src` directive blocked WordPress core's emoji web worker.
* **DOM clobbering:** `form.action` broke the in-place report form.
* **Block source:** the "block source" moderation action failed when the item rows had been purged.

## Required scenarios → tests

| # | Scenario | Test(s) |
|---|---|---|
| 1 | RSS with video enclosure | `ImportTest::test_scenario_1_video_enclosure_creates_published_post_with_player`, E2E "video loads metadata" |
| 2 | Audio enclosure | `ImportTest::test_scenario_2_audio_enclosure_renders_audio_player`, E2E "audio player rendered" |
| 3 | Multiple images | `ImportTest::test_scenario_3_multiple_images_render_gallery`, `MediaExtractionTest::test_multiple_images_become_a_gallery`, E2E gallery + lightbox |
| 4 | Media RSS thumbnail + video | `ImportTest::test_scenario_4_media_rss_video_with_thumbnail`, `ParserTest::test_media_rss_group` |
| 5 | Atom feed | `ParserTest::test_scenario_5_atom_entries`, `ImportTest::test_scenario_5_atom_feed_import` |
| 6 | Item with no media | `ImportTest::test_scenario_6_item_without_media`, `MediaExtractionTest::test_no_media_gets_fallback_link` |
| 7 | Missing optional metadata | `ParserTest::test_scenario_7_missing_optional_metadata`, `ImportTest::test_scenario_7_missing_optional_metadata_still_imports` |
| 8 | Invalid publication date | `ParserTest::test_scenario_8_invalid_and_future_dates`, `ImportTest::test_scenario_8_invalid_date_uses_import_time_and_is_logged` |
| 9 | Malformed XML | `ParserTest::test_scenario_9_malformed_xml_is_rejected`, `…truncated…`, `…recoverable…`, `FailureRecoveryTest::test_scenario_9_malformed_feed_is_reported_and_other_feeds_continue` |
| 10 | Unreachable feed | `FailureRecoveryTest::test_scenario_10_unreachable_feed_backs_off` |
| 11 | Temporary error then success | `FailureRecoveryTest::test_scenario_11_temporary_http_error_then_success`, `…long_retry_after…` |
| 12 | Duplicate GUIDs | `ImportTest::test_scenario_12_duplicate_guids_create_one_post` |
| 13 | Duplicate canonical URLs | `ImportTest::test_scenario_13_duplicate_canonical_urls_create_one_post` |
| 14 | Updates without duplicates | `ImportTest::test_scenario_14_updates_never_duplicate_and_respect_editor_changes`, `…update_policy_none…` |
| 15 | Blocked private destination | `UrlGuardTest::test_non_public_addresses_are_refused` (23 ranges), `…hostnames_resolving_to_private_ranges…`, `…scenario_15_private_feed_url_is_never_requested` |
| 16 | Redirect to prohibited address | `UrlGuardTest::test_scenario_16_redirect_to_prohibited_address_is_blocked` |
| 17 | Unsafe iframe URL | `MediaExtractionTest::test_scenario_17_untrusted_iframes_are_not_embedded`, `MediaRenderTest::test_scenario_17_only_allowlisted_embeds_render_with_sandbox`, E2E "untrusted iframe not rendered" |
| 18 | Malicious HTML | `SanitizerTest::test_malicious_description_is_neutralized`, `MediaRenderTest::test_scenario_18_malicious_html_never_reaches_the_page` |
| 19 | Unexpected MIME | `MediaRenderTest::test_scenario_19_unexpected_mime_marks_media_invalid_and_renders_fallback` |
| 20 | Media disappears after import | `MediaRenderTest::test_scenario_20_and_27_disappeared_media_keeps_post_with_working_fallback` |
| 21 | Failure halfway through a batch | `FailureRecoveryTest::test_scenario_21_failure_halfway_through_a_batch_is_isolated_and_retried`, `…crash_after_post_insert…`, `…fail_permanently_after_max_attempts` |
| 22 | Overlapping scheduled jobs | `FailureRecoveryTest::test_scenario_22_overlapping_runs_are_prevented`, `…expired_lock_is_taken_over…` |
| 23 | Unauthorized admin requests | `AdminSecurityTest::test_scenario_23_unauthorized_users_are_refused_everywhere`, `…rest_permission_matrix`, `…every_route_has_a_permission_callback` |
| 24 | Missing/invalid CSRF protection | `AdminSecurityTest::test_scenario_24_missing_or_invalid_nonce_is_rejected`, E2E "REST cookie request without nonce rejected", "admin-post with forged nonce rejected" |
| 25 | Manual review feed | `ImportTest::test_scenario_25_manual_review_feed`, `…pending_review_mode…` |
| 26 | Media-only feed | `ImportTest::test_scenario_26_media_only_feed` |
| 27 | Unavailable media with fallback link | `MediaRenderTest::test_scenario_20_and_27_…`, E2E "untrusted-iframe-fallback" screenshot |
| 28 | Upgrade preserves posts | `LifecycleTest::test_scenario_28_upgrade_preserves_imported_posts` |
| 29 | Caching-plugin interaction | `LifecycleTest::test_scenario_29_cache_plugins_are_purged_and_dynamic_responses_not_cached`, `MediaRenderTest::test_gate_page_renders_without_media_and_is_uncacheable`, E2E "REST responses are no-store", "gate response not cacheable" |
| 30 | Mobile rendering with embedded media | `MediaRenderTest::test_scenario_30_markup_is_responsive_and_accessible`, E2E (390×844): video/embed fit the viewport, no horizontal overflow on archive/single/admin, lightbox keyboard navigation |

Additional coverage includes:

* XXE and entity expansion;
* credential encryption at rest;
* log redaction;
* settings sanitisation;
* the custom adapter extension point;
* provider URL matching (including look-alike hosts);
* authorized local import with magic-byte checks, and refusal when rights are not confirmed;
* age gate cookie forgery;
* geo 451 rules;
* report rate limits, urgent auto-hide and takedown re-import prevention;
* uninstall modes;
* conditional GET;
* staggering;
* paused feeds;
* cron self-healing;
* manual runs being non-blocking;
* retention.

# Security model

Everything that comes from a feed is untrusted: feed URLs, XML, titles, descriptions, article HTML, media URLs, embed markup and HTTP headers.

## Outbound requests (SSRF)

Every outbound request goes through `HttpClient`, which calls `UrlGuard::check()`. The guard rejects any URL that fails one of these checks:

* **Scheme and URL form:**
  * `http` or `https` only;
  * no userinfo (credentials) in the URL;
  * no control characters or NUL bytes;
  * at most 4096 characters.
* **Port:** must be in the allowlist (default `80,443`).
* **Hostname:**
  * the name must be syntactically valid;
  * internal names are refused: `localhost`, `*.localhost`, `.local`, `.internal`, `.lan`, `.home.arpa`, `.corp`, `.localdomain`;
  * non-canonical numeric hosts are refused: `2130706433`, `0x7f.1`, `0177.0.0.1`, `127.1`.
* **Resolved addresses:** the host is resolved for both A and AAAA records, and **every** answer must be public. One bad answer taints the host. Refused ranges:
  * **IPv4:** `0/8`, `10/8`, `100.64/10`, `127/8`, `169.254/16`, `172.16/12`, `192.0.0/24`, `192.0.2/24`, `192.88.99/24`, `192.168/16`, `198.18/15`, `198.51.100/24`, `203.0.113/24`, `224/4`, `240/4`, broadcast.
  * **IPv6:** `::`, `::1`, `::/96`, `100::/64`, `2001::/23`, `2001:db8::/32`, `2002::/16`, `fc00::/7`, `fe80::/10`, `fec0::/10`, `ff00::/8`.
  * **Embedded IPv4:** IPv4-mapped and NAT64 addresses are judged by the IPv4 address they embed.
* **Final veto:** the `rmh_url_guard_verdict` filter can refuse any URL.

How requests are sent:

* **IP pinning:** the validated IP is passed to the transport. With cURL, `WpTransport` sets `CURLOPT_RESOLVE`, so the connection cannot be redirected by a second DNS answer (DNS rebinding). Only `http` and `https` protocols are permitted.
* **Redirects** are followed manually. Every hop is validated again, credentials are dropped when a redirect crosses origins, and the number of hops is limited.
* **Response limits:** response size (`limit_response_size` plus `Content-Length`) and timeouts. Retries happen only for temporary failures (408, 425, 429, 5xx, connection errors), with backoff. A long `Retry-After` defers to feed-level backoff instead of retrying.
* **WordPress's own check:** WordPress's `reject_unsafe_urls` is also enabled as defence in depth.

## XML

* **XXE and entity expansion:** any document that contains `<!ENTITY` is refused, which covers XXE and entity-expansion ("billion laughs") attacks. The DOCTYPE is removed.
* **Parser flags:** `LIBXML_NONET`, no entity substitution, and no external DTDs.
* **Size limit** is enforced before parsing.
* **Repair:**
  * documents with stray `&` or HTML named entities are repaired, with a warning;
  * recovery mode is accepted only for truncated documents;
  * structurally corrupt documents (mismatched tags) are rejected and logged as `invalid_feed`.

## HTML and output

* **Content sanitisation.** `Sanitizer::content()` first removes `script`, `style`, `iframe`, `object`, `embed`, `svg`, `math`, `form`, `base`, `meta`, `link`, `template` and media elements together with their content. It then applies a strict `wp_kses` allowlist that permits only http(s) URLs and no event handlers, `style`, `srcset` or `data:` URIs.
* **Links in imported content:**
  * relative URLs are absolutised against the *source*, never against this site;
  * links get `rel="nofollow ugc noopener noreferrer"` and `target="_blank"`.
* **Titles and excerpts** are stored as plain text.
* **Stored snapshots.** The raw feed HTML is never stored. Item payload snapshots are sanitized before insert.
* **Embeds:**
  * an iframe is only ever built by a provider adapter (YouTube via youtube-nocookie, Vimeo, Dailymotion, or a custom provider limited to one exact https host and a path prefix);
  * the embed is re-validated at render time, so disabling a provider or tampering with the stored URL removes the iframe;
  * the iframe carries `sandbox`, `loading="lazy"` and `referrerpolicy`;
  * media pages send `Content-Security-Policy: frame-src <allowlisted origins>; object-src 'none'; base-uri 'self'; form-action 'self'`, plus `nosniff` and a `Referrer-Policy`.
* **Escaping.** All template output is escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`). PHPCS with the WordPress security sniffs reports 0 errors.
* **Front-end JavaScript** never inserts feed data as HTML. It only clones server-rendered, escaped markup, and reads the form action through `getAttribute()` to avoid DOM clobbering.

## Requests into WordPress

* **admin-post handlers:**
  * a per-action nonce (`check_admin_referer`), checked after the capability check;
  * there are no `nopriv` variants for admin actions;
  * every ID is passed through `absint`, and every value is sanitized.
* **REST routes:**
  * every route has a `permission_callback`;
  * management routes use cookie authentication plus `X-WP-Nonce`, or application passwords. Without a nonce, core downgrades the request to anonymous, which gets 401;
  * responses carry `Cache-Control: no-store, private`.
* **Capabilities:**

  | Capability | Granted to | Allows |
  |---|---|---|
  | `rmh_manage_feeds` | administrators | feeds, settings tools, restrictions, logs |
  | `rmh_moderate` | administrators and editors | queue, media, reports |
  | `manage_options` | administrators | Settings page |

* **Public endpoints** (reports, view counts):
  * validated input, a honeypot field, and rate limits keyed by an HMAC of the client IP;
  * the raw IP is never stored, and duplicate reports are de-duplicated;
  * a broken-media report never triggers a synchronous outbound fetch, so it cannot be used for amplification.
* **SQL:** `$wpdb->prepare()` everywhere. Dynamic `WHERE` clauses are built only from constant fragments and placeholders.

## Secrets and logs

* **Feed credentials** are encrypted with libsodium `secretbox`. The key is derived from `AUTH_KEY`/`SECURE_AUTH_KEY`, or from the `RMH_SECRET_KEY` constant if defined. Credentials are never returned by REST or shown in diagnostics, and are only sent to the feed's own origin.
* **Log redaction.** Before storage, `Redactor` removes URL userinfo, sensitive query values (`token`, `key`, `sig`, `auth`, `password`, …), `Bearer`/`Basic` credentials and `key=value` secrets, and recursively masks sensitive context keys. The diagnostic report contains no salts, credentials or visitor data.

## Content-safety posture

* Content classification is never inferred from domains.
* Suspected CSAM or non-consensual content is unpublished on report and escalated, with the evidence preserved. See [MODERATION.md](MODERATION.md).
* Public RSS availability is **not** treated as permission to republish or mirror. The defaults are excerpt-only and remote embedding, and local storage requires an explicit rights confirmation for each feed.

## Residual risks

* Pinning requires the cURL transport.
* The self-declaration age gate is not age verification.
* Geo rules trust the configured header. Use only a header your edge proxy overwrites.
* Embedded third-party players run their own scripts. They are sandboxed in a cross-origin iframe, but they can still track viewers. Disable providers if that is a concern.

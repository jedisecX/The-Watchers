# Security headers and why each exists

Headers are set by `SecurityHeadersMiddleware` (`backend/app/middleware.py`). In production nginx sets the
same values for static files (`deploy/nginx/security_headers.conf`, and `spa_headers.conf`, which is
*generated* from the app's `frontend_csp()`; CI fails on drift). Routes may set a *stricter* value, and the
middleware never duplicates or weakens one.

| Header | Value | Purpose / threat addressed |
|---|---|---|
| `Content-Security-Policy` (SPA) | `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob:; media-src 'self' blob:; font-src 'self'; connect-src 'self' wss://HOST; manifest-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'; require-trusted-types-for 'script'; trusted-types 'none'; report-uri /api/v1/csp-report; upgrade-insecure-requests` | Main XSS containment. No inline or `eval` script, so an injected `<script>` or `onerror=` cannot run. `base-uri 'none'` stops `<base>` hijacking of relative script URLs. `form-action 'self'` stops exfiltration via injected forms. `object-src 'none'` kills plugin vectors. **Trusted Types with no policies** makes every string→DOM-sink assignment (`innerHTML`, `eval`, string timers, `script.src`) throw, which removes DOM XSS by construction in Chromium browsers. `connect-src` limits where a compromised script could send data. |
| `Content-Security-Policy` (API) | `default-src 'none'; frame-ancestors 'none'; sandbox` | If a JSON response were ever rendered as a document (MIME confusion, old browsers), it can load nothing, run nothing, and has an opaque origin. |
| `Content-Security-Policy` (media) | `default-src 'none'; sandbox` | Even if a hostile file were somehow served, opening it directly gives a sandboxed, script-less document on an opaque origin. |
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains` (+`preload` via `HSTS_PRELOAD`) | Stops SSL-stripping and cookie leakage over HTTP after the first visit. Only sent when `PUBLIC_BASE_URL` is https. Add `preload` only once every subdomain is HTTPS-only. |
| `X-Content-Type-Options` | `nosniff` | Browsers must honour the declared type, so a crafted upload can't be sniffed into HTML or script. |
| `X-Frame-Options` | `DENY` | Clickjacking defence for browsers that predate `frame-ancestors`. |
| `Referrer-Policy` | `same-origin` | No URL paths or identifiers leak to external sites via outbound links. |
| `Permissions-Policy` | camera, mic, geolocation, sensors, payment, USB, FLoC/Topics = `()`; WebAuthn = `(self)` | Removes powerful features the app never needs, so injected content can't ask for them. Passkeys stay available. |
| `Cross-Origin-Opener-Policy` | `same-origin` | Isolates the browsing context from cross-origin windows (reverse tabnabbing, XS-Leaks via `window.opener`). |
| `Cross-Origin-Resource-Policy` | `same-origin` | Other sites cannot embed our API responses or media (Spectre-style and XS-Leak cross-origin reads). |
| `X-Permitted-Cross-Domain-Policies` | `none` | Legacy Flash/PDF cross-domain policy files are ignored. |
| `Cache-Control` (API) | `no-store` | Personalised responses are never stored by browsers, proxies, or CDNs (cache deception). |
| `Cache-Control` (media) | `private, max-age=300` + `Vary: Cookie, Authorization` | Authorised media may be cached by the viewer's browser only, never by shared caches, because authorization is per-viewer and can change (blocks, privacy). |
| `Content-Disposition` (media) | `inline; filename="media.webp"` | Fixed, server-chosen filename. No user-controlled names reach headers (header injection). |
| `X-Request-ID` | random per request | Correlates client reports, nginx logs and app logs during incident response. Not a secret. |
| *(removed)* `Server` | — | Uvicorn runs with `--no-server-header` and nginx with `server_tokens off`. Less fingerprinting (defence in depth, not a control). |

Deliberately **absent**: `Access-Control-Allow-*`. The API is same-origin only; there is no CORS.
`X-XSS-Protection` is also absent: it is deprecated and its auditor could itself be abused, so CSP replaces it.

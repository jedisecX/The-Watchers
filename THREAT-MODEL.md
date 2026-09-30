# Bastion — Threat Model

Living document. Update it whenever a feature, dependency or trust boundary changes.
Architecture and diagrams are in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
Test IDs in brackets (e.g. `[T-AUTHZ-03]`) point to automated tests in `backend/tests/`
(see the mapping in `SECURITY.md`).

Methodology: STRIDE per element, supplemented by OWASP ASVS 4.0.3 (L2 target, L3 for
auth/admin), OWASP Top 10 (2021), OWASP API Security Top 10 (2023), relevant CWEs,
abuse/misuse cases, LINDDUN-style privacy threats, and supply-chain (SLSA) threats.

---

## 1. Assets

| ID | Asset | Why it matters |
|---|---|---|
| A1 | User credentials (password hashes, TOTP secrets, recovery codes, passkeys) | Account takeover at scale |
| A2 | Session and API tokens | Direct account takeover |
| A3 | Private content: DMs, followers-only posts, private accounts, email addresses | Privacy harm, harassment, legal exposure |
| A4 | Social graph (who follows/blocks whom) | Stalking, harassment targeting |
| A5 | Admin/moderator capability | Platform-wide compromise |
| A6 | Audit log integrity | Forensics, accountability |
| A7 | Service availability | Users rely on the platform |
| A8 | Platform integrity (no spam/bots, trustworthy counts) | Trust, abuse resistance |
| A9 | Secrets (DB passwords, field-encryption key, SMTP creds, TLS key) | Lateral movement |
| A10 | Build pipeline and dependencies | Supply-chain compromise |

## 2. Actors

| Actor | Capability assumed |
|---|---|
| Anonymous Internet attacker | Arbitrary HTTP/WS traffic, botnets, residential proxies, leaked credential lists |
| Malicious registered user | Valid account(s), can create many (sock puppets), knows the API well |
| Harasser | Targets a specific user, and will evade blocks through new accounts |
| Compromised user account | Legitimate session in attacker hands |
| Malicious/compromised moderator | Moderator role, possibly a stolen session |
| Compromised internal component | Code execution in the media worker, or read access to Redis |
| Malicious dependency / CI actor | Code executed at build or install time |
| Curious insider (ops) | Read access to logs |

---

## 3. Attack-surface inventory

| # | Surface | Entry point | Auth | Notes |
|---|---|---|---|---|
| S1 | Registration | `POST /api/v1/auth/register` | none | Bot signups, enumeration, weak passwords |
| S2 | Login (password) | `POST /api/v1/auth/login` | none | Brute force, stuffing, timing enumeration |
| S3 | MFA verify | `POST /api/v1/auth/login/mfa` | pending-MFA token | TOTP brute force, replay |
| S4 | Passkey login | `POST /api/v1/auth/passkey/login/{begin,finish}` | none | Challenge replay, origin/RP confusion |
| S5 | Email verification | `POST /api/v1/auth/verify-email` | token | Token guessing, reuse |
| S6 | Password reset | `POST /api/v1/auth/password-reset/{request,confirm}` | token | Enumeration, token reuse, host-header poisoning |
| S7 | Session management | `/api/v1/sessions*`, logout | session | Fixation, theft, revocation gaps |
| S8 | MFA / passkey / PAT management | `/api/v1/account/mfa/*`, `/passkeys/*`, `/tokens/*` | session + password/step-up | Silent MFA removal by a session thief |
| S9 | Profile & settings | `/api/v1/me`, `/api/v1/users/{handle}` | mixed | Mass assignment, privacy leaks |
| S10 | Posts, replies, likes, reposts | `/api/v1/posts*` | mixed | IDOR, XSS, race conditions, visibility bypass |
| S11 | Social graph | `/api/v1/users/{handle}/{follow,block,mute}`, follow requests | session | Self-follow, block bypass, mass follow |
| S12 | Timelines / search / hashtags | `/api/v1/timeline`, `/api/v1/search`, `/api/v1/hashtags/*` | mixed | Scraping, private data leakage, ReDoS, LIKE injection |
| S13 | Notifications | `/api/v1/notifications*` | session | Cross-user leaks |
| S14 | Direct messages | `/api/v1/conversations*` | session | BOLA on conversation IDs, spam, block bypass |
| S15 | Media upload | `PUT /api/v1/media` (raw body) | session, verified | Polyglots, bombs, RCE in decoders, traversal |
| S16 | Media serving | `GET /api/v1/media/{id}/{variant}` | mixed | Unauthorized access, MIME sniffing XSS |
| S17 | WebSocket | `GET /api/v1/ws` (upgrade) | session / ticket | CSWSH, subscription bypass, flooding |
| S18 | Reports | `POST /api/v1/reports` | session | Report-bombing, abuse of moderation |
| S19 | Admin API | `/admin/api/v1/*` | mod/admin + MFA step-up | Privilege escalation, CSRF, destructive mistakes |
| S20 | Data export / deletion | `/api/v1/account/export`, `/api/v1/account/delete` | session + re-auth | Data exfiltration by a session thief |
| S21 | API tokens (PAT) | `Authorization: Bearer` | PAT | Scope escalation, token leakage |
| S22 | Static frontend | `/`, `/assets/*` | none | DOM XSS, clickjacking |
| S23 | Outbound email | SMTP | n/a | Header injection, link poisoning |
| S24 | CI/CD & dependencies | GitHub Actions, PyPI | n/a | Supply chain |
| S25 | Operational interfaces | Postgres, Redis, logs, backups | infra | Exposure, credential reuse |

Deliberately **absent** surfaces (attack-surface reduction):
- No HTML/Markdown rendering of UGC.
- No SVG.
- No multipart parser.
- No URL-fetching/unfurling (no SSRF surface).
- No open redirect endpoint.
- No server-side templates rendering user data.
- No shell invocation (ffmpeg is called with argument vectors).
- No file paths derived from user input.
- No GraphQL.
- No JWT.
- No `eval`.

---

## 4. Security assumptions

Each assumption is a risk if it turns out false. They are listed so they can be re-verified.

1. TLS terminates at our reverse proxy, and HSTS preload is configured for the production apex domain.
2. `TRUSTED_PROXIES` accurately lists the proxy addresses. Otherwise `X-Forwarded-For` spoofing could evade IP rate limits.
3. The host OS, container runtime and kernel are patched. Container isolation is a *mitigation*, not a sandbox guarantee.
4. PostgreSQL and Redis are reachable only on internal networks.
5. Secrets are injected at runtime (env / mounted files) and never baked into images.
6. The system clock is NTP-synchronised. TOTP and token expiry depend on it.
7. Users' email accounts are reasonably secure. Email is a recovery channel. MFA users are protected because reset **does not** remove MFA.
8. Browsers enforce `SameSite`, CSP, and `__Host-` cookie semantics (all evergreen browsers do). Trusted Types is defence-in-depth, currently Chromium-only.
9. Pillow/libwebp/libjpeg/ffmpeg will have future CVEs. We assume the decoder **can** be compromised, and TB3 is designed for that.
10. The single-region Postgres is the source of truth. Backups are encrypted and stored off-host.

---

## 5. Security requirements (ASVS-mapped)

| ID | Requirement | ASVS |
|---|---|---|
| R-AUTH-1 | Passwords hashed with argon2id (m=64 MiB, t=3, p=4 by default); length 12–256; checked against a common-password list and user context; no composition rules | V2.1, V2.4 |
| R-AUTH-2 | Uniform login errors and timing whether or not the account exists | V2.2.1, V3 |
| R-AUTH-3 | Progressive throttling per account, per IP, and per account+IP; no hard lockout that enables lockout DoS | V2.2.1 |
| R-AUTH-4 | Verification/reset tokens: ≥128-bit, hashed at rest, purpose-bound, single-use, short TTL (verify 24 h, reset 30 min) | V2.5, V2.7 |
| R-AUTH-5 | Password reset revokes all sessions and PATs, and never disables MFA | V2.5.x |
| R-AUTH-6 | TOTP with replay prevention (last used step stored), ±1 step skew; recovery codes single-use | V2.8 |
| R-AUTH-7 | WebAuthn: RP ID + origin bound, challenge single-use with 5-minute TTL, sign-count regression flagged | V2.8.x |
| R-SESS-1 | Sessions: 256-bit random, hashed at rest, `__Host-` HttpOnly Secure SameSite=Lax cookie; rotated on login, MFA completion and privilege change; idle (7 d user / 30 min admin) and absolute (30 d) timeouts | V3 |
| R-SESS-2 | Users can list and revoke sessions. Revocation reaches live WebSocket connections within seconds | V3.3 |
| R-CSRF-1 | All cookie-authenticated unsafe requests need a synchronizer token and an allowed `Origin` | V4.2.2, V13.2.3 |
| R-AUTHZ-1 | Every operation passes through the central policy; list queries use the shared visibility clause | V4.1, API1, API5 |
| R-AUTHZ-2 | Request schemas forbid unknown fields (no mass assignment); role/state fields are never client-writable | V5.1.2, API3 |
| R-INPUT-1 | Strict JSON: no duplicate keys, no NaN/Infinity, depth ≤ 32, body limits per route; duplicate query keys rejected | V5.1, V13.1 |
| R-OUT-1 | UGC is plaintext and rendered via DOM text APIs; CSP without `unsafe-inline`/`unsafe-eval`; Trusted Types enforced | V5.3, V14.4 |
| R-FILE-1 | Upload pipeline: see §9 in `SECURITY.md` (sniff, size, pixel limits, isolated decode, re-encode, metadata strip, controlled serving) | V12 |
| R-API-1 | Uniform error envelope, no stack traces; every endpoint has auth, authz, schema and rate limit declared | V7.4, V13 |
| R-RL-1 | Adaptive rate limits keyed by user trust level, IP, and endpoint cost | API4 |
| R-LOG-1 | Security events logged as structured JSON with secrets redacted; audit log hash-chained and INSERT-only | V7 |
| R-PRIV-1 | Privacy controls: account privacy, post visibility, DM permission, discoverability, likes visibility; data export; account deletion with 30-day purge | V8, GDPR |
| R-ADM-1 | Admin API separated; MFA required plus step-up within 15 min; destructive actions need typed confirmation; audited | V4.3 |
| R-SC-1 | Hash-pinned dependencies, SBOM, dependency/secret/container scanning in CI, blocking on high/critical | V14.2, V10.3 |

---

## 6. Threats (STRIDE + OWASP), in the required chain format

Format: **Threat → Attack path → Impact → Mitigation → Detection → Recovery**.

### 6.1 Spoofing

**S-1 Credential stuffing / brute force** (CWE-307, API2, ASVS V2.2)
- **Attack path:** A botnet replays breached email/password pairs against `POST /auth/login` from thousands of IPs.
- **Impact:** Mass account takeover (A1, A2, A3).
- **Mitigation:**
  - Argon2id makes offline cracking expensive.
  - Per-IP, per-account and per-account+IP sliding-window limits.
  - Account-level progressive delay tracked in the DB (`failed_login_count`, `login_locked_until`, exponential up to 15 min). It is not a permanent lock, so an attacker cannot lock victims out indefinitely.
  - Global login-failure circuit metric.
  - Uniform error + dummy hash on unknown accounts.
  - Common-password rejection at registration.
  - MFA / passkeys.
  - New-device login notification email.
- **Detection:** `auth.login_failed` events with IP prefix and account; alerting on the failure-rate ratio per /24 and globally; `auth.login_throttled` events.
- **Recovery:** Force password reset for affected accounts (admin `revoke-sessions` + reset); raise the global limit tier; block ASN/IP ranges at the proxy.
- **Tests:** `[T-AUTH-BRUTE]`, `[T-AUTH-ENUM]`.

**S-2 Session fixation** (CWE-384)
- **Attack path:** An attacker plants a known session cookie (via a subdomain or network position) before the victim logs in.
- **Impact:** Takeover of the victim's session.
- **Mitigation:**
  - `__Host-` prefix, so the cookie cannot be set from subdomains or without Secure.
  - A new session token is always issued on login and on MFA completion. Any pre-existing session cookie is revoked.
  - Tokens are server-generated, and unknown tokens are ignored rather than adopted.
- **Detection:** A session used from a new IP prefix/UA is flagged in the `auth.session_anomaly` log.
- **Recovery:** "Log out everywhere"; admin revocation.
- **Tests:** `[T-AUTH-FIXATION]`.

**S-3 Session theft / replay** (CWE-613, CWE-294)
- **Attack path:** A cookie is stolen via malware, a shared device, or an XSS (mitigated separately), then replayed.
- **Impact:** Account takeover.
- **Mitigation:**
  - HttpOnly, so script cannot read it.
  - Idle and absolute expiry.
  - Session list with device/IP-prefix/last-seen, and per-session revocation.
  - Sensitive operations (password/email/MFA change, PAT creation, export, delete) need **re-authentication** (password or passkey), so a stolen cookie alone cannot lock the owner out or remove MFA.
  - The admin API needs MFA step-up within 15 minutes.
- **Detection:** Concurrent use from distant IP prefixes; `auth.reauth_failed`.
- **Recovery:** Revoke all sessions (the user or an admin can); password change revokes everything.
- **Tests:** `[T-AUTH-REVOKE]`, `[T-AUTH-REAUTH]`.

**S-4 MFA bypass** (CWE-287, CWE-308)
- **Attack path:**
  - (a) Call post-login endpoints with a "password-OK, MFA-pending" state.
  - (b) Brute-force 6-digit TOTP codes.
  - (c) Replay an observed TOTP code.
  - (d) Use password reset to strip MFA.
  - (e) Disable MFA using only a stolen session.
- **Impact:** Account takeover despite MFA.
- **Mitigation:**
  - (a) Password success on an MFA account yields only a *pending-MFA token*: a separate, 5-minute, single-purpose record that is **not** a session. No session exists until the second factor succeeds.
  - (b) 5 attempts per pending token, then it is invalidated. Per-account MFA attempt limits also apply.
  - (c) The last accepted time step is stored, and codes for steps ≤ last are rejected.
  - (d) Reset never touches MFA.
  - (e) Disabling MFA requires the password **and** a current TOTP/recovery code.
- **Detection:** `auth.mfa_failed` with counts, and alerts on bursts.
- **Recovery:** Admin-assisted MFA reset through a documented, identity-verified support process (audited).
- **Tests:** `[T-MFA-PENDING]`, `[T-MFA-BRUTE]`, `[T-MFA-REPLAY]`, `[T-MFA-RESET]`.

**S-5 Passkey assertion replay / cross-origin use**
- **Attack path:** Replay a captured assertion, or relay one from a phishing origin.
- **Impact:** Account takeover.
- **Mitigation:**
  - Server-generated single-use challenge (stored hashed, 5-minute TTL, deleted on use).
  - `expected_origin` and `expected_rp_id` are verified by `py_webauthn`.
  - Sign-count regression is logged as a possible authenticator clone.
  - User verification is required for passwordless login.
- **Detection:** `auth.passkey_signcount_regression`.
- **Recovery:** User or admin deletes the credential; sessions are revoked.
- **Tests:** `[T-PASSKEY-*]`.

**S-6 Realtime event spoofing** (CWE-345)
- **Attack path:** A client sends `{"type":"message","from":"victim"}` frames over the WebSocket.
- **Impact:** Forged messages or notifications.
- **Mitigation:**
  - Client frames are limited to `ping`, `subscribe`, `unsubscribe` and `typing`. Every other type closes the connection (1008).
  - The server stamps the actor from the authenticated session and ignores client-supplied identity fields.
  - Messages can only be created over authenticated HTTP.
- **Detection:** `ws.protocol_violation` events.
- **Recovery:** No state change is possible, so there is nothing to roll back.
- **Tests:** `[T-WS-SPOOF]`.

**S-7 Cross-site WebSocket hijacking (CSWSH)**
- **Attack path:** `evil.com` opens `wss://bastion/api/v1/ws` and the browser attaches cookies.
- **Impact:** A live read of the victim's events.
- **Mitigation:** The handshake needs `Origin` ∈ `ALLOWED_ORIGINS` when authenticating by cookie. Non-browser clients use a single-use 60-second ticket obtained with a PAT or session.
- **Detection:** `ws.origin_rejected`.
- **Recovery:** Nothing leaks, so no recovery is needed.
- **Tests:** `[T-WS-ORIGIN]`.

**S-8 Email spoofing / reset-link poisoning** (CWE-640, host header injection)
- **Attack path:** Send a reset request with `Host: evil.com` so the emailed link points at the attacker.
- **Impact:** Reset token captured.
- **Mitigation:** Links are built from the configured `PUBLIC_BASE_URL`, never from request headers. `TrustedHostMiddleware` rejects unknown hosts.
- **Detection:** `http.bad_host` log events.
- **Recovery:** Tokens are single-use and short-lived. Revoke outstanding tokens.
- **Tests:** `[T-HOSTHDR]`.

### 6.2 Tampering

**T-1 SQL injection** (CWE-89, A03)
- **Attack path:** Payloads in search, handles, cursors, or sort params.
- **Impact:** Full DB compromise.
- **Mitigation:**
  - SQLAlchemy parameterised queries only. There is no `text()` with interpolation, and CI greps/bandit B608 block it.
  - Sort orders and filters are allow-listed enums.
  - Search uses `plainto_tsquery` (not `to_tsquery`), and LIKE wildcards are escaped.
  - Cursors are decoded into typed values (timestamp + UUID) and rejected on any parse error.
  - Least-privilege DB role limits blast radius: no DDL, and no audit rewrite.
- **Detection:** DB error-rate alerting; `http.bad_cursor` events.
- **Recovery:** Restore from PITR; rotate DB credentials.
- **Tests:** `[T-INJ-SQL]`.

**T-2 Mass assignment / parameter tampering** (CWE-915, API3)
- **Attack path:** `PATCH /me {"role":"admin","email_verified":true}`.
- **Impact:** Privilege escalation.
- **Mitigation:** Pydantic request models with `extra="forbid"`. Explicit field copy in services. Role/verification fields exist only in admin/internal code paths.
- **Detection:** 422 responses with `extra_forbidden` are logged as `api.schema_violation`.
- **Recovery:** Audit shows every role change; revert.
- **Tests:** `[T-AUTHZ-MASS]`.

**T-3 Race conditions on business logic** (CWE-362)
- **Attack path:** 50 parallel likes/follows/votes, or a double-spent reset token.
- **Impact:** Inflated counts, duplicate rows, token reuse.
- **Mitigation:**
  - Unique constraints (`likes(user_id, post_id)`, `follows(follower_id, followee_id)`, …) with `INSERT … ON CONFLICT DO NOTHING`.
  - Counters maintained by atomic `UPDATE … SET n = n + 1` guarded by the insert result.
  - Tokens consumed via `UPDATE … WHERE used_at IS NULL AND expires_at > now() RETURNING`.
  - TOTP step stored with a conditional update.
  - Optimistic locking (`version` column) on profile/settings.
- **Detection:** Constraint-violation metrics.
- **Recovery:** Recount job (`bastion-admin recount`).
- **Tests:** `[T-RACE-LIKE]`, `[T-RACE-TOKEN]`, `[T-RACE-FOLLOW]`.

**T-4 Audit-log tampering** (CWE-117, CWE-778)
- **Attack path:** A compromised app role deletes or edits audit rows to hide admin abuse.
- **Impact:** Loss of accountability.
- **Mitigation:**
  - `bastion_app` has INSERT/SELECT only.
  - A `BEFORE UPDATE OR DELETE` trigger raises; a TRUNCATE trigger also raises.
  - Each row stores `prev_hash` and `row_hash = SHA-256(prev_hash ‖ canonical_json(row))`.
  - Inserts are serialised by an advisory lock.
  - Log injection is prevented because the log is structured JSON with no string concatenation.
- **Detection:** `bastion-admin verify-audit` (scheduled) recomputes the chain; tampering shows as the first bad sequence number. Optionally ship to external WORM storage.
- **Recovery:** Restore the chain segment from backup/WORM copy, then investigate the DBA account.
- **Tests:** `[T-AUDIT-IMMUTABLE]`, `[T-AUDIT-CHAIN]`.

**T-5 Upload path traversal / filename injection** (CWE-22, CWE-73)
- **Attack path:** A filename like `../../app/main.py` or `x.php%00.jpg`.
- **Impact:** RCE or file overwrite.
- **Mitigation:**
  - Client filenames are **never accepted**: the raw-body upload has no filename field.
  - Storage keys are random UUIDs under fixed roots, and paths are resolved and checked to stay under the root.
  - The media volume is not executable and not a web root. It is served only via internal X-Accel with a fixed Content-Type.
- **Detection:** N/A (the surface is absent).
- **Recovery:** N/A.
- **Tests:** `[T-UPLOAD-TRAVERSAL]`.

**T-6 Cache poisoning / cache deception**
- **Attack path:**
  - (a) Unkeyed headers (`X-Forwarded-Host`) alter responses that are then cached.
  - (b) `/api/v1/me/x.css` gets cached by a CDN.
- **Impact:** Stored XSS/redirect via cache, or private data cached publicly.
- **Mitigation:**
  - API responses are `Cache-Control: no-store`.
  - No response depends on `X-Forwarded-Host` (links use config).
  - The router is strict (no path-suffix tolerance) and returns 404 for `/me/x.css`.
  - Media responses are `private` unless public-post media; auth-dependent media uses `Vary: Cookie` + `private`.
- **Detection:** CDN logs.
- **Recovery:** Purge the CDN.
- **Tests:** `[T-CACHE]`.

**T-7 Parser differentials** (CWE-436)
- **Attack path:** Duplicate JSON keys (`{"visibility":"public","visibility":"followers"}`), duplicate query params, or mixed-case `Content-Type` tricks, so that the proxy/WAF and the app see different values.
- **Impact:** Validation bypass.
- **Mitigation:**
  - Strict JSON parser rejects duplicate keys, NaN, and deep nesting.
  - Duplicate query keys are rejected.
  - `Content-Type` must be exactly `application/json` (params allowed).
  - Unicode handles are restricted to `[A-Za-z0-9_]{3,30}` and compared case-insensitively on a normalised form.
  - Text is normalised to NFC with bidi/control characters removed.
- **Detection:** `api.parse_rejected` events.
- **Recovery:** N/A.
- **Tests:** `[T-API-DUPKEY]`, `[T-API-HPP]`.

### 6.3 Repudiation

**R-1 Admin/moderator denies an action**
- **Attack path:** A moderator suspends a user and later claims they didn't.
- **Impact:** Disputes, insider abuse hidden.
- **Mitigation:** Every admin/mod action writes a hash-chained audit entry (actor, action, target, reason, IP prefix, request ID). Actions require a reason string.
- **Detection:** Audit review UI (admin only); chain verification.
- **Recovery:** Reverse the action and remove the role.
- **Tests:** `[T-ADMIN-AUDIT]`.

**R-2 User denies a security-relevant change**
- **Attack path:** "I never changed my email / disabled MFA."
- **Mitigation:** Audit entries for password/email/MFA/passkey/PAT/session events, and notification emails to the *old* address on email change.
- **Detection:** Same as mitigation.
- **Recovery:** Support-assisted rollback.

### 6.4 Information disclosure

**I-1 BOLA/IDOR** (CWE-639, API1)
- **Attack path:** Iterate or guess `post_id`, `conversation_id`, `media_id` or `notification_id` belonging to others.
- **Impact:** Private posts, DMs, and media exposed.
- **Mitigation:**
  - UUIDv4 IDs (non-enumerable), and **authorization regardless**.
  - Every fetch goes through the `authz` policy (`can_view_post`, `require_conversation_member`, `can_view_media`).
  - Lists use the SQL visibility clause.
  - Inaccessible and non-existent objects both return 404 (no existence oracle).
- **Detection:** `authz.denied` events with a per-actor counter; alert when one actor has many denials (probing).
- **Recovery:** Suspend the actor; rotate affected media keys.
- **Tests:** `[T-AUTHZ-BOLA-*]`, the full matrix in `test_authz_matrix.py`.

**I-2 Private data via alternate paths** (API9 improper inventory)
- **Attack path:** A followers-only post reached through search, hashtag feed, reply thread, repost, notifications, the media endpoint, a user's likes list, or export.
- **Impact:** Privacy breach.
- **Mitigation:** One `visible_posts_clause(viewer)` is used by **every** post-returning query. Reposts and replies re-check the original. Media authz derives from the owning post/message. Route inventory is tested: every registered route must appear in the declared auth table (`test_route_inventory.py` fails on undocumented endpoints).
- **Detection:** Authz denial metrics.
- **Recovery:** Patch the query and notify affected users.
- **Tests:** `[T-PRIV-ALTPATH]`, `[T-ROUTES]`.

**I-3 User/email enumeration** (CWE-204)
- **Attack path:**
  - (a) The login error differs for "no such user".
  - (b) Response timing differs.
  - (c) Registration says "email taken".
  - (d) Reset says "no such email".
- **Impact:** Targeted phishing and stuffing.
- **Mitigation:**
  - (a) A uniform `invalid_credentials` error.
  - (b) A dummy argon2 verify on a miss.
  - (c) Registration with an existing email returns the same 202 and emails the owner instead.
  - (d) Reset always returns 202.
  - Handles are public by design and are not treated as secret.
- **Detection:** Rate-limit hits on these endpoints.
- **Recovery:** N/A.
- **Tests:** `[T-AUTH-ENUM]`.

**I-4 Stored/reflected/DOM XSS** (CWE-79, A03)
- **Attack path:** `<img src=x onerror=…>` in a post, bio, display name or DM; `javascript:` in a link; a malicious SVG avatar; `#<payload>` in the URL fragment.
- **Impact:** Session riding and account takeover.
- **Mitigation:**
  - UGC is plaintext, and the SPA renders it with `textContent`.
  - Links are rendered only from server-extracted entities whose scheme is `http`/`https` (validated server-side and client-side via `new URL`).
  - CSP is `script-src 'self'` with no inline code, and Trusted Types (`'none'`) is enforced.
  - No SVG is accepted. The media endpoint sends `Content-Security-Policy: default-src 'none'; sandbox` plus `nosniff`.
  - The API only returns JSON with `nosniff`.
  - Error messages never reflect input.
- **Detection:** CSP `report-to` endpoint (`/api/v1/csp-report`, rate-limited) logs violations.
- **Recovery:** Hot-fix, rotate sessions if exploitation is suspected.
- **Tests:** `[T-XSS-*]`, `[T-HEADERS]`.

**I-5 Error/stack-trace leakage** (CWE-209)
- **Mitigation:** Global exception handlers return a generic `internal_error` with a request ID. Debug mode is off in production and cannot be enabled via request.
- **Tests:** `[T-API-ERRORS]`.

**I-6 Cross-user realtime leakage**
- **Attack path:** Subscribe to `conversation:<victim-conv-id>`, or receive events for other users on a shared worker.
- **Impact:** Leaked DMs or notifications.
- **Mitigation:**
  - Subscriptions are authorised at subscribe time (DB membership check).
  - Event recipients are computed server-side at publish time.
  - The gateway delivers only if `event.recipient_id == connection.user_id`.
  - Membership removal or a block triggers server-side unsubscribe.
- **Tests:** `[T-WS-SUBSCRIBE]`, `[T-WS-LEAK]`.

**I-7 Log leakage of secrets / PII** (CWE-532)
- **Mitigation:**
  - A redaction filter on keys matching `password|token|secret|cookie|authorization|code|otp|recovery`.
  - Bodies are never logged.
  - IPs are truncated in long-retention stores.
  - DM content is never logged.
- **Tests:** `[T-LOG-REDACT]`.

**I-8 Metadata leakage via media (EXIF GPS)**
- **Mitigation:** Re-encoding from pixels drops all metadata. Orientation is applied first.
- **Tests:** `[T-UPLOAD-EXIF]`.

**I-9 Scraping / bulk harvesting**
- **Attack path:** Enumerate followers lists, search results, and profiles anonymously at scale.
- **Mitigation:**
  - Anonymous users see public profiles/posts only, with strict per-IP limits.
  - Follower/following lists and search require login.
  - Pagination is capped at 50.
  - Per-user read budgets scale with trust level.
  - `discoverable=false` hides users from search.
- **Detection:** Per-actor read-volume metrics.
- **Recovery:** Throttle tier, suspend.

### 6.5 Denial of service

**D-1 Request floods, large bodies, slowloris**
- **Mitigation:**
  - Proxy: connection/rate limits and timeouts.
  - App: body limits (64 KiB JSON default; 20 MiB images; 100 MiB video), streaming counting for chunked bodies, pagination caps, query-string length cap, per-route rate limits.
- **Tests:** `[T-API-BODYLIMIT]`.

**D-2 Decompression bomb / pixel flood** (CWE-409)
- **Attack path:** A 50 KB PNG declaring 100000×100000 pixels, or a GIF with 10k frames.
- **Mitigation:**
  - Header dimensions are checked before decode (≤ 40 MP, ≤ 8192 on either side).
  - `Image.MAX_IMAGE_PIXELS` is set.
  - GIF frame limit (animated GIFs are flattened to the first frame).
  - Decode happens in a subprocess with `RLIMIT_AS` 512 MiB, `RLIMIT_CPU` 20 s, and a wall timeout.
  - Video: ffprobe duration/dimension limits; ffmpeg runs with `-t` cap and rlimits.
- **Tests:** `[T-UPLOAD-BOMB]`.

**D-3 Argon2 CPU/memory exhaustion**
- **Attack path:** A flood of login attempts, each forcing a 64 MiB hash.
- **Mitigation:**
  - Rate limits are checked **before** hashing.
  - A bounded semaphore limits concurrent hashes per process.
  - The password length cap is 256.

**D-4 WebSocket exhaustion / event flooding**
- **Mitigation:**
  - Per-user (5) and per-IP (20) connection caps, plus a global cap.
  - Max frame 4 KiB.
  - Per-connection token bucket (10 msgs/s, burst 20), then close with 1008.
  - A bounded outbound queue (256); on overflow the connection is closed, so a slow consumer cannot grow server memory.
  - Idle timeout and heartbeat.
- **Tests:** `[T-WS-FLOOD]`, `[T-WS-CONNLIMIT]`.

**D-5 ReDoS** (CWE-1333)
- **Mitigation:** Entity-extraction regexes are linear (no nested quantifiers) and run on length-capped input (≤ 500 chars for posts). Tests include adversarial inputs with time bounds.
- **Tests:** `[T-REDOS]`.

**D-6 Lockout DoS**
- **Attack path:** Deliberately failing logins to lock out a victim.
- **Mitigation:** The progressive delay is keyed **per account+IP prefix** for the lock. The account-wide limit only slows attempts (max 15 min) and never locks permanently. Passkey login stays available.

### 6.6 Elevation of privilege

**E-1 Vertical escalation** (API5)
- **Attack path:** A user calls `/admin/api/v1/*`; a moderator promotes themselves; a moderator acts on an admin.
- **Mitigation:**
  - Role checks on the admin router.
  - Only admins change roles.
  - Nobody can change their own role.
  - The last admin cannot be demoted.
  - Moderators cannot act on moderators/admins.
  - MFA plus step-up is required.
- **Tests:** `[T-AUTHZ-VERT]`.

**E-2 Horizontal escalation**
- **Attack path:** Edit or delete another user's post, profile, or session.
- **Mitigation:** Ownership checks in policy. `DELETE /sessions/{id}` is scoped to the current user in SQL (`WHERE id=:id AND user_id=:me`).
- **Tests:** `[T-AUTHZ-HORIZ]`.

**E-3 PAT scope escalation**
- **Attack path:** A `read`-scoped token posts, or any token reaches admin/account-security endpoints.
- **Mitigation:**
  - Scopes are enforced per route.
  - PATs are **never** accepted on the admin API or account-security endpoints (password, MFA, tokens, export, delete, sessions).
- **Tests:** `[T-PAT-SCOPE]`.

**E-4 CSRF into state-changing actions** (CWE-352)
- **Mitigation:** See R-CSRF-1. The admin API also requires the CSRF token.
- **Tests:** `[T-CSRF]`.

**E-5 RCE via media decoders** (CWE-94 class, supply chain of libjpeg/libwebp/ffmpeg)
- **Attack path:** A crafted WebP exploits a libwebp overflow (the CVE-2023-4863 class).
- **Impact:** Code execution in the worker.
- **Mitigation:**
  - Decode only in the worker, never in the API.
  - Per-file subprocess with rlimits.
  - The worker container is non-root, read-only rootfs, drops all capabilities, and uses a seccomp default profile.
  - No network except Postgres.
  - The DB role can touch only the `media` table.
  - No secrets besides its own DB password.
  - Output is written to a separate volume.
  - An auto-rebuilt image pulls patched libraries.
- **Detection:** Worker crash/timeout metrics; `media.decode_failed` spikes.
- **Recovery:** Rebuild the image, rotate the worker DB password, re-scan the media volume.
- **Tests:** `[T-UPLOAD-MALFORMED]`.

**E-6 Command injection** (CWE-78)
- **Mitigation:** `subprocess.run([...], shell=False)` with fixed argument vectors. Inputs are server-generated paths only. Bandit B602/B605 are gated in CI.
- **Tests:** `[T-INJ-CMD]` (static check + ffmpeg path test).

**E-7 Template injection** (CWE-1336)
- **Mitigation:** No server-side templates render user data. Email bodies use `str.format`-free static text with the link appended, and email headers are validated to reject CR/LF.
- **Tests:** `[T-INJ-TEMPLATE]`, `[T-INJ-HEADER]`.

---

## 7. Privacy threats (LINDDUN-lite)

| Threat | Mitigation |
|---|---|
| Linkability via avatar/media EXIF | Metadata stripped |
| Identifiability via email | Email never shown to others; not searchable |
| Detectability of blocks | Blocked party gets 404, same as non-existent |
| Disclosure of social graph | Followers lists require login; private accounts hide lists from non-followers |
| Unawareness | Settings page exposes every control; export is self-service |
| Non-compliance (retention) | Retention policy enforced by `bastion-admin purge` (see `docs/PRIVACY.md`) |
| Excess collection | No phone numbers, no contacts upload, no precise geolocation, no third-party analytics, no fingerprinting |

---

## 8. Abuse cases

| # | Abuse case | Defences |
|---|---|---|
| AB-1 | Bot mass-registration | Per-IP registration limit (5/h per /24 IPv4 or /48 IPv6), email verification required to post/DM/upload, disposable-domain blocklist hook, proof-of-work challenge on registration (hashcash-style, adaptive difficulty) instead of CAPTCHA |
| AB-2 | Spam posting | Trust-tiered post limits (new: 10/h, verified: 60/h, established: 200/h), duplicate-content detection (same normalised text hash posted > 3× in 1 h is rejected), link limits per post for new accounts, domain blocklist |
| AB-3 | Mass following (follow-back farming) | Follow limits per trust tier (new: 50/day, established: 400/day), a follow/unfollow churn limit |
| AB-4 | Mass unsolicited DMs | Recipient's `dm_policy` (default: people I follow); new accounts may start ≤ 10 new conversations/day; message rate limits; block ends the conversation |
| AB-5 | Harassment via replies/mentions | Block (bidirectional hide), mute, private account, reports; blocked users cannot reply, mention-notify, like, repost, follow or DM |
| AB-6 | Block evasion by new accounts | Accounts younger than 24 h have mentions to non-followers de-prioritised (no notification); moderation can suspend groups |
| AB-7 | Report bombing | Reports deduplicated per (reporter, target); per-user report limits; moderators see reporter trust level |
| AB-8 | Like/repost count inflation | Unique constraints; verified email required |
| AB-9 | Scraping | See I-9 |
| AB-10 | Malicious links | Scheme allow-list, blocked-domain list (admin-managed), `rel="nofollow noopener noreferrer ugc"`, no link previews |
| AB-11 | Coordinated abuse | Moderation queue shows reports aggregated per target; admin can suspend and bulk-hide posts; audit trail |
| AB-12 | Account farming (aged accounts sold) | Trust level also depends on verified email and absence of strikes; strikes reduce limits |

---

## 9. Failure modes

| Component failure | Behaviour | Rationale |
|---|---|---|
| Redis down | Rate limiter **fails closed** for auth endpoints (login/register/reset return 503) and **fails open with a local in-process limiter** for read endpoints; realtime degrades to HTTP polling | Credential attacks must never get unlimited attempts. Reading keeps working |
| Postgres down | 503 for everything; health check fails; no partial writes (transactions) | The DB is the source of truth |
| Media worker down | Uploads stay `pending`; posts referencing pending media are rejected (only `ready` media can be attached) | Unprocessed media is never served |
| Decoder crash / timeout | Media marked `failed`, quarantine file deleted, event logged | Contain the failure and don't retry forever |
| SMTP down | Tokens are still created; the email send is retried; the user can request a resend (rate-limited) | Nothing is stuck |
| Clock skew | TOTP ±1 step; token expiry uses DB `now()` for consistency | |
| Audit insert fails | The whole admin action rolls back (same transaction) | Unaudited privileged actions must not happen |
| Field-encryption key missing | Startup refuses to run | Fail fast rather than store secrets unencrypted |
| Config missing a secret | Startup refuses in production (`ENV=production` validation) | |

---

## 10. Recovery strategy

- **Session compromise:**
  - User: "log out all devices".
  - Admin: `POST /admin/api/v1/users/{id}/revoke-sessions`.
  - Global: `bastion-admin revoke-all-sessions` (bumps `users.session_epoch`, which invalidates every session instantly).
- **Credential DB leak:** Argon2id makes offline cracking slow. Force a global password reset (`bastion-admin force-reset --all`), which revokes sessions/PATs and emails users.
- **Secret leak:** Every secret is externalised and rotatable. See `docs/OPERATIONS.md` §Rotation for DB passwords, `SECRET_KEY`, field-encryption key (supports a key ring, `FIELD_ENCRYPTION_KEYS=new,old`, then `bastion-admin reencrypt`), and SMTP.
- **Worker compromise:** Kill the worker, rotate the worker DB password, rebuild the image, and re-validate derivatives (`bastion-admin media-rescan`).
- **Data corruption:** Postgres PITR (WAL archiving) plus nightly encrypted base backups. See `docs/BACKUP-RECOVERY.md`.
- **Forensics:** Hash-chained audit log, structured security logs (90-day retention), and request IDs that correlate proxy ↔ app logs.

---

## 11. Residual risks

Tracked in `SECURITY.md` §Known limitations and §Unresolved risks.

# Security

Bastion is designed on one premise: **every component will eventually be attacked.** This file is the
living security record. It lists the controls, how each one is verified, what the adversarial pass
found, and what risk remains. It is updated together with [`THREAT-MODEL.md`](THREAT-MODEL.md).

> Bastion is **not** "unhackable". The claim is narrower and testable: every control listed below is
> implemented and covered by an automated test that fails if the control regresses.

## Reporting a vulnerability

Email **security@your-domain.example** (PGP key in `/.well-known/security.txt` in production). Don't open
public issues for vulnerabilities. We acknowledge within 2 business days and aim to fix critical
issues within 7 days. Good-faith research against **your own local instance** is welcome. Never test
against other people's deployments.

---

## 1. Security model in one page

| Layer | Control | Where |
|---|---|---|
| Edge | TLS 1.2+/HSTS, body ceilings, per-IP req/conn limits, slowloris timeouts, `X-Forwarded-For` overwritten | `deploy/nginx/nginx.conf` |
| Request boundary | Trusted Host, strict JSON (no dup keys, NaN, NUL, depth > 32), duplicate query keys rejected, control chars in path rejected, strict routing (no trailing-slash aliases), per-route body limits incl. chunked | `middleware.py`, `strictjson.py`, `deps.StrictRoute` |
| Cross-origin | No CORS at all; `Origin`/`Sec-Fetch-Site` check on every unsafe request; synchronizer CSRF token bound to the session; `SameSite=Lax`; JSON-only bodies | `deps.py`, `services/sessions.py` |
| Authentication | argon2id; uniform errors + dummy hash; identifier-keyed throttling (no lockout oracle); PoW on sign-up; single-use hashed tokens; TOTP with replay protection; recovery codes; WebAuthn (UV required); session rotation; idle/absolute expiry; epoch-based global revocation | `api/auth.py`, `services/*` |
| Authorization | Central policy module; one SQL visibility clause used by **every** post query; 404 for invisible objects; PAT scopes; account-security & admin never reachable by PAT; admin = role + MFA + step-up ≤ 15 min; rank rules | `authz.py` |
| Data | Parameterised SQL only; FK/unique/check constraints; race-safe `ON CONFLICT` + conditional `UPDATE … RETURNING`; optimistic locking; per-service DB roles; `audit_log` INSERT-only + trigger + hash chain | `models.py`, `migrations/`, `audit.py` |
| Content | Plaintext UGC → typed segments rendered with `textContent`; http(s)-only links; blocked domains; bidi/invisible chars stripped | `text.py`, `frontend/assets/js/dom.js` |
| Browser | CSP `script-src 'self'`, no inline code, **Trusted Types enforced (`'none'`)**, `frame-ancestors 'none'`, nosniff, COOP/CORP, Permissions-Policy | `middleware.py`, [`docs/SECURITY-HEADERS.md`](docs/SECURITY-HEADERS.md) |
| Media | Raw-body upload (no filename, no multipart), magic-byte allow-list, size caps while streaming, quarantine, **separate worker + per-file rlimited subprocess with empty env**, header-only dimension check, re-encode from pixels, authz on serving, `sandbox` CSP | `api/media.py`, `worker.py`, `media_process.py` |
| Realtime | Cookie + strict Origin or single-use ticket; server-computed recipients; DB-checked subscriptions; 4 client frame types only; frame/rate/connection caps; bounded queues; immediate revocation | `api/ws.py`, `realtime.py` |
| Abuse | Adaptive, trust-tiered limits on every endpoint (baseline `api` bucket + specific buckets); duplicate-content detection; DM consent model; report dedup; mention damping for new accounts | `ratelimit.py`, services |
| Ops | Secrets from env/`*_FILE`; production config refuses insecure defaults; JSON logs with redaction; non-root read-only containers, all caps dropped, segmented networks | `config.py`, `deploy/` |
| Supply chain | Hash-locked wheels-only deps; pip-audit; Trivy (fs/config/image); gitleaks; Semgrep; CodeQL; SBOM (CycloneDX); actions pinned by SHA; vendored JS pinned by hash | `.github/workflows/ci.yml` |

---

## 2. Requirement → implementation → test

Test IDs used in `THREAT-MODEL.md` resolve to these files (all under `backend/tests/`). The suite
has **459 tests** and runs against real PostgreSQL 16 as the least-privilege app role.

| Test ID(s) | What is attacked | File |
|---|---|---|
| T-HEADERS, T-API-DUPKEY, T-API-HPP, T-API-BODYLIMIT, T-API-ERRORS, T-HOSTHDR, T-CACHE, T-AUDIT-*, T-LOG-REDACT | Boundary parsing, headers, errors, audit immutability & tamper detection, log redaction, config safety, field encryption | `test_infra.py` (35) |
| T-AUTH-BRUTE, T-AUTH-ENUM, T-AUTH-FIXATION, T-AUTH-REVOKE, T-AUTH-REAUTH, T-MFA-PENDING, T-MFA-BRUTE, T-MFA-REPLAY, T-MFA-RESET, T-RACE-TOKEN, T-PAT-SCOPE, T-CSRF, T-AUTHZ-MASS | Brute force, stuffing, enumeration (content + timing), session fixation/theft/expiry, reset-token reuse & races, MFA bypass/brute/replay, PAT scope escalation, CSRF, mass assignment | `test_auth.py` (70) |
| T-PASSKEY-* | Challenge replay, phishing origin, missing UV, sign-count regression (clone), tampered signature, cross-user challenge/credential | `test_passkeys.py` (14) |
| T-AUTHZ-HORIZ, T-AUTHZ-BOLA-*, T-PRIV-ALTPATH, T-RACE-LIKE, T-RACE-FOLLOW, T-XSS-*, T-INJ-SQL, T-REDOS | IDOR/BOLA, alternate-path privacy leaks (search, hashtags, timelines, replies, notifications, reposts, media), block bypass, self-follow, repeated/concurrent likes & follows, stored XSS, unsafe links, SQL injection, bad cursors, ReDoS | `test_social_posts.py` (59) |
| T-AUTHZ-BOLA-DM, T-DM-*, T-REPORT-* | Conversation-ID guessing on every DM endpoint, consent/block rules, deletion semantics, spam limits, report dedup/visibility | `test_dm_reports.py` (19) |
| T-UPLOAD-POLYGLOT, -BOMB, -MALFORMED, -EXIF, -TRAVERSAL, T-INJ-CMD | Polyglots (JPEG+HTML, GIFAR), SVG/HTML/ELF/PE/PHP/PDF/ZIP, MIME confusion, pixel & zlib bombs, oversized streams, malformed media, container/signature mismatch, traversal, HLS playlist trick, metadata leakage, decoder env secrets, media authz | `test_media.py` (46) |
| T-WS-* | CSWSH, unauthenticated, ticket replay, cross-user leakage, foreign subscriptions, spoofed events, malformed/binary/oversized frames, flooding, connection exhaustion, revocation, backpressure | `test_ws.py` (34) |
| T-AUTHZ-VERT, T-ADMIN-* | Vertical escalation, MFA/step-up enforcement, PAT on admin, CSRF, rank rules, last-admin, typed confirmations, audit; operator CLI (force-reset, global revocation, media rescan, retention purge) | `test_admin.py` (35) |
| T-ROUTES, T-API-UNAUTH | **Route inventory** (undocumented endpoints fail CI); every protected route swept anonymously; every admin route swept as a regular user; garbage path params never 500 | `test_routes.py` (108) |
| ADV-* | Independent adversarial pass (below) | `test_adversarial.py` (39) |

Beyond pytest:

* `scripts/e2e_smoke.py` runs real Chromium against the full stack (Redis limiter and pub/sub, worker). It fails on **any** console error or CSP/Trusted-Types violation, and asserts an XSS payload stays inert.
* `scripts/check_security.py` fails the build on:
  - DOM sinks in JS;
  - inline script/style or handlers in HTML;
  - a vendored-file hash mismatch;
  - drift between the nginx CSP and the app CSP;
  - SQL built by interpolation inside `text()`;
  - shell/eval/pickle use;
  - a router without `StrictRoute`;
  - `assert` in app code.

---

## 3. Adversarial testing results (Phase 11)

Method: after the functional suites passed, I attacked the running system as an independent tester,
against the local environment only. I used black-box probes (encoding tricks, path normalisation,
races, header spoofing, protocol abuse), a route-inventory sweep, and a code-review pass over each
handler. **Actual** is the behaviour observed *before* the fix. Every row now has a regression test.

| ID | Attack | Expected | Actual (before fix) | Severity | Fix | Status |
|---|---|---|---|---|---|---|
| ADV-01 | NUL byte in a path param or JSON string (`/users/%00`, `{"identifier":"\u0000"}`) | 400 | Reached Postgres → `DataError` → **500** | Low | Control chars rejected in path/query (middleware); NUL rejected in strict JSON | Fixed |
| ADV-02 | **Account pre-hijacking**: attacker registers with the victim's email (unverified), plants PAT/TOTP/passkey; victim later resets the password | Planted credentials impossible or discarded | Unverified accounts could create PATs and enrol TOTP/passkeys that **survived** the victim's reset | **High** | Adding credentials requires a verified email; the first proof of email control (reset) wipes TOTP, recovery codes, passkeys, PATs; audited | Fixed |
| ADV-03 | `GET /admin/api/v1/me/` (trailing slash) to slip past proxy/CDN rules | 404 | **200**: path alias served (normalisation differential) | Medium | Strict routing: trailing/duplicate slashes → 404; `redirect_slashes=False`; `/admin/api` catch-all | Fixed |
| ADV-04 | Keep a stolen session's WebSocket open after the owner changes the password | Socket closed immediately | Closed only by the 30 s watchdog | Low | `announce_revocation()` after commit; client re-checks session on 4001 | Fixed |
| ADV-05b | Attach the same upload to two posts concurrently | One succeeds, one 404/409 | Loser hit a unique violation → **500** | Low | Attachment re-checked under the row lock; DM path locks too | Fixed |
| ADV-06b | Distributed TOTP guessing (password known) from many IPs | Per-account budget independent of IP | Only IP-keyed and per-pending-token budgets (≈2,880 guesses/day) | Medium | `mfa_user` bucket: 20/hour per account, fail-closed; owner emailed after 3 failures | Fixed |
| ADV-07b | `dm`-scoped PAT reads `GET /api/v1/me` (email) | 403 insufficient_scope | 200 | Low | `/me` requires `read:auth` scope | Fixed |
| ADV-08b | Moderator restores content an admin removed | 403 | 200 (rank not checked on restore) | Low | Rank check on restore | Fixed |
| ADV-09b | Any unforeseen constraint race or DB value error | 409/400, never 500 | 500 with generic body | Low | Global `IntegrityError`→409, `DataError`→400, `OperationalError`→503 | Fixed |
| ADV-10b | Hammer endpoints that had no specific limit (found by the generated API inventory: e.g. `GET /blocks`, `POST /account/delete`) | Every endpoint rate-limited | ~25 endpoints unlimited | Medium | Baseline `api` budget enforced in the dependency every route uses | Fixed |
| ADV-11 | `"version": true`, `1e400`, `10**29` in JSON | 422 | `true` coerced to `1` (type confusion); big int → DB overflow **500** | Low | `StrictInt` + INT32 bounds on all body integers; query ints bounded | Fixed |

Attacks that **held on first try** (tests in `test_adversarial.py` and suites above):
- **Spoofing:** `X-Forwarded-For`/`X-Real-IP` spoofing to evade limits; `X-Forwarded-Host`/`Forwarded`/`X-Original-URL` poisoning of reset links; method-override headers.
- **Cross-origin and transport:** CORS preflight from an evil origin; UTF-16/BOM/content-type confusion.
- **Races:** concurrent same-handle registration; concurrent DM creation; mutual concurrent admin demotion (an admin always remains); the same TOTP code raced on two pending logins.
- **Identity and data handling:** email case-variants as one identity; case-variant JSON keys; visibility changes retroactively hiding reposts; no state change reachable via GET.
- **Tokens and credentials:** session-token entropy; PoW difficulty escalation; revoked-PAT WebSocket tickets.
- **Enumeration:** deleted/unverified/missing accounts are indistinguishable at login; registration timing does not reveal existing emails.

Issues found and fixed **during development**, before the adversarial pass:

| # | Issue | How found | Fix |
|---|---|---|---|
| D-1 | Per-account login lockout would have been a user-enumeration oracle (and a lockout-DoS lever) | Design review | Throttling keyed by the *submitted identifier* (+IP), identical for non-existent accounts |
| D-2 | `ROLE_PERMISSIONS["user"]` granted only the unverified set (fail-closed functional bug) | Test | Role grants the full set; unverified accounts intersected down |
| D-3 | Blocker couldn't open the blocked profile to undo a block | Test | Directional profile visibility plus explicit `assert_can_interact` on follow/DM |
| D-4 | Duplicate `nosniff` header when a route set its own | Test | Middleware never duplicates or weakens route-set headers |
| D-5 | Hard-coded throwaway `SECRET_KEY` in the CI workflow | gitleaks | Generated at runtime (`openssl rand`) |
| D-6 | `# noqa` accidentally placed *inside* a migration's SQL f-string | Code review | Moved outside the string; migration re-verified |
| D-7 | `assert` used for invariants (stripped under `python -O`) | bandit B101 | `ensure()` runtime checks; CI bans `assert` in app code |
| D-8 | Timed suspensions never lifted | Review | Expiry honoured at login (audited) |
| D-9 | Correct-password-but-failing-MFA not surfaced to the owner | Review | Security email after 3 failures |

---

## 4. CI security gates (build fails on any)

| Gate | Tool | Threshold |
|---|---|---|
| Lint + flake8-bandit rules | ruff | any |
| SAST | bandit (`-ll`), Semgrep (OWASP Top 10, python, javascript, secrets), CodeQL (`security-extended`) | medium+ / ERROR |
| Project gates | `scripts/check_security.py`, CSP drift check | any |
| Secrets | gitleaks (full history) | any |
| Dependencies | lockfile ⇔ `.in` sync; wheels-only hash-verified download; pip-audit `--strict`; dependency-review (PRs) | any known vuln / high |
| Containers | Trivy image (fixable HIGH/CRITICAL), non-root check; Trivy config (IaC) | HIGH+ |
| SBOM | CycloneDX (Python deps + every image) | artifact |
| Tests | pytest (unit/integration/security/authz/adversarial) on Postgres 16 + Redis; migration reversibility + model/migration drift | any failure |
| E2E | Playwright/Chromium under production CSP | any console/CSP/TT error |

Verified **in this environment**:
- ruff, bandit, `check_security.py` (including a negative test proving it catches an injected sink), gitleaks (history and working tree), pip-audit (0 known vulns), Trivy fs + config (0 findings), actionlint;
- wheels-only hash-verified download of all 42 runtime deps;
- CycloneDX SBOM (42 components);
- pytest (459 passed), the Chromium E2E, `nginx -t`, and nginx X-Accel/XFF behaviour against a live API.

**Not executed here** (no Docker daemon available): image builds, Trivy image scans, Semgrep (container), CodeQL. They're configured in CI but unverified until the first pipeline run.

---

## 5. SECURITY STATUS

### Verified protections

Each has passing automated tests.
- **Authentication**
  - argon2id with uniform-time failures.
  - Identifier-keyed brute-force and stuffing throttles (fail-closed when Redis is down).
  - Registration PoW (single-use, IP-bound, adaptive).
  - Single-use, hashed, purpose-bound, short-lived tokens, including race-safe consumption.
  - Reset revokes all sessions and tokens but keeps MFA.
  - TOTP with replay protection, and recovery codes.
  - WebAuthn with UV, origin/RP binding, challenge single-use and clone detection.
  - Session fixation prevention, rotation, idle/absolute expiry, per-device revocation reaching live sockets.
  - Re-authentication for sensitive changes.
  - Account pre-hijacking prevention.
- **Authorization**
  - Central policy and one visibility definition for every post path.
  - No BOLA/IDOR on posts, DMs, media, sessions, tokens, passkeys or notifications.
  - Vertical and horizontal escalation blocked.
  - Mass assignment rejected; strict types.
  - PAT scopes; no PAT on security/admin endpoints.
  - Admin requires MFA and a recent step-up.
  - Complete route inventory with automated anonymous and low-privilege sweeps.
- **Input and output**
  - Strict JSON; parameter pollution, NUL/control characters and path aliases rejected.
  - SQL injection inert.
  - Stored/reflected/DOM XSS inert: plaintext segments, CSP, Trusted Types, and a browser-verified payload.
  - Header injection in email blocked; ReDoS-safe parsing.
- **Uploads:** all listed attack classes rejected or neutralised; decoders isolated from secrets.
- **Realtime:** authenticated, origin-checked, authorised per subscription/event, rate- and connection-limited, with backpressure and immediate revocation.
- **Data integrity**
  - DB constraints and race-safe writes.
  - Optimistic locking.
  - Least-privilege DB roles, verified by tests running as those roles.
  - Tamper-evident, append-only audit log (tampering by the owner is detected).
- **Operations**
  - Security headers.
  - Externalised secrets, with production config refusing insecure values.
  - Redacted structured logs.
  - Hardened, segmented container deployment (non-root, read-only, no caps, internal networks, no public DB/Redis).
  - Verified nginx X-Accel serving and XFF handling.
- **Supply chain:** hash-locked wheels, zero known vulnerable dependencies at time of writing, SBOM, SHA-pinned actions, hash-pinned vendored JS.

### Known limitations
1. **Direct messages are not end-to-end encrypted.** They are protected in transit (TLS) and by authorization. At rest, protection relies on disk/volume encryption, and a DB operator can read them.
2. Direct messages are **1:1 only** (no group conversations).
3. **Decoders run in an rlimited subprocess inside a hardened container, not in a dedicated sandbox** (gVisor, Firecracker, or a custom seccomp profile).
4. There is no antivirus scanning. Re-encoding mitigates this for served media, and originals are deleted after processing.
5. **Trusted Types is enforced only in Chromium-based browsers.** Firefox and Safari rely on CSP and the sink-free codebase, which CI enforces.
6. Password screening uses a local list of 46k common passwords. There is no breached-password (HIBP) check.
7. When Redis is down, non-credential limits fall back per process, so the effective limits multiply by the number of instances. Credential endpoints fail closed.
8. Handles become reusable after the 30-day purge, so old mentions could point to a new person.
9. Abuse defences are rule-based: tiered limits, PoW, duplicate detection, consent model and reports. There is no ML or coordinated-behaviour detection.
10. The CSP uses `report-uri`; the Reporting API (`report-to`) is not wired up.
11. Data export is synchronous. Very large accounts may hit the 5 s statement timeout.
12. Account recovery after losing both the MFA device and the recovery codes is a manual, identity-verified support process. It is documented, not automated.
13. There has been no load or performance testing and no formal accessibility audit.
14. Container image scans, Semgrep and CodeQL have not yet run (see §4).

### Unresolved risks
1. **A compromise of the API container is a compromise of user data.** The app DB role necessarily has DML on user tables, and the container holds `SECRET_KEY` and the field-encryption keys. Mitigations: least privilege elsewhere, secret rotation runbooks, and the audit chain. Row-level security is not implemented.
2. **Decoder 0-days** (libwebp, libjpeg, ffmpeg) could give code execution in the worker container. The blast radius is limited to the `media` table (column-level UPDATE) and the media volume. Served output still carries `nosniff`, a fixed type and a `sandbox` CSP.
3. A **DB superuser or schema owner** can disable the audit triggers. This is detected (hash chain plus `log_statement=ddl`) but not prevented without external WORM log shipping.
4. **Email-account takeover** allows password-reset takeover of accounts **without MFA**.
5. **Low-and-slow credential stuffing** through large residential-proxy pools can stay under per-IP limits. The per-identifier limits, MFA, and new-network sign-in emails reduce but don't eliminate this.
6. **Malicious or compromised staff** actions are audited and rank-limited, not prevented.
7. **Volumetric DDoS** needs upstream protection (CDN or scrubbing); app and nginx limits don't address it.
8. **A future compromised upstream package release** would be picked up on the next lock update if review misses it.

### Recommended future hardening
1. Run the media worker under gVisor or Firecracker with a custom seccomp profile, and serve media from a separate cookieless domain.
2. PostgreSQL **row-level security** for DMs and private content, plus envelope encryption of DM bodies with KMS-held keys. Longer term, E2EE (MLS).
3. Ship audit logs to WORM storage (e.g. S3 Object Lock) and a SIEM, with alerts on `authz.denied` bursts, `auth.mfa_failed`, `ws.protocol_violation` and audit-chain failures.
4. Require **passkeys (phishing-resistant MFA) for staff**, and use the admin IP allow-list (VPN).
5. Breached-password checks (HIBP k-anonymity) at registration, password change and login.
6. A secrets manager (Vault or cloud KMS) with automatic rotation, Redis ACLs and TLS, and mTLS between services.
7. Content-hashed asset names with SRI and immutable caching, plus Reporting API CSP reports.
8. DAST (OWASP ZAP), OpenAPI fuzzing (Schemathesis), and continuous fuzzing of the parsers.
9. Image signing (cosign), SLSA provenance, admission policies, and digest-pinned base images (via Renovate).
10. Handle tombstoning after purge, and ML-assisted spam and coordinated-behaviour detection.
11. Load, soak and chaos tests of the documented failure modes, plus a public bug-bounty programme.

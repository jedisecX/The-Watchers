# Incident response guide

Principle: **a breach will eventually happen somewhere; the architecture decides how far it spreads.**
This guide covers detecting, containing, eradicating and recovering, and it preserves evidence throughout.

## Roles

* **Incident commander**: runs the response and decides; one person.
* **Operations**: executes containment and recovery.
* **Communications**: users, legal and regulators (GDPR Art. 33: 72 hours to notify the supervisory authority for personal-data breaches).
* **Scribe**: keeps a timestamped log of every action, which becomes part of the forensic record.

## Severity

| Sev | Examples | Response |
|---|---|---|
| 1 | Confirmed data exfiltration; admin account compromise; audit chain broken; RCE | Page immediately, 24/7 |
| 2 | Credential-stuffing wave with successful logins; worker compromise suspected; secret leaked | Same day |
| 3 | Spam wave; single account takeover; CSP violation spike | Next business day |

## Detection signals (alert on these)

| Signal | Source | Possible meaning |
|---|---|---|
| `auth.login_failed` ratio spike per /24 or globally | JSON logs | Credential stuffing |
| `ratelimit.exceeded` with `login_*` buckets across many IPs | logs | Distributed stuffing |
| `auth.mfa_failed` bursts; "correct password + MFA failures" emails | logs | Passwords compromised |
| `authz.denied` or `authz.conversation_denied` bursts from one actor | logs | BOLA probing |
| `ws.protocol_violation`, `ws.subscribe_denied`, `ws.origin_rejected` | logs | Realtime probing / CSWSH attempt |
| `csrf.bad_origin`, `csrf.token_invalid` spikes | logs | CSRF campaign |
| `csp.violation` spike | CSP report endpoint | Injection attempt, or a malicious browser extension |
| `media.decode_failed` spike, worker crashes/timeouts | worker logs | Malicious-media campaign; decoder exploit attempt |
| `verify-audit` non-zero exit | cron | **Audit tampering** (Sev 1) |
| Postgres `log_statement=ddl` shows `ALTER TABLE audit_log DISABLE TRIGGER` | DB logs | **Tampering attempt** (Sev 1) |
| `db.integrity_conflict` / `http.unhandled` spikes | logs | Race exploitation, or a bug |

## Playbooks

### A. Single account takeover
1. Run the admin action `POST /admin/api/v1/users/{id}/revoke-sessions` (with a reason). This revokes all sessions and PATs and closes sockets immediately.
2. Run `python -m app.cli force-reset --handle <h>`. The password is invalidated; MFA and passkeys are kept.
3. Review the user's `audit_log` (email/MFA/passkey/token changes) and restore anything the attacker changed. The old address received a security notice on email change.
4. Help the user re-secure their email account and enable MFA.

### B. Credential-stuffing wave
1. Confirm via the logs. Identify ASNs and ranges; block them at the edge or CDN.
2. Temporarily tighten `login_*` buckets in `ratelimit.py` (or via config), and raise `POW_BASE_DIFFICULTY`.
3. For accounts with successful suspicious logins, run playbook A. Consider `force-reset --all` if the scale is large.

### C. Secret leaked (repo, logs, backup)
Rotate the leaked secret per `OPERATIONS.md` §Rotation **immediately**. Scope:
- `SECRET_KEY`: invalidates CSRF tokens and PoW challenges. Users just refresh.
- DB passwords: per role.
- Field keys: rotate, then `reencrypt`.
- TLS key: re-issue the certificate and revoke the old one.

Search logs for use of the leaked secret. Run gitleaks over history, then purge the history and treat the secret as burned regardless.

### D. Media worker compromise (decoder RCE suspected)
1. **Contain**: `docker compose stop worker`. Uploads queue safely as `pending`, and nothing unprocessed is ever served.
2. Preserve evidence: `docker export`, `docker logs`, a copy of the media and quarantine volumes.
3. Rotate the `bastion_worker` DB password (it only ever had the `media` table).
4. Rebuild the image from a clean base with patched libraries (Trivy must be clean).
5. Run `python -m app.cli media-rescan` to withdraw tampered derivatives. Review media rows updated during the window.
6. Resume the worker.

### E. API compromise (RCE in the app container)
Treat this as **all user data exposed**.
1. Isolate the host and snapshot volumes and memory for forensics.
2. Rotate **every** secret: DB roles, `SECRET_KEY`, field keys (then `reencrypt`), Redis, SMTP, TLS.
3. Run `python -m app.cli revoke-all-sessions`, then `force-reset --all`.
4. Rebuild from source at a known-good commit; verify image digests and SBOM against CI artifacts.
5. Run `verify-audit` and check the Postgres DDL logs for trigger tampering.
6. Determine the scope from logs (request IDs, IP prefixes), and notify users and regulators as required.

### F. Admin / moderator compromise
1. Demote with `POST /users/{id}/role` from another admin. If this is the only admin, use the DB console with an audited, dual-control procedure. Then revoke their sessions.
2. Review every `audit_log` row by that actor (`GET /admin/api/v1/audit?actor_id=…`). Reverse suspensions, removals, role changes and domain blocks.
3. Run `verify-audit` to prove the log itself is intact.

### G. Audit chain failure
Sev 1. The first bad `seq` locates the tampering.
1. Compare against the WORM or offsite copy.
2. Identify DB superuser and owner sessions from the Postgres connection and DDL logs.
3. Treat the database credentials as compromised (playbook E, steps 2–6).

## Evidence preservation

* Don't restart compromised containers before exporting them.
* Logs are JSON with request IDs: preserve raw copies, and hash them (SHA-256) into the incident record.
* `audit_log` is already hash-chained. Export it with `COPY audit_log TO STDOUT` and record the final `row_hash`.

## Post-incident

A blameless review within 5 business days. Add a regression test (as in the `ADV-*` series), update
`THREAT-MODEL.md` and `SECURITY.md`, and track follow-ups to completion.

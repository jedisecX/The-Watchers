# Backup & recovery

## What must be backed up

| Asset | Where | Method | RPO / retention |
|---|---|---|---|
| PostgreSQL (system of record, audit log) | `pgdata` volume | Continuous WAL archiving plus a nightly base backup (pgBackRest or WAL-G) to off-host object storage, **encrypted client-side** | RPO ≤ 5 min; PITR window 14 days; daily for 35 days; monthly for 12 months |
| Media derivatives | `media` volume | Nightly incremental sync (restic or rclone with encryption) | RPO 24 h (media can also be re-uploaded) |
| Secrets | `deploy/secrets/` or the secret manager | Secret-manager versioning, or an offline encrypted copy (two custodians) | Every rotation |
| Field-encryption key ring | secret store | As above. **Losing it makes every TOTP seed undecryptable**: users would need MFA reset via support | Every rotation; keep old keys until `reencrypt` completes |
| Configuration | git | Repository plus tags | — |

**Not backed up (by design):**
- Redis (ephemeral rate-limit counters and pub/sub);
- the `quarantine` volume (unprocessed uploads; users re-upload);
- application logs beyond the log-retention policy.

## Backup hygiene

* Backups are encrypted before leaving the host, with a key **different** from the app's secrets. That key lives in a separate account or vault that the application hosts cannot read.
* Storage uses object lock/immutability (WORM) for the retention period, so an attacker with host access cannot delete backups (ransomware defence).
* The backup credential is write-only (no delete/list) from the database host.
* Integrity: `pgbackrest verify` weekly. Media sync checksums are verified.

## Restore procedure (rehearse quarterly)

1. **Provision** a fresh, isolated environment. Never restore over a possibly-compromised host.
2. **Restore Postgres** to a target time: `pgbackrest --stanza=bastion --type=time --target="YYYY-MM-DD HH:MM:SS+00" restore`.
3. **Verify the audit chain before going live**: `python -m app.cli verify-audit` must print `OK`. If it reports tampering, choose an earlier recovery target and open an incident.
4. **Restore media**, then run `python -m app.cli media-rescan`. It withdraws any derivative that is missing or not the declared type.
5. **Secrets.** If the restore is due to a compromise, **rotate every secret first** (`OPERATIONS.md` §Rotation), then run `python -m app.cli revoke-all-sessions`.
6. **Run the migrations** (idempotent), start the services, and run the smoke test (`scripts/e2e_smoke.py` against a staging hostname).
7. Record the RTO/RPO achieved and any issues in the restore log.

Targets: **RTO ≤ 4 h**, **RPO ≤ 5 min** (database).

## Partial recovery

| Situation | Action |
|---|---|
| A user deleted their account by mistake (within 30 days) | Support verifies identity, then an admin clears `deleted_at`/`purge_after` and sets `status='active'` in a transaction together with an `audit_log` entry, and the user resets their password. |
| Bad deploy corrupted counters | `python -m app.cli recount` |
| Media volume lost | Restore from backup, then `media-rescan`. Unrecoverable items are marked `failed` and show as unavailable. |
| Redis lost | No action: limits reset (credential endpoints fail closed until Redis returns), realtime reconnects. |

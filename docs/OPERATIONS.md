# Operations runbook

## Scheduled jobs

| Job | Command | Schedule | Alert on |
|---|---|---|---|
| Retention purge | `python -m app.cli purge` | hourly | non-zero exit |
| Audit chain verification | `python -m app.cli verify-audit` | hourly | **non-zero exit (Sev 1)** |
| Media integrity rescan | `python -m app.cli media-rescan` | weekly | non-zero exit (items withdrawn) |
| Mail delivery | `mailer` service (`app.cli mail-loop`) | continuous | outbox rows with `attempts ≥ 5` |
| Dependency and image re-scan | CI `schedule` (weekly) | weekly | failed run |

## Rotation

All secrets are files under `/run/secrets` (Compose secrets or a secret manager). Procedure: add the new value,
restart consumers, then retire the old value.

| Secret | Impact of rotation | Steps |
|---|---|---|
| `SECRET_KEY` | CSRF tokens and PoW challenges become invalid (clients refresh the session endpoint and continue); **sessions stay valid** because they are DB-backed | Replace the file, restart api and mailer |
| `FIELD_ENCRYPTION_KEYS` | None if done as a key ring | 1. Set `new_key,old_key` (new first). 2. Restart the api. 3. `python -m app.cli reencrypt`. 4. Remove `old_key`, restart. |
| DB role passwords | Brief reconnects | `ALTER ROLE bastion_app PASSWORD '…'` as superuser; update `pg_app_password` and `database_url`; restart the api (and the same for worker/migrator) |
| Redis password | Rate-limit counters reset | Update `redis_password` and `redis_url`; restart redis, api and mailer |
| SMTP credentials | — | Update `smtp_password`; restart the mailer |
| TLS certificate | — | Replace `tls_cert`/`tls_key`; `docker compose exec nginx nginx -s reload` |
| All sessions (kill switch) | Everyone is signed out | `python -m app.cli revoke-all-sessions` |
| All passwords | Everyone must reset (MFA kept) | `python -m app.cli force-reset --all` |

Recommended cadence: DB and Redis passwords every 90 days; field keys yearly; `SECRET_KEY` yearly or on any
suspicion; and immediately on staff departure for any secret they could have read.

## Capacity and limits

* Argon2 at m=64 MiB, t=3, p=4 takes about 60–100 ms per hash. `ARGON2_MAX_CONCURRENCY` (default 8) bounds memory to about 512 MiB per API process.
* WebSocket caps: 5 per user, 20 per IP, 10k per process. Each connection holds an outbound queue of at most 256 events.
* The worker processes one job at a time per replica. Scale replicas horizontally; claiming uses `SKIP LOCKED`.

## Health

* `GET /api/v1/health` returns 200 `{"status":"ok"}`, or 503 when the DB is unreachable.
* The container `HEALTHCHECK` polls the endpoint above.
* Logs are JSON on stdout. Security events use `logger=bastion.security` with an `event` field.

## Useful queries (read-only replica recommended)

```sql
-- Failed-media reasons in the last day
SELECT error_code, count(*) FROM media WHERE state='failed' AND created_at > now()-interval '1 day' GROUP BY 1;
-- Open report backlog by reason
SELECT reason, count(*) FROM reports WHERE status='open' GROUP BY 1 ORDER BY 2 DESC;
-- Staff accounts without MFA (should be empty)
SELECT handle FROM users WHERE role <> 'user' AND mfa_enabled_at IS NULL;
```

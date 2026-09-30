# Deployment guide

The reference deployment is Docker Compose (`deploy/docker-compose.yml`). The same topology maps directly
onto Kubernetes (see §6).

## 1. Prerequisites

* A Linux host with Docker Engine ≥ 24 and the Compose plugin. Keep the kernel patched; enable automatic security updates.
* A DNS name (`BASTION_HOST`) and a TLS certificate/key (ACME/Let's Encrypt recommended).
* An SMTP relay with SPF, DKIM and DMARC configured for your sending domain.
* Encrypted disks for the Postgres and media volumes (LUKS or cloud-provider encryption).
* An off-host, encrypted backup target (see `BACKUP-RECOVERY.md`).
* Upstream DDoS protection or a CDN is recommended for internet-facing deployments.

## 2. First deployment

```sh
git clone … bastion && cd bastion
./deploy/gen-secrets.sh                                   # 0600 files in deploy/secrets/ (gitignored)
cp /path/fullchain.pem deploy/secrets/tls_cert
cp /path/privkey.pem   deploy/secrets/tls_key && chmod 600 deploy/secrets/tls_*
PUBLIC_BASE_URL=https://bastion.example python scripts/render_nginx.py   # CSP for nginx from app source
export PUBLIC_BASE_URL=https://bastion.example BASTION_HOST=bastion.example SMTP_HOST=smtp.example.com \
       SMTP_USER=bastion SMTP_FROM="Bastion <no-reply@bastion.example>"
echo -n 'smtp-password' > deploy/secrets/smtp_password
docker compose -f deploy/docker-compose.yml up -d --build
docker compose -f deploy/docker-compose.yml run --rm api python -m app.cli create-admin \
       --handle yourname --email you@bastion.example
```

Then sign in with the one-time password, **change it, enrol TOTP or a passkey**, and re-authenticate. The admin
console (`/admin`) refuses staff accounts without MFA.

## 3. What the stack enforces

| Property | How |
|---|---|
| Only nginx is reachable | Only `nginx` has `ports:`. Postgres and Redis publish nothing. |
| Network segmentation | `backend` and `workernet` are `internal: true` (no egress). The worker shares a network only with Postgres. Only the mailer can reach SMTP. |
| Least privilege | Separate DB roles and passwords per service; the worker's role can only touch processing columns of `media`. |
| Non-root | App images run as UID 10001, nginx as 101, Redis as 999. |
| Immutable containers | `read_only: true` rootfs with size-limited `tmpfs` for `/tmp`; code owned by root and not writable by the service. |
| No privilege escalation | `cap_drop: [ALL]`, `no-new-privileges`. Postgres alone re-adds the capabilities its entrypoint needs to drop to the `postgres` user. |
| Resource limits | `mem_limit`, `cpus`, `pids_limit` per service (fork bombs, memory exhaustion by a decoder). |
| Secrets | Docker secrets mounted at `/run/secrets/*`, read via `*_FILE`. Never baked into images or passed as build args. |
| Fail-safe config | With `ENV=production` the app **refuses to start** on http base URL, missing Redis/SMTP, placeholder secrets, weak argon2/PoW settings, or `/tmp` media dirs. |
| Proxy trust | `TRUSTED_PROXIES` is exactly nginx's fixed IP. nginx *overwrites* `X-Forwarded-For`. Uvicorn runs `--no-proxy-headers`, so there is one interpretation only. |
| Media | The API writes raw uploads to `quarantine`; only the worker reads them. Derivatives go to `media`, which nginx mounts **read-only** and serves solely through an `internal` location after the API authorises each request (`X-Accel-Redirect`). |
| Postgres | `scram-sha-256`, connection/DDL logging (detects audit-trigger tampering), statement/idle timeouts. |
| Redis | Password-protected, dangerous commands renamed away, no persistence (ephemeral data only), memory-capped. |

## 4. Production checklist

- [ ] Base images pinned by **digest** (`python:3.11-slim-bookworm@sha256:…`, `nginx-unprivileged@sha256:…`, `postgres@sha256:…`, `redis@sha256:…`); Renovate/Dependabot keeps them current.
- [ ] CI green on the exact commit: tests, E2E, SAST, secrets, dependency, container and IaC gates.
- [ ] `HSTS_PRELOAD=true` only after confirming every subdomain is HTTPS-only.
- [ ] Admin allow-list enabled in nginx (`/admin/api/` → VPN range) if staff access comes from known networks.
- [ ] Log shipping to a central store/SIEM with alerts (see `INCIDENT-RESPONSE.md` §Detection).
- [ ] Backups configured **and a restore rehearsed** (`BACKUP-RECOVERY.md`).
- [ ] Cron (e.g. hourly/daily):
  - `python -m app.cli purge` (retention);
  - `python -m app.cli verify-audit` (alert on non-zero exit);
  - `python -m app.cli media-rescan` (weekly).
- [ ] `security.txt` published; the vulnerability intake mailbox monitored.
- [ ] Clocks NTP-synchronised (TOTP, token expiry).

## 5. Upgrades

1. Read the changelog and the migration notes.
2. Take a backup (or confirm PITR is current).
3. Run `docker compose build`, then `docker compose run --rm migrate`. Migrations run as `bastion_migrator` only.
4. `docker compose up -d`. The API is stateless and restarts safely; the worker re-claims stuck jobs after 5 minutes.

Every migration has a tested `downgrade()`. Prefer roll-forward fixes, but rollback is available.

## 6. Kubernetes mapping

| Compose | Kubernetes |
|---|---|
| networks `internal` | `NetworkPolicy` default-deny, with explicit allows: nginx→api:8000; api→postgres:5432, redis:6379; worker→postgres:5432; mailer→SMTP egress |
| `read_only`, `cap_drop`, `user`, `no-new-privileges` | `securityContext: {readOnlyRootFilesystem: true, runAsNonRoot: true, runAsUser: 10001, allowPrivilegeEscalation: false, capabilities: {drop: [ALL]}, seccompProfile: {type: RuntimeDefault}}` |
| secrets | `Secret` volumes (or the CSI Secrets Store driver with Vault/KMS); `automountServiceAccountToken: false` |
| worker isolation | a dedicated node pool, and a `RuntimeClass` of gVisor/Kata where available |
| limits | `resources.limits`, `LimitRange`, `PodDisruptionBudget` |
| admission | Pod Security Admission `restricted`, and cosign signature verification |

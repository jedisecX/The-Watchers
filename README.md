# 🛡 Bastion: a security-first social network

Bastion is a complete social network built on one design principle:

> **Assume every component will eventually be attacked.** Minimise attack surface, isolate failures,
> validate every boundary, and make security a first-class architectural requirement.

It is not "unhackable". It is hardened, auditable and continuously tested. Every control is backed by an
automated test that fails if the control regresses (459 tests, a browser E2E under the production CSP,
and CI security gates). See **[SECURITY.md](SECURITY.md)** for the controls, the adversarial findings and
the honest status, and **[THREAT-MODEL.md](THREAT-MODEL.md)** for the STRIDE analysis.

## Features

**Accounts**
- Registration with proof-of-work (no CAPTCHA) and email verification.
- Login and logout, password reset.
- TOTP MFA with recovery codes; passkeys (WebAuthn), including passwordless sign-in.
- Session/device management; personal API tokens with scopes; data export; account deletion.

**Social**
- Profiles and avatars; posts (plaintext, with safely rendered links, @mentions and #hashtags); replies, likes and reposts.
- Follows, private accounts with follow requests, blocks, mutes; followers and following lists.
- Home and explore timelines, hashtags, and search over posts and people.
- Notifications with realtime push; direct messages (1:1) with realtime delivery, typing indicators, unsend, and attachments.
- Image and video uploads processed by an isolated worker.

**Privacy**
- Profile and post visibility, DM permissions, discoverability, likes visibility.
- Metadata stripping, retention policies.

**Safety**
- Reporting with evidence snapshots; a moderation console (reports, removals, warnings, suspensions, roles, blocked link domains).
- A hash-chained audit log.
- Adaptive rate limits on every endpoint; duplicate-content and spam controls.

## Architecture

```
Browser SPA (vanilla JS, CSP + Trusted Types) ──HTTPS/WSS──▶ nginx ──▶ FastAPI (API + WebSocket)
                                                               │            │        │
                                            X-Accel (internal) │     PostgreSQL 16   Redis
                                                               ▼            ▲   (roles: migrator/app/worker)
                                                     media volume ◀── media worker (rlimited decoder subprocess)
```

Details: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) (components, data flow, trust boundaries, design decisions).

## Quick start (development)

Requirements: Python 3.11, PostgreSQL 16, Redis 7 (optional in dev), ffmpeg (for video).

```sh
python3.11 -m venv .venv && . .venv/bin/activate
pip install --require-hashes -r backend/requirements-dev.txt
./scripts/ci_db_setup.sh                     # least-privilege roles + dev/test databases (as the postgres superuser)
cd backend
export MIGRATION_DATABASE_URL=postgresql+psycopg://bastion_migrator:dev_migrator_pw@localhost/bastion
alembic upgrade head
export DATABASE_URL=postgresql+psycopg://bastion_app:dev_app_pw@localhost/bastion \
       WORKER_DATABASE_URL=postgresql+psycopg://bastion_worker:dev_worker_pw@localhost/bastion \
       REDIS_URL=redis://localhost:6379/0 PUBLIC_BASE_URL=http://localhost:8000 POW_BASE_DIFFICULTY=12
uvicorn app.main:create_app --factory --port 8000 &     # API + SPA at http://localhost:8000
python -m app.worker &                                   # media worker
python -m app.cli outbox                                 # dev only: see verification/reset emails
```

## Tests and checks

```sh
cd backend && python -m pytest tests            # 459 tests against real Postgres, as the restricted app role
ruff check app tests migrations && bandit -r app -ll
python ../scripts/check_security.py             # DOM sinks, SQL interpolation, vendored hashes, CSP drift…
python ../scripts/e2e_smoke.py                  # Chromium E2E against a running stack; fails on any CSP/TT violation
pip-audit -r requirements.txt --require-hashes --strict
```

## Production

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md):
- `./deploy/gen-secrets.sh` generates every secret as a separate file;
- `docker compose -f deploy/docker-compose.yml up -d --build` starts non-root, read-only containers with all capabilities dropped, on segmented networks, with no public database or Redis.

## Documentation

| Document | Contents |
|---|---|
| [SECURITY.md](SECURITY.md) | Security model, control→test mapping, adversarial findings, CI gates, **SECURITY STATUS** |
| [THREAT-MODEL.md](THREAT-MODEL.md) | Assets, actors, attack surface, assumptions, requirements, STRIDE threats (Threat → Attack path → Impact → Mitigation → Detection → Recovery), abuse cases, failure modes, recovery |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System architecture, data-flow and trust-boundary diagrams |
| [docs/API.md](docs/API.md) · [openapi.json](docs/openapi.json) | Every endpoint with auth, schemas and rate limits (generated) |
| [docs/DATABASE.md](docs/DATABASE.md) | Schema, constraints, indexes, DB roles (generated) |
| [docs/SECURITY-HEADERS.md](docs/SECURITY-HEADERS.md) | Every security header and why |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Hardened deployment, production checklist, Kubernetes mapping |
| [docs/BACKUP-RECOVERY.md](docs/BACKUP-RECOVERY.md) | Backups, restore procedure, RPO/RTO |
| [docs/INCIDENT-RESPONSE.md](docs/INCIDENT-RESPONSE.md) | Detection signals and playbooks |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Scheduled jobs, secret rotation, capacity |
| [docs/ADMIN-GUIDE.md](docs/ADMIN-GUIDE.md) | Moderator/administrator guide |
| [docs/PRIVACY.md](docs/PRIVACY.md) | Data inventory, user controls, retention |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Secure development rules for contributors |

## Repository layout

```
backend/app/          FastAPI application (api/ routers, services/, authz.py, middleware.py, worker.py …)
backend/migrations/   Alembic migrations (0002 = audit immutability + least-privilege grants)
backend/tests/        Security-focused test suites
frontend/             No-build SPA (index.html, admin.html, assets/js, assets/css, vendored QR lib)
deploy/               docker-compose, nginx, Postgres role bootstrap, secret generator
scripts/              security gates, E2E, doc generators, CI DB setup
.github/workflows/    CI security pipeline
```

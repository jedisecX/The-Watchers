# Contributing to Bastion

Security is a first-class requirement, and these rules are enforced by CI wherever possible.

## Workflow

1. Branch from `main`. Keep changes small and focused.
2. For any change to authentication, authorization, parsing, uploads, realtime, or deployment, **update `THREAT-MODEL.md`**: add or adjust the threat entry and the test ID.
3. **Add a failing test first** for every security fix (see the `ADV-*` tests in `backend/tests/test_adversarial.py`).
4. Run the local gates before pushing:
   ```sh
   cd backend && python -m pytest tests && ruff check app tests migrations && bandit -r app -ll
   python ../scripts/check_security.py
   ```
5. Pull requests need one reviewer. Changes to `authz.py`, `deps.py`, `middleware.py`, `services/sessions.py`, migrations, `deploy/` or `.github/` need a **security-owner** review (CODEOWNERS).

## Rules (and what enforces them)

| Rule | Enforced by |
|---|---|
| Every new endpoint is declared in `tests/test_routes.py::INVENTORY` with an access class | `test_every_route_is_declared` |
| Every router uses `route_class=StrictRoute` | `check_security.py` |
| Handlers take `p: Principal = Depends(principal)`, which applies CSRF and the baseline rate limit | review; the route sweep proves no protected route answers anonymously |
| Authorization goes through `authz.py`. Post queries **must** use `visible_posts_query`/`visible_posts_clause` | review; `test_social_posts.py` alternate-path tests |
| Ownership is part of the SQL `WHERE` (`… AND author_id = :me`), not a check after fetching | review |
| Objects the caller can't see return **404**, not 403 | tests |
| Request models subclass `schemas.In` (`extra="forbid"`); integers use `StrictInt` with bounds; booleans use `StrictBool` | review; ADV-11 tests |
| Responses are built by `serializers.py` allow-lists, never by dumping ORM objects | review |
| SQL: SQLAlchemy expressions only. Never interpolate into `text()` | `check_security.py`, bandit B608, ruff S608 |
| No `shell=True`, `os.system`, `eval`, `exec`, `pickle`, `yaml.load`; subprocesses use argv lists | `check_security.py`, bandit |
| No `assert` in application code (use `errors.ensure`) | `check_security.py` |
| Frontend: no HTML-string sinks, no inline script/style/handlers; user data only via `textContent` / `h()` | `check_security.py`, Trusted Types at runtime, E2E |
| New third-party JS must be vendored with its hash in `VENDORED.md` | `check_security.py` |
| Never log secrets, tokens, bodies or message content; pass identifiers to `sec_event` | redaction filter plus `test_log_redact` |
| Race-prone writes use constraints plus `ON CONFLICT` or conditional `UPDATE … RETURNING` | race tests |
| Schema changes: an Alembic migration with a working `downgrade()`, plus explicit grants for new tables | CI `alembic check` and up/down/up |
| Dependencies: edit `requirements*.in`, then `uv pip compile --generate-hashes`. Wheels only | CI lockfile and wheels-only jobs, pip-audit |
| Secrets never in git. Use `*_FILE` env vars | gitleaks |

## Security-sensitive review checklist

- [ ] Who can call this? Is that declared in `INVENTORY`, and enforced server-side?
- [ ] Can a different user's ID be substituted anywhere (path, body, query, cursor, WebSocket frame)?
- [ ] What happens with 50 concurrent identical requests?
- [ ] Is every input bounded (length, count, numeric range, nesting)?
- [ ] Does any new path leak private content (search, notifications, media, reposts, counters)?
- [ ] Does the change respect blocks, mutes and privacy settings?
- [ ] Is a security-relevant action audited, and is the actor notified where appropriate?
- [ ] Does the change fail closed when a dependency (Redis, SMTP, worker) is down?

## Reporting vulnerabilities

Please don't open public issues. See [SECURITY.md](SECURITY.md#reporting-a-vulnerability).

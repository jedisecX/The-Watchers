"""Test harness.

* Migrations run as the *migrator* role; the application runs as the restricted *app* role, so
  every test also exercises the production privilege model (e.g. audit_log is INSERT-only).
* Tables are truncated between tests. audit_log's TRUNCATE trigger is disabled by its owner
  for this test-only cleanup.
"""
from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TMP = Path(os.environ.get("BASTION_TEST_TMP", "/tmp/bastion-test"))

os.environ.update({
    "ENV": "test",
    "DATABASE_URL": os.environ.get("TEST_DATABASE_URL",
                                   "postgresql+psycopg://bastion_app:dev_app_pw@localhost/bastion_test"),
    "MIGRATION_DATABASE_URL": os.environ.get("TEST_MIGRATION_DATABASE_URL",
                                             "postgresql+psycopg://bastion_migrator:dev_migrator_pw@localhost/bastion_test"),
    "WORKER_DATABASE_URL": os.environ.get("TEST_WORKER_DATABASE_URL",
                                          "postgresql+psycopg://bastion_worker:dev_worker_pw@localhost/bastion_test"),
    "PUBLIC_BASE_URL": "https://testserver",
    "ALLOWED_HOSTS": "testserver",
    "SECRET_KEY": "test-secret-key-0123456789abcdef0123456789abcdef",
    "FIELD_ENCRYPTION_KEYS": "dGVzdC1maWVsZC1rZXktMzItYnl0ZXMtbG9uZyEhISE=",
    "ARGON2_TIME_COST": "1",
    "ARGON2_MEMORY_KIB": "8192",
    "ARGON2_PARALLELISM": "1",
    "POW_BASE_DIFFICULTY": "4",
    "MEDIA_QUARANTINE_DIR": str(TMP / "quarantine"),
    "MEDIA_STORE_DIR": str(TMP / "media"),
    "LOG_LEVEL": "WARNING",
})
os.environ.pop("REDIS_URL", None)
sys.path.insert(0, str(ROOT))

import pytest  # noqa: E402
from sqlalchemy import create_engine, text  # noqa: E402

TABLES = [
    "audit_log", "email_outbox", "blocked_domains", "reports", "messages", "conversation_members",
    "conversations", "notifications", "reposts", "likes", "post_media", "post_mentions", "post_hashtags",
    "posts", "mutes", "blocks", "follow_requests", "follows", "api_tokens", "webauthn_credentials",
    "recovery_codes", "auth_tokens", "sessions", "media", "users",
]


@pytest.fixture(scope="session", autouse=True)
def _migrate():
    env = dict(os.environ)
    for args in (["downgrade", "base"], ["upgrade", "head"]):
        r = subprocess.run([sys.executable, "-m", "alembic", *args], cwd=ROOT, env=env,
                           capture_output=True, text=True)
        assert r.returncode == 0, r.stderr[-2000:]
    (TMP / "quarantine").mkdir(parents=True, exist_ok=True)
    (TMP / "media").mkdir(parents=True, exist_ok=True)
    yield


@pytest.fixture(scope="session")
def migrator_engine():
    eng = create_engine(os.environ["MIGRATION_DATABASE_URL"])
    yield eng
    eng.dispose()


@pytest.fixture(autouse=True)
def _clean(migrator_engine):
    with migrator_engine.begin() as c:
        c.execute(text("ALTER TABLE audit_log DISABLE TRIGGER audit_log_no_truncate"))
        c.execute(text(f"TRUNCATE {', '.join(TABLES)} RESTART IDENTITY CASCADE"))
        c.execute(text("ALTER TABLE audit_log ENABLE TRIGGER audit_log_no_truncate"))
    import shutil
    for d in ("quarantine", "media"):
        shutil.rmtree(TMP / d, ignore_errors=True)
        (TMP / d).mkdir(parents=True, exist_ok=True)
    from app.ratelimit import fallback_limiter, get_limiter
    get_limiter().reset()
    fallback_limiter().reset()
    yield


@pytest.fixture(scope="session")
def app():
    from app.main import create_app
    return create_app()


@pytest.fixture
def db():
    from app.db import new_session
    s = new_session()
    yield s
    s.rollback()
    s.close()


@pytest.fixture
def client(app):
    from tests.helpers import make_client
    return make_client(app)


@pytest.fixture
def factory(app):
    from tests.helpers import Factory
    return Factory(app)

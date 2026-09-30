"""Security hardening: audit-log immutability and least-privilege grants.

* ``audit_log``: triggers reject UPDATE, DELETE and TRUNCATE for *every* role, including the
  owner, so tampering requires a superuser who first drops the trigger, and that is itself
  detectable by the hash chain and by DDL logging.
* ``bastion_app``: DML on application tables; INSERT/SELECT only on ``audit_log``; no DDL.
* ``bastion_worker``: SELECT on ``media`` and UPDATE on the processing columns only. It cannot
  read users, sessions, messages or anything else, which limits a decoder RCE's blast radius.

Revision ID: 0002
Revises: 0001
"""
from alembic import op

revision = "0002"
down_revision = "0001"
branch_labels = None
depends_on = None

APP_TABLES = [
    "users", "sessions", "auth_tokens", "recovery_codes", "webauthn_credentials", "api_tokens",
    "follows", "follow_requests", "blocks", "mutes", "posts", "post_hashtags", "post_mentions",
    "post_media", "likes", "reposts", "notifications", "conversations", "conversation_members",
    "messages", "media", "reports", "blocked_domains", "email_outbox",
]
WORKER_UPDATE_COLS = [
    "state", "variants", "width", "height", "duration_ms", "error_code", "attempts",
    "claimed_at", "processed_at", "quarantine_key",
]


def _role_exists_guard(role: str, sql: str) -> str:
    # Grants are skipped if the role does not exist (e.g. a developer's single-role local DB).
    return f"""
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{role}') THEN
    {sql}
  END IF;
END $$;"""


def upgrade() -> None:
    op.execute("""
CREATE OR REPLACE FUNCTION audit_log_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'audit_log is append-only' USING ERRCODE = 'insufficient_privilege';
END $$;
""")
    op.execute("""
CREATE TRIGGER audit_log_no_update BEFORE UPDATE OR DELETE ON audit_log
FOR EACH ROW EXECUTE FUNCTION audit_log_immutable();
""")
    op.execute("""
CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log
FOR EACH STATEMENT EXECUTE FUNCTION audit_log_immutable();
""")

    tables = ", ".join(APP_TABLES)
    op.execute(_role_exists_guard("bastion_app", f"""
    EXECUTE 'REVOKE ALL ON ALL TABLES IN SCHEMA public FROM bastion_app';
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON {tables} TO bastion_app';
    EXECUTE 'GRANT SELECT, INSERT ON audit_log TO bastion_app';
    EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO bastion_app';
"""))
    cols = ", ".join(WORKER_UPDATE_COLS)
    op.execute(_role_exists_guard("bastion_worker", f"""
    EXECUTE 'REVOKE ALL ON ALL TABLES IN SCHEMA public FROM bastion_worker';
    EXECUTE 'GRANT SELECT ON media TO bastion_worker';
    EXECUTE 'GRANT UPDATE ({cols}) ON media TO bastion_worker';
"""))


def downgrade() -> None:
    op.execute("DROP TRIGGER IF EXISTS audit_log_no_truncate ON audit_log")
    op.execute("DROP TRIGGER IF EXISTS audit_log_no_update ON audit_log")
    op.execute("DROP FUNCTION IF EXISTS audit_log_immutable()")

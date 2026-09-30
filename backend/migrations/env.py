"""Alembic environment. Runs as the *migrator* role (schema owner); the app role never runs DDL."""
import os

from alembic import context
from sqlalchemy import create_engine, pool

from app.models import Base

config = context.config
target_metadata = Base.metadata


def _url() -> str:
    url = os.environ.get("MIGRATION_DATABASE_URL")
    if not url and os.environ.get("MIGRATION_DATABASE_URL_FILE"):
        with open(os.environ["MIGRATION_DATABASE_URL_FILE"]) as fh:
            url = fh.read().strip()
    if not url:
        raise RuntimeError("MIGRATION_DATABASE_URL is required")
    return url


def run_migrations_offline() -> None:
    context.configure(url=_url(), target_metadata=target_metadata, literal_binds=True, compare_type=True)
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    engine = create_engine(_url(), poolclass=pool.NullPool)
    with engine.connect() as connection:
        context.configure(connection=connection, target_metadata=target_metadata, compare_type=True)
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()

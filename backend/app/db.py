from __future__ import annotations

from collections.abc import Iterator
from functools import lru_cache

from sqlalchemy import create_engine
from sqlalchemy.engine import Engine
from sqlalchemy.orm import Session, sessionmaker

from .config import get_settings


@lru_cache(maxsize=1)
def get_engine() -> Engine:
    s = get_settings()
    return create_engine(
        s.database_url,
        pool_pre_ping=True,
        pool_size=10,
        max_overflow=10,
        pool_timeout=10,
        # Per-statement timeout bounds the damage of any pathological query.
        connect_args={"options": "-c statement_timeout=5000 -c idle_in_transaction_session_timeout=15000"},
    )


@lru_cache(maxsize=1)
def _factory() -> sessionmaker[Session]:
    return sessionmaker(bind=get_engine(), expire_on_commit=False, autoflush=False)


def new_session() -> Session:
    return _factory()()


def get_db() -> Iterator[Session]:
    db = new_session()
    try:
        yield db
        # Handlers commit explicitly; anything left uncommitted is discarded.
        db.rollback()
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()

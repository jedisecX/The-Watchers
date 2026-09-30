"""Single-use, purpose-bound, short-lived tokens (R-AUTH-4).

Consumption is a single conditional UPDATE … RETURNING, so two concurrent requests presenting
the same token cannot both succeed (THREAT-MODEL T-3).
"""
from __future__ import annotations

import uuid
from datetime import datetime, timedelta, timezone

from sqlalchemy import delete, select, update
from sqlalchemy.orm import Session

from ..models import AuthToken
from ..security.crypto import random_token, token_hash

TTL = {
    "email_verify": timedelta(hours=24),
    "email_change": timedelta(hours=1),
    "password_reset": timedelta(minutes=30),
    "mfa_pending": timedelta(minutes=5),
    "ws_ticket": timedelta(seconds=60),
    "webauthn_register": timedelta(minutes=5),
    "webauthn_login": timedelta(minutes=5),
}
MAX_ATTEMPTS = {"mfa_pending": 5}


def issue(db: Session, purpose: str, user_id: uuid.UUID | None, data: dict | None = None,
          invalidate_previous: bool = True) -> str:
    if invalidate_previous and user_id is not None:
        # Only the newest token of a purpose is valid: old reset links die when a new one is sent.
        db.execute(
            update(AuthToken)
            .where(AuthToken.user_id == user_id, AuthToken.purpose == purpose, AuthToken.used_at.is_(None))
            .values(used_at=datetime.now(timezone.utc))
        )
    tok = random_token(32)
    now = datetime.now(timezone.utc)
    db.add(AuthToken(
        user_id=user_id, purpose=purpose, token_hash=token_hash(tok), data=data or {},
        created_at=now, expires_at=now + TTL[purpose],
    ))
    db.flush()
    return tok


def peek(db: Session, purpose: str, tok: str) -> AuthToken | None:
    """Look up a live token without consuming it (used for pending-MFA attempt counting)."""
    if not tok or len(tok) > 128:
        return None
    return db.scalar(
        select(AuthToken).where(
            AuthToken.token_hash == token_hash(tok),
            AuthToken.purpose == purpose,
            AuthToken.used_at.is_(None),
            AuthToken.expires_at > datetime.now(timezone.utc),
        )
    )


def consume(db: Session, purpose: str, tok: str) -> AuthToken | None:
    if not tok or len(tok) > 128:
        return None
    row = db.execute(
        update(AuthToken)
        .where(
            AuthToken.token_hash == token_hash(tok),
            AuthToken.purpose == purpose,
            AuthToken.used_at.is_(None),
            AuthToken.expires_at > datetime.now(timezone.utc),
        )
        .values(used_at=datetime.now(timezone.utc))
        .returning(AuthToken)
    ).scalar_one_or_none()
    return row


def register_failure(db: Session, token_id: uuid.UUID, purpose: str) -> int:
    """Increments attempts; burns the token when the attempt budget is exhausted."""
    attempts = db.execute(
        update(AuthToken).where(AuthToken.id == token_id).values(attempts=AuthToken.attempts + 1)
        .returning(AuthToken.attempts)
    ).scalar_one()
    if attempts >= MAX_ATTEMPTS.get(purpose, 1_000_000):
        db.execute(update(AuthToken).where(AuthToken.id == token_id).values(used_at=datetime.now(timezone.utc)))
    return attempts


def purge_expired(db: Session) -> int:
    cutoff = datetime.now(timezone.utc) - timedelta(days=1)
    return db.execute(delete(AuthToken).where(AuthToken.expires_at < cutoff)).rowcount

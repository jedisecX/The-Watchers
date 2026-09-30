"""Server-side sessions.

* 256-bit random token in a ``__Host-`` cookie; only its SHA-256 is stored.
* ``epoch`` must equal ``users.session_epoch``. Bumping the user's epoch invalidates every
  session and API token at once (password reset, "log out everywhere", admin revocation).
* Idle timeout (shorter for privileged roles) and absolute timeout.
* CSRF token = HMAC(csrf-key, session token hash): bound to the session, stateless, rotates
  whenever the session rotates.
"""
from __future__ import annotations

import base64
import uuid
from datetime import datetime, timedelta, timezone

from sqlalchemy import select, update
from sqlalchemy.orm import Session

from ..config import get_settings
from ..models import Session as DbSession
from ..models import User
from ..security.crypto import constant_time_eq, derive_key, hmac_sha256, random_token, token_hash

COOKIE_NAME = "__Host-bastion_sid"
LAST_SEEN_GRANULARITY = timedelta(seconds=60)


def now() -> datetime:
    return datetime.now(timezone.utc)


def create_session(
    db: Session, user: User, *, ip_prefix: str, user_agent: str, auth_method: str, mfa: bool
) -> tuple[str, DbSession]:
    s = get_settings()
    token = random_token(32)
    t = now()
    row = DbSession(
        user_id=user.id,
        token_hash=token_hash(token),
        epoch=user.session_epoch,
        created_at=t,
        last_seen_at=t,
        expires_at=t + timedelta(days=s.session_absolute_days),
        reauth_at=t,
        mfa_at=t if mfa else None,
        ip_prefix=ip_prefix,
        user_agent=(user_agent or "")[:200],
        auth_method=auth_method,
    )
    db.add(row)
    db.flush()
    return token, row


def idle_limit(user: User) -> timedelta:
    s = get_settings()
    if user.role in ("moderator", "admin"):
        return timedelta(minutes=s.priv_session_idle_minutes)
    return timedelta(minutes=s.session_idle_minutes)


def load_session(db: Session, token: str) -> tuple[DbSession, User] | None:
    if not token or len(token) > 128:
        return None
    row = db.execute(
        select(DbSession, User).join(User, User.id == DbSession.user_id).where(DbSession.token_hash == token_hash(token))
    ).first()
    if not row:
        return None
    sess, user = row
    t = now()
    if (
        sess.revoked_at is not None
        or sess.expires_at <= t
        or sess.epoch != user.session_epoch
        or user.status != "active"
        or user.deleted_at is not None
        or t - sess.last_seen_at > idle_limit(user)
    ):
        return None
    if t - sess.last_seen_at > LAST_SEEN_GRANULARITY:
        db.execute(update(DbSession).where(DbSession.id == sess.id).values(last_seen_at=t))
        db.commit()
        sess.last_seen_at = t
    return sess, user


def revoke(db: Session, session_id: uuid.UUID, user_id: uuid.UUID) -> bool:
    """Scoped to the owning user in SQL, so users cannot revoke others' sessions (E-2)."""
    res = db.execute(
        update(DbSession)
        .where(DbSession.id == session_id, DbSession.user_id == user_id, DbSession.revoked_at.is_(None))
        .values(revoked_at=now())
    )
    return res.rowcount == 1


def revoke_all(db: Session, user_id: uuid.UUID, *, except_id: uuid.UUID | None = None) -> int:
    q = update(DbSession).where(DbSession.user_id == user_id, DbSession.revoked_at.is_(None))
    if except_id:
        q = q.where(DbSession.id != except_id)
    return db.execute(q.values(revoked_at=now())).rowcount


def bump_epoch(db: Session, user_id: uuid.UUID) -> None:
    """Invalidates every session and API token for the user immediately."""
    db.execute(update(User).where(User.id == user_id).values(session_epoch=User.session_epoch + 1))
    revoke_all(db, user_id)


def csrf_token(sess: DbSession) -> str:
    mac = hmac_sha256(derive_key("csrf"), sess.token_hash)
    return base64.urlsafe_b64encode(mac).rstrip(b"=").decode()


def check_csrf(sess: DbSession, presented: str | None) -> bool:
    return bool(presented) and constant_time_eq(csrf_token(sess), presented)


def cookie_kwargs(max_age: int) -> dict:
    return {
        "key": COOKIE_NAME,
        "max_age": max_age,
        "path": "/",
        "secure": True,
        "httponly": True,
        "samesite": "lax",
    }

"""Notifications. Created inside the triggering transaction; realtime push happens after commit.

Suppressed when: actor == recipient, a block exists either way, the recipient muted the actor,
or the actor is a brand-new account mentioning a non-follower (block-evasion damping, AB-6).
Duplicate notifications are impossible (unique index with NULLS NOT DISTINCT).
"""
from __future__ import annotations

import uuid
from datetime import datetime, timedelta, timezone

from sqlalchemy import and_, delete, exists, or_, select
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.orm import Session

from ..authz import blocked_either_way, follows_clause
from ..models import Mute, Notification, User


def create(db: Session, *, recipient: uuid.UUID, actor: User, type_: str, post_id: uuid.UUID | None = None) -> dict | None:
    if recipient == actor.id:
        return None
    suppressed = db.scalar(select(or_(
        blocked_either_way(recipient, actor.id),
        exists().where(Mute.muter_id == recipient, Mute.muted_id == actor.id),
    )))
    if suppressed:
        return None
    if type_ == "mention" and datetime.now(timezone.utc) - actor.created_at < timedelta(hours=24):
        if not db.scalar(select(follows_clause(recipient, actor.id))):
            return None
    row = db.execute(
        insert(Notification).values(recipient_id=recipient, actor_id=actor.id, type=type_, post_id=post_id)
        .on_conflict_do_nothing().returning(Notification.id)
    ).first()
    if not row:
        return None
    return {"recipient": recipient, "event": {"type": "notification", "notification_id": str(row[0]),
                                              "kind": type_}}


def purge_between(db: Session, a: uuid.UUID, b: uuid.UUID) -> None:
    db.execute(delete(Notification).where(or_(
        and_(Notification.recipient_id == a, Notification.actor_id == b),
        and_(Notification.recipient_id == b, Notification.actor_id == a))))


def push(pending: list[dict | None]) -> None:
    """Call after commit."""
    from ..realtime import broker

    for p in pending:
        if p:
            broker.publish_user(p["recipient"], p["event"])

"""Notifications: /api/v1/notifications*

| Endpoint | Auth | Authorization |
|---|---|---|
| GET /notifications | yes | recipient == caller (in SQL); actor active & not blocked; referenced post visible |
| GET /notifications/unread-count | yes | same |
| POST /notifications/read | yes | only the caller's rows |
"""
from __future__ import annotations

from datetime import datetime, timezone

from fastapi import APIRouter, Depends
from sqlalchemy import and_, func, or_, select, update
from sqlalchemy.orm import Session, aliased

from ..authz import Principal, active_user_clause, blocked_either_way, require, visible_posts_clause
from ..db import get_db
from ..deps import StrictRoute, principal
from ..models import Notification, Post, User
from ..pagination import Page, finish, keyset_desc
from ..serializers import iso, user_mini
from .users import read_limit

router = APIRouter(prefix="/api/v1/notifications", tags=["notifications"], route_class=StrictRoute)


def _base(me):
    actor = aliased(User, name="actor")
    post_author = aliased(User, name="post_author")
    q = (select(Notification, actor, Post)
         .join(actor, actor.id == Notification.actor_id)
         .outerjoin(Post, Post.id == Notification.post_id)
         .outerjoin(post_author, post_author.id == Post.author_id)
         .where(Notification.recipient_id == me, active_user_clause(actor),
                ~blocked_either_way(actor.id, me),
                # Re-check visibility at read time: the post may have become private/deleted since.
                or_(Notification.post_id.is_(None), visible_posts_clause(me, Post, post_author))))
    return q


@router.get("")
def list_notifications(page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "notifications:read")
    read_limit(p)
    rows = db.execute(keyset_desc(_base(p.user_id), Notification.created_at, Notification.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[0].created_at, r[0].id))
    items = [{
        "id": str(n.id), "type": n.type, "actor": user_mini(a), "created_at": iso(n.created_at),
        "read": n.read_at is not None,
        "post": {"id": str(post.id), "text": post.body[:140]} if post is not None else None,
    } for n, a, post in rows]
    return {"items": items, "next_cursor": nxt}


@router.get("/unread-count")
def unread_count(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "notifications:read")
    sub = _base(p.user_id).where(Notification.read_at.is_(None)).limit(100).subquery()
    return {"count": db.scalar(select(func.count()).select_from(sub)) or 0}


@router.post("/read")
def mark_read(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "notifications:read")
    db.execute(update(Notification).where(and_(Notification.recipient_id == p.user_id,
                                               Notification.read_at.is_(None)))
               .values(read_at=datetime.now(timezone.utc)))
    db.commit()
    return {"status": "ok"}

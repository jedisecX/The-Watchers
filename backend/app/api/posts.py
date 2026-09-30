"""Posts, interactions, timelines, hashtags.

| Endpoint | Auth | Permission | Rate limit |
|---|---|---|---|
| POST /posts | yes | post:create (verified) | post_create (trust-tiered) |
| GET /posts/{id} | optional | visibility clause | read |
| DELETE /posts/{id} | yes | author only (in SQL) | post_create |
| GET /posts/{id}/replies | optional | visibility clause (each reply) | read |
| POST/DELETE /posts/{id}/like | yes | post:interact | like |
| POST/DELETE /posts/{id}/repost | yes | post:interact; public posts only | repost |
| GET /posts/{id}/likes | yes | post visible | read |
| GET /timeline/home | yes | followees + self, minus mutes | read |
| GET /timeline/public | optional | public only, minus mutes | read |
| GET /hashtags/{tag} | optional | visibility clause | read |
"""
from __future__ import annotations

import re
import uuid

from fastapi import APIRouter, Depends, Path, Response
from pydantic import Field
from sqlalchemy import and_, exists, null, or_, select, union_all
from sqlalchemy.orm import Session, aliased

from ..authz import (
    Principal,
    active_user_clause,
    blocked_either_way,
    get_visible_post,
    require,
    trust_tier,
    visible_posts_clause,
    visible_posts_query,
)
from ..db import get_db
from ..deps import StrictRoute, enforce_limit, principal
from ..errors import ApiError, ensure
from ..models import Follow, Like, Mute, Post, PostHashtag, Repost, User
from ..pagination import Page, finish, keyset_asc, keyset_desc
from ..schemas import In
from ..serializers import user_mini
from ..services import notify
from ..services import posts as svc
from .users import read_limit

router = APIRouter(prefix="/api/v1", tags=["posts"], route_class=StrictRoute)


class PostIn(In):
    text: str = Field(min_length=1, max_length=2000)  # hard cap before normalisation; 500 after
    visibility: str | None = Field(default=None, pattern="^(public|followers)$")
    reply_to_id: uuid.UUID | None = None
    media_ids: list[uuid.UUID] = Field(default_factory=list, max_length=4)


def _limit(p: Principal, bucket: str) -> None:
    enforce_limit(bucket, str(p.user_id), trust_tier(p.user))


def _not_muted(viewer_id, author_col):
    return ~exists().where(Mute.muter_id == viewer_id, Mute.muted_id == author_col)


@router.post("/posts", status_code=201)
def create_post(body: PostIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "post:create")
    _limit(p, "post_create")
    ensure(p.user is not None)
    post, pending = svc.create_post(db, p.user, body.text, body.visibility, body.reply_to_id, body.media_ids)
    db.commit()
    notify.push(pending)
    return svc.get_post_view(db, p.user_id, post.id)


@router.get("/posts/{post_id}")
def get_post(post_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    read_limit(p)
    return svc.get_post_view(db, p.user_id, post_id)


@router.delete("/posts/{post_id}", status_code=204)
def delete_post(post_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "post:create")
    _limit(p, "post_create")
    svc.delete_post(db, p.user_id, post_id)
    db.commit()
    return Response(status_code=204)


@router.get("/posts/{post_id}/replies")
def replies(post_id: uuid.UUID, page: Page = Depends(), p: Principal = Depends(principal),
            db: Session = Depends(get_db)):
    read_limit(p)
    get_visible_post(db, p.user_id, post_id)
    q, author = visible_posts_query(p.user_id)
    q = q.where(Post.reply_to_id == post_id)
    if p.user_id:
        q = q.where(_not_muted(p.user_id, Post.author_id))
    rows = db.execute(keyset_asc(q, Post.created_at, Post.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[0].created_at, r[0].id))
    return {"items": svc.hydrate(db, p.user_id, [tuple(r) for r in rows]), "next_cursor": nxt}


@router.post("/posts/{post_id}/like")
def like(post_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "post:interact")
    _limit(p, "like")
    pending = svc.like(db, p.user, post_id)
    db.commit()
    notify.push(pending)
    return {"liked": True}


@router.delete("/posts/{post_id}/like")
def unlike(post_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "post:interact")
    _limit(p, "like")
    svc.unlike(db, p.user, post_id)
    db.commit()
    return {"liked": False}


@router.post("/posts/{post_id}/repost")
def repost(post_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "post:interact")
    _limit(p, "repost")
    pending = svc.repost(db, p.user, post_id)
    db.commit()
    notify.push(pending)
    return {"reposted": True}


@router.delete("/posts/{post_id}/repost")
def unrepost(post_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "post:interact")
    _limit(p, "repost")
    svc.unrepost(db, p.user, post_id)
    db.commit()
    return {"reposted": False}


@router.get("/posts/{post_id}/likes")
def post_likers(post_id: uuid.UUID, page: Page = Depends(), p: Principal = Depends(principal),
                db: Session = Depends(get_db)):
    require(p, "read:auth")
    read_limit(p)
    get_visible_post(db, p.user_id, post_id)
    u = aliased(User)
    rows = db.execute(select(u).join(Like, Like.user_id == u.id).where(
        Like.post_id == post_id, active_user_clause(u), ~blocked_either_way(u.id, p.user_id))
        .order_by(Like.created_at.desc()).limit(page.limit)).scalars().all()
    return {"items": [user_mini(x) for x in rows]}


# --------------------------------------------------------------------------- timelines


@router.get("/timeline/home")
def home_timeline(page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "read:auth")
    read_limit(p)
    me = p.user_id
    followees = select(Follow.followee_id).where(Follow.follower_id == me)
    own_and_followed = select(Post.id.label("post_id"), Post.created_at.label("at"),
                              null().label("reposter_id")).where(
        or_(Post.author_id == me, Post.author_id.in_(followees)))
    reposts = select(Repost.post_id.label("post_id"), Repost.created_at.label("at"),
                     Repost.user_id.label("reposter_id")).where(
        Repost.user_id.in_(followees), ~exists().where(Mute.muter_id == me, Mute.muted_id == Repost.user_id))
    feed = union_all(own_and_followed, reposts).subquery("feed")
    author = aliased(User, name="author")
    reposter = aliased(User, name="reposter")
    q = (select(Post, author, reposter, feed.c.at)
         .join(feed, feed.c.post_id == Post.id)
         .join(author, author.id == Post.author_id)
         .outerjoin(reposter, reposter.id == feed.c.reposter_id)
         .where(visible_posts_clause(me, Post, author), _not_muted(me, Post.author_id),
                or_(feed.c.reposter_id.is_(None), active_user_clause(reposter))))
    rows = db.execute(keyset_desc(q, feed.c.at, Post.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[3], r[0].id))
    return {"items": svc.hydrate(db, me, [(r[0], r[1], r[2]) for r in rows]), "next_cursor": nxt}


@router.get("/timeline/public")
def public_timeline(page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    read_limit(p)
    q, author = visible_posts_query(p.user_id)
    q = q.where(Post.visibility == "public", author.is_private.is_(False), Post.reply_to_id.is_(None))
    if p.user_id:
        q = q.where(_not_muted(p.user_id, Post.author_id))
    rows = db.execute(keyset_desc(q, Post.created_at, Post.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[0].created_at, r[0].id))
    return {"items": svc.hydrate(db, p.user_id, [tuple(r) for r in rows]), "next_cursor": nxt}


@router.get("/hashtags/{tag}")
def hashtag(tag: str = Path(max_length=64), page: Page = Depends(), p: Principal = Depends(principal),
            db: Session = Depends(get_db)):
    read_limit(p)
    tag = tag.lower()
    if not re.fullmatch(r"\w{1,64}", tag):
        raise ApiError(400, "invalid_hashtag", "Invalid hashtag.")
    q, _ = visible_posts_query(p.user_id)
    q = q.join(PostHashtag, and_(PostHashtag.post_id == Post.id, PostHashtag.tag == tag))
    if p.user_id:
        q = q.where(_not_muted(p.user_id, Post.author_id))
    rows = db.execute(keyset_desc(q, Post.created_at, Post.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[0].created_at, r[0].id))
    return {"tag": tag, "items": svc.hydrate(db, p.user_id, [tuple(r) for r in rows]), "next_cursor": nxt}



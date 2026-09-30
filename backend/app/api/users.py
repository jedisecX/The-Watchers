"""Profiles and profile-scoped lists: /api/v1/users/{handle}*

| Endpoint | Auth | Authorization | Rate limit |
|---|---|---|---|
| GET /users/{handle} | optional | 404 if inactive or blocked either way | read |
| GET /users/{handle}/posts | optional | shared visibility clause | read |
| GET /users/{handle}/followers, /following | **required** (anti-scraping) | private: followers only | read |
| GET /users/{handle}/likes | optional | owner, or `show_likes` + content visibility | read |
"""
from __future__ import annotations

from fastapi import APIRouter, Depends, Query
from sqlalchemy import exists, select
from sqlalchemy.orm import Session, aliased

from ..authz import (
    Principal,
    active_user_clause,
    blocked_either_way,
    can_see_user_content,
    get_visible_user,
    trust_tier,
    visible_posts_query,
)
from ..db import get_db
from ..deps import StrictRoute, enforce_limit, principal
from ..errors import ApiError, not_found
from ..models import Block, Follow, FollowRequest, Like, Mute, Post, User
from ..pagination import Page, finish, keyset_desc
from ..serializers import user_mini, user_public
from ..services.posts import hydrate

router = APIRouter(prefix="/api/v1/users", tags=["users"], route_class=StrictRoute)


def read_limit(p: Principal) -> None:
    enforce_limit("read", str(p.user_id) if p.user_id else f"ip:{p.rl_ip}", trust_tier(p.user))


def relationship(db: Session, viewer_id, target_id) -> dict:
    if viewer_id is None or viewer_id == target_id:
        return {}

    def ex(clause):
        return bool(db.scalar(select(exists().where(*clause))))
    return {
        "following": ex([Follow.follower_id == viewer_id, Follow.followee_id == target_id]),
        "followed_by": ex([Follow.follower_id == target_id, Follow.followee_id == viewer_id]),
        "requested": ex([FollowRequest.requester_id == viewer_id, FollowRequest.target_id == target_id]),
        "blocking": ex([Block.blocker_id == viewer_id, Block.blocked_id == target_id]),
        "muting": ex([Mute.muter_id == viewer_id, Mute.muted_id == target_id]),
    }


@router.get("/{handle}")
def get_user(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    read_limit(p)
    u = get_visible_user(db, p.user_id, handle[:30])
    return user_public(u, relationship=relationship(db, p.user_id, u.id))


@router.get("/{handle}/posts")
def user_posts(handle: str, replies: bool = Query(default=False), page: Page = Depends(),
               p: Principal = Depends(principal), db: Session = Depends(get_db)):
    read_limit(p)
    u = get_visible_user(db, p.user_id, handle[:30])
    q, _ = visible_posts_query(p.user_id)
    q = q.where(Post.author_id == u.id)
    if not replies:
        q = q.where(Post.reply_to_id.is_(None))
    rows = db.execute(keyset_desc(q, Post.created_at, Post.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[0].created_at, r[0].id))
    return {"items": hydrate(db, p.user_id, [tuple(r) for r in rows]), "next_cursor": nxt}


def _people(db: Session, p: Principal, handle: str, page: Page, followers: bool):
    if not p.is_authenticated:
        raise ApiError(401, "unauthenticated", "Sign in to view follower lists.")
    read_limit(p)
    u = get_visible_user(db, p.user_id, handle[:30])
    if not can_see_user_content(db, p.user_id, u):
        raise ApiError(403, "private_account", "This account's lists are private.")
    other = aliased(User)
    join_col, filt_col = (Follow.follower_id, Follow.followee_id) if followers else (Follow.followee_id, Follow.follower_id)
    q = (select(other, Follow.created_at).join(Follow, join_col == other.id)
         .where(filt_col == u.id, active_user_clause(other), ~blocked_either_way(other.id, p.user_id)))
    if page.after:
        from sqlalchemy import and_, or_
        ts, oid = page.after
        q = q.where(or_(Follow.created_at < ts, and_(Follow.created_at == ts, other.id < oid)))
    rows = db.execute(q.order_by(Follow.created_at.desc(), other.id.desc()).limit(page.limit + 1)).all()
    rows, nxt = finish(rows, page, lambda r: (r[1], r[0].id))
    return {"items": [user_mini(r[0]) for r in rows], "next_cursor": nxt}


@router.get("/{handle}/followers")
def followers(handle: str, page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    return _people(db, p, handle, page, followers=True)


@router.get("/{handle}/following")
def following(handle: str, page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    return _people(db, p, handle, page, followers=False)


@router.get("/{handle}/likes")
def likes(handle: str, page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    read_limit(p)
    u = get_visible_user(db, p.user_id, handle[:30])
    if p.user_id != u.id and (not u.show_likes or not can_see_user_content(db, p.user_id, u)):
        raise not_found("Likes")
    q, _ = visible_posts_query(p.user_id)
    q = q.add_columns(Like.created_at).join(Like, Like.post_id == Post.id).where(Like.user_id == u.id)
    q = keyset_desc(q, Like.created_at, Post.id, page)
    rows = db.execute(q).all()
    rows, nxt = finish(rows, page, lambda r: (r[2], r[0].id))
    return {"items": hydrate(db, p.user_id, [(r[0], r[1]) for r in rows]), "next_cursor": nxt}

"""Search: /api/v1/search/*

| Endpoint | Auth | Notes | Rate limit |
|---|---|---|---|
| GET /search/posts?q= | **required** (anti-scraping; anon limit is 0) | `plainto_tsquery` (no query syntax injection), visibility clause | search |
| GET /search/users?q= | **required** | only `discoverable` users; LIKE wildcards escaped; blocks excluded | search |
"""
from __future__ import annotations

from fastapi import APIRouter, Depends, Query
from sqlalchemy import func, or_, select
from sqlalchemy.orm import Session

from ..authz import Principal, active_user_clause, blocked_either_way, require, trust_tier, visible_posts_query
from ..db import get_db
from ..deps import StrictRoute, enforce_limit, principal
from ..errors import ApiError
from ..models import Mute, Post, User
from ..pagination import Page, finish, keyset_desc
from ..serializers import user_mini
from ..services.posts import hydrate
from ..text import escape_like, search_terms

router = APIRouter(prefix="/api/v1/search", tags=["search"], route_class=StrictRoute)


def _prep(p: Principal, q: str) -> str:
    require(p, "read:auth")
    enforce_limit("search", str(p.user_id), trust_tier(p.user))
    terms = search_terms(q)
    if len(terms) < 2:
        raise ApiError(400, "query_too_short", "Search for at least 2 characters.")
    return terms


@router.get("/posts")
def search_posts(q: str = Query(min_length=1, max_length=200), page: Page = Depends(),
                 p: Principal = Depends(principal), db: Session = Depends(get_db)):
    terms = _prep(p, q)
    qry, _ = visible_posts_query(p.user_id)
    # plainto_tsquery treats input as plain words: no operator injection, no syntax errors.
    qry = qry.where(Post.search_vector.op("@@")(func.plainto_tsquery("simple", terms)),
                    ~select(Mute.muter_id).where(Mute.muter_id == p.user_id, Mute.muted_id == Post.author_id).exists())
    rows = db.execute(keyset_desc(qry, Post.created_at, Post.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[0].created_at, r[0].id))
    return {"items": hydrate(db, p.user_id, [tuple(r) for r in rows]), "next_cursor": nxt}


@router.get("/users")
def search_users(q: str = Query(min_length=1, max_length=100), limit: int = Query(default=20, ge=1, le=50),
                 p: Principal = Depends(principal), db: Session = Depends(get_db)):
    terms = _prep(p, q).lstrip("@")
    pat = f"%{escape_like(terms.lower())}%"
    rows = db.execute(
        select(User).where(
            active_user_clause(), User.discoverable.is_(True), ~blocked_either_way(User.id, p.user_id),
            or_(User.handle_norm.like(pat, escape="\\"), func.lower(User.display_name).like(pat, escape="\\")))
        .order_by(func.length(User.handle_norm), User.handle_norm).limit(limit)
    ).scalars().all()
    return {"items": [user_mini(u) for u in rows]}



"""Post business logic.

Security properties:
* Text is parsed into plaintext segments (``text.parse``); links are http(s)-only and checked
  against the blocked-domain list, including parent domains.
* Duplicate-content spam: the same normalised text more than 3 times per hour is rejected (AB-2).
* A reply can never be *more* visible than its parent (no context leak of followers-only threads).
* Mention/reply notifications are only created for users who can actually see the post, so a
  followers-only post cannot be pushed into a non-follower's notifications.
* Likes/reposts are idempotent (PK + ON CONFLICT) and counters move only on real inserts/deletes.
"""
from __future__ import annotations

import uuid
from collections import defaultdict
from datetime import datetime, timedelta, timezone

from sqlalchemy import delete, func, select, update
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.orm import Session

from ..authz import assert_can_interact, get_visible_post, visible_posts_query
from ..errors import ApiError, not_found
from ..models import (
    BlockedDomain,
    Like,
    Media,
    Notification,
    Post,
    PostHashtag,
    PostMedia,
    PostMention,
    Repost,
    User,
)
from ..security.crypto import sha256
from ..serializers import post_view
from ..text import TextError, parse, url_host
from . import notify

MAX_POST_LEN = 500
MAX_MEDIA = 4
DUP_WINDOW = timedelta(hours=1)
DUP_LIMIT = 3


def _domain_blocked(db: Session, host: str) -> bool:
    parts = host.split(".")
    candidates = [".".join(parts[i:]) for i in range(len(parts) - 1)]
    return bool(db.scalar(select(func.count()).select_from(BlockedDomain).where(BlockedDomain.domain.in_(candidates))))


def can_user_see_post(db: Session, user_id: uuid.UUID, post_id: uuid.UUID) -> bool:
    try:
        get_visible_post(db, user_id, post_id)
        return True
    except ApiError:
        return False


def create_post(db: Session, author: User, text: str, visibility: str | None, reply_to_id: uuid.UUID | None,
                media_ids: list[uuid.UUID]) -> tuple[Post, list]:
    try:
        parsed = parse(text, max_len=MAX_POST_LEN)
    except TextError as exc:
        raise ApiError(400, exc.code, exc.message) from exc
    for link in parsed.links:
        if _domain_blocked(db, url_host(link)):
            raise ApiError(400, "blocked_link", "This post links to a domain that is not allowed.")

    chash = sha256(parsed.text.lower())
    since = datetime.now(timezone.utc) - DUP_WINDOW
    dups = db.scalar(select(func.count()).select_from(Post).where(
        Post.author_id == author.id, Post.content_hash == chash, Post.created_at > since))
    if (dups or 0) >= DUP_LIMIT:
        raise ApiError(429, "duplicate_content", "You have posted this too many times recently.")

    vis = visibility or author.default_visibility
    parent = None
    if reply_to_id is not None:
        parent, parent_author = get_visible_post(db, author.id, reply_to_id)  # 404 if not visible
        assert_can_interact(db, author, parent_author.id)
        if parent.visibility == "followers" or parent_author.is_private:
            vis = "followers"

    if len(media_ids) > MAX_MEDIA or len(set(media_ids)) != len(media_ids):
        raise ApiError(400, "too_many_media", f"At most {MAX_MEDIA} distinct attachments.")
    media = []
    if media_ids:
        media = db.execute(select(Media).where(Media.id.in_(media_ids)).with_for_update()).scalars().all()
        ok = {m.id: m for m in media if m.owner_id == author.id and m.purpose == "post" and m.state == "ready"}
        if len(ok) != len(media_ids):
            raise not_found("Media")  # also covers "someone else's media" (no existence oracle)
        # Checked *after* taking the row locks, so two concurrent posts cannot both claim one upload (ADV-05b).
        if db.scalar(select(func.count()).select_from(PostMedia).where(PostMedia.media_id.in_(media_ids))):
            raise not_found("Media")
        media = [ok[i] for i in media_ids]

    post = Post(author_id=author.id, body=parsed.text, entities={"segments": parsed.segments}, visibility=vis,
                reply_to_id=reply_to_id, content_hash=chash)
    db.add(post)
    db.flush()
    for i, m in enumerate(media):
        db.add(PostMedia(post_id=post.id, media_id=m.id, position=i))
    for tag in parsed.hashtags:
        db.add(PostHashtag(post_id=post.id, tag=tag))
    db.execute(update(User).where(User.id == author.id).values(posts_count=User.posts_count + 1))

    pending = []
    if parsed.mentions:
        mentioned = db.execute(select(User.id).where(
            User.handle_norm.in_(parsed.mentions), User.status == "active", User.deleted_at.is_(None))).scalars().all()
        for uid in mentioned:
            db.add(PostMention(post_id=post.id, user_id=uid))
    db.flush()
    if parent is not None:
        db.execute(update(Post).where(Post.id == parent.id).values(reply_count=Post.reply_count + 1))
        if can_user_see_post(db, parent.author_id, post.id):
            pending.append(notify.create(db, recipient=parent.author_id, actor=author, type_="reply", post_id=post.id))
    if parsed.mentions:
        for uid in db.execute(select(PostMention.user_id).where(PostMention.post_id == post.id)).scalars():
            if (parent is None or uid != parent.author_id) and can_user_see_post(db, uid, post.id):
                pending.append(notify.create(db, recipient=uid, actor=author, type_="mention", post_id=post.id))
    return post, pending


def delete_post(db: Session, actor_id: uuid.UUID, post_id: uuid.UUID) -> None:
    """Author-only. Ownership is part of the WHERE clause, so IDOR is impossible by construction."""
    row = db.execute(
        update(Post).where(Post.id == post_id, Post.author_id == actor_id, Post.deleted_at.is_(None))
        .values(deleted_at=datetime.now(timezone.utc), body="", entities={})
        .returning(Post.reply_to_id)
    ).first()
    if row is None:
        raise not_found("Post")
    _after_removal(db, post_id, actor_id, row[0])


def _after_removal(db: Session, post_id: uuid.UUID, author_id: uuid.UUID, reply_to_id: uuid.UUID | None) -> None:
    db.execute(delete(PostHashtag).where(PostHashtag.post_id == post_id))
    db.execute(delete(PostMention).where(PostMention.post_id == post_id))
    db.execute(delete(Notification).where(Notification.post_id == post_id))
    db.execute(update(User).where(User.id == author_id).values(posts_count=func.greatest(User.posts_count - 1, 0)))
    if reply_to_id:
        db.execute(update(Post).where(Post.id == reply_to_id).values(
            reply_count=func.greatest(Post.reply_count - 1, 0)))


def moderator_remove(db: Session, post_id: uuid.UUID) -> Post:
    post = db.execute(update(Post).where(Post.id == post_id, Post.removed_at.is_(None))
                      .values(removed_at=datetime.now(timezone.utc)).returning(Post)).scalar_one_or_none()
    if post is None:
        raise not_found("Post")
    return post


def like(db: Session, user: User, post_id: uuid.UUID) -> list:
    post, author = get_visible_post(db, user.id, post_id)
    assert_can_interact(db, user, author.id)
    ins = db.execute(insert(Like).values(user_id=user.id, post_id=post.id).on_conflict_do_nothing()
                     .returning(Like.user_id)).first()
    if not ins:
        return []
    db.execute(update(Post).where(Post.id == post.id).values(like_count=Post.like_count + 1))
    return [notify.create(db, recipient=author.id, actor=user, type_="like", post_id=post.id)]


def unlike(db: Session, user: User, post_id: uuid.UUID) -> None:
    if db.execute(delete(Like).where(Like.user_id == user.id, Like.post_id == post_id).returning(Like.user_id)).first():
        db.execute(update(Post).where(Post.id == post_id).values(like_count=func.greatest(Post.like_count - 1, 0)))


def repost(db: Session, user: User, post_id: uuid.UUID) -> list:
    post, author = get_visible_post(db, user.id, post_id)
    assert_can_interact(db, user, author.id)
    if post.visibility != "public" or author.is_private:
        raise ApiError(403, "cannot_repost", "Only public posts can be reposted.")
    ins = db.execute(insert(Repost).values(user_id=user.id, post_id=post.id).on_conflict_do_nothing()
                     .returning(Repost.user_id)).first()
    if not ins:
        return []
    db.execute(update(Post).where(Post.id == post.id).values(repost_count=Post.repost_count + 1))
    return [notify.create(db, recipient=author.id, actor=user, type_="repost", post_id=post.id)]


def unrepost(db: Session, user: User, post_id: uuid.UUID) -> None:
    if db.execute(delete(Repost).where(Repost.user_id == user.id, Repost.post_id == post_id)
                  .returning(Repost.user_id)).first():
        db.execute(update(Post).where(Post.id == post_id).values(repost_count=func.greatest(Post.repost_count - 1, 0)))


def hydrate(db: Session, viewer_id: uuid.UUID | None, rows: list[tuple]) -> list[dict]:
    """rows: (Post, author User[, reposter User|None]). Batch-loads media and viewer state (no N+1)."""
    if not rows:
        return []
    ids = [r[0].id for r in rows]
    media_by_post: dict = defaultdict(list)
    for pm, m in db.execute(select(PostMedia, Media).join(Media, Media.id == PostMedia.media_id)
                            .where(PostMedia.post_id.in_(ids), Media.state == "ready")
                            .order_by(PostMedia.position)).all():
        media_by_post[pm.post_id].append(m)
    liked, reposted = set(), set()
    if viewer_id:
        liked = set(db.execute(select(Like.post_id).where(Like.user_id == viewer_id, Like.post_id.in_(ids))).scalars())
        reposted = set(db.execute(select(Repost.post_id).where(Repost.user_id == viewer_id,
                                                               Repost.post_id.in_(ids))).scalars())
    out = []
    for r in rows:
        p, a = r[0], r[1]
        reposter = r[2] if len(r) > 2 else None
        vs = {"liked": p.id in liked, "reposted": p.id in reposted, "is_author": viewer_id == p.author_id} \
            if viewer_id else None
        out.append(post_view(p, a, viewer_state=vs, media=media_by_post.get(p.id, []), reposted_by=reposter))
    return out


def get_post_view(db: Session, viewer_id: uuid.UUID | None, post_id: uuid.UUID) -> dict:
    q, _ = visible_posts_query(viewer_id)
    row = db.execute(q.where(Post.id == post_id)).first()
    if not row:
        raise not_found("Post")
    return hydrate(db, viewer_id, [tuple(row)])[0]

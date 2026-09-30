"""Media upload & serving: /api/v1/media*

| Endpoint | Auth | Permission | Rate limit | Notes |
|---|---|---|---|---|
| POST /media?purpose=post\\|avatar\\|dm | session/PAT(write) | media:upload (verified) | upload | raw body, no filename; magic-byte sniff; size cap by type; ≤10 pending per user |
| GET /media/{id} | owner | – | read | processing status |
| PATCH /media/{id} | owner | – | settings | alt text |
| DELETE /media/{id} | owner | unattached only | settings | |
| GET /media/{id}/{variant} | depends | owner / avatar of visible user / visible post / DM member | read | fixed Content-Type, nosniff, sandbox CSP |

Upload pipeline (THREAT-MODEL T-5, D-2, E-5, I-8): authenticate → authorize → rate-limit →
per-user pending quota → stream to quarantine (O_EXCL, 0600, random name) while counting bytes
and hashing → reject by signature → row in state *pending* → isolated worker re-encodes → only
derivatives are ever served.
"""
from __future__ import annotations

import hashlib
import uuid
from typing import Literal

from fastapi import APIRouter, Depends, Query, Request, Response
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import FileResponse
from pydantic import Field
from sqlalchemy import exists, func, select
from sqlalchemy.orm import Session

from .. import media_store
from ..authz import (
    Principal,
    active_user_clause,
    get_visible_post,
    require,
    trust_tier,
)
from ..config import get_settings
from ..db import get_db, new_session
from ..deps import StrictRoute, enforce_limit, principal
from ..errors import ApiError, not_found
from ..logging_setup import sec_event
from ..models import Block, ConversationMember, Media, Message, PostMedia, User
from ..schemas import In
from ..serializers import media_public
from ..text import normalize

router = APIRouter(prefix="/api/v1/media", tags=["media"], route_class=StrictRoute)
MAX_PENDING = 10


@router.post("", status_code=202, openapi_extra={"x-raw-body": True})
async def upload(request: Request, purpose: Literal["post", "avatar", "dm"] = Query(...),
                 p: Principal = Depends(principal)):
    require(p, "media:upload")
    enforce_limit("upload", str(p.user_id), trust_tier(p.user))
    uid = p.user_id

    def pending_count() -> int:
        db = new_session()
        try:
            return db.scalar(select(func.count()).select_from(Media).where(
                Media.owner_id == uid, Media.state.in_(("pending", "processing")))) or 0
        finally:
            db.close()
    if await run_in_threadpool(pending_count) >= MAX_PENDING:
        raise ApiError(429, "too_many_pending", "Too many uploads are still processing. Try again shortly.")

    key = media_store.new_key()
    path = media_store.safe_path("quarantine", key)
    h = hashlib.sha256()
    size = 0
    sniffed: str | None = None
    limit = media_store.MAX_IMAGE_BYTES
    head = b""
    try:
        with media_store.open_exclusive(path) as fh:
            async for chunk in request.stream():
                if not chunk:
                    continue
                if sniffed is None:
                    head += chunk
                    if len(head) < 16:
                        continue
                    sniffed = media_store.sniff(head[:64])
                    if sniffed is None:
                        sec_event("media.rejected_signature", user_id=str(uid))
                        raise ApiError(415, "unsupported_media_type",
                                       "Unsupported file type. Use JPEG, PNG, GIF, WebP, MP4 or WebM.")
                    if purpose == "avatar" and media_store.kind_of(sniffed) != "image":
                        raise ApiError(415, "unsupported_media_type", "Avatars must be images.")
                    limit = media_store.max_bytes(sniffed)
                    chunk, head = head, b""
                size += len(chunk)
                if size > limit:
                    raise ApiError(413, "payload_too_large", "File too large.")
                h.update(chunk)
                fh.write(chunk)
            if sniffed is None:
                if head:
                    sniffed = media_store.sniff(head[:64])
                if sniffed is None or size + len(head) == 0:
                    raise ApiError(415, "unsupported_media_type", "Unsupported or empty file.")
                h.update(head)
                fh.write(head)
                size += len(head)
    except BaseException:
        media_store.remove_quietly(path)
        raise

    def create_row() -> Media:
        db = new_session()
        try:
            m = Media(owner_id=uid, kind=media_store.kind_of(sniffed), purpose=purpose, sniffed_type=sniffed,
                      size_bytes=size, sha256=h.digest(), quarantine_key=key)
            db.add(m)
            db.commit()
            db.refresh(m)
            return m
        finally:
            db.close()
    try:
        m = await run_in_threadpool(create_row)
    except BaseException:
        media_store.remove_quietly(path)
        raise
    return media_public(m)


def _own_media(db: Session, p: Principal, media_id: uuid.UUID) -> Media:
    require(p, "read:auth")
    m = db.get(Media, media_id)
    if m is None or m.owner_id != p.user_id:
        raise not_found("Media")
    return m


@router.get("/{media_id}")
def media_status(media_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    m = _own_media(db, p, media_id)
    out = media_public(m)
    if m.state == "failed":
        out["error_code"] = m.error_code
    return out


class AltIn(In):
    alt_text: str = Field(max_length=2000)


@router.patch("/{media_id}")
def set_alt(media_id: uuid.UUID, body: AltIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    m = _own_media(db, p, media_id)
    enforce_limit("settings", str(p.user_id), "anon")
    m.alt_text = normalize(body.alt_text, multiline=False)[:1000]
    db.commit()
    return media_public(m)


@router.delete("/{media_id}", status_code=204)
def delete_media(media_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    m = _own_media(db, p, media_id)
    attached = db.scalar(select(exists().where(PostMedia.media_id == m.id))) or \
        db.scalar(select(exists().where(Message.media_id == m.id))) or \
        db.scalar(select(exists().where(User.avatar_media_id == m.id)))
    if attached:
        raise ApiError(409, "media_in_use", "This media is attached and cannot be deleted directly.")
    for v in (m.variants or {}).values():
        media_store.remove_quietly(media_store.safe_path("store", v["key"]))
    if m.quarantine_key:
        media_store.remove_quietly(media_store.safe_path("quarantine", m.quarantine_key))
    db.delete(m)
    db.commit()
    return Response(status_code=204)


def can_view_media(db: Session, viewer_id: uuid.UUID | None, m: Media) -> tuple[bool, bool]:
    """Returns (allowed, publicly_cacheable). Authorization is derived from what the media is attached to."""
    if viewer_id is not None and m.owner_id == viewer_id:
        return True, False
    if m.purpose == "avatar":
        owner = db.scalar(select(User).where(User.avatar_media_id == m.id, active_user_clause()))
        if owner is None:
            return False, False
        if viewer_id and db.scalar(select(exists().where(Block.blocker_id == owner.id, Block.blocked_id == viewer_id))):
            return False, False
        return True, False
    if m.purpose == "post":
        post_id = db.scalar(select(PostMedia.post_id).where(PostMedia.media_id == m.id))
        if post_id is None:
            return False, False
        try:
            get_visible_post(db, viewer_id, post_id)
        except ApiError:
            return False, False
        return True, False
    if m.purpose == "dm" and viewer_id is not None:
        conv = db.scalar(select(Message.conversation_id).where(Message.media_id == m.id, Message.deleted_at.is_(None)))
        if conv is None:
            return False, False
        return bool(db.get(ConversationMember, (conv, viewer_id))), False
    return False, False


@router.get("/{media_id}/{variant}")
def serve(media_id: uuid.UUID, variant: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    enforce_limit("read", str(p.user_id) if p.user_id else f"ip:{p.rl_ip}", trust_tier(p.user))
    if len(variant) > 32 or not variant.replace("_", "").isalnum():
        raise not_found("Media")
    m = db.get(Media, media_id)
    if m is None or m.state != "ready":
        raise not_found("Media")
    v = (m.variants or {}).get(variant)
    if not v or v.get("content_type") not in media_store.SERVE_TYPES:
        raise not_found("Media")
    allowed, _ = can_view_media(db, p.user_id, m)
    if not allowed:
        raise not_found("Media")
    try:
        path = media_store.safe_path("store", v["key"])
    except ValueError as exc:
        raise not_found("Media") from exc
    ext = "webp" if v["content_type"] == "image/webp" else "mp4"
    headers = {
        "X-Content-Type-Options": "nosniff",
        "Content-Security-Policy": "default-src 'none'; sandbox",
        "Content-Disposition": f'inline; filename="media.{ext}"',
        # Authorization is per-viewer and can change (blocks, privacy), so never shared-cache.
        "Cache-Control": "private, max-age=300",
        "Vary": "Cookie, Authorization",
        "Cross-Origin-Resource-Policy": "same-origin",
    }
    s = get_settings()
    if s.media_serve_mode == "x-accel":
        headers["X-Accel-Redirect"] = f"{s.media_x_accel_prefix}{v['key']}"
        headers["Content-Type"] = v["content_type"]
        return Response(status_code=200, headers=headers)
    if not path.is_file():
        raise not_found("Media")
    return FileResponse(path, media_type=v["content_type"], headers=headers)



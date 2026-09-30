"""Explicit output allow-lists.

Responses are built field-by-field from these functions, never by dumping ORM objects, so
new columns (hashes, secrets, internal flags) can never leak by accident (API3: excessive data
exposure).
"""
from __future__ import annotations

from .models import Media, Post, User


def iso(dt) -> str | None:
    return dt.isoformat() if dt else None


def avatar_url(user: User) -> str | None:
    return f"/api/v1/media/{user.avatar_media_id}/avatar" if user.avatar_media_id else None


def user_public(u: User, *, relationship: dict | None = None) -> dict:
    out = {
        "id": str(u.id),
        "handle": u.handle,
        "display_name": u.display_name,
        "bio": u.bio,
        "avatar_url": avatar_url(u),
        "is_private": u.is_private,
        "followers_count": u.followers_count,
        "following_count": u.following_count,
        "posts_count": u.posts_count,
        "created_at": iso(u.created_at),
        "badge": "staff" if u.role in ("moderator", "admin") else None,
    }
    if relationship is not None:
        out["relationship"] = relationship
    return out


def user_mini(u: User) -> dict:
    return {"id": str(u.id), "handle": u.handle, "display_name": u.display_name, "avatar_url": avatar_url(u)}


def user_self(u: User, *, has_passkeys: bool = False, recovery_codes_left: int | None = None) -> dict:
    """Only ever returned to the account owner."""
    out = user_public(u)
    out.update({
        "email": u.email,
        "email_verified": u.email_verified_at is not None,
        "role": u.role,
        "mfa_enabled": u.mfa_enabled_at is not None,
        "has_passkeys": has_passkeys,
        "settings": {
            "is_private": u.is_private,
            "dm_policy": u.dm_policy,
            "discoverable": u.discoverable,
            "show_likes": u.show_likes,
            "default_visibility": u.default_visibility,
        },
        "version": u.version,
    })
    if recovery_codes_left is not None:
        out["recovery_codes_left"] = recovery_codes_left
    return out


def media_public(m: Media) -> dict:
    variants = {}
    for name, v in (m.variants or {}).items():
        variants[name] = {
            "url": f"/api/v1/media/{m.id}/{name}",
            "width": v.get("width"),
            "height": v.get("height"),
            "content_type": v.get("content_type"),
        }
    return {"id": str(m.id), "kind": m.kind, "state": m.state, "alt_text": m.alt_text,
            "width": m.width, "height": m.height, "duration_ms": m.duration_ms, "variants": variants}


def post_view(p: Post, author: User, *, viewer_state: dict | None = None, media: list[Media] | None = None,
              reposted_by: User | None = None) -> dict:
    out = {
        "id": str(p.id),
        "author": user_mini(author),
        "segments": (p.entities or {}).get("segments", [{"t": "text", "v": p.body}]),
        "text": p.body,
        "visibility": p.visibility,
        "reply_to_id": str(p.reply_to_id) if p.reply_to_id else None,
        "like_count": p.like_count,
        "reply_count": p.reply_count,
        "repost_count": p.repost_count,
        "created_at": iso(p.created_at),
        "media": [media_public(m) for m in (media or [])],
    }
    if viewer_state is not None:
        out["viewer"] = viewer_state
    if reposted_by is not None:
        out["reposted_by"] = user_mini(reposted_by)
    return out

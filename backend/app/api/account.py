"""Account security & lifecycle: /api/v1/account/* and profile/settings: /api/v1/me

All endpoints here are **interactive-session only** (API tokens are refused, THREAT-MODEL E-3).
Sensitive operations require a *recent re-authentication* (password, plus the second factor when
MFA is on) so a stolen session cookie alone cannot lock the owner out, remove MFA, mint tokens,
or exfiltrate the account (S-3).

| Endpoint | Extra requirement | Rate limit |
|---|---|---|
| PATCH /me | optimistic lock `version` | settings |
| POST /account/password | current password | reauth |
| POST /account/email | recent reauth | settings |
| POST /account/mfa/totp/setup, /enable, /disable; /recovery-codes | recent reauth | settings |
| /account/passkeys* | recent reauth (register/delete) | settings |
| GET/DELETE /account/sessions*, POST /revoke-others | – | settings |
| /account/tokens* | recent reauth (create) | token_create |
| GET /account/export | recent reauth | export (2/day) |
| POST /account/delete | recent reauth + typed handle | settings |
"""
from __future__ import annotations

import uuid
from datetime import datetime, timedelta, timezone
from typing import Annotated, Any, Literal

from fastapi import APIRouter, Depends, Request, Response
from pydantic import Field, StrictBool, StrictInt
from sqlalchemy import delete, exists, func, select, update
from sqlalchemy.orm import Session

from .. import audit
from ..authz import PAT_SCOPES, Principal, require, require_recent_reauth, require_session
from ..config import get_settings
from ..db import get_db
from ..deps import PAT_PREFIX, StrictRoute, enforce_limit, principal
from ..errors import ApiError, ensure, not_found
from ..models import (
    ApiToken,
    AuditLog,
    Block,
    ConversationMember,
    Follow,
    FollowRequest,
    Like,
    Media,
    Message,
    Mute,
    Post,
    Repost,
    User,
    WebAuthnCredential,
)
from ..models import Session as DbSession
from ..schemas import In, PosInt32
from ..security.crypto import random_token, token_hash
from ..security.passwords import PasswordPolicyError, check_password_policy, hash_password, verify_password
from ..serializers import iso, user_self
from ..services import mailer, mfa, passkeys, sessions, tokens
from ..services.social import create_follow, remove_all_follows
from ..text import normalize
from .auth import normalize_email

router = APIRouter(tags=["account"], route_class=StrictRoute)


def _session_principal(p: Principal) -> Principal:
    require_session(p)
    return p


def _reauthed(p: Principal) -> None:
    require_recent_reauth(p, get_settings().stepup_minutes)


def _reauthed_verified(p: Principal) -> None:
    """Adding long-lived credentials (MFA, passkeys, API tokens) requires a *verified* email.
    Otherwise an attacker could pre-register a victim's address and plant credentials that survive
    the victim later claiming the account (account pre-hijacking, finding ADV-02)."""
    _reauthed(p)
    if p.user is None or p.user.email_verified_at is None:
        raise ApiError(403, "email_unverified", "Verify your email address before adding sign-in methods or tokens.")


def _audit(db: Session, p: Principal, action: str, **details: Any) -> None:
    audit.record(db, action=action, actor_id=p.user_id, actor_role=p.role, target_type="user",
                 target_id=p.user_id or "", ip_prefix=p.ip_prefix, request_id=p.request_id, details=details)


# =========================================================================== profile & settings
class MeUpdateIn(In):
    version: PosInt32
    display_name: str | None = Field(default=None, max_length=100)
    bio: str | None = Field(default=None, max_length=600)
    avatar_media_id: uuid.UUID | None = None
    remove_avatar: StrictBool | None = None
    is_private: StrictBool | None = None
    dm_policy: Literal["everyone", "following", "nobody"] | None = None
    discoverable: StrictBool | None = None
    show_likes: StrictBool | None = None
    default_visibility: Literal["public", "followers"] | None = None


@router.get("/api/v1/me")
def get_me(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    if not p.user:
        raise ApiError(401, "unauthenticated", "Authentication required.")
    require(p, "read:auth")  # a PAT must carry the read scope (ADV-07b)
    has_pk = bool(db.scalar(select(exists().where(WebAuthnCredential.user_id == p.user.id))))
    return user_self(p.user, has_passkeys=has_pk)


@router.patch("/api/v1/me")
def update_me(body: MeUpdateIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _session_principal(p)
    ensure(p.user is not None)
    enforce_limit("settings", str(p.user_id), "anon")
    values: dict[str, Any] = {}
    if body.display_name is not None:
        values["display_name"] = normalize(body.display_name, multiline=False)[:50]
    if body.bio is not None:
        bio = normalize(body.bio, multiline=True)
        if len(bio) > 300:
            raise ApiError(400, "too_long", "Bio must be at most 300 characters.")
        values["bio"] = bio
    if body.avatar_media_id is not None:
        m = db.get(Media, body.avatar_media_id)
        # Ownership + purpose + state: you cannot set someone else's (or an unprocessed) upload as avatar.
        if m is None or m.owner_id != p.user.id or m.purpose != "avatar" or m.state != "ready":
            raise not_found("Media")
        values["avatar_media_id"] = m.id
    if body.remove_avatar:
        values["avatar_media_id"] = None
    for f in ("is_private", "dm_policy", "discoverable", "show_likes", "default_visibility"):
        v = getattr(body, f)
        if v is not None:
            values[f] = v
    if not values:
        return user_self(p.user)
    values["updated_at"] = datetime.now(timezone.utc)
    values["version"] = User.version + 1
    # Optimistic locking: concurrent edits from two tabs cannot silently clobber each other.
    res = db.execute(update(User).where(User.id == p.user.id, User.version == body.version).values(**values))
    if res.rowcount != 1:
        db.rollback()
        raise ApiError(409, "version_conflict", "Your profile changed elsewhere. Reload and try again.")
    if body.is_private is False and p.user.is_private:
        # Going public: pending follow requests are approved (their authors asked to follow).
        reqs = db.execute(select(FollowRequest).where(FollowRequest.target_id == p.user.id)).scalars().all()
        for r in reqs:
            create_follow(db, r.requester_id, p.user.id)
        db.execute(delete(FollowRequest).where(FollowRequest.target_id == p.user.id))
    if any(k in values for k in ("is_private", "dm_policy", "discoverable", "show_likes")):
        _audit(db, p, "account.privacy_changed", **{k: values[k] for k in values if k in
                                                     ("is_private", "dm_policy", "discoverable", "show_likes")})
    db.commit()
    db.refresh(p.user)
    return user_self(p.user)


# =========================================================================== password & email
class PasswordChangeIn(In):
    current_password: str = Field(min_length=1, max_length=1024)
    new_password: str = Field(min_length=1, max_length=1024)


@router.post("/api/v1/account/password")
def change_password(body: PasswordChangeIn, request: Request, response: Response,
                    p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _session_principal(p)
    ensure(p.user is not None)
    enforce_limit("reauth", str(p.user_id), "anon")
    if not verify_password(p.user.password_hash, body.current_password):
        raise ApiError(401, "invalid_credentials", "Incorrect password.")
    try:
        check_password_policy(body.new_password, handle=p.user.handle, email=p.user.email)
    except PasswordPolicyError as exc:
        raise ApiError(400, exc.code, exc.message) from exc
    now = datetime.now(timezone.utc)
    db.execute(update(User).where(User.id == p.user.id).values(
        password_hash=hash_password(body.new_password), password_changed_at=now, updated_at=now))
    sessions.bump_epoch(db, p.user.id)  # signs out all other devices and kills API tokens
    db.refresh(p.user)
    token, sess = sessions.create_session(db, p.user, ip_prefix=p.ip_prefix,
                                          user_agent=request.headers.get("user-agent", ""),
                                          auth_method=p.session.auth_method if p.session else "password",
                                          mfa=p.session is not None and p.session.mfa_at is not None)
    _audit(db, p, "account.password_changed")
    mailer.security_notice(db, p.user.email, "Your Bastion password was changed and other sessions were signed out.")
    db.commit()
    sessions.announce_revocation(p.user.id, except_session_id=sess.id)
    response.set_cookie(value=token, **sessions.cookie_kwargs(get_settings().session_absolute_days * 86400))
    return {"status": "password_changed", "csrf_token": sessions.csrf_token(sess)}


class EmailChangeIn(In):
    new_email: str = Field(min_length=3, max_length=254)


@router.post("/api/v1/account/email", status_code=202)
def change_email(body: EmailChangeIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _reauthed(p)
    ensure(p.user is not None)
    enforce_limit("settings", str(p.user_id), "anon")
    new = normalize_email(body.new_email)
    if new != p.user.email and not db.scalar(select(exists().where(User.email == new))):
        tok = tokens.issue(db, "email_change", p.user.id, {"new_email": new})
        mailer.email_change(db, new, tok)
        mailer.security_notice(db, p.user.email, "A change of the email address on your Bastion account was requested.")
        db.commit()
    return {"status": "verification_sent"}  # identical response whether or not the address is taken


# =========================================================================== MFA
class CodeIn(In):
    code: str = Field(min_length=6, max_length=40)


@router.post("/api/v1/account/mfa/totp/setup")
def totp_setup(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _reauthed_verified(p)
    ensure(p.user is not None)
    if p.user.mfa_enabled_at is not None:
        raise ApiError(409, "mfa_already_enabled", "Two-factor authentication is already enabled.")
    secret = mfa.new_secret()
    db.execute(update(User).where(User.id == p.user.id).values(totp_pending_enc=mfa.enc(p.user.id, secret)))
    db.commit()
    return {"secret": secret, "otpauth_uri": mfa.provisioning_uri(secret, p.user.handle)}


@router.post("/api/v1/account/mfa/totp/enable")
def totp_enable(body: CodeIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _reauthed_verified(p)
    ensure(p.user is not None and p.session is not None)
    enforce_limit("mfa_verify", f"user:{p.user_id}", "anon")
    if p.user.mfa_enabled_at is not None or p.user.totp_pending_enc is None:
        raise ApiError(409, "mfa_state", "Start two-factor setup first.")
    secret = mfa.dec(p.user.id, p.user.totp_pending_enc)
    step = mfa.matching_step(secret, body.code)
    if step is None:
        raise ApiError(400, "mfa_invalid", "Incorrect code. Check your device clock and try again.")
    now = datetime.now(timezone.utc)
    db.execute(update(User).where(User.id == p.user.id).values(
        totp_secret_enc=p.user.totp_pending_enc, totp_pending_enc=None, totp_last_step=step, mfa_enabled_at=now))
    db.execute(update(DbSession).where(DbSession.id == p.session.id).values(mfa_at=now))
    codes = mfa.generate_recovery_codes(db, p.user.id)
    _audit(db, p, "account.mfa_enabled", method="totp")
    mailer.security_notice(db, p.user.email, "Two-factor authentication was enabled on your Bastion account.")
    db.commit()
    return {"status": "enabled", "recovery_codes": codes}


@router.post("/api/v1/account/mfa/totp/disable")
def totp_disable(body: CodeIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _reauthed(p)
    ensure(p.user is not None)
    enforce_limit("mfa_verify", f"user:{p.user_id}", "anon")
    if p.user.mfa_enabled_at is None:
        raise ApiError(409, "mfa_state", "Two-factor authentication is not enabled.")
    if p.user.role in ("moderator", "admin"):
        raise ApiError(403, "mfa_required_for_role", "Staff accounts must keep two-factor authentication.")
    # A fresh code is required *in addition to* recent reauth: proves possession right now.
    if mfa.verify_second_factor(db, p.user, body.code) is None:
        db.commit()
        raise ApiError(401, "mfa_invalid", "Incorrect code.")
    db.execute(update(User).where(User.id == p.user.id).values(
        totp_secret_enc=None, totp_pending_enc=None, mfa_enabled_at=None))
    mfa.generate_recovery_codes(db, p.user.id, n=0)
    _audit(db, p, "account.mfa_disabled", method="totp")
    mailer.security_notice(db, p.user.email, "Two-factor authentication was DISABLED on your Bastion account.")
    db.commit()
    return {"status": "disabled"}


@router.post("/api/v1/account/mfa/recovery-codes")
def regenerate_recovery_codes(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _reauthed(p)
    ensure(p.user is not None)
    if p.user.mfa_enabled_at is None:
        raise ApiError(409, "mfa_state", "Two-factor authentication is not enabled.")
    codes = mfa.generate_recovery_codes(db, p.user.id)
    _audit(db, p, "account.recovery_codes_regenerated")
    db.commit()
    return {"recovery_codes": codes}


# =========================================================================== passkeys
class PasskeyRegisterFinishIn(In):
    challenge_id: str = Field(min_length=10, max_length=128)
    credential: dict[str, Any]
    name: str = Field(default="Passkey", min_length=1, max_length=50)


@router.post("/api/v1/account/passkeys/register/begin")
def passkey_register_begin(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _reauthed_verified(p)
    ensure(p.user is not None)
    enforce_limit("settings", str(p.user_id), "anon")
    out = passkeys.begin_registration(db, p.user)
    db.commit()
    return out


@router.post("/api/v1/account/passkeys/register/finish", status_code=201)
def passkey_register_finish(body: PasskeyRegisterFinishIn, p: Principal = Depends(principal),
                            db: Session = Depends(get_db)):
    _reauthed_verified(p)
    ensure(p.user is not None)
    cred = passkeys.finish_registration(db, p.user, body.challenge_id, body.credential,
                                        normalize(body.name, multiline=False)[:50] or "Passkey")
    _audit(db, p, "account.passkey_added", passkey_id=str(cred.id))
    mailer.security_notice(db, p.user.email, "A passkey was added to your Bastion account.")
    db.commit()
    return {"id": str(cred.id), "name": cred.name, "created_at": iso(cred.created_at)}


@router.get("/api/v1/account/passkeys")
def passkey_list(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _session_principal(p)
    rows = db.execute(select(WebAuthnCredential).where(WebAuthnCredential.user_id == p.user_id)
                      .order_by(WebAuthnCredential.created_at)).scalars().all()
    return {"items": [{"id": str(c.id), "name": c.name, "created_at": iso(c.created_at),
                       "last_used_at": iso(c.last_used_at)} for c in rows]}


@router.delete("/api/v1/account/passkeys/{passkey_id}", status_code=204)
def passkey_delete(passkey_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _reauthed(p)
    ensure(p.user is not None)
    res = db.execute(delete(WebAuthnCredential).where(WebAuthnCredential.id == passkey_id,
                                                      WebAuthnCredential.user_id == p.user.id))
    if res.rowcount != 1:
        raise not_found("Passkey")
    _audit(db, p, "account.passkey_removed", passkey_id=str(passkey_id))
    mailer.security_notice(db, p.user.email, "A passkey was removed from your Bastion account.")
    db.commit()
    return Response(status_code=204)


# =========================================================================== sessions
@router.get("/api/v1/account/sessions")
def session_list(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _session_principal(p)
    ensure(p.user is not None and p.session is not None)
    now = datetime.now(timezone.utc)
    rows = db.execute(select(DbSession).where(
        DbSession.user_id == p.user.id, DbSession.revoked_at.is_(None), DbSession.expires_at > now,
        DbSession.epoch == p.user.session_epoch).order_by(DbSession.last_seen_at.desc()).limit(100)).scalars().all()
    return {"items": [{
        "id": str(s.id), "created_at": iso(s.created_at), "last_seen_at": iso(s.last_seen_at),
        "ip_prefix": s.ip_prefix, "user_agent": s.user_agent, "auth_method": s.auth_method,
        "current": s.id == p.session.id} for s in rows]}


@router.delete("/api/v1/account/sessions/{session_id}", status_code=204)
def session_revoke(session_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _session_principal(p)
    ensure(p.user is not None)
    enforce_limit("settings", str(p.user_id), "anon")
    if not sessions.revoke(db, session_id, p.user.id):
        raise not_found("Session")
    db.commit()
    from ..realtime import broker  # disconnect live sockets of that session
    broker.publish_control({"type": "session_revoked", "session_id": str(session_id)})
    return Response(status_code=204)


@router.post("/api/v1/account/sessions/revoke-others")
def session_revoke_others(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _session_principal(p)
    ensure(p.user is not None and p.session is not None)
    n = sessions.revoke_all(db, p.user.id, except_id=p.session.id)
    _audit(db, p, "account.sessions_revoked", count=n)
    db.commit()
    from ..realtime import broker
    broker.publish_control({"type": "user_sessions_revoked", "user_id": str(p.user.id),
                            "except_session_id": str(p.session.id)})
    return {"revoked": n}


# =========================================================================== API tokens
class TokenCreateIn(In):
    name: str = Field(min_length=1, max_length=50)
    scopes: list[Literal["read", "write", "dm"]] = Field(min_length=1, max_length=3)
    expires_in_days: Annotated[StrictInt, Field(ge=1, le=365)] = 90


@router.get("/api/v1/account/tokens")
def token_list(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _session_principal(p)
    rows = db.execute(select(ApiToken).where(ApiToken.user_id == p.user_id, ApiToken.revoked_at.is_(None))
                      .order_by(ApiToken.created_at.desc())).scalars().all()
    return {"items": [{"id": str(t.id), "name": t.name, "prefix": t.prefix, "scopes": t.scopes,
                       "created_at": iso(t.created_at), "last_used_at": iso(t.last_used_at),
                       "expires_at": iso(t.expires_at)} for t in rows]}


@router.post("/api/v1/account/tokens", status_code=201)
def token_create(body: TokenCreateIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _reauthed_verified(p)
    ensure(p.user is not None)
    enforce_limit("token_create", str(p.user_id), "anon")
    active = db.scalar(select(func.count()).select_from(ApiToken).where(
        ApiToken.user_id == p.user.id, ApiToken.revoked_at.is_(None)))
    if (active or 0) >= 20:
        raise ApiError(400, "too_many_tokens", "At most 20 active tokens.")
    scopes = sorted(set(body.scopes) & set(PAT_SCOPES))
    raw = PAT_PREFIX + random_token(32)
    now = datetime.now(timezone.utc)
    t = ApiToken(user_id=p.user.id, name=normalize(body.name, multiline=False)[:50] or "token",
                 prefix=raw[:12], token_hash=token_hash(raw), scopes=scopes, epoch=p.user.session_epoch,
                 created_at=now, expires_at=now + timedelta(days=body.expires_in_days))
    db.add(t)
    db.flush()
    _audit(db, p, "account.token_created", token_id=str(t.id), scopes=scopes)
    mailer.security_notice(db, p.user.email, "A new API token was created on your Bastion account.")
    db.commit()
    # The raw token is returned exactly once; only its hash is stored.
    return {"id": str(t.id), "token": raw, "prefix": t.prefix, "scopes": scopes, "expires_at": iso(t.expires_at)}


@router.delete("/api/v1/account/tokens/{token_id}", status_code=204)
def token_revoke(token_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    _session_principal(p)
    res = db.execute(update(ApiToken).where(ApiToken.id == token_id, ApiToken.user_id == p.user_id,
                                            ApiToken.revoked_at.is_(None))
                     .values(revoked_at=datetime.now(timezone.utc)))
    if res.rowcount != 1:
        raise not_found("Token")
    _audit(db, p, "account.token_revoked", token_id=str(token_id))
    db.commit()
    return Response(status_code=204)


# =========================================================================== export & deletion
@router.get("/api/v1/account/export")
def export_data(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    """GDPR Art. 20-style export of the requester's own data. DMs: only messages the user sent,
    plus conversation metadata, because received messages are the other party's personal data."""
    _reauthed(p)
    ensure(p.user is not None)
    enforce_limit("export", str(p.user_id), "anon")
    uid = p.user.id
    u = p.user

    def ids(q):
        return [str(x) for x in db.execute(q).scalars().all()]

    handles = lambda q: db.execute(q).scalars().all()  # noqa: E731
    posts = db.execute(select(Post).where(Post.author_id == uid, Post.deleted_at.is_(None))
                       .order_by(Post.created_at)).scalars().all()
    msgs = db.execute(select(Message).where(Message.sender_id == uid, Message.deleted_at.is_(None))
                      .order_by(Message.created_at)).scalars().all()
    audits = db.execute(select(AuditLog).where(AuditLog.actor_id == uid).order_by(AuditLog.seq)).scalars().all()
    _audit(db, p, "account.exported")
    db.commit()
    return {
        "exported_at": iso(datetime.now(timezone.utc)),
        "profile": {"id": str(uid), "handle": u.handle, "email": u.email, "display_name": u.display_name,
                    "bio": u.bio, "created_at": iso(u.created_at), "is_private": u.is_private,
                    "dm_policy": u.dm_policy, "discoverable": u.discoverable, "show_likes": u.show_likes},
        "posts": [{"id": str(x.id), "text": x.body, "visibility": x.visibility, "created_at": iso(x.created_at),
                   "reply_to_id": str(x.reply_to_id) if x.reply_to_id else None} for x in posts],
        "likes": ids(select(Like.post_id).where(Like.user_id == uid)),
        "reposts": ids(select(Repost.post_id).where(Repost.user_id == uid)),
        "following": handles(select(User.handle).join(Follow, Follow.followee_id == User.id).where(Follow.follower_id == uid)),
        "followers": handles(select(User.handle).join(Follow, Follow.follower_id == User.id).where(Follow.followee_id == uid)),
        "blocked": handles(select(User.handle).join(Block, Block.blocked_id == User.id).where(Block.blocker_id == uid)),
        "muted": handles(select(User.handle).join(Mute, Mute.muted_id == User.id).where(Mute.muter_id == uid)),
        "conversations": ids(select(ConversationMember.conversation_id).where(ConversationMember.user_id == uid)),
        "messages_sent": [{"conversation_id": str(m.conversation_id), "text": m.body, "created_at": iso(m.created_at)}
                          for m in msgs],
        "security_log": [{"action": a.action, "at": iso(a.created_at), "ip_prefix": a.ip_prefix} for a in audits],
    }


class DeleteIn(In):
    confirm_handle: str = Field(min_length=1, max_length=30)


@router.post("/api/v1/account/delete", status_code=202)
def delete_account(body: DeleteIn, response: Response, p: Principal = Depends(principal),
                   db: Session = Depends(get_db)):
    _reauthed(p)
    ensure(p.user is not None)
    if body.confirm_handle.lower() != p.user.handle_norm:
        raise ApiError(400, "confirmation_mismatch", "Type your handle exactly to confirm.")
    if p.user.role == "admin":
        remaining = db.scalar(select(func.count()).select_from(User).where(
            User.role == "admin", User.id != p.user.id, User.deleted_at.is_(None)))
        if not remaining:
            raise ApiError(409, "last_admin", "The last administrator cannot delete their account.")
    now = datetime.now(timezone.utc)
    db.execute(update(User).where(User.id == p.user.id).values(
        status="deactivated", deleted_at=now, purge_after=now + timedelta(days=30), updated_at=now))
    sessions.bump_epoch(db, p.user.id)
    remove_all_follows(db, p.user.id)  # keeps other users' counters consistent
    _audit(db, p, "account.deleted")
    mailer.security_notice(db, p.user.email, "Your Bastion account was deleted. Data is purged permanently after 30 days.")
    db.commit()
    from ..realtime import broker
    broker.publish_control({"type": "user_disabled", "user_id": str(p.user.id)})
    response.delete_cookie(sessions.COOKIE_NAME, path="/", secure=True, httponly=True, samesite="lax")
    return {"status": "scheduled_for_purge", "purge_after": iso(now + timedelta(days=30))}


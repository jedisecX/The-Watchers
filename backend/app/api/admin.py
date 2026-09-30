"""Administrative & moderation API: /admin/api/v1/*  (separate from the user API)

Every endpoint: interactive session only (no PATs) + role permission + MFA enrolled + MFA step-up
within STEPUP_MINUTES + CSRF + ``admin`` rate limit. Every mutation requires a ``reason`` and is
written to the hash-chained audit log in the same transaction (THREAT-MODEL R-1, E-1).
Destructive actions (suspend, role change) require typing the target's handle (``confirm``).

| Endpoint | Permission |
|---|---|
| GET  /me | mod:reports |
| GET  /reports, POST /reports/{id}/resolve | mod:reports |
| POST /posts/{id}/remove, /restore | mod:content |
| GET  /users?q=, GET /users/{id} | mod:users |
| POST /users/{id}/warn, /suspend, /unsuspend | mod:users (never on staff of equal/higher rank) |
| POST /users/{id}/revoke-sessions | admin:sessions |
| POST /users/{id}/role | admin:roles (not self; not last admin; target must have MFA) |
| GET/POST/DELETE /blocked-domains | admin:domains |
| GET  /audit, GET /audit/verify | admin:audit |
"""
from __future__ import annotations

import uuid
from datetime import datetime, timedelta, timezone
from typing import Annotated, Literal

from fastapi import APIRouter, Depends, Query, Response
from pydantic import Field, StrictInt
from sqlalchemy import delete, func, select, update
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.orm import Session, aliased

from .. import audit
from ..authz import Principal, can_moderate_target, require_admin_context, trust_tier
from ..config import get_settings
from ..db import get_db
from ..deps import StrictRoute, enforce_limit, principal
from ..errors import ApiError, not_found
from ..models import AuditLog, BlockedDomain, Post, Report, User, WebAuthnCredential
from ..pagination import Page, finish, keyset_desc
from ..realtime import broker
from ..schemas import In
from ..serializers import iso, user_mini
from ..services import mailer, sessions
from ..services import posts as post_svc

router = APIRouter(prefix="/admin/api/v1", tags=["admin"], route_class=StrictRoute)


def staff(permission: str):
    def dep(p: Principal = Depends(principal)) -> Principal:
        require_admin_context(p, permission, get_settings().stepup_minutes)
        enforce_limit("admin", str(p.user_id), "anon")
        return p
    return dep


class Reason(In):
    reason: str = Field(min_length=3, max_length=500)


def _audit(db: Session, p: Principal, action: str, target_type: str, target_id, **details) -> None:
    audit.record(db, action=action, actor_id=p.user_id, actor_role=p.role, target_type=target_type,
                 target_id=target_id, ip_prefix=p.ip_prefix, request_id=p.request_id, details=details)


def _target_user(db: Session, p: Principal, user_id: uuid.UUID) -> User:
    u = db.get(User, user_id)
    if u is None:
        raise not_found("User")
    if not can_moderate_target(p.user, u):
        raise ApiError(403, "insufficient_rank", "You cannot act on this account.")
    return u


def _staff_user_view(u: User) -> dict:
    return {**user_mini(u), "role": u.role, "status": u.status, "strikes": u.strikes,
            "created_at": iso(u.created_at), "suspended_until": iso(u.suspended_until),
            "email_verified": u.email_verified_at is not None, "mfa_enabled": u.mfa_enabled_at is not None,
            "trust_tier": trust_tier(u), "deleted": u.deleted_at is not None}


# =========================================================================== self
@router.get("/me")
def me(p: Principal = Depends(staff("mod:reports"))):
    return {"user": user_mini(p.user), "role": p.role, "permissions": sorted(p.permissions())}


# =========================================================================== reports
@router.get("/reports")
def list_reports(status: Literal["open", "resolved", "dismissed"] = Query(default="open"), page: Page = Depends(),
                 p: Principal = Depends(staff("mod:reports")), db: Session = Depends(get_db)):
    reporter = aliased(User)
    target = aliased(User)
    q = (select(Report, reporter, target).join(reporter, reporter.id == Report.reporter_id)
         .outerjoin(target, target.id == Report.target_user_id).where(Report.status == status))
    rows = db.execute(keyset_desc(q, Report.created_at, Report.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[0].created_at, r[0].id))
    counts = dict(db.execute(select(Report.target_id, func.count()).where(
        Report.status == "open", Report.target_id.in_([r[0].target_id for r in rows])).group_by(Report.target_id)).all())
    return {"items": [{
        "id": str(r.id), "target_type": r.target_type, "target_id": str(r.target_id), "reason": r.reason,
        "details": r.details, "evidence": r.evidence, "status": r.status, "created_at": iso(r.created_at),
        "reporter": {**user_mini(rep), "trust_tier": trust_tier(rep)},
        "target_user": _staff_user_view(tu) if tu else None, "open_reports_on_target": counts.get(r.target_id, 0),
    } for r, rep, tu in rows], "next_cursor": nxt}


class ResolveIn(Reason):
    outcome: Literal["resolved", "dismissed"]


@router.post("/reports/{report_id}/resolve")
def resolve_report(report_id: uuid.UUID, body: ResolveIn, p: Principal = Depends(staff("mod:reports")),
                   db: Session = Depends(get_db)):
    res = db.execute(update(Report).where(Report.id == report_id, Report.status == "open").values(
        status=body.outcome, resolved_by=p.user_id, resolved_at=datetime.now(timezone.utc),
        resolution_note=body.reason).returning(Report.id))
    if res.first() is None:
        raise not_found("Open report")
    _audit(db, p, f"mod.report_{body.outcome}", "report", report_id, reason=body.reason)
    db.commit()
    return {"status": body.outcome}


# =========================================================================== content
@router.post("/posts/{post_id}/remove")
def remove_post(post_id: uuid.UUID, body: Reason, p: Principal = Depends(staff("mod:content")),
                db: Session = Depends(get_db)):
    post = db.get(Post, post_id)
    if post is None:
        raise not_found("Post")
    author = db.get(User, post.author_id)
    if author and author.id != p.user_id and not can_moderate_target(p.user, author):
        raise ApiError(403, "insufficient_rank", "You cannot act on this account's content.")
    post_svc.moderator_remove(db, post_id)
    _audit(db, p, "mod.post_removed", "post", post_id, reason=body.reason, author_id=str(post.author_id))
    db.commit()
    return {"status": "removed"}


@router.post("/posts/{post_id}/restore")
def restore_post(post_id: uuid.UUID, body: Reason, p: Principal = Depends(staff("mod:content")),
                 db: Session = Depends(get_db)):
    post = db.get(Post, post_id)
    author = db.get(User, post.author_id) if post else None
    if author and author.id != p.user_id and not can_moderate_target(p.user, author):
        raise ApiError(403, "insufficient_rank", "You cannot act on this account's content.")  # ADV-08b
    res = db.execute(update(Post).where(Post.id == post_id, Post.removed_at.is_not(None)).values(removed_at=None)
                     .returning(Post.id))
    if res.first() is None:
        raise not_found("Removed post")
    _audit(db, p, "mod.post_restored", "post", post_id, reason=body.reason)
    db.commit()
    return {"status": "restored"}


# =========================================================================== users
@router.get("/users")
def find_users(q: str = Query(min_length=1, max_length=30), p: Principal = Depends(staff("mod:users")),
               db: Session = Depends(get_db)):
    from ..text import escape_like
    rows = db.execute(select(User).where(User.handle_norm.like(f"{escape_like(q.lower())}%", escape="\\"))
                      .order_by(User.handle_norm).limit(20)).scalars().all()
    return {"items": [_staff_user_view(u) for u in rows]}


@router.get("/users/{user_id}")
def get_user(user_id: uuid.UUID, p: Principal = Depends(staff("mod:users")), db: Session = Depends(get_db)):
    u = db.get(User, user_id)
    if u is None:
        raise not_found("User")
    return _staff_user_view(u)


class ConfirmedReason(Reason):
    confirm: str = Field(min_length=1, max_length=30)


def _confirm(u: User, typed: str) -> None:
    if typed.lower() != u.handle_norm:
        raise ApiError(400, "confirmation_mismatch", "Type the account's handle to confirm.")


@router.post("/users/{user_id}/warn")
def warn(user_id: uuid.UUID, body: Reason, p: Principal = Depends(staff("mod:users")), db: Session = Depends(get_db)):
    u = _target_user(db, p, user_id)
    db.execute(update(User).where(User.id == u.id).values(strikes=User.strikes + 1))
    _audit(db, p, "mod.user_warned", "user", u.id, reason=body.reason)
    mailer.security_notice(db, u.email, "Your Bastion account received a warning for violating the rules.")
    db.commit()
    return {"status": "warned"}


class SuspendIn(ConfirmedReason):
    days: Annotated[StrictInt, Field(ge=1, le=3650)] | None = None


@router.post("/users/{user_id}/suspend")
def suspend(user_id: uuid.UUID, body: SuspendIn, p: Principal = Depends(staff("mod:users")),
            db: Session = Depends(get_db)):
    u = _target_user(db, p, user_id)
    _confirm(u, body.confirm)
    until = datetime.now(timezone.utc) + timedelta(days=body.days) if body.days else None
    db.execute(update(User).where(User.id == u.id).values(status="suspended", suspended_until=until,
                                                          strikes=User.strikes + 1))
    sessions.bump_epoch(db, u.id)  # kills sessions and API tokens immediately
    _audit(db, p, "mod.user_suspended", "user", u.id, reason=body.reason, days=body.days)
    mailer.security_notice(db, u.email, "Your Bastion account has been suspended.")
    db.commit()
    broker.publish_control({"type": "user_disabled", "user_id": str(u.id)})
    return {"status": "suspended", "until": iso(until)}


@router.post("/users/{user_id}/unsuspend")
def unsuspend(user_id: uuid.UUID, body: Reason, p: Principal = Depends(staff("mod:users")),
              db: Session = Depends(get_db)):
    u = _target_user(db, p, user_id)
    res = db.execute(update(User).where(User.id == u.id, User.status == "suspended")
                     .values(status="active", suspended_until=None).returning(User.id))
    if res.first() is None:
        raise ApiError(409, "not_suspended", "This account is not suspended.")
    _audit(db, p, "mod.user_unsuspended", "user", u.id, reason=body.reason)
    db.commit()
    return {"status": "active"}


@router.post("/users/{user_id}/revoke-sessions")
def revoke_sessions(user_id: uuid.UUID, body: Reason, p: Principal = Depends(staff("admin:sessions")),
                    db: Session = Depends(get_db)):
    u = db.get(User, user_id)
    if u is None:
        raise not_found("User")
    sessions.bump_epoch(db, u.id)
    _audit(db, p, "admin.sessions_revoked", "user", u.id, reason=body.reason)
    db.commit()
    broker.publish_control({"type": "user_disabled", "user_id": str(u.id)})
    return {"status": "revoked"}


class RoleIn(ConfirmedReason):
    role: Literal["user", "moderator", "admin"]


@router.post("/users/{user_id}/role")
def set_role(user_id: uuid.UUID, body: RoleIn, p: Principal = Depends(staff("admin:roles")),
             db: Session = Depends(get_db)):
    if user_id == p.user_id:
        raise ApiError(403, "cannot_change_own_role", "You cannot change your own role.")
    # Lock admin rows so two concurrent demotions cannot remove the last admin (race, T-3).
    admins = db.execute(select(User.id).where(User.role == "admin", User.deleted_at.is_(None))
                        .with_for_update()).scalars().all()
    u = db.get(User, user_id)
    if u is None:
        raise not_found("User")
    _confirm(u, body.confirm)
    if u.role == body.role:
        return {"status": "unchanged", "role": u.role}
    if u.role == "admin" and body.role != "admin" and len([a for a in admins if a != u.id]) == 0:
        raise ApiError(409, "last_admin", "Cannot demote the last administrator.")
    if body.role in ("moderator", "admin"):
        has_pk = db.scalar(select(func.count()).select_from(WebAuthnCredential).where(WebAuthnCredential.user_id == u.id))
        if u.mfa_enabled_at is None and not has_pk:
            raise ApiError(409, "target_mfa_required", "Staff accounts must have two-factor authentication first.")
        if u.status != "active" or u.email_verified_at is None:
            raise ApiError(409, "target_ineligible", "Only active, verified accounts can be staff.")
    old = u.role
    db.execute(update(User).where(User.id == u.id).values(role=body.role))
    sessions.bump_epoch(db, u.id)  # privilege change → fresh sessions (rotation)
    _audit(db, p, "admin.role_changed", "user", u.id, reason=body.reason, old_role=old, new_role=body.role)
    mailer.security_notice(db, u.email, "The permissions on your Bastion account were changed.")
    db.commit()
    broker.publish_control({"type": "user_disabled", "user_id": str(u.id)})
    return {"status": "changed", "role": body.role}


# =========================================================================== blocked domains
class DomainIn(Reason):
    domain: str = Field(min_length=3, max_length=253, pattern=r"^[A-Za-z0-9.-]+$")


@router.get("/blocked-domains")
def list_domains(p: Principal = Depends(staff("admin:domains")), db: Session = Depends(get_db)):
    rows = db.execute(select(BlockedDomain).order_by(BlockedDomain.domain).limit(1000)).scalars().all()
    return {"items": [{"domain": d.domain, "reason": d.reason, "created_at": iso(d.created_at)} for d in rows]}


@router.post("/blocked-domains", status_code=201)
def add_domain(body: DomainIn, p: Principal = Depends(staff("admin:domains")), db: Session = Depends(get_db)):
    d = body.domain.lower().strip(".")
    if "." not in d or ".." in d:
        raise ApiError(400, "invalid_domain", "Enter a domain like example.com.")
    db.execute(insert(BlockedDomain).values(domain=d, reason=body.reason, created_by=p.user_id).on_conflict_do_nothing())
    _audit(db, p, "admin.domain_blocked", "domain", d, reason=body.reason)
    db.commit()
    return {"domain": d}


@router.delete("/blocked-domains/{domain}", status_code=204)
def remove_domain(domain: str, p: Principal = Depends(staff("admin:domains")), db: Session = Depends(get_db)):
    if db.execute(delete(BlockedDomain).where(BlockedDomain.domain == domain.lower()[:253])).rowcount != 1:
        raise not_found("Domain")
    _audit(db, p, "admin.domain_unblocked", "domain", domain.lower()[:253])
    db.commit()
    return Response(status_code=204)


# =========================================================================== audit
@router.get("/audit")
def audit_log(action: str | None = Query(default=None, max_length=64), actor_id: uuid.UUID | None = None,
              before_seq: int | None = Query(default=None, ge=1, le=2**62), limit: int = Query(default=50, ge=1, le=200),
              p: Principal = Depends(staff("admin:audit")), db: Session = Depends(get_db)):
    q = select(AuditLog)
    if action:
        q = q.where(AuditLog.action == action)
    if actor_id:
        q = q.where(AuditLog.actor_id == actor_id)
    if before_seq:
        q = q.where(AuditLog.seq < before_seq)
    rows = db.execute(q.order_by(AuditLog.seq.desc()).limit(limit)).scalars().all()
    return {"items": [{"seq": r.seq, "at": iso(r.created_at), "actor_id": str(r.actor_id) if r.actor_id else None,
                       "actor_role": r.actor_role, "action": r.action, "target_type": r.target_type,
                       "target_id": r.target_id, "ip_prefix": r.ip_prefix, "request_id": r.request_id,
                       "details": r.details, "hash": r.row_hash.hex()} for r in rows]}


@router.get("/audit/verify")
def audit_verify(p: Principal = Depends(staff("admin:audit")), db: Session = Depends(get_db)):
    ok, bad, n = audit.verify_chain(db)
    return {"ok": ok, "first_bad_seq": bad, "rows_checked": n}

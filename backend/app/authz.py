"""Centralised authorization.

Two complementary mechanisms, both defined only here:

1. **Permissions** (``require(principal, "post:create")``): coarse capability by role, email
   verification, account status, and token scope.
2. **Object policies** (``visible_posts_clause``, ``can_view_user``, ``require_conversation_member``,
   ``assert_can_interact`` …): relationship-aware checks. List endpoints embed the *same* SQL
   clause used by single-object checks, so there is exactly one definition of "visible"
   (THREAT-MODEL I-1, I-2).

Denials that would reveal existence return 404 instead of 403.
"""
from __future__ import annotations

import uuid
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone

from sqlalchemy import and_, exists, false, or_, select
from sqlalchemy.orm import Session, aliased

from .errors import ApiError, ensure, forbidden, not_found
from .logging_setup import sec_event
from .models import ApiToken, Block, ConversationMember, Follow, Post, User
from .models import Session as DbSession

# --------------------------------------------------------------------------- permissions
ANON = frozenset({"read:public"})
USER = ANON | {"read:auth", "account:manage", "block", "mute", "notifications:read"}
VERIFIED = USER | {"post:create", "post:interact", "follow", "dm:send", "media:upload", "report:create"}
MODERATOR = VERIFIED | {"mod:reports", "mod:content", "mod:users"}
ADMIN = MODERATOR | {"admin:roles", "admin:domains", "admin:audit", "admin:sessions"}

# A role grants its full set; unverified accounts are then intersected down to USER in permissions().
ROLE_PERMISSIONS = {"user": VERIFIED, "moderator": MODERATOR, "admin": ADMIN}

# Scope required when the request is authenticated with a personal access token.
# Permissions absent from this map are NEVER available to tokens (account security, admin).
PAT_SCOPE_FOR = {
    "read:public": "read",
    "read:auth": "read",
    "notifications:read": "read",
    "post:create": "write",
    "post:interact": "write",
    "follow": "write",
    "block": "write",
    "mute": "write",
    "media:upload": "write",
    "report:create": "write",
    "dm:send": "dm",
}
PAT_SCOPES = ("read", "write", "dm")


@dataclass
class Principal:
    user: User | None = None
    session: DbSession | None = None
    token: ApiToken | None = None
    ip_prefix: str = ""
    rl_ip: str = ""
    request_id: str = ""
    scopes: frozenset[str] = field(default_factory=frozenset)

    @property
    def is_authenticated(self) -> bool:
        return self.user is not None

    @property
    def user_id(self) -> uuid.UUID | None:
        return self.user.id if self.user else None

    @property
    def via(self) -> str:
        return "session" if self.session else ("token" if self.token else "anonymous")

    @property
    def role(self) -> str:
        return self.user.role if self.user else "anonymous"

    def permissions(self) -> frozenset[str]:
        if not self.user:
            return ANON
        base = ROLE_PERMISSIONS.get(self.user.role, USER)
        if self.user.email_verified_at is None:
            base = base & USER  # unverified accounts get no write capabilities, whatever their role
        return base


def trust_tier(user: User | None) -> str:
    """Adaptive rate-limit tier (THREAT-MODEL AB-2, AB-3, AB-12)."""
    if user is None:
        return "anon"
    now = datetime.now(timezone.utc)
    age = now - user.created_at
    if user.email_verified_at is None or age < timedelta(hours=24) or user.strikes >= 3:
        return "new"
    if age >= timedelta(days=7) and user.strikes == 0:
        return "established"
    return "basic"


def require(p: Principal, permission: str) -> None:
    if permission not in ANON and not p.is_authenticated:
        raise ApiError(401, "unauthenticated", "Authentication required.")
    if permission not in p.permissions():
        if p.user and p.user.email_verified_at is None and permission in VERIFIED:
            raise ApiError(403, "email_unverified", "Verify your email address to do that.")
        sec_event("authz.denied", user_id=str(p.user_id), action=permission, request_id=p.request_id)
        raise forbidden()
    if p.token is not None:
        scope = PAT_SCOPE_FOR.get(permission)
        if scope is None or scope not in p.scopes:
            sec_event("authz.token_scope_denied", user_id=str(p.user_id), action=permission)
            raise ApiError(403, "insufficient_scope", "This token does not have the required scope.")


def require_session(p: Principal) -> None:
    """Account-security operations are browser-session only: never via API token."""
    if not p.is_authenticated:
        raise ApiError(401, "unauthenticated", "Authentication required.")
    if p.session is None:
        raise ApiError(403, "session_required", "This operation requires an interactive session.")


def _recent(ts: datetime | None, minutes: int) -> bool:
    return ts is not None and datetime.now(timezone.utc) - ts <= timedelta(minutes=minutes)


def require_recent_reauth(p: Principal, minutes: int) -> None:
    require_session(p)
    ensure(p.session is not None and p.user is not None)
    if not _recent(p.session.reauth_at, minutes):
        raise ApiError(403, "reauth_required", "Please confirm your password to continue.")
    if user_has_mfa(p.user) and not _recent(p.session.mfa_at, minutes):
        raise ApiError(403, "reauth_required", "Please confirm your second factor to continue.")


def user_has_mfa(user: User) -> bool:
    return user.mfa_enabled_at is not None


def require_admin_context(p: Principal, permission: str, stepup_minutes: int) -> None:
    """Admin/moderator API: role + MFA enrolled + phishing-resistant or TOTP step-up recently."""
    require_session(p)
    require(p, permission)
    ensure(p.session is not None and p.user is not None)
    if not (p.user.mfa_enabled_at is not None or p.session.auth_method == "passkey"):
        raise ApiError(403, "mfa_required", "Administrative access requires multi-factor authentication.")
    if not _recent(p.session.mfa_at, stepup_minutes):
        raise ApiError(403, "stepup_required", "Re-verify your second factor to continue.")


# --------------------------------------------------------------------------- relationship SQL


def blocked_either_way(a, b):
    """SQL: a block exists between user a and user b in either direction."""
    return exists().where(or_(
        and_(Block.blocker_id == a, Block.blocked_id == b),
        and_(Block.blocker_id == b, Block.blocked_id == a),
    ))


def follows_clause(follower, followee):
    return exists().where(Follow.follower_id == follower, Follow.followee_id == followee)


def active_user_clause(user_cls=User):
    return and_(user_cls.status == "active", user_cls.deleted_at.is_(None))


def visible_posts_clause(viewer_id: uuid.UUID | None, post_cls=Post, author_cls=None):
    """THE definition of post visibility. Every query returning posts must include it.

    Requires the query to join the author as ``author_cls`` (defaults to an alias joined by caller).
    """
    author = author_cls if author_cls is not None else User
    base = and_(
        post_cls.deleted_at.is_(None),
        post_cls.removed_at.is_(None),
        active_user_clause(author),
    )
    public = and_(post_cls.visibility == "public", author.is_private.is_(False))
    if viewer_id is None:
        return and_(base, public)
    return and_(
        base,
        or_(
            post_cls.author_id == viewer_id,
            and_(
                ~blocked_either_way(post_cls.author_id, viewer_id),
                or_(public, follows_clause(viewer_id, post_cls.author_id)),
            ),
        ),
    )


def visible_posts_query(viewer_id: uuid.UUID | None):
    """select(Post, User) with the author joined and visibility applied."""
    author = aliased(User, name="author")
    return select(Post, author).join(author, author.id == Post.author_id).where(
        visible_posts_clause(viewer_id, Post, author)
    ), author


def get_visible_post(db: Session, viewer_id: uuid.UUID | None, post_id: uuid.UUID) -> tuple[Post, User]:
    q, _ = visible_posts_query(viewer_id)
    row = db.execute(q.where(Post.id == post_id)).first()
    if not row:
        raise not_found("Post")
    return row[0], row[1]


# --------------------------------------------------------------------------- user-level policies


def is_blocked(db: Session, a: uuid.UUID, b: uuid.UUID) -> bool:
    return bool(db.scalar(select(blocked_either_way(a, b))))


def is_following(db: Session, follower: uuid.UUID, followee: uuid.UUID) -> bool:
    return bool(db.scalar(select(follows_clause(follower, followee))))


def get_visible_user(db: Session, viewer_id: uuid.UUID | None, handle: str) -> User:
    """Profile lookup: 404 if missing, inactive, or if the *target has blocked the viewer* (the blocked
    party cannot distinguish that from a non-existent account). The blocker can still open the
    profile (to see and undo the block); every *interaction* is separately refused by
    ``assert_can_interact`` and content by ``visible_posts_clause``, which are bidirectional."""
    u = db.scalar(select(User).where(User.handle_norm == handle.lower(), active_user_clause()))
    if not u:
        raise not_found("User")
    if viewer_id and viewer_id != u.id and db.scalar(select(exists().where(
            Block.blocker_id == u.id, Block.blocked_id == viewer_id))):
        raise not_found("User")
    return u


def can_see_user_content(db: Session, viewer_id: uuid.UUID | None, target: User) -> bool:
    """Whether follower lists / likes / non-public posts of ``target`` are visible to viewer."""
    if viewer_id == target.id:
        return True
    if not target.is_private:
        return True
    return viewer_id is not None and is_following(db, viewer_id, target.id)


def assert_can_interact(db: Session, actor: User, target_user_id: uuid.UUID) -> None:
    """Reply/like/repost/mention-notify/follow/DM are impossible across a block."""
    if actor.id != target_user_id and is_blocked(db, actor.id, target_user_id):
        raise not_found()


def require_conversation_member(db: Session, user_id: uuid.UUID, conversation_id: uuid.UUID) -> ConversationMember:
    """Knowing a conversation id grants nothing: membership is checked on every access (THREAT-MODEL I-1)."""
    m = db.get(ConversationMember, (conversation_id, user_id))
    if m is None:
        sec_event("authz.conversation_denied", user_id=str(user_id), target_id=str(conversation_id))
        raise not_found("Conversation")
    return m


def can_moderate_target(actor: User, target: User) -> bool:
    """Moderators cannot act on moderators/admins; nobody acts on themselves via mod tools."""
    if actor.id == target.id:
        return False
    rank = {"user": 0, "moderator": 1, "admin": 2}
    return rank.get(actor.role, 0) > rank.get(target.role, 0)


NEVER = false()

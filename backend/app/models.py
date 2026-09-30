"""Relational schema.

Security-relevant conventions:
* All primary keys are random UUIDv4 (non-enumerable). Authorization never relies on this.
* Enumerations are ``varchar`` + ``CHECK`` constraints (portable, migration-friendly).
* Every relationship that must be unique is enforced by a PK/unique constraint so that
  concurrent duplicate requests cannot create duplicate rows (see T-3 in THREAT-MODEL.md).
* Counters carry ``CHECK (x >= 0)``.
* Self-relations (follow/block/mute yourself) are rejected with ``CHECK``.
* Secrets are stored hashed (tokens) or encrypted (TOTP seeds); never in plaintext.
"""
from __future__ import annotations

import uuid
from datetime import datetime

from sqlalchemy import (
    BigInteger,
    Boolean,
    CheckConstraint,
    Computed,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    LargeBinary,
    PrimaryKeyConstraint,
    SmallInteger,
    String,
    Text,
    UniqueConstraint,
    func,
    text,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB, TSVECTOR, UUID
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column


class Base(DeclarativeBase):
    pass


def _uuid_pk() -> Mapped[uuid.UUID]:
    return mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)


def _now() -> Mapped[datetime]:
    return mapped_column(DateTime(timezone=True), nullable=False, server_default=func.now())


def _in(col: str, values: tuple[str, ...]) -> str:
    return f"{col} IN (" + ", ".join(f"'{v}'" for v in values) + ")"


ROLES = ("user", "moderator", "admin")
USER_STATUS = ("active", "suspended", "deactivated")
DM_POLICIES = ("everyone", "following", "nobody")
VISIBILITIES = ("public", "followers")
TOKEN_PURPOSES = (
    "email_verify",
    "password_reset",
    "mfa_pending",
    "ws_ticket",
    "email_change",
    "webauthn_register",
    "webauthn_login",
)
NOTIF_TYPES = ("follow", "follow_request", "follow_accept", "like", "reply", "mention", "repost")
MEDIA_KINDS = ("image", "video")
MEDIA_STATES = ("pending", "processing", "ready", "failed")
MEDIA_PURPOSES = ("post", "avatar", "dm")
REPORT_TARGETS = ("user", "post", "message")
REPORT_REASONS = ("spam", "harassment", "hate", "violence", "sexual", "self_harm", "impersonation", "illegal", "other")
REPORT_STATUS = ("open", "resolved", "dismissed")


# --------------------------------------------------------------------------- users
class User(Base):
    __tablename__ = "users"
    id: Mapped[uuid.UUID] = _uuid_pk()
    handle: Mapped[str] = mapped_column(String(30), nullable=False)
    handle_norm: Mapped[str] = mapped_column(String(30), nullable=False, unique=True)
    email: Mapped[str] = mapped_column(String(254), nullable=False, unique=True)
    email_verified_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    password_hash: Mapped[str] = mapped_column(String(255), nullable=False)
    password_changed_at: Mapped[datetime] = _now()
    display_name: Mapped[str] = mapped_column(String(50), nullable=False, server_default="")
    bio: Mapped[str] = mapped_column(String(300), nullable=False, server_default="")
    avatar_media_id: Mapped[uuid.UUID | None] = mapped_column(
        UUID(as_uuid=True), ForeignKey("media.id", ondelete="SET NULL", use_alter=True, name="fk_users_avatar")
    )
    role: Mapped[str] = mapped_column(String(16), nullable=False, server_default="user")
    status: Mapped[str] = mapped_column(String(16), nullable=False, server_default="active")
    # privacy
    is_private: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("false"))
    dm_policy: Mapped[str] = mapped_column(String(16), nullable=False, server_default="following")
    discoverable: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("true"))
    show_likes: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("false"))
    default_visibility: Mapped[str] = mapped_column(String(16), nullable=False, server_default="public")
    # auth state
    failed_login_count: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    session_epoch: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    totp_secret_enc: Mapped[bytes | None] = mapped_column(LargeBinary)
    totp_pending_enc: Mapped[bytes | None] = mapped_column(LargeBinary)
    totp_last_step: Mapped[int] = mapped_column(BigInteger, nullable=False, server_default="0")
    mfa_enabled_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    # moderation / lifecycle
    strikes: Mapped[int] = mapped_column(SmallInteger, nullable=False, server_default="0")
    suspended_until: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    deleted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    purge_after: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    # denormalised counters
    followers_count: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    following_count: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    posts_count: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    version: Mapped[int] = mapped_column(Integer, nullable=False, server_default="1")
    created_at: Mapped[datetime] = _now()
    updated_at: Mapped[datetime] = _now()

    __table_args__ = (
        CheckConstraint(_in("role", ROLES), name="ck_users_role"),
        CheckConstraint(_in("status", USER_STATUS), name="ck_users_status"),
        CheckConstraint(_in("dm_policy", DM_POLICIES), name="ck_users_dm_policy"),
        CheckConstraint(_in("default_visibility", VISIBILITIES), name="ck_users_default_vis"),
        CheckConstraint("handle_norm = lower(handle)", name="ck_users_handle_norm"),
        CheckConstraint("handle ~ '^[A-Za-z0-9_]{3,30}$'", name="ck_users_handle_charset"),
        CheckConstraint("email = lower(email)", name="ck_users_email_lower"),
        CheckConstraint("followers_count >= 0 AND following_count >= 0 AND posts_count >= 0", name="ck_users_counts"),
        CheckConstraint("failed_login_count >= 0", name="ck_users_failed"),
        Index("ix_users_handle_trgm", "handle_norm", postgresql_using="gin", postgresql_ops={"handle_norm": "gin_trgm_ops"}),
        Index("ix_users_purge", "purge_after", postgresql_where=text("purge_after IS NOT NULL")),
    )


class Session(Base):
    __tablename__ = "sessions"
    id: Mapped[uuid.UUID] = _uuid_pk()
    user_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    token_hash: Mapped[bytes] = mapped_column(LargeBinary(32), nullable=False, unique=True)
    epoch: Mapped[int] = mapped_column(Integer, nullable=False)
    created_at: Mapped[datetime] = _now()
    last_seen_at: Mapped[datetime] = _now()
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    reauth_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    mfa_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    revoked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    ip_prefix: Mapped[str] = mapped_column(String(64), nullable=False, server_default="")
    user_agent: Mapped[str] = mapped_column(String(200), nullable=False, server_default="")
    auth_method: Mapped[str] = mapped_column(String(16), nullable=False, server_default="password")
    __table_args__ = (Index("ix_sessions_user", "user_id", "revoked_at"),)


class AuthToken(Base):
    """Single-use, purpose-bound, hashed tokens (verify, reset, pending-MFA, WS tickets, WebAuthn challenges)."""

    __tablename__ = "auth_tokens"
    id: Mapped[uuid.UUID] = _uuid_pk()
    user_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    purpose: Mapped[str] = mapped_column(String(32), nullable=False)
    token_hash: Mapped[bytes] = mapped_column(LargeBinary(32), nullable=False, unique=True)
    data: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    attempts: Mapped[int] = mapped_column(SmallInteger, nullable=False, server_default="0")
    created_at: Mapped[datetime] = _now()
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    __table_args__ = (
        CheckConstraint(_in("purpose", TOKEN_PURPOSES), name="ck_auth_tokens_purpose"),
        CheckConstraint("expires_at > created_at", name="ck_auth_tokens_expiry"),
        Index("ix_auth_tokens_user_purpose", "user_id", "purpose"),
        Index("ix_auth_tokens_expires", "expires_at"),
    )


class RecoveryCode(Base):
    __tablename__ = "recovery_codes"
    id: Mapped[uuid.UUID] = _uuid_pk()
    user_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    code_hash: Mapped[bytes] = mapped_column(LargeBinary(32), nullable=False)
    used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    created_at: Mapped[datetime] = _now()
    __table_args__ = (UniqueConstraint("user_id", "code_hash", name="uq_recovery_codes"),)


class WebAuthnCredential(Base):
    __tablename__ = "webauthn_credentials"
    id: Mapped[uuid.UUID] = _uuid_pk()
    user_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    credential_id: Mapped[bytes] = mapped_column(LargeBinary(1023), nullable=False, unique=True)
    public_key: Mapped[bytes] = mapped_column(LargeBinary, nullable=False)
    sign_count: Mapped[int] = mapped_column(BigInteger, nullable=False, server_default="0")
    transports: Mapped[list] = mapped_column(JSONB, nullable=False, server_default=text("'[]'::jsonb"))
    name: Mapped[str] = mapped_column(String(50), nullable=False, server_default="Passkey")
    backup_eligible: Mapped[bool] = mapped_column(Boolean, nullable=False, server_default=text("false"))
    created_at: Mapped[datetime] = _now()
    last_used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    __table_args__ = (Index("ix_webauthn_user", "user_id"),)


class ApiToken(Base):
    __tablename__ = "api_tokens"
    id: Mapped[uuid.UUID] = _uuid_pk()
    user_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    name: Mapped[str] = mapped_column(String(50), nullable=False)
    prefix: Mapped[str] = mapped_column(String(16), nullable=False)
    token_hash: Mapped[bytes] = mapped_column(LargeBinary(32), nullable=False, unique=True)
    scopes: Mapped[list[str]] = mapped_column(ARRAY(String(32)), nullable=False)
    epoch: Mapped[int] = mapped_column(Integer, nullable=False)
    created_at: Mapped[datetime] = _now()
    last_used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    revoked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    __table_args__ = (Index("ix_api_tokens_user", "user_id"),)


# --------------------------------------------------------------------------- graph
class Follow(Base):
    __tablename__ = "follows"
    follower_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    followee_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    created_at: Mapped[datetime] = _now()
    __table_args__ = (
        PrimaryKeyConstraint("follower_id", "followee_id"),
        CheckConstraint("follower_id <> followee_id", name="ck_follows_self"),
        Index("ix_follows_followee", "followee_id", "created_at"),
        Index("ix_follows_follower_created", "follower_id", "created_at"),
    )


class FollowRequest(Base):
    __tablename__ = "follow_requests"
    requester_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    target_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    created_at: Mapped[datetime] = _now()
    __table_args__ = (
        PrimaryKeyConstraint("requester_id", "target_id"),
        CheckConstraint("requester_id <> target_id", name="ck_follow_requests_self"),
        Index("ix_follow_requests_target", "target_id", "created_at"),
    )


class Block(Base):
    __tablename__ = "blocks"
    blocker_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    blocked_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    created_at: Mapped[datetime] = _now()
    __table_args__ = (
        PrimaryKeyConstraint("blocker_id", "blocked_id"),
        CheckConstraint("blocker_id <> blocked_id", name="ck_blocks_self"),
        Index("ix_blocks_blocked", "blocked_id"),
    )


class Mute(Base):
    __tablename__ = "mutes"
    muter_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    muted_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    created_at: Mapped[datetime] = _now()
    __table_args__ = (
        PrimaryKeyConstraint("muter_id", "muted_id"),
        CheckConstraint("muter_id <> muted_id", name="ck_mutes_self"),
    )


# --------------------------------------------------------------------------- content
class Post(Base):
    __tablename__ = "posts"
    id: Mapped[uuid.UUID] = _uuid_pk()
    author_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    body: Mapped[str] = mapped_column(String(500), nullable=False)
    entities: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    visibility: Mapped[str] = mapped_column(String(16), nullable=False, server_default="public")
    reply_to_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("posts.id", ondelete="SET NULL"))
    content_hash: Mapped[bytes] = mapped_column(LargeBinary(32), nullable=False)
    like_count: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    reply_count: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    repost_count: Mapped[int] = mapped_column(Integer, nullable=False, server_default="0")
    created_at: Mapped[datetime] = _now()
    deleted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    removed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))  # moderation removal
    search_vector = mapped_column(
        TSVECTOR, Computed("to_tsvector('simple', body)", persisted=True), nullable=True
    )
    __table_args__ = (
        CheckConstraint(_in("visibility", VISIBILITIES), name="ck_posts_visibility"),
        CheckConstraint("like_count >= 0 AND reply_count >= 0 AND repost_count >= 0", name="ck_posts_counts"),
        CheckConstraint("char_length(body) <= 500", name="ck_posts_body_len"),
        CheckConstraint("reply_to_id IS NULL OR reply_to_id <> id", name="ck_posts_self_reply"),
        Index("ix_posts_author_created", "author_id", "created_at", "id"),
        Index("ix_posts_created", "created_at", "id"),
        Index("ix_posts_reply_to", "reply_to_id", "created_at"),
        Index("ix_posts_dupe", "author_id", "content_hash", "created_at"),
        Index("ix_posts_search", "search_vector", postgresql_using="gin"),
    )


class PostHashtag(Base):
    __tablename__ = "post_hashtags"
    post_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("posts.id", ondelete="CASCADE"))
    tag: Mapped[str] = mapped_column(String(64), nullable=False)
    created_at: Mapped[datetime] = _now()
    __table_args__ = (
        PrimaryKeyConstraint("post_id", "tag"),
        CheckConstraint("tag = lower(tag)", name="ck_hashtag_lower"),
        Index("ix_post_hashtags_tag", "tag", "created_at"),
    )


class PostMention(Base):
    __tablename__ = "post_mentions"
    post_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("posts.id", ondelete="CASCADE"))
    user_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    __table_args__ = (PrimaryKeyConstraint("post_id", "user_id"), Index("ix_post_mentions_user", "user_id"))


class PostMedia(Base):
    __tablename__ = "post_media"
    post_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("posts.id", ondelete="CASCADE"))
    media_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("media.id", ondelete="CASCADE"), unique=True)
    position: Mapped[int] = mapped_column(SmallInteger, nullable=False)
    __table_args__ = (
        PrimaryKeyConstraint("post_id", "media_id"),
        UniqueConstraint("post_id", "position", name="uq_post_media_position"),
        CheckConstraint("position >= 0 AND position < 4", name="ck_post_media_position"),
    )


class Like(Base):
    __tablename__ = "likes"
    user_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    post_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("posts.id", ondelete="CASCADE"))
    created_at: Mapped[datetime] = _now()
    __table_args__ = (PrimaryKeyConstraint("user_id", "post_id"), Index("ix_likes_post", "post_id"),
                      Index("ix_likes_user_created", "user_id", "created_at"))


class Repost(Base):
    __tablename__ = "reposts"
    user_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    post_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("posts.id", ondelete="CASCADE"))
    created_at: Mapped[datetime] = _now()
    __table_args__ = (PrimaryKeyConstraint("user_id", "post_id"), Index("ix_reposts_post", "post_id"),
                      Index("ix_reposts_user_created", "user_id", "created_at"))


class Notification(Base):
    __tablename__ = "notifications"
    id: Mapped[uuid.UUID] = _uuid_pk()
    recipient_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    actor_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    type: Mapped[str] = mapped_column(String(24), nullable=False)
    post_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("posts.id", ondelete="CASCADE"))
    created_at: Mapped[datetime] = _now()
    read_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    __table_args__ = (
        CheckConstraint(_in("type", NOTIF_TYPES), name="ck_notifications_type"),
        CheckConstraint("recipient_id <> actor_id", name="ck_notifications_self"),
        Index("ix_notifications_recipient", "recipient_id", "created_at", "id"),
        Index(
            "uq_notifications_dedupe", "recipient_id", "actor_id", "type", "post_id",
            unique=True, postgresql_nulls_not_distinct=True,
        ),
    )


# --------------------------------------------------------------------------- messaging
class Conversation(Base):
    __tablename__ = "conversations"
    id: Mapped[uuid.UUID] = _uuid_pk()
    # canonical "<smaller-uuid>:<larger-uuid>" so a pair can only ever have one DM thread
    pair_key: Mapped[str] = mapped_column(String(73), nullable=False, unique=True)
    created_at: Mapped[datetime] = _now()
    last_message_at: Mapped[datetime] = _now()


class ConversationMember(Base):
    __tablename__ = "conversation_members"
    conversation_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("conversations.id", ondelete="CASCADE"))
    user_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"))
    joined_at: Mapped[datetime] = _now()
    last_read_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    cleared_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))  # "delete for me"
    __table_args__ = (PrimaryKeyConstraint("conversation_id", "user_id"), Index("ix_conv_members_user", "user_id"))


class Message(Base):
    __tablename__ = "messages"
    id: Mapped[uuid.UUID] = _uuid_pk()
    conversation_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("conversations.id", ondelete="CASCADE"), nullable=False)
    sender_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    body: Mapped[str] = mapped_column(String(2000), nullable=False, server_default="")
    media_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("media.id", ondelete="SET NULL"), unique=True)
    created_at: Mapped[datetime] = _now()
    deleted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    __table_args__ = (
        CheckConstraint("char_length(body) <= 2000", name="ck_messages_body_len"),
        Index("ix_messages_conv_created", "conversation_id", "created_at", "id"),
        Index("ix_messages_sender_created", "sender_id", "created_at"),
    )


# --------------------------------------------------------------------------- media
class Media(Base):
    __tablename__ = "media"
    id: Mapped[uuid.UUID] = _uuid_pk()
    owner_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    kind: Mapped[str] = mapped_column(String(8), nullable=False)
    purpose: Mapped[str] = mapped_column(String(8), nullable=False)
    state: Mapped[str] = mapped_column(String(12), nullable=False, server_default="pending")
    sniffed_type: Mapped[str] = mapped_column(String(32), nullable=False)
    size_bytes: Mapped[int] = mapped_column(BigInteger, nullable=False)
    sha256: Mapped[bytes] = mapped_column(LargeBinary(32), nullable=False)
    quarantine_key: Mapped[str | None] = mapped_column(String(64))
    variants: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    width: Mapped[int | None] = mapped_column(Integer)
    height: Mapped[int | None] = mapped_column(Integer)
    duration_ms: Mapped[int | None] = mapped_column(Integer)
    error_code: Mapped[str | None] = mapped_column(String(32))
    attempts: Mapped[int] = mapped_column(SmallInteger, nullable=False, server_default="0")
    alt_text: Mapped[str] = mapped_column(String(1000), nullable=False, server_default="")
    created_at: Mapped[datetime] = _now()
    claimed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    processed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    __table_args__ = (
        CheckConstraint(_in("kind", MEDIA_KINDS), name="ck_media_kind"),
        CheckConstraint(_in("purpose", MEDIA_PURPOSES), name="ck_media_purpose"),
        CheckConstraint(_in("state", MEDIA_STATES), name="ck_media_state"),
        CheckConstraint("size_bytes > 0", name="ck_media_size"),
        Index("ix_media_state", "state", "created_at"),
        Index("ix_media_owner_created", "owner_id", "created_at"),
    )


# --------------------------------------------------------------------------- moderation
class Report(Base):
    __tablename__ = "reports"
    id: Mapped[uuid.UUID] = _uuid_pk()
    reporter_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    target_type: Mapped[str] = mapped_column(String(16), nullable=False)
    target_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False)
    target_user_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="SET NULL"))
    reason: Mapped[str] = mapped_column(String(24), nullable=False)
    details: Mapped[str] = mapped_column(String(1000), nullable=False, server_default="")
    evidence: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    status: Mapped[str] = mapped_column(String(16), nullable=False, server_default="open")
    created_at: Mapped[datetime] = _now()
    resolved_by: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="SET NULL"))
    resolved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    resolution_note: Mapped[str] = mapped_column(String(1000), nullable=False, server_default="")
    __table_args__ = (
        CheckConstraint(_in("target_type", REPORT_TARGETS), name="ck_reports_target"),
        CheckConstraint(_in("reason", REPORT_REASONS), name="ck_reports_reason"),
        CheckConstraint(_in("status", REPORT_STATUS), name="ck_reports_status"),
        Index("uq_reports_open", "reporter_id", "target_type", "target_id", unique=True,
              postgresql_where=text("status = 'open'")),
        Index("ix_reports_status_created", "status", "created_at"),
    )


class BlockedDomain(Base):
    __tablename__ = "blocked_domains"
    domain: Mapped[str] = mapped_column(String(253), primary_key=True)
    reason: Mapped[str] = mapped_column(String(200), nullable=False, server_default="")
    created_by: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = _now()
    __table_args__ = (CheckConstraint("domain = lower(domain)", name="ck_blocked_domains_lower"),)


class AuditLog(Base):
    """Append-only, hash-chained. UPDATE/DELETE/TRUNCATE are blocked by trigger (see migration 0002)."""

    __tablename__ = "audit_log"
    seq: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    actor_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True))
    actor_role: Mapped[str] = mapped_column(String(16), nullable=False)
    action: Mapped[str] = mapped_column(String(64), nullable=False)
    target_type: Mapped[str] = mapped_column(String(32), nullable=False, server_default="")
    target_id: Mapped[str] = mapped_column(String(64), nullable=False, server_default="")
    ip_prefix: Mapped[str] = mapped_column(String(64), nullable=False, server_default="")
    request_id: Mapped[str] = mapped_column(String(64), nullable=False, server_default="")
    details: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    prev_hash: Mapped[bytes] = mapped_column(LargeBinary(32), nullable=False)
    row_hash: Mapped[bytes] = mapped_column(LargeBinary(32), nullable=False, unique=True)
    __table_args__ = (Index("ix_audit_actor", "actor_id", "seq"), Index("ix_audit_action", "action", "seq"))


class EmailOutbox(Base):
    """Transactional outbox: mail is enqueued in the same transaction as the state change."""

    __tablename__ = "email_outbox"
    id: Mapped[uuid.UUID] = _uuid_pk()
    to_addr: Mapped[str] = mapped_column(String(254), nullable=False)
    subject: Mapped[str] = mapped_column(String(200), nullable=False)
    body: Mapped[str | None] = mapped_column(Text)
    kind: Mapped[str] = mapped_column(String(32), nullable=False)
    created_at: Mapped[datetime] = _now()
    sent_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    attempts: Mapped[int] = mapped_column(SmallInteger, nullable=False, server_default="0")
    last_error: Mapped[str | None] = mapped_column(String(200))
    __table_args__ = (Index("ix_email_outbox_pending", "created_at", postgresql_where=text("sent_at IS NULL")),)

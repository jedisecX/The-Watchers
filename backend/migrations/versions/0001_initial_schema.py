"""initial schema

Revision ID: 0001
Revises: 
Create Date: 2026-09-30 09:46:08.168986
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = '0001'
down_revision = None
branch_labels = None
depends_on = None


def upgrade() -> None:
    # pg_trgm is a trusted extension (PG13+), so the schema owner may create it.
    op.execute("CREATE EXTENSION IF NOT EXISTS pg_trgm")
    op.create_table('audit_log',
    sa.Column('seq', sa.BigInteger(), autoincrement=True, nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('actor_id', sa.UUID(), nullable=True),
    sa.Column('actor_role', sa.String(length=16), nullable=False),
    sa.Column('action', sa.String(length=64), nullable=False),
    sa.Column('target_type', sa.String(length=32), server_default='', nullable=False),
    sa.Column('target_id', sa.String(length=64), server_default='', nullable=False),
    sa.Column('ip_prefix', sa.String(length=64), server_default='', nullable=False),
    sa.Column('request_id', sa.String(length=64), server_default='', nullable=False),
    sa.Column('details', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('prev_hash', sa.LargeBinary(length=32), nullable=False),
    sa.Column('row_hash', sa.LargeBinary(length=32), nullable=False),
    sa.PrimaryKeyConstraint('seq'),
    sa.UniqueConstraint('row_hash')
    )
    op.create_index('ix_audit_action', 'audit_log', ['action', 'seq'], unique=False)
    op.create_index('ix_audit_actor', 'audit_log', ['actor_id', 'seq'], unique=False)
    op.create_table('conversations',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('pair_key', sa.String(length=73), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('last_message_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('pair_key')
    )
    op.create_table('email_outbox',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('to_addr', sa.String(length=254), nullable=False),
    sa.Column('subject', sa.String(length=200), nullable=False),
    sa.Column('body', sa.Text(), nullable=True),
    sa.Column('kind', sa.String(length=32), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('sent_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('attempts', sa.SmallInteger(), server_default='0', nullable=False),
    sa.Column('last_error', sa.String(length=200), nullable=True),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index('ix_email_outbox_pending', 'email_outbox', ['created_at'], unique=False, postgresql_where=sa.text('sent_at IS NULL'))
    op.create_table('users',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('handle', sa.String(length=30), nullable=False),
    sa.Column('handle_norm', sa.String(length=30), nullable=False),
    sa.Column('email', sa.String(length=254), nullable=False),
    sa.Column('email_verified_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('password_hash', sa.String(length=255), nullable=False),
    sa.Column('password_changed_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('display_name', sa.String(length=50), server_default='', nullable=False),
    sa.Column('bio', sa.String(length=300), server_default='', nullable=False),
    sa.Column('avatar_media_id', sa.UUID(), nullable=True),
    sa.Column('role', sa.String(length=16), server_default='user', nullable=False),
    sa.Column('status', sa.String(length=16), server_default='active', nullable=False),
    sa.Column('is_private', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('dm_policy', sa.String(length=16), server_default='following', nullable=False),
    sa.Column('discoverable', sa.Boolean(), server_default=sa.text('true'), nullable=False),
    sa.Column('show_likes', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('default_visibility', sa.String(length=16), server_default='public', nullable=False),
    sa.Column('failed_login_count', sa.Integer(), server_default='0', nullable=False),
    sa.Column('session_epoch', sa.Integer(), server_default='0', nullable=False),
    sa.Column('totp_secret_enc', sa.LargeBinary(), nullable=True),
    sa.Column('totp_pending_enc', sa.LargeBinary(), nullable=True),
    sa.Column('totp_last_step', sa.BigInteger(), server_default='0', nullable=False),
    sa.Column('mfa_enabled_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('strikes', sa.SmallInteger(), server_default='0', nullable=False),
    sa.Column('suspended_until', sa.DateTime(timezone=True), nullable=True),
    sa.Column('deleted_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('purge_after', sa.DateTime(timezone=True), nullable=True),
    sa.Column('followers_count', sa.Integer(), server_default='0', nullable=False),
    sa.Column('following_count', sa.Integer(), server_default='0', nullable=False),
    sa.Column('posts_count', sa.Integer(), server_default='0', nullable=False),
    sa.Column('version', sa.Integer(), server_default='1', nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("default_visibility IN ('public', 'followers')", name='ck_users_default_vis'),
    sa.CheckConstraint("dm_policy IN ('everyone', 'following', 'nobody')", name='ck_users_dm_policy'),
    sa.CheckConstraint("handle ~ '^[A-Za-z0-9_]{3,30}$'", name='ck_users_handle_charset'),
    sa.CheckConstraint("role IN ('user', 'moderator', 'admin')", name='ck_users_role'),
    sa.CheckConstraint("status IN ('active', 'suspended', 'deactivated')", name='ck_users_status'),
    sa.CheckConstraint('email = lower(email)', name='ck_users_email_lower'),
    sa.CheckConstraint('failed_login_count >= 0', name='ck_users_failed'),
    sa.CheckConstraint('followers_count >= 0 AND following_count >= 0 AND posts_count >= 0', name='ck_users_counts'),
    sa.CheckConstraint('handle_norm = lower(handle)', name='ck_users_handle_norm'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('email'),
    sa.UniqueConstraint('handle_norm')
    )
    op.create_index('ix_users_handle_trgm', 'users', ['handle_norm'], unique=False, postgresql_using='gin', postgresql_ops={'handle_norm': 'gin_trgm_ops'})
    op.create_index('ix_users_purge', 'users', ['purge_after'], unique=False, postgresql_where=sa.text('purge_after IS NOT NULL'))
    op.create_table('api_tokens',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('name', sa.String(length=50), nullable=False),
    sa.Column('prefix', sa.String(length=16), nullable=False),
    sa.Column('token_hash', sa.LargeBinary(length=32), nullable=False),
    sa.Column('scopes', postgresql.ARRAY(sa.String(length=32)), nullable=False),
    sa.Column('epoch', sa.Integer(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('last_used_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('expires_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('revoked_at', sa.DateTime(timezone=True), nullable=True),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('token_hash')
    )
    op.create_index('ix_api_tokens_user', 'api_tokens', ['user_id'], unique=False)
    op.create_table('auth_tokens',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=True),
    sa.Column('purpose', sa.String(length=32), nullable=False),
    sa.Column('token_hash', sa.LargeBinary(length=32), nullable=False),
    sa.Column('data', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('attempts', sa.SmallInteger(), server_default='0', nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('expires_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('used_at', sa.DateTime(timezone=True), nullable=True),
    sa.CheckConstraint("purpose IN ('email_verify', 'password_reset', 'mfa_pending', 'ws_ticket', 'email_change', 'webauthn_register', 'webauthn_login')", name='ck_auth_tokens_purpose'),
    sa.CheckConstraint('expires_at > created_at', name='ck_auth_tokens_expiry'),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('token_hash')
    )
    op.create_index('ix_auth_tokens_expires', 'auth_tokens', ['expires_at'], unique=False)
    op.create_index('ix_auth_tokens_user_purpose', 'auth_tokens', ['user_id', 'purpose'], unique=False)
    op.create_table('blocked_domains',
    sa.Column('domain', sa.String(length=253), nullable=False),
    sa.Column('reason', sa.String(length=200), server_default='', nullable=False),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('domain = lower(domain)', name='ck_blocked_domains_lower'),
    sa.ForeignKeyConstraint(['created_by'], ['users.id'], ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('domain')
    )
    op.create_table('blocks',
    sa.Column('blocker_id', sa.UUID(), nullable=False),
    sa.Column('blocked_id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('blocker_id <> blocked_id', name='ck_blocks_self'),
    sa.ForeignKeyConstraint(['blocked_id'], ['users.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['blocker_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('blocker_id', 'blocked_id')
    )
    op.create_index('ix_blocks_blocked', 'blocks', ['blocked_id'], unique=False)
    op.create_table('conversation_members',
    sa.Column('conversation_id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('joined_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('last_read_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('cleared_at', sa.DateTime(timezone=True), nullable=True),
    sa.ForeignKeyConstraint(['conversation_id'], ['conversations.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('conversation_id', 'user_id')
    )
    op.create_index('ix_conv_members_user', 'conversation_members', ['user_id'], unique=False)
    op.create_table('follow_requests',
    sa.Column('requester_id', sa.UUID(), nullable=False),
    sa.Column('target_id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('requester_id <> target_id', name='ck_follow_requests_self'),
    sa.ForeignKeyConstraint(['requester_id'], ['users.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['target_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('requester_id', 'target_id')
    )
    op.create_index('ix_follow_requests_target', 'follow_requests', ['target_id', 'created_at'], unique=False)
    op.create_table('follows',
    sa.Column('follower_id', sa.UUID(), nullable=False),
    sa.Column('followee_id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('follower_id <> followee_id', name='ck_follows_self'),
    sa.ForeignKeyConstraint(['followee_id'], ['users.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['follower_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('follower_id', 'followee_id')
    )
    op.create_index('ix_follows_followee', 'follows', ['followee_id', 'created_at'], unique=False)
    op.create_index('ix_follows_follower_created', 'follows', ['follower_id', 'created_at'], unique=False)
    op.create_table('media',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('owner_id', sa.UUID(), nullable=False),
    sa.Column('kind', sa.String(length=8), nullable=False),
    sa.Column('purpose', sa.String(length=8), nullable=False),
    sa.Column('state', sa.String(length=12), server_default='pending', nullable=False),
    sa.Column('sniffed_type', sa.String(length=32), nullable=False),
    sa.Column('size_bytes', sa.BigInteger(), nullable=False),
    sa.Column('sha256', sa.LargeBinary(length=32), nullable=False),
    sa.Column('quarantine_key', sa.String(length=64), nullable=True),
    sa.Column('variants', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('width', sa.Integer(), nullable=True),
    sa.Column('height', sa.Integer(), nullable=True),
    sa.Column('duration_ms', sa.Integer(), nullable=True),
    sa.Column('error_code', sa.String(length=32), nullable=True),
    sa.Column('attempts', sa.SmallInteger(), server_default='0', nullable=False),
    sa.Column('alt_text', sa.String(length=1000), server_default='', nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('claimed_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('processed_at', sa.DateTime(timezone=True), nullable=True),
    sa.CheckConstraint("kind IN ('image', 'video')", name='ck_media_kind'),
    sa.CheckConstraint("purpose IN ('post', 'avatar', 'dm')", name='ck_media_purpose'),
    sa.CheckConstraint("state IN ('pending', 'processing', 'ready', 'failed')", name='ck_media_state'),
    sa.CheckConstraint('size_bytes > 0', name='ck_media_size'),
    sa.ForeignKeyConstraint(['owner_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index('ix_media_owner_created', 'media', ['owner_id', 'created_at'], unique=False)
    op.create_index('ix_media_state', 'media', ['state', 'created_at'], unique=False)
    op.create_table('mutes',
    sa.Column('muter_id', sa.UUID(), nullable=False),
    sa.Column('muted_id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('muter_id <> muted_id', name='ck_mutes_self'),
    sa.ForeignKeyConstraint(['muted_id'], ['users.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['muter_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('muter_id', 'muted_id')
    )
    op.create_table('posts',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('author_id', sa.UUID(), nullable=False),
    sa.Column('body', sa.String(length=500), nullable=False),
    sa.Column('entities', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('visibility', sa.String(length=16), server_default='public', nullable=False),
    sa.Column('reply_to_id', sa.UUID(), nullable=True),
    sa.Column('content_hash', sa.LargeBinary(length=32), nullable=False),
    sa.Column('like_count', sa.Integer(), server_default='0', nullable=False),
    sa.Column('reply_count', sa.Integer(), server_default='0', nullable=False),
    sa.Column('repost_count', sa.Integer(), server_default='0', nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('deleted_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('removed_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('search_vector', postgresql.TSVECTOR(), sa.Computed("to_tsvector('simple', body)", persisted=True), nullable=True),
    sa.CheckConstraint("visibility IN ('public', 'followers')", name='ck_posts_visibility'),
    sa.CheckConstraint('char_length(body) <= 500', name='ck_posts_body_len'),
    sa.CheckConstraint('like_count >= 0 AND reply_count >= 0 AND repost_count >= 0', name='ck_posts_counts'),
    sa.CheckConstraint('reply_to_id IS NULL OR reply_to_id <> id', name='ck_posts_self_reply'),
    sa.ForeignKeyConstraint(['author_id'], ['users.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['reply_to_id'], ['posts.id'], ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index('ix_posts_author_created', 'posts', ['author_id', 'created_at', 'id'], unique=False)
    op.create_index('ix_posts_created', 'posts', ['created_at', 'id'], unique=False)
    op.create_index('ix_posts_dupe', 'posts', ['author_id', 'content_hash', 'created_at'], unique=False)
    op.create_index('ix_posts_reply_to', 'posts', ['reply_to_id', 'created_at'], unique=False)
    op.create_index('ix_posts_search', 'posts', ['search_vector'], unique=False, postgresql_using='gin')
    op.create_table('recovery_codes',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('code_hash', sa.LargeBinary(length=32), nullable=False),
    sa.Column('used_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('user_id', 'code_hash', name='uq_recovery_codes')
    )
    op.create_table('reports',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('reporter_id', sa.UUID(), nullable=False),
    sa.Column('target_type', sa.String(length=16), nullable=False),
    sa.Column('target_id', sa.UUID(), nullable=False),
    sa.Column('target_user_id', sa.UUID(), nullable=True),
    sa.Column('reason', sa.String(length=24), nullable=False),
    sa.Column('details', sa.String(length=1000), server_default='', nullable=False),
    sa.Column('evidence', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('status', sa.String(length=16), server_default='open', nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('resolved_by', sa.UUID(), nullable=True),
    sa.Column('resolved_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('resolution_note', sa.String(length=1000), server_default='', nullable=False),
    sa.CheckConstraint("reason IN ('spam', 'harassment', 'hate', 'violence', 'sexual', 'self_harm', 'impersonation', 'illegal', 'other')", name='ck_reports_reason'),
    sa.CheckConstraint("status IN ('open', 'resolved', 'dismissed')", name='ck_reports_status'),
    sa.CheckConstraint("target_type IN ('user', 'post', 'message')", name='ck_reports_target'),
    sa.ForeignKeyConstraint(['reporter_id'], ['users.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['resolved_by'], ['users.id'], ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['target_user_id'], ['users.id'], ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index('ix_reports_status_created', 'reports', ['status', 'created_at'], unique=False)
    op.create_index('uq_reports_open', 'reports', ['reporter_id', 'target_type', 'target_id'], unique=True, postgresql_where=sa.text("status = 'open'"))
    op.create_table('sessions',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('token_hash', sa.LargeBinary(length=32), nullable=False),
    sa.Column('epoch', sa.Integer(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('last_seen_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('expires_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('reauth_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('mfa_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('revoked_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('ip_prefix', sa.String(length=64), server_default='', nullable=False),
    sa.Column('user_agent', sa.String(length=200), server_default='', nullable=False),
    sa.Column('auth_method', sa.String(length=16), server_default='password', nullable=False),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('token_hash')
    )
    op.create_index('ix_sessions_user', 'sessions', ['user_id', 'revoked_at'], unique=False)
    op.create_table('webauthn_credentials',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('credential_id', sa.LargeBinary(length=1023), nullable=False),
    sa.Column('public_key', sa.LargeBinary(), nullable=False),
    sa.Column('sign_count', sa.BigInteger(), server_default='0', nullable=False),
    sa.Column('transports', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'[]'::jsonb"), nullable=False),
    sa.Column('name', sa.String(length=50), server_default='Passkey', nullable=False),
    sa.Column('backup_eligible', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('last_used_at', sa.DateTime(timezone=True), nullable=True),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('credential_id')
    )
    op.create_index('ix_webauthn_user', 'webauthn_credentials', ['user_id'], unique=False)
    op.create_table('likes',
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('post_id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['post_id'], ['posts.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('user_id', 'post_id')
    )
    op.create_index('ix_likes_post', 'likes', ['post_id'], unique=False)
    op.create_index('ix_likes_user_created', 'likes', ['user_id', 'created_at'], unique=False)
    op.create_table('messages',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('conversation_id', sa.UUID(), nullable=False),
    sa.Column('sender_id', sa.UUID(), nullable=False),
    sa.Column('body', sa.String(length=2000), server_default='', nullable=False),
    sa.Column('media_id', sa.UUID(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('deleted_at', sa.DateTime(timezone=True), nullable=True),
    sa.CheckConstraint('char_length(body) <= 2000', name='ck_messages_body_len'),
    sa.ForeignKeyConstraint(['conversation_id'], ['conversations.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['media_id'], ['media.id'], ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['sender_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('media_id')
    )
    op.create_index('ix_messages_conv_created', 'messages', ['conversation_id', 'created_at', 'id'], unique=False)
    op.create_index('ix_messages_sender_created', 'messages', ['sender_id', 'created_at'], unique=False)
    op.create_table('notifications',
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('recipient_id', sa.UUID(), nullable=False),
    sa.Column('actor_id', sa.UUID(), nullable=False),
    sa.Column('type', sa.String(length=24), nullable=False),
    sa.Column('post_id', sa.UUID(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('read_at', sa.DateTime(timezone=True), nullable=True),
    sa.CheckConstraint("type IN ('follow', 'follow_request', 'follow_accept', 'like', 'reply', 'mention', 'repost')", name='ck_notifications_type'),
    sa.CheckConstraint('recipient_id <> actor_id', name='ck_notifications_self'),
    sa.ForeignKeyConstraint(['actor_id'], ['users.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['post_id'], ['posts.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['recipient_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index('ix_notifications_recipient', 'notifications', ['recipient_id', 'created_at', 'id'], unique=False)
    op.create_index('uq_notifications_dedupe', 'notifications', ['recipient_id', 'actor_id', 'type', 'post_id'], unique=True, postgresql_nulls_not_distinct=True)
    op.create_table('post_hashtags',
    sa.Column('post_id', sa.UUID(), nullable=False),
    sa.Column('tag', sa.String(length=64), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('tag = lower(tag)', name='ck_hashtag_lower'),
    sa.ForeignKeyConstraint(['post_id'], ['posts.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('post_id', 'tag')
    )
    op.create_index('ix_post_hashtags_tag', 'post_hashtags', ['tag', 'created_at'], unique=False)
    op.create_table('post_media',
    sa.Column('post_id', sa.UUID(), nullable=False),
    sa.Column('media_id', sa.UUID(), nullable=False),
    sa.Column('position', sa.SmallInteger(), nullable=False),
    sa.CheckConstraint('position >= 0 AND position < 4', name='ck_post_media_position'),
    sa.ForeignKeyConstraint(['media_id'], ['media.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['post_id'], ['posts.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('post_id', 'media_id'),
    sa.UniqueConstraint('media_id'),
    sa.UniqueConstraint('post_id', 'position', name='uq_post_media_position')
    )
    op.create_table('post_mentions',
    sa.Column('post_id', sa.UUID(), nullable=False),
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['post_id'], ['posts.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('post_id', 'user_id')
    )
    op.create_index('ix_post_mentions_user', 'post_mentions', ['user_id'], unique=False)
    op.create_table('reposts',
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('post_id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['post_id'], ['posts.id'], ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['user_id'], ['users.id'], ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('user_id', 'post_id')
    )
    op.create_index('ix_reposts_post', 'reposts', ['post_id'], unique=False)
    op.create_index('ix_reposts_user_created', 'reposts', ['user_id', 'created_at'], unique=False)
    # users <-> media is circular; add the avatar FK once both tables exist.
    op.create_foreign_key('fk_users_avatar', 'users', 'media', ['avatar_media_id'], ['id'], ondelete='SET NULL')


def downgrade() -> None:
    op.drop_constraint('fk_users_avatar', 'users', type_='foreignkey')
    op.drop_index('ix_reposts_user_created', table_name='reposts')
    op.drop_index('ix_reposts_post', table_name='reposts')
    op.drop_table('reposts')
    op.drop_index('ix_post_mentions_user', table_name='post_mentions')
    op.drop_table('post_mentions')
    op.drop_table('post_media')
    op.drop_index('ix_post_hashtags_tag', table_name='post_hashtags')
    op.drop_table('post_hashtags')
    op.drop_index('uq_notifications_dedupe', table_name='notifications', postgresql_nulls_not_distinct=True)
    op.drop_index('ix_notifications_recipient', table_name='notifications')
    op.drop_table('notifications')
    op.drop_index('ix_messages_sender_created', table_name='messages')
    op.drop_index('ix_messages_conv_created', table_name='messages')
    op.drop_table('messages')
    op.drop_index('ix_likes_user_created', table_name='likes')
    op.drop_index('ix_likes_post', table_name='likes')
    op.drop_table('likes')
    op.drop_index('ix_webauthn_user', table_name='webauthn_credentials')
    op.drop_table('webauthn_credentials')
    op.drop_index('ix_sessions_user', table_name='sessions')
    op.drop_table('sessions')
    op.drop_index('uq_reports_open', table_name='reports', postgresql_where=sa.text("status = 'open'"))
    op.drop_index('ix_reports_status_created', table_name='reports')
    op.drop_table('reports')
    op.drop_table('recovery_codes')
    op.drop_index('ix_posts_search', table_name='posts', postgresql_using='gin')
    op.drop_index('ix_posts_reply_to', table_name='posts')
    op.drop_index('ix_posts_dupe', table_name='posts')
    op.drop_index('ix_posts_created', table_name='posts')
    op.drop_index('ix_posts_author_created', table_name='posts')
    op.drop_table('posts')
    op.drop_table('mutes')
    op.drop_index('ix_media_state', table_name='media')
    op.drop_index('ix_media_owner_created', table_name='media')
    op.drop_table('media')
    op.drop_index('ix_follows_follower_created', table_name='follows')
    op.drop_index('ix_follows_followee', table_name='follows')
    op.drop_table('follows')
    op.drop_index('ix_follow_requests_target', table_name='follow_requests')
    op.drop_table('follow_requests')
    op.drop_index('ix_conv_members_user', table_name='conversation_members')
    op.drop_table('conversation_members')
    op.drop_index('ix_blocks_blocked', table_name='blocks')
    op.drop_table('blocks')
    op.drop_table('blocked_domains')
    op.drop_index('ix_auth_tokens_user_purpose', table_name='auth_tokens')
    op.drop_index('ix_auth_tokens_expires', table_name='auth_tokens')
    op.drop_table('auth_tokens')
    op.drop_index('ix_api_tokens_user', table_name='api_tokens')
    op.drop_table('api_tokens')
    op.drop_index('ix_users_purge', table_name='users', postgresql_where=sa.text('purge_after IS NOT NULL'))
    op.drop_index('ix_users_handle_trgm', table_name='users', postgresql_using='gin', postgresql_ops={'handle_norm': 'gin_trgm_ops'})
    op.drop_table('users')
    op.drop_index('ix_email_outbox_pending', table_name='email_outbox', postgresql_where=sa.text('sent_at IS NULL'))
    op.drop_table('email_outbox')
    op.drop_table('conversations')
    op.drop_index('ix_audit_actor', table_name='audit_log')
    op.drop_index('ix_audit_action', table_name='audit_log')
    op.drop_table('audit_log')

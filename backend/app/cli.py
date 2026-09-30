"""Operator CLI:  python -m app.cli <command>

  create-admin --handle H --email E     bootstrap an administrator (prints a one-time password;
                                        the account must enrol MFA before the admin API works)
  verify-audit                          recompute the audit hash chain; exit 1 on tampering
  revoke-all-sessions                   global kill switch: invalidate every session and API token
  purge                                 retention: purge accounts past their 30-day grace period,
                                        expired tokens, sent email bodies, stale failed media
  mail-loop                             deliver the email outbox continuously
  reencrypt                             re-encrypt TOTP secrets with the primary field key (rotation)
  recount                               recompute denormalised counters
  outbox                                (development only) print recent outgoing mail
  force-reset (--handle H | --all)      incident response: invalidate passwords + revoke sessions/tokens
  media-rescan                          withdraw any served derivative that is missing or not the declared type
"""
from __future__ import annotations

import argparse
import secrets
import sys
import time
from datetime import datetime, timedelta, timezone

from sqlalchemy import delete, func, select, update

from . import audit, media_store
from .db import new_session
from .logging_setup import configure
from .models import EmailOutbox, Follow, Like, Media, Message, Post, PostMedia, Report, Repost, User
from .models import Session as DbSession
from .security.crypto import reencrypt_field
from .security.passwords import UNUSABLE_PASSWORD_HASH, hash_password
from .services import mailer, tokens


def create_admin(handle: str, email: str) -> int:
    from .text import validate_handle
    validate_handle(handle)
    db = new_session()
    pw = secrets.token_urlsafe(18)
    u = User(handle=handle, handle_norm=handle.lower(), email=email.lower(), password_hash=hash_password(pw),
             role="admin", email_verified_at=datetime.now(timezone.utc))
    db.add(u)
    db.flush()
    audit.record(db, action="admin.bootstrap", actor_id=None, actor_role="system", target_type="user", target_id=u.id)
    db.commit()
    print(f"Created admin @{handle}. One-time password (change it and enrol MFA immediately):\n{pw}")
    return 0


def verify_audit() -> int:
    db = new_session()
    ok, bad, n = audit.verify_chain(db)
    print(f"audit chain: {'OK' if ok else 'TAMPERED at seq ' + str(bad)} ({n} rows verified)")
    return 0 if ok else 1


def revoke_all() -> int:
    db = new_session()
    db.execute(update(User).values(session_epoch=User.session_epoch + 1))
    audit.record(db, action="admin.global_session_revocation", actor_id=None, actor_role="system")
    db.commit()
    print("All sessions and API tokens revoked.")
    return 0


def purge() -> int:
    db = new_session()
    now = datetime.now(timezone.utc)
    doomed = db.execute(select(User.id).where(User.purge_after.is_not(None), User.purge_after < now)).scalars().all()
    for uid in doomed:
        for m in db.execute(select(Media).where(Media.owner_id == uid)).scalars():
            for v in (m.variants or {}).values():
                media_store.remove_quietly(media_store.safe_path("store", v["key"]))
        db.execute(delete(User).where(User.id == uid))  # FK cascades remove all owned rows
        audit.record(db, action="retention.account_purged", actor_id=None, actor_role="system",
                     target_type="user", target_id=uid)
    n_tok = tokens.purge_expired(db)
    db.execute(update(EmailOutbox).where(EmailOutbox.sent_at.is_not(None)).values(body=None))
    db.execute(delete(EmailOutbox).where(EmailOutbox.created_at < now - timedelta(days=30)))
    db.execute(delete(Media).where(Media.state == "failed", Media.created_at < now - timedelta(days=1)))
    # Uploads never attached to anything within 7 days are orphans (also a storage-abuse vector).
    orphans = db.execute(select(Media).where(
        Media.state.in_(("ready", "failed")), Media.created_at < now - timedelta(days=7),
        ~select(PostMedia.media_id).where(PostMedia.media_id == Media.id).exists(),
        ~select(Message.id).where(Message.media_id == Media.id).exists(),
        ~select(User.id).where(User.avatar_media_id == Media.id).exists())).scalars().all()
    for m in orphans:
        for v in (m.variants or {}).values():
            media_store.remove_quietly(media_store.safe_path("store", v["key"]))
        db.delete(m)
    # Retention (docs/PRIVACY.md): device history 90 days; closed reports 1 year.
    n_sess = db.execute(delete(DbSession).where(
        (DbSession.revoked_at < now - timedelta(days=90)) | (DbSession.expires_at < now - timedelta(days=90)))).rowcount
    n_rep = db.execute(delete(Report).where(Report.status != "open",
                                            Report.resolved_at < now - timedelta(days=365))).rowcount
    db.commit()
    print(f"purged accounts={len(doomed)} tokens={n_tok} sessions={n_sess} reports={n_rep} orphan_media={len(orphans)}")
    return 0


def outbox() -> int:
    """Development only: show recent outbox mail (e.g. verification links). Refused in production."""
    from .config import get_settings
    if get_settings().is_production:
        print("refused: outbox dump is disabled in production", file=sys.stderr)
        return 2
    db = new_session()
    for m in db.execute(select(EmailOutbox).order_by(EmailOutbox.created_at.desc()).limit(5)).scalars():
        print(f"--- {m.created_at:%H:%M:%S} to={m.to_addr} subject={m.subject}\n{m.body}")
    return 0


def force_reset(handle: str | None, everyone: bool) -> int:
    """Incident response: invalidate passwords (unusable hash), revoke all sessions/tokens, and tell the
    affected users to reset. MFA/passkeys are kept, so a reset still requires the second factor at login."""
    if not handle and not everyone:
        print("specify --handle H or --all", file=sys.stderr)
        return 2
    db = new_session()
    q = select(User).where(User.deleted_at.is_(None))
    if handle:
        q = q.where(User.handle_norm == handle.lower())
    n = 0
    for u in db.execute(q).scalars():
        u.password_hash = UNUSABLE_PASSWORD_HASH
        u.session_epoch = u.session_epoch + 1
        mailer.security_notice(db, u.email, "As a security precaution your Bastion password was invalidated. "
                                            "Use 'Forgot password' to choose a new one.")
        n += 1
    audit.record(db, action="admin.force_password_reset", actor_id=None, actor_role="system",
                 details={"scope": "all" if everyone else handle, "count": n})
    db.commit()
    print(f"forced password reset for {n} account(s)")
    return 0


MAGIC = {"image/webp": lambda b: b[:4] == b"RIFF" and b[8:12] == b"WEBP",
         "video/mp4": lambda b: b[4:8] == b"ftyp"}


def media_rescan() -> int:
    """After a suspected worker compromise: verify every served derivative still exists, is inside the
    store, and carries the magic bytes of its declared type; anything else is withdrawn (state=failed)."""
    db = new_session()
    bad = 0
    for m in db.execute(select(Media).where(Media.state == "ready")).scalars():
        ok = True
        for v in (m.variants or {}).values():
            try:
                path = media_store.safe_path("store", v["key"])
                head = path.open("rb").read(16)
            except (ValueError, OSError, KeyError):
                ok = False
                break
            if v.get("content_type") not in MAGIC or not MAGIC[v["content_type"]](head):
                ok = False
                break
        if not ok:
            m.state, m.error_code = "failed", "rescan_rejected"
            bad += 1
    audit.record(db, action="admin.media_rescan", actor_id=None, actor_role="system", details={"withdrawn": bad})
    db.commit()
    print(f"media rescan complete: {bad} item(s) withdrawn")
    return 0 if bad == 0 else 1


def mail_loop() -> int:
    while True:
        db = new_session()
        try:
            mailer.deliver_pending(db)
        finally:
            db.close()
        time.sleep(5)


def reencrypt() -> int:
    db = new_session()
    n = 0
    for u in db.execute(select(User).where(User.totp_secret_enc.is_not(None))).scalars():
        u.totp_secret_enc = reencrypt_field(u.totp_secret_enc, b"totp:" + u.id.bytes)
        n += 1
    audit.record(db, action="admin.field_keys_rotated", actor_id=None, actor_role="system", details={"rows": n})
    db.commit()
    print(f"re-encrypted {n} secrets with the primary key")
    return 0


def recount() -> int:
    db = new_session()
    db.execute(update(Post).values(
        like_count=select(func.count()).where(Like.post_id == Post.id).scalar_subquery(),
        repost_count=select(func.count()).where(Repost.post_id == Post.id).scalar_subquery()))
    db.execute(update(User).values(
        followers_count=select(func.count()).where(Follow.followee_id == User.id).scalar_subquery(),
        following_count=select(func.count()).where(Follow.follower_id == User.id).scalar_subquery()))
    db.commit()
    print("counters recomputed")
    return 0


def main(argv: list[str] | None = None) -> int:
    configure("WARNING")
    ap = argparse.ArgumentParser(prog="bastion-admin")
    sub = ap.add_subparsers(dest="cmd", required=True)
    c = sub.add_parser("create-admin")
    c.add_argument("--handle", required=True)
    c.add_argument("--email", required=True)
    for name in ("verify-audit", "revoke-all-sessions", "purge", "mail-loop", "reencrypt", "recount", "outbox",
                 "media-rescan"):
        sub.add_parser(name)
    fr = sub.add_parser("force-reset")
    fr.add_argument("--handle")
    fr.add_argument("--all", action="store_true", dest="everyone")
    a = ap.parse_args(argv)
    return {
        "create-admin": lambda: create_admin(a.handle, a.email),
        "verify-audit": verify_audit, "revoke-all-sessions": revoke_all, "purge": purge,
        "mail-loop": mail_loop, "reencrypt": reencrypt, "recount": recount, "outbox": outbox,
        "media-rescan": media_rescan, "force-reset": lambda: force_reset(a.handle, a.everyone),
    }[a.cmd]()


if __name__ == "__main__":
    sys.exit(main())


"""Transactional email outbox.

Mail is *enqueued in the same DB transaction* as the state change that triggers it, then sent
by ``deliver_pending`` (run by ``bastion-admin mail-loop``). If SMTP is down nothing is lost
(THREAT-MODEL §9), and bodies (which may contain single-use links) are erased once sent.

Header-injection defence (E-7): addresses are validated and CR/LF is rejected in every header
value; bodies are built from fixed text plus server-generated links, never user-supplied strings.
"""
from __future__ import annotations

import logging
import smtplib
import ssl
from datetime import datetime, timezone
from email.message import EmailMessage
from urllib.parse import quote

from email_validator import EmailNotValidError, validate_email
from sqlalchemy import select, update
from sqlalchemy.orm import Session

from ..config import get_settings
from ..models import EmailOutbox

log = logging.getLogger("bastion.mail")


class MailError(ValueError):
    pass


def _check_header(v: str) -> str:
    if "\r" in v or "\n" in v or "\x00" in v:
        raise MailError("header injection attempt")
    return v


def enqueue(db: Session, to: str, subject: str, body: str, kind: str) -> None:
    try:
        validate_email(to, check_deliverability=False)
    except EmailNotValidError as exc:
        raise MailError("invalid recipient") from exc
    db.add(EmailOutbox(to_addr=_check_header(to), subject=_check_header(subject)[:200], body=body, kind=kind))


def link(path: str, token: str) -> str:
    # Built from configuration, never from the request's Host header (THREAT-MODEL S-8).
    # Token goes in the fragment so it is not sent to servers or leaked via Referer.
    return f"{get_settings().public_base_url}{path}#token={quote(token, safe='')}"


# --------------------------------------------------------------------------- templates (static text)

def verify_email(db: Session, to: str, token: str) -> None:
    enqueue(db, to, "Verify your Bastion email address",
            "Confirm your email address by opening this link within 24 hours:\n\n"
            f"{link('/verify-email', token)}\n\nIf you did not create an account, ignore this message.\n",
            "verify")


def account_exists(db: Session, to: str) -> None:
    enqueue(db, to, "Bastion sign-up attempt",
            "Someone tried to create a Bastion account with this email address, but you already have one.\n"
            "If this was you, sign in or reset your password. Otherwise you can ignore this message.\n",
            "exists")


def password_reset(db: Session, to: str, token: str) -> None:
    enqueue(db, to, "Reset your Bastion password",
            "Reset your password by opening this link within 30 minutes:\n\n"
            f"{link('/reset-password', token)}\n\n"
            "Resetting signs you out everywhere. Two-factor authentication stays enabled.\n"
            "If you did not request this, ignore this message.\n",
            "reset")


def email_change(db: Session, to: str, token: str) -> None:
    enqueue(db, to, "Confirm your new Bastion email address",
            f"Confirm this address for your Bastion account within 1 hour:\n\n{link('/verify-email', token)}\n",
            "email_change")


def security_notice(db: Session, to: str, what: str) -> None:
    """``what`` is chosen from fixed server-side strings only."""
    enqueue(db, to, "Bastion security notice",
            f"{what}\n\nIf this wasn't you, reset your password immediately and review your active sessions.\n",
            "security")


# --------------------------------------------------------------------------- delivery


def deliver_pending(db: Session, batch: int = 50) -> int:
    s = get_settings()
    if s.mail_backend != "smtp":
        return 0
    rows = db.execute(
        select(EmailOutbox).where(EmailOutbox.sent_at.is_(None), EmailOutbox.attempts < 10)
        .order_by(EmailOutbox.created_at).limit(batch).with_for_update(skip_locked=True)
    ).scalars().all()
    sent = 0
    for r in rows:
        msg = EmailMessage()
        msg["From"] = s.smtp_from
        msg["To"] = r.to_addr
        msg["Subject"] = r.subject
        msg.set_content(r.body or "")
        try:
            with smtplib.SMTP(s.smtp_host or "localhost", s.smtp_port, timeout=10) as smtp:
                if s.smtp_starttls:
                    smtp.starttls(context=ssl.create_default_context())
                if s.smtp_user:
                    smtp.login(s.smtp_user, s.smtp_password or "")
                smtp.send_message(msg)
            db.execute(update(EmailOutbox).where(EmailOutbox.id == r.id).values(
                sent_at=datetime.now(timezone.utc), body=None, attempts=r.attempts + 1))
            sent += 1
        except (smtplib.SMTPException, OSError) as exc:
            db.execute(update(EmailOutbox).where(EmailOutbox.id == r.id).values(
                attempts=r.attempts + 1, last_error=type(exc).__name__))
            log.warning("mail.send_failed", extra={"event": "mail.send_failed", "error_code": type(exc).__name__})
    db.commit()
    return sent

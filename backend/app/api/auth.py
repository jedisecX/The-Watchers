"""Authentication endpoints: /api/v1/auth/*

| Endpoint | Auth | Rate limits (fail-closed) |
|---|---|---|
| GET  /pow | none | read |
| POST /register | none + PoW | register_ip |
| POST /verify-email | token | verify_email |
| POST /resend-verification | session | resend_verify |
| POST /login | none | login_ip, login_account_ip, login_account |
| POST /login/mfa | pending-MFA token | mfa_verify, token attempt budget (5) |
| POST /passkey/login/begin, /finish | none | passkey_login |
| POST /logout | session (CSRF) | none |
| GET  /session | optional | read |
| POST /password-reset/request | none | reset_ip, reset_account (silent) |
| POST /password-reset/confirm | token | reset_ip |
| POST /reauth, /reauth/passkey/* | session (CSRF) | reauth |
"""
from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from email_validator import EmailNotValidError, validate_email
from fastapi import APIRouter, Depends, Request, Response
from pydantic import Field
from sqlalchemy import exists, select, update
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from .. import audit
from ..authz import Principal, require_session, trust_tier
from ..config import get_settings
from ..db import get_db
from ..deps import StrictRoute, enforce_limit, principal
from ..errors import ApiError
from ..logging_setup import sec_event
from ..models import Session as DbSession
from ..models import User, WebAuthnCredential
from ..schemas import In
from ..security.crypto import sha256
from ..security.passwords import (
    PasswordPolicyError,
    check_password_policy,
    hash_password,
    needs_rehash,
    verify_password,
)
from ..serializers import user_self
from ..services import mailer, mfa, passkeys, sessions, tokens
from ..services import pow as powsvc
from ..text import TextError, normalize, validate_handle

router = APIRouter(prefix="/api/v1/auth", tags=["auth"], route_class=StrictRoute)

INVALID_CREDENTIALS = ApiError(401, "invalid_credentials", "Incorrect username/email or password.")


# --------------------------------------------------------------------------- schemas
class PowIn(In):
    challenge: str = Field(max_length=200)
    nonce: str = Field(max_length=64)


class RegisterIn(In):
    handle: str = Field(min_length=3, max_length=30)
    email: str = Field(min_length=3, max_length=254)
    password: str = Field(min_length=1, max_length=1024)
    display_name: str = Field(default="", max_length=100)
    pow: PowIn


class TokenIn(In):
    token: str = Field(min_length=10, max_length=128)


class LoginIn(In):
    identifier: str = Field(min_length=1, max_length=254)
    password: str = Field(min_length=1, max_length=1024)


class MfaIn(In):
    mfa_token: str = Field(min_length=10, max_length=128)
    code: str = Field(min_length=6, max_length=40)


class ResetRequestIn(In):
    email: str = Field(min_length=3, max_length=254)


class ResetConfirmIn(In):
    token: str = Field(min_length=10, max_length=128)
    new_password: str = Field(min_length=1, max_length=1024)


class ReauthIn(In):
    password: str = Field(min_length=1, max_length=1024)
    code: str | None = Field(default=None, min_length=6, max_length=40)


class PasskeyFinishIn(In):
    challenge_id: str = Field(min_length=10, max_length=128)
    credential: dict[str, Any]


# --------------------------------------------------------------------------- helpers


def normalize_email(raw: str) -> str:
    try:
        return validate_email(raw.strip(), check_deliverability=False).normalized.lower()
    except EmailNotValidError as exc:
        raise ApiError(400, "invalid_email", "Enter a valid email address.") from exc


def _has_passkeys(db: Session, user_id) -> bool:
    return bool(db.scalar(select(exists().where(WebAuthnCredential.user_id == user_id))))


def start_session(db: Session, request: Request, response: Response, user: User, p: Principal,
                  *, method: str, mfa_used: bool) -> dict:
    """Always issue a *new* session token (anti-fixation, S-2); revoke any session the browser already had."""
    if p.session is not None:
        sessions.revoke(db, p.session.id, p.session.user_id)
    ua = request.headers.get("user-agent", "")
    seen_before = db.scalar(select(exists().where(DbSession.user_id == user.id, DbSession.ip_prefix == p.ip_prefix)))
    had_sessions = db.scalar(select(exists().where(DbSession.user_id == user.id)))
    token, sess = sessions.create_session(db, user, ip_prefix=p.ip_prefix, user_agent=ua,
                                          auth_method=method, mfa=mfa_used)
    db.execute(update(User).where(User.id == user.id).values(failed_login_count=0))
    if had_sessions and not seen_before:
        mailer.security_notice(db, user.email, "Your Bastion account was signed in to from a new network.")
    db.commit()
    s = get_settings()
    response.set_cookie(value=token, **sessions.cookie_kwargs(s.session_absolute_days * 86400))
    sec_event("auth.login_success", user_id=str(user.id), ip_prefix=p.ip_prefix, kind=method)
    return {"user": user_self(user, has_passkeys=_has_passkeys(db, user.id)), "csrf_token": sessions.csrf_token(sess)}


def _account_usable(user: User | None) -> bool:
    return user is not None and user.deleted_at is None and user.status in ("active", "suspended")


# --------------------------------------------------------------------------- registration


@router.get("/pow")
def pow_challenge(p: Principal = Depends(principal)):
    enforce_limit("read", f"ip:{p.rl_ip}", "anon")
    return powsvc.issue(p.ip_prefix, p.rl_ip)


@router.post("/register", status_code=202)
def register(body: RegisterIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    enforce_limit("register_ip", p.rl_ip, "anon")
    if not powsvc.verify(body.pow.challenge, body.pow.nonce, p.ip_prefix):
        raise ApiError(400, "pow_invalid", "Proof-of-work challenge failed or expired. Request a new one.")
    try:
        handle = validate_handle(body.handle)
    except TextError as exc:
        raise ApiError(400, exc.code, exc.message) from exc
    email = normalize_email(body.email)
    try:
        check_password_policy(body.password, handle=handle, email=email)
    except PasswordPolicyError as exc:
        raise ApiError(400, exc.code, exc.message) from exc
    # Hash unconditionally *before* any existence check so timing is identical either way (I-3).
    pw_hash = hash_password(body.password)
    if db.scalar(select(exists().where(User.handle_norm == handle.lower()))):
        raise ApiError(409, "handle_taken", "That handle is taken.")  # handles are public by design
    existing = db.scalar(select(User).where(User.email == email))
    if existing is not None:
        mailer.account_exists(db, email)
        db.commit()
        sec_event("auth.register_existing_email", ip_prefix=p.ip_prefix)
        return {"status": "verification_sent"}
    display = normalize(body.display_name, multiline=False)[:50]
    user = User(handle=handle, handle_norm=handle.lower(), email=email, password_hash=pw_hash, display_name=display)
    db.add(user)
    try:
        db.flush()
    except IntegrityError:
        db.rollback()  # lost a race on handle or email; the response must not reveal which
        raise ApiError(409, "handle_taken", "That handle is taken.") from None
    tok = tokens.issue(db, "email_verify", user.id, {"email": email})
    mailer.verify_email(db, email, tok)
    audit.record(db, action="account.register", actor_id=user.id, target_type="user", target_id=user.id,
                 ip_prefix=p.ip_prefix, request_id=p.request_id)
    db.commit()
    return {"status": "verification_sent"}


@router.post("/verify-email")
def verify_email(body: TokenIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    enforce_limit("verify_email", p.rl_ip, "anon")
    tok = tokens.consume(db, "email_verify", body.token)
    purpose = "email_verify"
    if tok is None:
        tok = tokens.consume(db, "email_change", body.token)
        purpose = "email_change"
    if tok is None or tok.user_id is None:
        raise ApiError(400, "token_invalid", "This link is invalid or has expired.")
    user = db.get(User, tok.user_id)
    if user is None or user.deleted_at is not None:
        raise ApiError(400, "token_invalid", "This link is invalid or has expired.")
    now = datetime.now(timezone.utc)
    if purpose == "email_verify":
        if tok.data.get("email") != user.email:
            raise ApiError(400, "token_invalid", "This link is invalid or has expired.")
        user.email_verified_at = user.email_verified_at or now
    else:
        new_email = tok.data.get("new_email", "")
        if db.scalar(select(exists().where(User.email == new_email, User.id != user.id))):
            raise ApiError(409, "email_unavailable", "That email address can no longer be used.")
        old = user.email
        user.email = new_email
        user.email_verified_at = now
        mailer.security_notice(db, old, "The email address on your Bastion account was changed.")
        audit.record(db, action="account.email_changed", actor_id=user.id, target_type="user", target_id=user.id,
                     ip_prefix=p.ip_prefix, request_id=p.request_id)
    user.updated_at = now
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise ApiError(409, "email_unavailable", "That email address can no longer be used.") from None
    return {"status": "verified"}


@router.post("/resend-verification", status_code=202)
def resend_verification(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require_session(p)
    enforce_limit("resend_verify", str(p.user_id), "anon")
    user = db.get(User, p.user_id)
    if user and user.email_verified_at is None:
        tok = tokens.issue(db, "email_verify", user.id, {"email": user.email})
        mailer.verify_email(db, user.email, tok)
        db.commit()
    return {"status": "verification_sent"}


# --------------------------------------------------------------------------- login


@router.post("/login")
def login(body: LoginIn, request: Request, response: Response, p: Principal = Depends(principal),
          db: Session = Depends(get_db)):
    ident = body.identifier.strip().lower()
    ident_key = sha256(ident).hex()[:32]
    # Rate limits run BEFORE the expensive hash, and are keyed on the *submitted* identifier so
    # they behave identically for existing and non-existing accounts (no lockout oracle).
    enforce_limit("login_ip", p.rl_ip, "anon")
    enforce_limit("login_account_ip", f"{ident_key}:{p.rl_ip}", "anon")
    enforce_limit("login_account", ident_key, "anon")

    col = User.email if "@" in ident else User.handle_norm
    user = db.scalar(select(User).where(col == ident))
    ok = verify_password(user.password_hash if _account_usable(user) else None, body.password)
    if not ok or user is None:
        if user is not None:
            db.execute(update(User).where(User.id == user.id).values(failed_login_count=User.failed_login_count + 1))
            db.commit()
        sec_event("auth.login_failed", ip_prefix=p.ip_prefix, user_id=str(user.id) if user else None)
        raise INVALID_CREDENTIALS
    if user.status == "suspended":
        # Only revealed after a correct password, so this is not an enumeration oracle.
        raise ApiError(403, "account_suspended", "This account is suspended.")
    if needs_rehash(user.password_hash):
        user.password_hash = hash_password(body.password)
    if user.mfa_enabled_at is not None:
        mfa_token = tokens.issue(db, "mfa_pending", user.id, {"ip": p.ip_prefix})
        db.commit()
        methods = ["totp", "recovery_code"] + (["passkey"] if _has_passkeys(db, user.id) else [])
        return {"mfa_required": True, "mfa_token": mfa_token, "methods": methods}
    return start_session(db, request, response, user, p, method="password", mfa_used=False)


@router.post("/login/mfa")
def login_mfa(body: MfaIn, request: Request, response: Response, p: Principal = Depends(principal),
              db: Session = Depends(get_db)):
    enforce_limit("mfa_verify", p.rl_ip, "anon")
    pending = tokens.peek(db, "mfa_pending", body.mfa_token)
    if pending is None or pending.user_id is None:
        raise ApiError(401, "mfa_token_invalid", "Sign-in expired. Start again.")
    enforce_limit("mfa_verify", f"user:{pending.user_id}", "anon")
    user = db.get(User, pending.user_id)
    if user is None or user.status != "active" or user.deleted_at is not None:
        raise ApiError(401, "mfa_token_invalid", "Sign-in expired. Start again.")
    method = mfa.verify_second_factor(db, user, body.code)
    if method is None:
        attempts = tokens.register_failure(db, pending.id, "mfa_pending")
        if attempts == 3:
            # Correct password + repeated MFA failures is a strong compromise signal: tell the owner.
            mailer.security_notice(db, user.email, "Someone entered your password correctly but failed "
                                                   "two-factor verification. Change your password now.")
        db.commit()
        sec_event("auth.mfa_failed", user_id=str(user.id), ip_prefix=p.ip_prefix, count=attempts)
        raise ApiError(401, "mfa_invalid", "Incorrect code.")
    if tokens.consume(db, "mfa_pending", body.mfa_token) is None:  # raced with another request
        db.rollback()
        raise ApiError(401, "mfa_token_invalid", "Sign-in expired. Start again.")
    if method == "recovery":
        audit.record(db, action="account.recovery_code_used", actor_id=user.id, target_type="user",
                     target_id=user.id, ip_prefix=p.ip_prefix, request_id=p.request_id)
        mailer.security_notice(db, user.email, "A recovery code was used to sign in to your Bastion account.")
    return start_session(db, request, response, user, p, method="password", mfa_used=True)


@router.post("/passkey/login/begin")
def passkey_login_begin(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    enforce_limit("passkey_login", p.rl_ip, "anon")
    out = passkeys.begin_authentication(db, None)
    db.commit()
    return out


@router.post("/passkey/login/finish")
def passkey_login_finish(body: PasskeyFinishIn, request: Request, response: Response,
                         p: Principal = Depends(principal), db: Session = Depends(get_db)):
    enforce_limit("passkey_login", p.rl_ip, "anon")
    user = passkeys.finish_authentication(db, body.challenge_id, body.credential, None)
    if user.status != "active" or user.deleted_at is not None:
        db.commit()  # burn the challenge
        raise ApiError(403, "account_unavailable", "This account cannot sign in.")
    return start_session(db, request, response, user, p, method="passkey", mfa_used=True)


@router.post("/logout", status_code=204)
def logout(response: Response, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    if p.session is not None:
        sessions.revoke(db, p.session.id, p.session.user_id)
        db.commit()
        sec_event("auth.logout", user_id=str(p.user_id))
    response.delete_cookie(sessions.COOKIE_NAME, path="/", secure=True, httponly=True, samesite="lax")
    response.status_code = 204
    return response


@router.get("/session")
def current_session(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    if p.session is None or p.user is None:
        return {"authenticated": False}
    return {
        "authenticated": True,
        "user": user_self(p.user, has_passkeys=_has_passkeys(db, p.user.id),
                          recovery_codes_left=mfa.remaining_recovery_codes(db, p.user.id) if p.user.mfa_enabled_at else None),
        "csrf_token": sessions.csrf_token(p.session),
        "trust_tier": trust_tier(p.user),
    }


# --------------------------------------------------------------------------- password reset


@router.post("/password-reset/request", status_code=202)
def reset_request(body: ResetRequestIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    enforce_limit("reset_ip", p.rl_ip, "anon")
    try:
        email = normalize_email(body.email)
    except ApiError:
        return {"status": "reset_sent"}
    user = db.scalar(select(User).where(User.email == email, User.deleted_at.is_(None), User.status == "active"))
    if user is not None:
        try:
            enforce_limit("reset_account", str(user.id), "anon")
            tok = tokens.issue(db, "password_reset", user.id)
            mailer.password_reset(db, user.email, tok)
            db.commit()
            sec_event("auth.reset_requested", user_id=str(user.id), ip_prefix=p.ip_prefix)
        except ApiError:
            pass  # per-account limit hit: stay silent so the response is identical (I-3)
    return {"status": "reset_sent"}


@router.post("/password-reset/confirm")
def reset_confirm(body: ResetConfirmIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    enforce_limit("reset_ip", p.rl_ip, "anon")
    peeked = tokens.peek(db, "password_reset", body.token)
    if peeked is None or peeked.user_id is None:
        raise ApiError(400, "token_invalid", "This link is invalid or has expired.")
    user = db.get(User, peeked.user_id)
    if user is None or user.deleted_at is not None:
        raise ApiError(400, "token_invalid", "This link is invalid or has expired.")
    # Validate the new password BEFORE burning the token, so a policy failure doesn't cost the link.
    try:
        check_password_policy(body.new_password, handle=user.handle, email=user.email)
    except PasswordPolicyError as exc:
        raise ApiError(400, exc.code, exc.message) from exc
    new_hash = hash_password(body.new_password)
    if tokens.consume(db, "password_reset", body.token) is None:
        raise ApiError(400, "token_invalid", "This link is invalid or has expired.")
    now = datetime.now(timezone.utc)
    db.execute(update(User).where(User.id == user.id).values(
        password_hash=new_hash, password_changed_at=now, failed_login_count=0, updated_at=now,
        # Clicking the emailed link also proves control of the address.
        email_verified_at=user.email_verified_at or now))
    # Revoke every session and API token. MFA is deliberately left untouched (S-4d).
    sessions.bump_epoch(db, user.id)
    audit.record(db, action="account.password_reset", actor_id=user.id, target_type="user", target_id=user.id,
                 ip_prefix=p.ip_prefix, request_id=p.request_id)
    mailer.security_notice(db, user.email, "Your Bastion password was reset and all sessions were signed out.")
    db.commit()
    return {"status": "password_reset"}


# --------------------------------------------------------------------------- re-authentication (step-up)


@router.post("/reauth")
def reauth(body: ReauthIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require_session(p)
    assert p.user is not None and p.session is not None
    enforce_limit("reauth", str(p.user_id), "anon")
    if not verify_password(p.user.password_hash, body.password):
        sec_event("auth.reauth_failed", user_id=str(p.user_id))
        raise ApiError(401, "invalid_credentials", "Incorrect password.")
    values: dict[str, Any] = {"reauth_at": datetime.now(timezone.utc)}
    if p.user.mfa_enabled_at is not None:
        if not body.code:
            raise ApiError(401, "mfa_required", "Enter your authentication code.")
        if mfa.verify_second_factor(db, p.user, body.code) is None:
            db.commit()
            sec_event("auth.reauth_mfa_failed", user_id=str(p.user_id))
            raise ApiError(401, "mfa_invalid", "Incorrect code.")
        values["mfa_at"] = values["reauth_at"]
    db.execute(update(DbSession).where(DbSession.id == p.session.id).values(**values))
    db.commit()
    return {"status": "ok", "mfa": "mfa_at" in values}


@router.post("/reauth/passkey/begin")
def reauth_passkey_begin(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require_session(p)
    enforce_limit("reauth", str(p.user_id), "anon")
    out = passkeys.begin_authentication(db, p.user)
    db.commit()
    return out


@router.post("/reauth/passkey/finish")
def reauth_passkey_finish(body: PasskeyFinishIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require_session(p)
    assert p.session is not None
    enforce_limit("reauth", str(p.user_id), "anon")
    passkeys.finish_authentication(db, body.challenge_id, body.credential, p.user)
    now = datetime.now(timezone.utc)
    db.execute(update(DbSession).where(DbSession.id == p.session.id).values(reauth_at=now, mfa_at=now))
    db.commit()
    return {"status": "ok", "mfa": True}

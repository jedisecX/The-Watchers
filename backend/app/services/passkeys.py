"""WebAuthn / passkeys (R-AUTH-7).

* Challenges are server-generated, stored against a single-use handle (``auth_tokens``), and
  expire after 5 minutes. A challenge can be consumed exactly once (anti-replay).
* ``expected_origin`` and ``expected_rp_id`` come from configuration, never from the request.
* User verification is REQUIRED, so a passkey login is inherently multi-factor
  (possession + biometric/PIN) and counts as MFA for admin step-up.
* A sign-count regression (a possible cloned authenticator) is rejected and logged. Synced
  passkeys report 0 permanently, which is allowed.
"""
from __future__ import annotations

import json
import uuid
from datetime import datetime, timezone

from sqlalchemy import select, update
from sqlalchemy.orm import Session
from webauthn import (
    generate_authentication_options,
    generate_registration_options,
    options_to_json,
    verify_authentication_response,
    verify_registration_response,
)
from webauthn.helpers import base64url_to_bytes, bytes_to_base64url
from webauthn.helpers.exceptions import InvalidAuthenticationResponse, InvalidRegistrationResponse
from webauthn.helpers.structs import (
    AuthenticatorSelectionCriteria,
    PublicKeyCredentialDescriptor,
    ResidentKeyRequirement,
    UserVerificationRequirement,
)

from ..config import get_settings
from ..errors import ApiError
from ..logging_setup import sec_event
from ..models import User, WebAuthnCredential
from . import tokens

MAX_PASSKEYS = 10


def _bad(code: str = "passkey_failed") -> ApiError:
    return ApiError(400, code, "Passkey verification failed.")


def begin_registration(db: Session, user: User) -> dict:
    s = get_settings()
    existing = db.execute(select(WebAuthnCredential.credential_id).where(WebAuthnCredential.user_id == user.id)).scalars().all()
    if len(existing) >= MAX_PASSKEYS:
        raise ApiError(400, "too_many_passkeys", f"At most {MAX_PASSKEYS} passkeys per account.")
    opts = generate_registration_options(
        rp_id=s.webauthn_rp_id,
        rp_name=s.webauthn_rp_name,
        user_id=user.id.bytes,
        user_name=user.handle,
        user_display_name=user.display_name or user.handle,
        authenticator_selection=AuthenticatorSelectionCriteria(
            resident_key=ResidentKeyRequirement.REQUIRED,
            user_verification=UserVerificationRequirement.REQUIRED,
        ),
        exclude_credentials=[PublicKeyCredentialDescriptor(id=c) for c in existing],
        timeout=300_000,
    )
    handle = tokens.issue(db, "webauthn_register", user.id, {"challenge": bytes_to_base64url(opts.challenge)})
    return {"challenge_id": handle, "options": json.loads(options_to_json(opts))}


def finish_registration(db: Session, user: User, challenge_id: str, credential: dict, name: str) -> WebAuthnCredential:
    s = get_settings()
    tok = tokens.consume(db, "webauthn_register", challenge_id)
    if tok is None or tok.user_id != user.id:
        raise _bad("challenge_invalid")
    try:
        v = verify_registration_response(
            credential=credential,
            expected_challenge=base64url_to_bytes(tok.data["challenge"]),
            expected_rp_id=s.webauthn_rp_id,
            expected_origin=list(s.allowed_origins),
            require_user_verification=True,
        )
    except (InvalidRegistrationResponse, ValueError, KeyError, TypeError) as exc:
        sec_event("auth.passkey_register_failed", user_id=str(user.id), reason_code=type(exc).__name__)
        raise _bad() from exc
    if db.scalar(select(WebAuthnCredential.id).where(WebAuthnCredential.credential_id == v.credential_id)):
        raise _bad("passkey_exists")
    cred = WebAuthnCredential(
        user_id=user.id,
        credential_id=v.credential_id,
        public_key=v.credential_public_key,
        sign_count=v.sign_count,
        transports=[],
        name=name,
        backup_eligible=bool(getattr(v, "credential_backed_up", False)),
    )
    db.add(cred)
    db.flush()
    return cred


def begin_authentication(db: Session, user: User | None) -> dict:
    """``user=None`` means discoverable-credential (username-less) login."""
    s = get_settings()
    allow = None
    if user is not None:
        ids = db.execute(select(WebAuthnCredential.credential_id).where(WebAuthnCredential.user_id == user.id)).scalars().all()
        if not ids:
            raise ApiError(400, "no_passkeys", "No passkeys registered.")
        allow = [PublicKeyCredentialDescriptor(id=c) for c in ids]
    opts = generate_authentication_options(
        rp_id=s.webauthn_rp_id,
        allow_credentials=allow,
        user_verification=UserVerificationRequirement.REQUIRED,
        timeout=300_000,
    )
    handle = tokens.issue(db, "webauthn_login", user.id if user else None,
                          {"challenge": bytes_to_base64url(opts.challenge)}, invalidate_previous=False)
    return {"challenge_id": handle, "options": json.loads(options_to_json(opts))}


def finish_authentication(db: Session, challenge_id: str, credential: dict, expect_user: User | None) -> User:
    s = get_settings()
    tok = tokens.consume(db, "webauthn_login", challenge_id)
    if tok is None:
        raise _bad("challenge_invalid")
    if expect_user is not None and tok.user_id != expect_user.id:
        raise _bad("challenge_invalid")
    raw_id = credential.get("rawId") or credential.get("id") if isinstance(credential, dict) else None
    try:
        cred_id = base64url_to_bytes(raw_id) if isinstance(raw_id, str) else None
    except ValueError:
        cred_id = None
    if not cred_id or len(cred_id) > 1023:
        raise _bad()
    cred = db.scalar(select(WebAuthnCredential).where(WebAuthnCredential.credential_id == cred_id))
    if cred is None or (expect_user is not None and cred.user_id != expect_user.id):
        raise _bad()
    try:
        v = verify_authentication_response(
            credential=credential,
            expected_challenge=base64url_to_bytes(tok.data["challenge"]),
            expected_rp_id=s.webauthn_rp_id,
            expected_origin=list(s.allowed_origins),
            credential_public_key=cred.public_key,
            credential_current_sign_count=cred.sign_count,
            require_user_verification=True,
        )
    except (InvalidAuthenticationResponse, ValueError, KeyError, TypeError) as exc:
        if "sign count" in str(exc).lower():
            sec_event("auth.passkey_signcount_regression", user_id=str(cred.user_id))
        sec_event("auth.passkey_login_failed", reason_code=type(exc).__name__)
        raise _bad() from exc
    user = db.get(User, cred.user_id)
    if user is None:
        raise _bad()
    # Discoverable login: the userHandle, if supplied, must match the credential owner.
    uh = credential.get("response", {}).get("userHandle")
    if uh:
        try:
            if uuid.UUID(bytes=base64url_to_bytes(uh)) != user.id:
                raise _bad()
        except ValueError as exc:
            raise _bad() from exc
    db.execute(update(WebAuthnCredential).where(WebAuthnCredential.id == cred.id).values(
        sign_count=v.new_sign_count, last_used_at=datetime.now(timezone.utc)))
    return user

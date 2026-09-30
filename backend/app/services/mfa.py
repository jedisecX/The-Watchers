"""TOTP (RFC 6238) and recovery codes.

* TOTP seeds are AES-256-GCM encrypted with the user id as associated data.
* Replay prevention: the last accepted time-step is stored, and a conditional UPDATE only
  accepts strictly newer steps, so a code cannot be used twice even concurrently (S-4c).
* ±1 step clock skew.
* Recovery codes: 10 × 80-bit random codes, stored as SHA-256, single-use via conditional UPDATE.
"""
from __future__ import annotations

import base64
import hmac
import secrets
import time
import uuid
from datetime import datetime, timezone

import pyotp
from sqlalchemy import delete, select, update
from sqlalchemy.orm import Session

from ..models import RecoveryCode, User
from ..security.crypto import decrypt_field, encrypt_field, sha256

STEP = 30
ISSUER = "Bastion"


def new_secret() -> str:
    return pyotp.random_base32(32)  # 160 bits


def enc(user_id: uuid.UUID, secret: str) -> bytes:
    return encrypt_field(secret.encode(), aad=b"totp:" + user_id.bytes)


def dec(user_id: uuid.UUID, blob: bytes) -> str:
    return decrypt_field(blob, aad=b"totp:" + user_id.bytes).decode()


def provisioning_uri(secret: str, handle: str) -> str:
    return pyotp.TOTP(secret).provisioning_uri(name=handle, issuer_name=ISSUER)


def _valid_code_format(code: str) -> bool:
    return isinstance(code, str) and len(code) == 6 and code.isdigit()


def matching_step(secret: str, code: str, at: float | None = None) -> int | None:
    if not _valid_code_format(code):
        return None
    totp = pyotp.TOTP(secret)
    now_step = int((at or time.time()) // STEP)
    for step in (now_step - 1, now_step, now_step + 1):
        if hmac.compare_digest(totp.generate_otp(step), code):
            return step
    return None


def verify_totp(db: Session, user: User, code: str) -> bool:
    """Verify against the *enabled* secret and atomically burn the step."""
    if user.totp_secret_enc is None:
        return False
    step = matching_step(dec(user.id, user.totp_secret_enc), code)
    if step is None:
        return False
    res = db.execute(
        update(User).where(User.id == user.id, User.totp_last_step < step).values(totp_last_step=step)
    )
    return res.rowcount == 1


# --------------------------------------------------------------------------- recovery codes


def _format(raw: bytes) -> str:
    s = base64.b32encode(raw).decode().rstrip("=").lower()  # 10 bytes -> 16 chars
    return f"{s[:4]}-{s[4:8]}-{s[8:12]}-{s[12:16]}"


def _canon(code: str) -> str:
    return code.strip().lower().replace("-", "").replace(" ", "")


def generate_recovery_codes(db: Session, user_id: uuid.UUID, n: int = 10) -> list[str]:
    db.execute(delete(RecoveryCode).where(RecoveryCode.user_id == user_id))
    codes = [_format(secrets.token_bytes(10)) for _ in range(n)]
    for c in codes:
        db.add(RecoveryCode(user_id=user_id, code_hash=sha256(_canon(c))))
    db.flush()
    return codes


def use_recovery_code(db: Session, user_id: uuid.UUID, code: str) -> bool:
    if not isinstance(code, str) or len(code) > 40:
        return False
    res = db.execute(
        update(RecoveryCode)
        .where(RecoveryCode.user_id == user_id, RecoveryCode.code_hash == sha256(_canon(code)),
               RecoveryCode.used_at.is_(None))
        .values(used_at=datetime.now(timezone.utc))
    )
    return res.rowcount == 1


def remaining_recovery_codes(db: Session, user_id: uuid.UUID) -> int:
    return len(db.execute(select(RecoveryCode.id).where(
        RecoveryCode.user_id == user_id, RecoveryCode.used_at.is_(None))).all())


def verify_second_factor(db: Session, user: User, code: str) -> str | None:
    """Returns 'totp' or 'recovery' on success, else None."""
    if not isinstance(code, str):
        return None
    if _valid_code_format(code):
        return "totp" if verify_totp(db, user, code) else None
    return "recovery" if use_recovery_code(db, user.id, code) else None

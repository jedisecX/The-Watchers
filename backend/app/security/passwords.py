"""Password hashing and policy.

* argon2id (RFC 9106), parameters from configuration, with transparent rehash on login when
  parameters are raised.
* A bounded semaphore caps concurrent hashes per process so a login flood cannot exhaust memory
  (64 MiB x N). Rate limits run *before* hashing.
* Policy follows NIST SP 800-63B: length 12-256, no composition rules, reject common passwords
  and passwords containing the user's handle or email local part.
"""
from __future__ import annotations

import threading
import unicodedata
from functools import lru_cache
from pathlib import Path

from argon2 import PasswordHasher
from argon2.exceptions import InvalidHashError, VerificationError, VerifyMismatchError

from ..config import get_settings

MIN_LEN = 12
MAX_LEN = 256


@lru_cache(maxsize=1)
def _hasher() -> PasswordHasher:
    s = get_settings()
    return PasswordHasher(
        time_cost=s.argon2_time_cost,
        memory_cost=s.argon2_memory_kib,
        parallelism=s.argon2_parallelism,
        hash_len=32,
        salt_len=16,
    )


@lru_cache(maxsize=1)
def _sem() -> threading.BoundedSemaphore:
    return threading.BoundedSemaphore(get_settings().argon2_max_concurrency)


@lru_cache(maxsize=1)
def _dummy_hash() -> str:
    return _hasher().hash("dummy-password-for-timing-equalisation")


def _normalise(pw: str) -> str:
    # NFKC so visually identical inputs hash identically across devices/keyboards.
    return unicodedata.normalize("NFKC", pw)


def hash_password(pw: str) -> str:
    with _sem():
        return _hasher().hash(_normalise(pw))


def verify_password(stored_hash: str | None, pw: str) -> bool:
    """Constant-work verification. With ``stored_hash=None`` a dummy hash is verified so that
    'no such user' takes the same time as 'wrong password' (anti-enumeration)."""
    with _sem():
        try:
            if stored_hash is None:
                _hasher().verify(_dummy_hash(), _normalise(pw))
                return False
            return _hasher().verify(stored_hash, _normalise(pw))
        except (VerifyMismatchError, VerificationError, InvalidHashError):
            return False


def needs_rehash(stored_hash: str) -> bool:
    try:
        return _hasher().check_needs_rehash(stored_hash)
    except InvalidHashError:
        return True


@lru_cache(maxsize=1)
def _common_passwords() -> frozenset[str]:
    p = Path(__file__).with_name("common_passwords.txt")
    try:
        return frozenset(line.strip().lower() for line in p.read_text().splitlines() if line.strip())
    except OSError:  # pragma: no cover
        return frozenset()


class PasswordPolicyError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code
        self.message = message


def check_password_policy(pw: str, *, handle: str = "", email: str = "") -> None:
    n = _normalise(pw)
    if len(n) < MIN_LEN:
        raise PasswordPolicyError("password_too_short", f"Password must be at least {MIN_LEN} characters.")
    if len(n) > MAX_LEN:
        raise PasswordPolicyError("password_too_long", f"Password must be at most {MAX_LEN} characters.")
    low = n.lower()
    if low in _common_passwords() or len(set(low)) < 4:
        raise PasswordPolicyError("password_too_common", "This password is too common. Choose another.")
    local = email.split("@", 1)[0].lower() if email else ""
    for ctx in (handle.lower(), local):
        if ctx and len(ctx) >= 3 and ctx in low:
            raise PasswordPolicyError("password_contains_identity", "Password must not contain your username or email.")

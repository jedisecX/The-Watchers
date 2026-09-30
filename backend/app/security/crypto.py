"""Cryptographic primitives. Small, boring, and centralised so they can be audited in one place."""
from __future__ import annotations

import hashlib
import hmac
import os
import secrets

from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from ..config import get_settings


def random_token(nbytes: int = 32) -> str:
    """URL-safe random token. 32 bytes = 256 bits of entropy."""
    return secrets.token_urlsafe(nbytes)


def sha256(data: str | bytes) -> bytes:
    if isinstance(data, str):
        data = data.encode()
    return hashlib.sha256(data).digest()


def token_hash(token: str) -> bytes:
    """Tokens have >=128 bits of entropy, so a fast hash is appropriate (no dictionary to attack).

    Hashing means a DB read leak does not yield usable tokens.
    """
    return sha256(token)


def hmac_sha256(key: bytes, msg: str | bytes) -> bytes:
    if isinstance(msg, str):
        msg = msg.encode()
    return hmac.new(key, msg, hashlib.sha256).digest()


def derive_key(purpose: str) -> bytes:
    """Domain-separated sub-key from SECRET_KEY, so one secret never serves two purposes."""
    return hmac_sha256(get_settings().secret_key, f"bastion-kdf:{purpose}")


def constant_time_eq(a: bytes | str, b: bytes | str) -> bool:
    if isinstance(a, str):
        a = a.encode()
    if isinstance(b, str):
        b = b.encode()
    return hmac.compare_digest(a, b)


# --------------------------------------------------------------------- field encryption
_VERSION = b"\x01"


def encrypt_field(plaintext: bytes, aad: bytes) -> bytes:
    """AES-256-GCM with a random 96-bit nonce. Format: ver(1) | key_index(1) | nonce(12) | ct+tag.

    ``aad`` binds the ciphertext to its row (e.g. the user id) so ciphertexts cannot be swapped
    between rows by someone with DB write access.
    """
    key = get_settings().field_encryption_keys[0]
    nonce = os.urandom(12)
    ct = AESGCM(key).encrypt(nonce, plaintext, aad)
    return _VERSION + b"\x00" + nonce + ct


def decrypt_field(blob: bytes, aad: bytes) -> bytes:
    if len(blob) < 2 + 12 + 16 or blob[:1] != _VERSION:
        raise ValueError("bad ciphertext")
    keys = get_settings().field_encryption_keys
    nonce, ct = blob[2:14], blob[14:]
    # Try every key in the ring (primary first) to support rotation.
    for key in keys:
        try:
            return AESGCM(key).decrypt(nonce, ct, aad)
        except Exception:  # noqa: BLE001 - InvalidTag; try next key
            continue
    raise ValueError("cannot decrypt field")


def reencrypt_field(blob: bytes, aad: bytes) -> bytes:
    return encrypt_field(decrypt_field(blob, aad), aad)

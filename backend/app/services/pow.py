"""Proof-of-work for registration (THREAT-MODEL AB-1): a privacy-friendly alternative to CAPTCHA.

A stateless, HMAC-signed challenge ``v1.<rand>.<expiry>.<difficulty>.<ip-bind>.<mac>`` requires the
client to find ``nonce`` such that ``SHA-256(challenge + ":" + nonce)`` has ``difficulty``
leading zero bits (about 2^difficulty hashes; ~0.3 s at 18 bits in a browser Web Worker).

* Difficulty adapts: each registration attempt from the same IP within the hour adds a bit.
* Challenges are bound to the requesting IP prefix, expire after 10 minutes, and are single-use.

PoW is a *cost* control, not an identity check; it is one layer beside rate limits and
mandatory email verification.
"""
from __future__ import annotations

import base64
import hashlib
import time

from .. import ratelimit
from ..config import get_settings
from ..security.crypto import constant_time_eq, derive_key, hmac_sha256, random_token, sha256

TTL = 600
MAX_DIFFICULTY = 26


def _mac(payload: str) -> str:
    return base64.urlsafe_b64encode(hmac_sha256(derive_key("pow"), payload)[:16]).rstrip(b"=").decode()


def _ip_bind(ip_prefix: str) -> str:
    return base64.urlsafe_b64encode(sha256(ip_prefix)[:6]).rstrip(b"=").decode()


def difficulty_for(rl_ip: str) -> int:
    base = get_settings().pow_base_difficulty
    try:
        d = ratelimit.get_limiter().hit(f"pow_issue:{rl_ip}", 1_000_000, 3600)
        extra = max(0, 1_000_000 - d.remaining - 1 - 3)  # first 3 challenges/hour at base difficulty
    except ratelimit.LimiterUnavailable:
        extra = 4
    return min(MAX_DIFFICULTY, base + min(extra, 8))


def issue(ip_prefix: str, rl_ip: str) -> dict:
    diff = difficulty_for(rl_ip)
    exp = int(time.time()) + TTL
    payload = f"v1.{random_token(12)}.{exp}.{diff}.{_ip_bind(ip_prefix)}"
    return {"challenge": f"{payload}.{_mac(payload)}", "difficulty": diff, "expires_in": TTL}


def leading_zero_bits(digest: bytes) -> int:
    n = 0
    for b in digest:
        if b == 0:
            n += 8
            continue
        return n + (8 - b.bit_length())
    return n


def verify(challenge: str, nonce: str, ip_prefix: str) -> bool:
    if not isinstance(challenge, str) or not isinstance(nonce, str) or len(challenge) > 200 or len(nonce) > 64:
        return False
    try:
        v, rnd, exp, diff, bind, mac = challenge.split(".")
        exp_i, diff_i = int(exp), int(diff)
    except ValueError:
        return False
    payload = f"{v}.{rnd}.{exp}.{diff}.{bind}"
    if v != "v1" or not constant_time_eq(_mac(payload), mac):
        return False
    if exp_i < time.time() or not constant_time_eq(bind, _ip_bind(ip_prefix)):
        return False
    digest = hashlib.sha256(f"{challenge}:{nonce}".encode()).digest()
    if leading_zero_bits(digest) < diff_i:
        return False
    try:
        return ratelimit.get_limiter().once(f"pow:{rnd}", TTL)
    except ratelimit.LimiterUnavailable:
        return False


def solve(challenge: str, difficulty: int) -> str:
    """Reference solver (tests and CLI clients)."""
    i = 0
    while True:
        nonce = str(i)
        if leading_zero_bits(hashlib.sha256(f"{challenge}:{nonce}".encode()).digest()) >= difficulty:
            return nonce
        i += 1

"""Adaptive rate limiting.

Algorithm: sliding-window counter (two fixed windows, weighted). O(1) memory per key, accurate
to within a few percent, atomic in Redis via a Lua script.

Adaptivity: limits are looked up by *trust tier* (anon/new/basic/established) so that a
brand-new unverified account gets far less throughput than an established one, instead of
making everything globally restrictive (THREAT-MODEL AB-2/AB-3).

Failure policy (THREAT-MODEL §9): if Redis is unavailable, buckets marked ``fail_closed``
(auth/credential endpoints) reject with 503; all others fall back to a per-process limiter.
"""
from __future__ import annotations

import logging
import math
import threading
import time
from dataclasses import dataclass
from functools import lru_cache
from typing import Protocol

from .config import get_settings

log = logging.getLogger("bastion.ratelimit")


class LimiterUnavailable(RuntimeError):
    pass


@dataclass(frozen=True)
class Decision:
    allowed: bool
    remaining: int
    retry_after: int


class Limiter(Protocol):
    def hit(self, key: str, limit: int, window: int, cost: int = 1) -> Decision: ...
    def once(self, key: str, ttl: int) -> bool: ...
    def reset(self) -> None: ...


def _weighted(prev: int, cur: int, window: int, now: float) -> float:
    elapsed = now % window
    return prev * ((window - elapsed) / window) + cur


class MemoryLimiter:
    """Per-process limiter. Used in tests/dev and as the fail-open fallback."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._counts: dict[str, tuple[int, int]] = {}  # key:window_index -> (count, expiry)
        self._once: dict[str, float] = {}

    def _gc(self, now: float) -> None:
        if len(self._counts) > 100_000:
            self._counts = {k: v for k, v in self._counts.items() if v[1] > now}
        if len(self._once) > 100_000:
            self._once = {k: v for k, v in self._once.items() if v > now}

    def hit(self, key: str, limit: int, window: int, cost: int = 1) -> Decision:
        now = time.time()
        idx = int(now // window)
        with self._lock:
            self._gc(now)
            cur_k, prev_k = f"{key}:{idx}", f"{key}:{idx - 1}"
            cur = self._counts.get(cur_k, (0, 0))[0]
            prev = self._counts.get(prev_k, (0, 0))[0]
            est = _weighted(prev, cur, window, now)
            if est + cost > limit:
                retry = max(1, math.ceil(window - (now % window)))
                return Decision(False, 0, retry)
            self._counts[cur_k] = (cur + cost, int(now) + 2 * window)
            return Decision(True, max(0, int(limit - est - cost)), 0)

    def once(self, key: str, ttl: int) -> bool:
        now = time.time()
        with self._lock:
            exp = self._once.get(key)
            if exp and exp > now:
                return False
            self._once[key] = now + ttl
            return True

    def reset(self) -> None:
        with self._lock:
            self._counts.clear()
            self._once.clear()


_LUA = """
local cur = tonumber(redis.call('GET', KEYS[1]) or '0')
local prev = tonumber(redis.call('GET', KEYS[2]) or '0')
local window = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])
local cost = tonumber(ARGV[3])
local elapsed = tonumber(ARGV[4])
local est = prev * ((window - elapsed) / window) + cur
if est + cost > limit then
  return {0, 0}
end
redis.call('INCRBY', KEYS[1], cost)
redis.call('EXPIRE', KEYS[1], window * 2)
return {1, math.floor(limit - est - cost)}
"""


class RedisLimiter:
    def __init__(self, url: str) -> None:
        import redis

        self._r = redis.Redis.from_url(url, socket_timeout=0.5, socket_connect_timeout=0.5)
        self._script = self._r.register_script(_LUA)

    def hit(self, key: str, limit: int, window: int, cost: int = 1) -> Decision:
        import redis

        now = time.time()
        idx = int(now // window)
        try:
            allowed, remaining = self._script(
                keys=[f"rl:{key}:{idx}", f"rl:{key}:{idx - 1}"],
                args=[window, limit, cost, now % window],
            )
        except redis.RedisError as exc:
            raise LimiterUnavailable() from exc
        if not allowed:
            return Decision(False, 0, max(1, math.ceil(window - (now % window))))
        return Decision(True, int(remaining), 0)

    def once(self, key: str, ttl: int) -> bool:
        import redis

        try:
            return bool(self._r.set(f"once:{key}", b"1", nx=True, ex=ttl))
        except redis.RedisError as exc:
            raise LimiterUnavailable() from exc

    def reset(self) -> None:  # tests only
        self._r.flushdb()


@lru_cache(maxsize=1)
def get_limiter() -> Limiter:
    url = get_settings().redis_url
    return RedisLimiter(url) if url else MemoryLimiter()


@lru_cache(maxsize=1)
def fallback_limiter() -> MemoryLimiter:
    return MemoryLimiter()


# ------------------------------------------------------------------------- policy table
# bucket -> tier -> (limit, window_seconds). "*" applies to all tiers not listed.
TIERS = ("anon", "new", "basic", "established")

BUCKETS: dict[str, dict[str, tuple[int, int]]] = {
    # credential endpoints (fail closed)
    "login_ip": {"*": (30, 300)},
    "login_account_ip": {"*": (10, 900)},   # identifier+IP: stops single-source guessing
    "login_account": {"*": (50, 3600)},     # identifier across all IPs: slows distributed stuffing
    "mfa_verify": {"*": (10, 300)},
    "register_ip": {"*": (5, 3600)},
    "reset_ip": {"*": (5, 3600)},
    "reset_account": {"*": (3, 3600)},
    "verify_email": {"*": (20, 3600)},
    "resend_verify": {"*": (3, 3600)},
    "reauth": {"*": (10, 900)},
    "passkey_login": {"*": (30, 300)},
    # writes
    "post_create": {"new": (10, 3600), "basic": (60, 3600), "established": (200, 3600)},
    "like": {"new": (100, 3600), "basic": (500, 3600), "established": (1000, 3600)},
    "repost": {"new": (30, 3600), "basic": (100, 3600), "established": (300, 3600)},
    "follow": {"new": (50, 86400), "basic": (200, 86400), "established": (400, 86400)},
    "block_mute": {"*": (200, 3600)},
    "dm_new_conversation": {"new": (10, 86400), "basic": (50, 86400), "established": (100, 86400)},
    "dm_send": {"new": (60, 3600), "basic": (300, 3600), "established": (600, 3600)},
    "upload": {"new": (10, 3600), "basic": (50, 3600), "established": (200, 3600)},
    "report": {"*": (20, 3600)},
    "settings": {"*": (60, 3600)},
    "export": {"*": (2, 86400)},
    "token_create": {"*": (10, 3600)},
    "ws_ticket": {"*": (30, 60)},
    "ws_connect_ip": {"*": (60, 60)},
    # reads
    "read": {"anon": (120, 60), "new": (300, 60), "basic": (600, 60), "established": (1200, 60)},
    "search": {"anon": (0, 60), "new": (20, 60), "basic": (60, 60), "established": (120, 60)},
    "csp_report": {"*": (20, 60)},
    # admin
    "admin": {"*": (300, 60)},
}

FAIL_CLOSED = {"login_ip", "login_account", "login_account_ip", "mfa_verify", "register_ip", "reset_ip", "reset_account",
               "verify_email", "resend_verify", "reauth", "passkey_login"}


def limits_for(bucket: str, tier: str) -> tuple[int, int]:
    table = BUCKETS[bucket]
    return table.get(tier) or table.get("*") or table["new"]


def check(bucket: str, key: str, tier: str = "anon", cost: int = 1) -> Decision:
    limit, window = limits_for(bucket, tier)
    full_key = f"{bucket}:{key}"
    try:
        return get_limiter().hit(full_key, limit, window, cost)
    except LimiterUnavailable:
        if bucket in FAIL_CLOSED:
            log.error("ratelimit.unavailable_fail_closed", extra={"event": "ratelimit.unavailable", "bucket": bucket})
            raise
        log.warning("ratelimit.unavailable_fallback", extra={"event": "ratelimit.unavailable", "bucket": bucket})
        return fallback_limiter().hit(full_key, limit, window, cost)

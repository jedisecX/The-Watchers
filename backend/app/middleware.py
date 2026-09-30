"""Pure-ASGI middleware. Order (outermost first) is set in ``main.create_app``:

RequestContext → TrustedHost → SecurityHeaders → BodyLimit → QueryPollution → router
"""
from __future__ import annotations

import ipaddress
import json
import logging
import secrets
import time
from urllib.parse import parse_qsl

from .config import get_settings

log = logging.getLogger("bastion.http")

# ----------------------------------------------------------------------------- helpers


def ip_prefix(ip: str) -> str:
    """Truncated IP for storage (privacy): IPv4 /24, IPv6 /48."""
    try:
        addr = ipaddress.ip_address(ip)
    except ValueError:
        return "unknown"
    net = ipaddress.ip_network(f"{ip}/{24 if addr.version == 4 else 48}", strict=False)
    return str(net)


def rl_ip_key(ip: str) -> str:
    """Rate-limit key: full IPv4, IPv6 /64 (a single host usually controls a whole /64)."""
    try:
        addr = ipaddress.ip_address(ip)
    except ValueError:
        return "unknown"
    if addr.version == 6:
        return str(ipaddress.ip_network(f"{ip}/64", strict=False))
    return ip


def resolve_client_ip(peer: str | None, xff: str | None) -> str:
    """Honour X-Forwarded-For only when the direct peer is a configured trusted proxy, then take the
    right-most address that is *not* itself a trusted proxy (left-most entries are attacker-controlled)."""
    s = get_settings()
    peer = peer or "unknown"

    def trusted(ip: str) -> bool:
        try:
            a = ipaddress.ip_address(ip)
        except ValueError:
            return False
        return any(a in n for n in s.trusted_proxies)

    if not xff or not trusted(peer):
        return peer
    hops = [h.strip() for h in xff.split(",") if h.strip()][:20]
    for hop in reversed(hops):
        if not trusted(hop):
            try:
                ipaddress.ip_address(hop)
                return hop
            except ValueError:
                return peer
    return peer


def _header(scope, name: bytes) -> str | None:
    for k, v in scope.get("headers", []):
        if k == name:
            return v.decode("latin-1")
    return None


async def _send_json(send, status: int, code: str, message: str, request_id: str = "", extra_headers=()):
    body = json.dumps({"error": {"code": code, "message": message, "request_id": request_id}}).encode()
    await send({
        "type": "http.response.start",
        "status": status,
        "headers": [(b"content-type", b"application/json"), (b"content-length", str(len(body)).encode()),
                    *extra_headers],
    })
    await send({"type": "http.response.body", "body": body})


# ----------------------------------------------------------------------------- middleware


class RequestContextMiddleware:
    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope["type"] not in ("http", "websocket"):
            return await self.app(scope, receive, send)
        rid = secrets.token_hex(8)
        peer = scope.get("client")[0] if scope.get("client") else None
        ip = resolve_client_ip(peer, _header(scope, b"x-forwarded-for"))
        state = scope.setdefault("state", {})
        state["request_id"] = rid
        state["client_ip"] = ip
        state["ip_prefix"] = ip_prefix(ip)
        state["rl_ip"] = rl_ip_key(ip)
        start = time.monotonic()
        status_holder = {"status": 0}

        async def _send(msg):
            if msg["type"] == "http.response.start":
                status_holder["status"] = msg["status"]
                msg.setdefault("headers", [])
                msg["headers"] = list(msg["headers"]) + [(b"x-request-id", rid.encode())]
            await send(msg)

        try:
            await self.app(scope, receive, _send)
        finally:
            if scope["type"] == "http":
                log.info("http.request", extra={
                    "event": "http.request", "method": scope.get("method"),
                    # path only: query strings may contain tokens (e.g. email links hit the SPA, not the API,
                    # but we never risk it)
                    "path": scope.get("path", "")[:200], "status": status_holder["status"],
                    "duration_ms": int((time.monotonic() - start) * 1000), "request_id": rid,
                    "ip_prefix": state["ip_prefix"],
                })


class _TooLarge(Exception):
    pass


class BodyLimitMiddleware:
    """Enforces per-route body ceilings, both via Content-Length and by counting streamed bytes
    (chunked transfer-encoding cannot sneak past)."""

    DEFAULT = 64 * 1024
    ROUTE_LIMITS = {
        "/api/v1/media": 100 * 1024 * 1024,  # raw upload; per-kind limit enforced again in handler
    }

    def __init__(self, app):
        self.app = app

    def _limit(self, path: str) -> int:
        return self.ROUTE_LIMITS.get(path.rstrip("/"), self.DEFAULT)

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            return await self.app(scope, receive, send)
        limit = self._limit(scope.get("path", ""))
        cl = _header(scope, b"content-length")
        rid = scope.get("state", {}).get("request_id", "")
        if cl is not None:
            if not cl.isdigit():
                return await _send_json(send, 400, "bad_request", "Invalid Content-Length.", rid)
            if int(cl) > limit:
                return await _send_json(send, 413, "payload_too_large", "Request body too large.", rid)
        seen = 0
        started = False

        async def _receive():
            nonlocal seen
            msg = await receive()
            if msg["type"] == "http.request":
                seen += len(msg.get("body", b""))
                if seen > limit:
                    raise _TooLarge()
            return msg

        async def _send(msg):
            nonlocal started
            if msg["type"] == "http.response.start":
                started = True
            await send(msg)

        try:
            await self.app(scope, _receive, _send)
        except _TooLarge:
            if not started:
                await _send_json(send, 413, "payload_too_large", "Request body too large.", rid)


class QueryPollutionMiddleware:
    """Rejects duplicate query keys (HTTP parameter pollution), oversized query strings, and control
    characters in the path or query."""

    MAX_QS = 2048

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope["type"] not in ("http", "websocket"):
            return await self.app(scope, receive, send)
        qs = scope.get("query_string", b"")
        rid = scope.get("state", {}).get("request_id", "")
        path = scope.get("path", "")
        # Control characters (incl. NUL) in the decoded path are never legitimate; Postgres also
        # rejects NUL in text, so letting them through would turn into 500s (finding ADV-01).
        if any(ord(ch) < 0x20 or ord(ch) == 0x7F for ch in path):
            if scope["type"] == "websocket":
                return await send({"type": "websocket.close", "code": 1008})
            return await _send_json(send, 400, "bad_request", "Invalid characters in path.", rid)
        # Strict routing for APIs: one canonical path per resource. A tolerated trailing slash or
        # duplicate slash would let /admin/api/v1/me/ slip past proxy/CDN rules written for
        # /admin/api/v1/me (path-normalisation differential, finding ADV-03).
        if (path.startswith("/api/") or path.startswith("/admin/api/")) and (path.endswith("/") or "//" in path):
            if scope["type"] == "websocket":
                return await send({"type": "websocket.close", "code": 1008})
            return await _send_json(send, 404, "not_found", "Resource not found.", rid)
        if len(qs) > self.MAX_QS:
            if scope["type"] == "websocket":
                return await send({"type": "websocket.close", "code": 1008})
            return await _send_json(send, 414, "uri_too_long", "Query string too long.", rid)
        if qs:
            try:
                pairs = parse_qsl(qs.decode("utf-8"), keep_blank_values=True, strict_parsing=False)
            except UnicodeDecodeError:
                pairs = None
            keys = [k for k, _ in pairs] if pairs is not None else None
            if keys is None or len(keys) != len(set(keys)) or any(
                    any(ord(ch) < 0x20 and ch not in "\t" for ch in k + v) for k, v in pairs):
                if scope["type"] == "websocket":
                    return await send({"type": "websocket.close", "code": 1008})
                return await _send_json(send, 400, "duplicate_parameter", "Duplicate or invalid query parameters.", rid)
        return await self.app(scope, receive, send)


def frontend_csp() -> str:
    s = get_settings()
    ws_origin = s.public_base_url.replace("https://", "wss://").replace("http://", "ws://")
    directives = [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self' blob:",
        "media-src 'self' blob:",
        "font-src 'self'",
        f"connect-src 'self' {ws_origin}",
        "manifest-src 'self'",
        "form-action 'self'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        "require-trusted-types-for 'script'",
        "trusted-types 'none'",
        "report-uri /api/v1/csp-report",
    ]
    if s.public_base_url.startswith("https://"):
        directives.append("upgrade-insecure-requests")
    return "; ".join(directives)


class SecurityHeadersMiddleware:
    """Sets security headers. Each one's purpose is documented in docs/SECURITY-HEADERS.md."""

    def __init__(self, app):
        self.app = app
        s = get_settings()
        hsts = "max-age=63072000; includeSubDomains" + ("; preload" if s.hsts_preload else "")
        self.https = s.public_base_url.startswith("https://")
        self.common = [
            (b"x-content-type-options", b"nosniff"),
            (b"referrer-policy", b"same-origin"),
            (b"permissions-policy", b"accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), "
                                    b"microphone=(), payment=(), usb=(), interest-cohort=(), browsing-topics=(), "
                                    b"publickey-credentials-get=(self), publickey-credentials-create=(self)"),
            (b"cross-origin-opener-policy", b"same-origin"),
            (b"cross-origin-resource-policy", b"same-origin"),
            (b"x-frame-options", b"DENY"),
            (b"x-permitted-cross-domain-policies", b"none"),
        ]
        if self.https:
            self.common.append((b"strict-transport-security", hsts.encode()))
        self.api_csp = b"default-src 'none'; frame-ancestors 'none'; sandbox"
        self.front_csp = frontend_csp().encode()

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            return await self.app(scope, receive, send)
        path = scope.get("path", "")
        is_api = path.startswith("/api/") or path.startswith("/admin/api/")

        async def _send(msg):
            if msg["type"] == "http.response.start":
                headers = [(k, v) for k, v in msg.get("headers", []) if k.lower() not in {b"server"}]
                names = {k.lower() for k, _ in headers}
                # A route may set a stricter value (e.g. media CSP); never duplicate or weaken it.
                headers.extend((k, v) for k, v in self.common if k not in names)
                if b"content-security-policy" not in names:
                    headers.append((b"content-security-policy", self.api_csp if is_api else self.front_csp))
                if is_api and b"cache-control" not in names:
                    headers.append((b"cache-control", b"no-store"))
                msg["headers"] = headers
            await send(msg)

        await self.app(scope, receive, _send)

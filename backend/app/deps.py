"""Request dependencies: strict body handling, principal resolution, CSRF, rate limiting."""
from __future__ import annotations

from collections.abc import Callable
from datetime import datetime, timezone

from fastapi import Depends, Request
from fastapi.routing import APIRoute
from sqlalchemy import select, update
from sqlalchemy.orm import Session

from . import ratelimit, strictjson
from .authz import Principal, trust_tier
from .config import get_settings
from .db import get_db
from .errors import ApiError
from .logging_setup import sec_event
from .models import ApiToken, User
from .security.crypto import token_hash
from .services import sessions

UNSAFE = {"POST", "PUT", "PATCH", "DELETE"}
PAT_PREFIX = "bst_"


def origin_allowed(origin: str | None) -> bool:
    return origin is not None and origin in get_settings().allowed_origins


class StrictRoute(APIRoute):
    """Runs boundary checks before FastAPI touches the body:

    * Cross-site requests are rejected outright (Origin not allowed / Sec-Fetch-Site: cross-site),
      which defeats login-CSRF on unauthenticated endpoints too.
    * Bodies must be ``application/json`` and pass the strict parser (no duplicate keys etc.),
      except routes explicitly marked ``x-raw-body`` (media upload).
    """

    def get_route_handler(self) -> Callable:
        original = super().get_route_handler()
        raw_body = bool(self.openapi_extra and self.openapi_extra.get("x-raw-body"))

        async def handler(request: Request):
            if request.method in UNSAFE:
                origin = request.headers.get("origin")
                if origin is not None and not origin_allowed(origin):
                    sec_event("csrf.bad_origin", path=request.url.path, ip_prefix=request.state.ip_prefix)
                    raise ApiError(403, "bad_origin", "Cross-origin request rejected.")
                if request.headers.get("sec-fetch-site") == "cross-site":
                    raise ApiError(403, "bad_origin", "Cross-origin request rejected.")
                if not raw_body:
                    body = await request.body()
                    if body:
                        ctype = request.headers.get("content-type", "").split(";")[0].strip().lower()
                        if ctype != "application/json":
                            raise ApiError(415, "unsupported_media_type", "Content-Type must be application/json.")
                        try:
                            strictjson.loads(body)
                        except strictjson.StrictJSONError as exc:
                            sec_event("api.parse_rejected", path=request.url.path, reason_code=str(exc))
                            raise ApiError(400, "invalid_json", "Request body is not valid JSON.") from exc
            return await original(request)

        return handler


# --------------------------------------------------------------------------- principal


def _load_token(db: Session, raw: str) -> tuple[ApiToken, User] | None:
    if not raw.startswith(PAT_PREFIX) or len(raw) > 128:
        return None
    row = db.execute(
        select(ApiToken, User).join(User, User.id == ApiToken.user_id).where(ApiToken.token_hash == token_hash(raw))
    ).first()
    if not row:
        return None
    tok, user = row
    now = datetime.now(timezone.utc)
    if (
        tok.revoked_at is not None
        or tok.expires_at <= now
        or tok.epoch != user.session_epoch
        or user.status != "active"
        or user.deleted_at is not None
    ):
        return None
    if tok.last_used_at is None or (now - tok.last_used_at).total_seconds() > 60:
        db.execute(update(ApiToken).where(ApiToken.id == tok.id).values(last_used_at=now))
        db.commit()
    return tok, user


def get_principal(request: Request, db: Session = Depends(get_db)) -> Principal:
    cached = getattr(request.state, "principal", None)
    if cached is not None:
        return cached
    st = request.state
    p = Principal(ip_prefix=st.ip_prefix, rl_ip=st.rl_ip, request_id=st.request_id)
    cookie = request.cookies.get(sessions.COOKIE_NAME)
    authz_header = request.headers.get("authorization")
    if cookie and authz_header:
        # Ambiguous credentials are refused rather than guessing which one "wins".
        raise ApiError(400, "ambiguous_credentials", "Send either a session cookie or a bearer token, not both.")
    if authz_header:
        scheme, _, value = authz_header.partition(" ")
        loaded = _load_token(db, value.strip()) if scheme.lower() == "bearer" else None
        if not loaded:
            sec_event("auth.bad_token", ip_prefix=st.ip_prefix)
            raise ApiError(401, "invalid_token", "Invalid or expired token.", {"WWW-Authenticate": "Bearer"})
        p.token, p.user = loaded
        p.scopes = frozenset(p.token.scopes)
    elif cookie:
        loaded = sessions.load_session(db, cookie)
        if loaded:
            p.session, p.user = loaded
    request.state.principal = p
    return p


def csrf_protect(request: Request, p: Principal = Depends(get_principal)) -> Principal:
    """Cookie-authenticated unsafe requests need the synchronizer token (R-CSRF-1)."""
    if request.method in UNSAFE and p.session is not None:
        if not sessions.check_csrf(p.session, request.headers.get("x-csrf-token")):
            sec_event("csrf.token_invalid", user_id=str(p.user_id), path=request.url.path)
            raise ApiError(403, "csrf_failed", "CSRF token missing or invalid.")
    return p


def principal(p: Principal = Depends(csrf_protect)) -> Principal:
    """The dependency handlers use: principal with CSRF already enforced, plus the baseline ``api``
    budget, so no endpoint can ever be called without *some* rate limit (finding ADV-10b)."""
    key = str(p.user_id) if p.user_id else f"ip:{p.rl_ip}"
    enforce_limit("api", key, trust_tier(p.user))
    return p


def authenticated(p: Principal = Depends(principal)) -> Principal:
    if not p.is_authenticated:
        raise ApiError(401, "unauthenticated", "Authentication required.")
    return p


# --------------------------------------------------------------------------- rate limiting


def enforce_limit(bucket: str, key: str, tier: str, cost: int = 1) -> None:
    try:
        d = ratelimit.check(bucket, key, tier, cost)
    except ratelimit.LimiterUnavailable as exc:
        raise ApiError(503, "temporarily_unavailable", "Service temporarily unavailable.") from exc
    if not d.allowed:
        sec_event("ratelimit.exceeded", bucket=bucket)
        raise ApiError(429, "rate_limited", "Too many requests. Try again later.", {"Retry-After": str(d.retry_after)})


def rate_limit(bucket: str, by: str = "user") -> Callable:
    """Dependency factory. ``by='user'`` keys by account when authenticated (else IP); ``'ip'`` always by IP."""

    def dep(p: Principal = Depends(principal)) -> Principal:
        key = str(p.user_id) if (by == "user" and p.user_id) else f"ip:{p.rl_ip}"
        enforce_limit(bucket, key, trust_tier(p.user))
        return p

    return dep

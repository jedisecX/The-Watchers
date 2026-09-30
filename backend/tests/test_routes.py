"""Route inventory and automated access sweep. [T-ROUTES] [T-API-UNAUTH]

* Every route must be declared in INVENTORY with its access class. A new endpoint added without a
  declared access class fails CI (THREAT-MODEL I-2 / OWASP API9: improper inventory).
* Every non-public route is called anonymously and must never succeed.
* Every admin route is called as a verified regular user (with valid CSRF) and must never succeed.
* Nothing may return 5xx, whatever garbage is put in path params/bodies.
"""
from __future__ import annotations

import re
import uuid

import pytest
from fastapi.routing import APIRoute, APIWebSocketRoute

PUBLIC, OPTIONAL, USER, ADMIN = "public", "optional-auth", "user", "admin"

INVENTORY: dict[tuple[str, str], str] = {
    # auth
    ("GET", "/api/v1/auth/pow"): PUBLIC,
    ("POST", "/api/v1/auth/register"): PUBLIC,
    ("POST", "/api/v1/auth/verify-email"): PUBLIC,
    ("POST", "/api/v1/auth/resend-verification"): USER,
    ("POST", "/api/v1/auth/login"): PUBLIC,
    ("POST", "/api/v1/auth/login/mfa"): PUBLIC,
    ("POST", "/api/v1/auth/passkey/login/begin"): PUBLIC,
    ("POST", "/api/v1/auth/passkey/login/finish"): PUBLIC,
    ("POST", "/api/v1/auth/logout"): OPTIONAL,
    ("GET", "/api/v1/auth/session"): OPTIONAL,
    ("POST", "/api/v1/auth/password-reset/request"): PUBLIC,
    ("POST", "/api/v1/auth/password-reset/confirm"): PUBLIC,
    ("POST", "/api/v1/auth/reauth"): USER,
    ("POST", "/api/v1/auth/reauth/passkey/begin"): USER,
    ("POST", "/api/v1/auth/reauth/passkey/finish"): USER,
    # account
    ("GET", "/api/v1/me"): USER,
    ("PATCH", "/api/v1/me"): USER,
    ("POST", "/api/v1/account/password"): USER,
    ("POST", "/api/v1/account/email"): USER,
    ("POST", "/api/v1/account/mfa/totp/setup"): USER,
    ("POST", "/api/v1/account/mfa/totp/enable"): USER,
    ("POST", "/api/v1/account/mfa/totp/disable"): USER,
    ("POST", "/api/v1/account/mfa/recovery-codes"): USER,
    ("POST", "/api/v1/account/passkeys/register/begin"): USER,
    ("POST", "/api/v1/account/passkeys/register/finish"): USER,
    ("GET", "/api/v1/account/passkeys"): USER,
    ("DELETE", "/api/v1/account/passkeys/{passkey_id}"): USER,
    ("GET", "/api/v1/account/sessions"): USER,
    ("DELETE", "/api/v1/account/sessions/{session_id}"): USER,
    ("POST", "/api/v1/account/sessions/revoke-others"): USER,
    ("GET", "/api/v1/account/tokens"): USER,
    ("POST", "/api/v1/account/tokens"): USER,
    ("DELETE", "/api/v1/account/tokens/{token_id}"): USER,
    ("GET", "/api/v1/account/export"): USER,
    ("POST", "/api/v1/account/delete"): USER,
    # users / social
    ("GET", "/api/v1/users/{handle}"): OPTIONAL,
    ("GET", "/api/v1/users/{handle}/posts"): OPTIONAL,
    ("GET", "/api/v1/users/{handle}/followers"): USER,
    ("GET", "/api/v1/users/{handle}/following"): USER,
    ("GET", "/api/v1/users/{handle}/likes"): OPTIONAL,
    ("POST", "/api/v1/users/{handle}/follow"): USER,
    ("DELETE", "/api/v1/users/{handle}/follow"): USER,
    ("DELETE", "/api/v1/users/{handle}/follower"): USER,
    ("GET", "/api/v1/follow-requests"): USER,
    ("POST", "/api/v1/follow-requests/{handle}/accept"): USER,
    ("POST", "/api/v1/follow-requests/{handle}/reject"): USER,
    ("POST", "/api/v1/users/{handle}/block"): USER,
    ("DELETE", "/api/v1/users/{handle}/block"): USER,
    ("POST", "/api/v1/users/{handle}/mute"): USER,
    ("DELETE", "/api/v1/users/{handle}/mute"): USER,
    ("GET", "/api/v1/blocks"): USER,
    ("GET", "/api/v1/mutes"): USER,
    # posts
    ("POST", "/api/v1/posts"): USER,
    ("GET", "/api/v1/posts/{post_id}"): OPTIONAL,
    ("DELETE", "/api/v1/posts/{post_id}"): USER,
    ("GET", "/api/v1/posts/{post_id}/replies"): OPTIONAL,
    ("POST", "/api/v1/posts/{post_id}/like"): USER,
    ("DELETE", "/api/v1/posts/{post_id}/like"): USER,
    ("POST", "/api/v1/posts/{post_id}/repost"): USER,
    ("DELETE", "/api/v1/posts/{post_id}/repost"): USER,
    ("GET", "/api/v1/posts/{post_id}/likes"): USER,
    ("GET", "/api/v1/timeline/home"): USER,
    ("GET", "/api/v1/timeline/public"): OPTIONAL,
    ("GET", "/api/v1/hashtags/{tag}"): OPTIONAL,
    ("GET", "/api/v1/search/posts"): USER,
    ("GET", "/api/v1/search/users"): USER,
    # notifications
    ("GET", "/api/v1/notifications"): USER,
    ("GET", "/api/v1/notifications/unread-count"): USER,
    ("POST", "/api/v1/notifications/read"): USER,
    # DMs
    ("POST", "/api/v1/conversations"): USER,
    ("GET", "/api/v1/conversations"): USER,
    ("GET", "/api/v1/conversations/{conversation_id}/messages"): USER,
    ("POST", "/api/v1/conversations/{conversation_id}/messages"): USER,
    ("DELETE", "/api/v1/conversations/{conversation_id}/messages/{message_id}"): USER,
    ("POST", "/api/v1/conversations/{conversation_id}/read"): USER,
    ("DELETE", "/api/v1/conversations/{conversation_id}"): USER,
    # media
    ("POST", "/api/v1/media"): USER,
    ("GET", "/api/v1/media/{media_id}"): USER,
    ("PATCH", "/api/v1/media/{media_id}"): USER,
    ("DELETE", "/api/v1/media/{media_id}"): USER,
    ("GET", "/api/v1/media/{media_id}/{variant}"): OPTIONAL,
    # reports, realtime, ops
    ("POST", "/api/v1/reports"): USER,
    ("POST", "/api/v1/ws/ticket"): USER,
    ("WS", "/api/v1/ws"): USER,
    ("GET", "/api/v1/health"): PUBLIC,
    ("POST", "/api/v1/csp-report"): PUBLIC,
    # admin
    ("GET", "/admin/api/v1/me"): ADMIN,
    ("GET", "/admin/api/v1/reports"): ADMIN,
    ("POST", "/admin/api/v1/reports/{report_id}/resolve"): ADMIN,
    ("POST", "/admin/api/v1/posts/{post_id}/remove"): ADMIN,
    ("POST", "/admin/api/v1/posts/{post_id}/restore"): ADMIN,
    ("GET", "/admin/api/v1/users"): ADMIN,
    ("GET", "/admin/api/v1/users/{user_id}"): ADMIN,
    ("POST", "/admin/api/v1/users/{user_id}/warn"): ADMIN,
    ("POST", "/admin/api/v1/users/{user_id}/suspend"): ADMIN,
    ("POST", "/admin/api/v1/users/{user_id}/unsuspend"): ADMIN,
    ("POST", "/admin/api/v1/users/{user_id}/revoke-sessions"): ADMIN,
    ("POST", "/admin/api/v1/users/{user_id}/role"): ADMIN,
    ("GET", "/admin/api/v1/blocked-domains"): ADMIN,
    ("POST", "/admin/api/v1/blocked-domains"): ADMIN,
    ("DELETE", "/admin/api/v1/blocked-domains/{domain}"): ADMIN,
    ("GET", "/admin/api/v1/audit"): ADMIN,
    ("GET", "/admin/api/v1/audit/verify"): ADMIN,
}
# Development-only routes (disabled in production by create_app) and internal catch-alls.
IGNORED = {"/api/openapi.json", "/api/docs", "/docs/oauth2-redirect", "/api/{rest:path}", "/admin/api/{rest:path}",
           "/{path:path}"}


def _flatten(routes):
    for r in routes:
        inner = getattr(r, "original_router", None)
        if inner is not None:
            yield from _flatten(inner.routes)
        else:
            yield r


def app_routes(app) -> set[tuple[str, str]]:
    out = set()
    for r in _flatten(app.router.routes):
        if isinstance(r, APIWebSocketRoute):
            out.add(("WS", r.path))
        elif isinstance(r, APIRoute) and r.path not in IGNORED:
            for m in r.methods - {"HEAD", "OPTIONS"}:
                out.add((m, r.path))
    return out


def test_every_route_is_declared(app):
    actual = app_routes(app)
    undeclared = actual - set(INVENTORY)
    stale = set(INVENTORY) - actual
    assert not undeclared, f"undocumented endpoints (declare an access class): {sorted(undeclared)}"
    assert not stale, f"inventory lists routes that no longer exist: {sorted(stale)}"


def test_production_hides_interactive_docs(monkeypatch):
    from app import config
    prod = config.Settings(**{**config.get_settings().__dict__, "env": "production"})
    import app.main as main_mod
    monkeypatch.setattr(main_mod, "get_settings", lambda: prod)
    app = main_mod.create_app()
    paths = {getattr(r, "path", None) for r in app.router.routes}
    assert "/api/docs" not in paths and "/api/openapi.json" not in paths


def _fill(path: str) -> str:
    subs = {"handle": "someone_x", "tag": "tag", "variant": "large", "domain": "a.example"}
    return re.sub(r"{(\w+)}", lambda m: subs.get(m.group(1), str(uuid.uuid4())), path)


HTTP = [(m, p, c) for (m, p), c in INVENTORY.items() if m != "WS"]


@pytest.mark.parametrize("method,path,cls", [x for x in HTTP if x[2] in (USER, ADMIN)],
                         ids=lambda v: v if isinstance(v, str) else None)
def test_anonymous_never_succeeds_on_protected_routes(factory, method, path, cls):
    c = factory.anon()
    for body in ({}, {"text": "x", "handle": "someone_x", "reason": "spam", "target_type": "user"}):
        r = c.request(method, _fill(path), json=body if method != "GET" else None,
                      headers={"Origin": "https://testserver"})
        assert r.status_code < 500, (method, path, r.status_code)
        assert not (200 <= r.status_code < 300), f"anonymous succeeded on {method} {path}: {r.status_code}"


@pytest.mark.parametrize("method,path,cls", [x for x in HTTP if x[2] == ADMIN],
                         ids=lambda v: v if isinstance(v, str) else None)
def test_regular_user_never_succeeds_on_admin_routes(factory, method, path, cls):
    u = factory.user()
    target = factory.user()
    bodies = [{}, {"reason": "because I can", "confirm": target.handle, "role": "admin", "outcome": "resolved",
                   "domain": "evil.example", "days": 1}]
    for body in bodies:
        p = _fill(path).replace("/users/" + path.split("/users/")[-1].split("/")[0] if "{user_id}" in path else "", "")
        p = re.sub(r"/users/[0-9a-f-]{36}", f"/users/{target.id}", _fill(path))
        r = u.client.request(method, p, json=body if method != "GET" else None,
                             headers={"Origin": "https://testserver", "X-CSRF-Token": u.csrf})
        assert r.status_code < 500
        assert not (200 <= r.status_code < 300), f"regular user succeeded on {method} {path}: {r.status_code} {r.text[:200]}"


@pytest.mark.parametrize("junk", ["%00", "..%2f..", "%ff%fe", "a" * 300, "' OR '1'='1", "${jndi:ldap://x}",
                                  "<script>", "%252e%252e"])
def test_garbage_path_params_never_500(factory, junk):
    u = factory.user()
    for (m, p), _ in INVENTORY.items():
        if m == "WS" or "{" not in p:
            continue
        path = re.sub(r"{(\w+)}", junk, p)
        r = u.client.request(m, path, json={} if m != "GET" else None,
                             headers={"Origin": "https://testserver", "X-CSRF-Token": u.csrf})
        assert r.status_code < 500, (m, path, r.status_code, r.text[:200])

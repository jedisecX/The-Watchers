"""Independent adversarial pass (Phase 11). Each test = Attack → Expected secure behaviour.
Results, severities and fixes are tabulated in SECURITY.md §Adversarial findings (ADV-xx)."""
from __future__ import annotations

import base64
import time
from concurrent.futures import ThreadPoolExecutor

import pyotp
import pytest
from sqlalchemy import func, select, update
from starlette.websockets import WebSocketDisconnect

from app.models import ApiToken, Conversation, User, WebAuthnCredential
from tests.helpers import ORIGIN, PASSWORD, token_from_outbox

H = {"Origin": ORIGIN}


def _register(c, handle, email, password=PASSWORD):
    from app.services.pow import solve
    ch = c.get("/api/v1/auth/pow").json()
    return c.post("/api/v1/auth/register", headers=H, json={
        "handle": handle, "email": email, "password": password,
        "pow": {"challenge": ch["challenge"], "nonce": solve(ch["challenge"], ch["difficulty"])}})


# ------------------------------------------------------------------ ADV-01 NUL bytes → 500
@pytest.mark.parametrize("body", [
    b'{"identifier":"\\u0000","password":"x"}',
    b'{"identifier":"a\\u0000b","password":"x"}',
])
def test_adv01_nul_in_json_is_400(client, body):
    r = client.post("/api/v1/auth/login", content=body, headers={**H, "Content-Type": "application/json"})
    assert r.status_code == 400


def test_adv01_nul_and_controls_in_path_are_400(client):
    for p in ("/api/v1/users/%00abc", "/api/v1/users/a%0Ab", "/api/v1/hashtags/%7F"):
        assert client.get(p).status_code == 400


# ------------------------------------------------------------------ ADV-02 account pre-hijacking
def test_adv02_unverified_account_cannot_plant_credentials(factory):
    """Attacker pre-registers the victim's email (can't verify it) and tries to plant a PAT/TOTP/passkey
    that would survive the victim later taking the account over via password reset."""
    atk = factory.user(verified=False)
    atk.reauth()
    assert atk.post("/api/v1/account/tokens", json={"name": "x", "scopes": ["read"]}).status_code == 403
    assert atk.post("/api/v1/account/mfa/totp/setup").status_code == 403
    assert atk.post("/api/v1/account/passkeys/register/begin").status_code == 403


def test_adv02_reset_of_unverified_account_wipes_foreign_credentials(factory, db):
    """Defence in depth: even if credentials exist on a never-verified account, the first proof of email
    control (password reset) removes everything the previous, unproven 'owner' set up."""
    atk = factory.user("prehijack", verified=False)
    from datetime import datetime, timedelta, timezone

    from app.security.crypto import token_hash
    db.add(ApiToken(user_id=atk.id, name="planted", prefix="bst_x", token_hash=token_hash("bst_planted"),
                    scopes=["read"], epoch=0, expires_at=datetime.now(timezone.utc) + timedelta(days=9)))
    db.add(WebAuthnCredential(user_id=atk.id, credential_id=b"planted-cred", public_key=b"k"))
    secret = pyotp.random_base32()
    from app.services import mfa as mfasvc
    db.execute(update(User).where(User.id == atk.id).values(
        totp_secret_enc=mfasvc.enc(atk.id, secret), mfa_enabled_at=datetime.now(timezone.utc)))
    db.commit()
    victim = factory.anon()
    victim.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "prehijack@example.com"})
    tok = token_from_outbox(db, "prehijack@example.com", "reset")
    assert victim.post("/api/v1/auth/password-reset/confirm", headers=H,
                       json={"token": tok, "new_password": "victim's own passphrase"}).status_code == 200
    db.expire_all()
    u = db.get(User, atk.id)
    assert u.mfa_enabled_at is None and u.totp_secret_enc is None
    assert db.scalar(select(func.count()).select_from(WebAuthnCredential).where(WebAuthnCredential.user_id == atk.id)) == 0
    assert db.scalar(select(func.count()).select_from(ApiToken).where(ApiToken.user_id == atk.id,
                                                                      ApiToken.revoked_at.is_(None))) == 0


# ------------------------------------------------------------------ ADV-03 redirects
@pytest.mark.parametrize("path", ["/api/v1/me/", "/api/v1/timeline/public/", "/admin/api/v1/me/"])
def test_adv03_no_trailing_slash_redirects(factory, path):
    """Automatic redirects are an unsafe-redirect/cache-key surface; strict routing returns 404."""
    u = factory.user()
    r = u.client.get(path, follow_redirects=False)
    assert r.status_code == 404 and "location" not in r.headers


# ------------------------------------------------------------------ ADV-04 live sockets after credential change
def test_adv04_password_change_closes_existing_sockets_immediately(factory):
    u = factory.user()
    from tests.helpers import UserCtx, make_client
    other = UserCtx(make_client(factory.app), u.handle, u.id, None, PASSWORD)
    factory.login(other)
    cookie = other.client.cookies.get("__Host-bastion_sid")
    with pytest.raises(WebSocketDisconnect) as ei:
        with other.client.websocket_connect("/api/v1/ws", headers={"Origin": ORIGIN,
                                                                    "Cookie": f"__Host-bastion_sid={cookie}"}) as ws:
            ws.receive_json()
            assert u.post("/api/v1/account/password", json={"current_password": PASSWORD,
                                                             "new_password": "rotated passphrase 99"}).status_code == 200
            started = time.monotonic()
            ws.receive_json()
    assert ei.value.code in (4001, 4003)
    assert time.monotonic() - started < 5, "socket survived until the periodic watchdog"


# ------------------------------------------------------------------ ADV-05 CORS
def test_adv05_no_cors_ever(factory):
    u = factory.user()
    r = u.client.options("/api/v1/me", headers={"Origin": "https://evil.example",
                                                "Access-Control-Request-Method": "GET"})
    assert "access-control-allow-origin" not in r.headers
    r = u.client.get("/api/v1/me", headers={"Origin": "https://evil.example"})
    assert "access-control-allow-origin" not in r.headers and "access-control-allow-credentials" not in r.headers


# ------------------------------------------------------------------ ADV-06 XFF spoofing
def test_adv06_spoofed_xff_does_not_evade_ip_limits(factory):
    c = factory.anon("203.0.113.66")
    codes = []
    for i in range(35):
        r = c.post("/api/v1/auth/login", headers={**H, "X-Forwarded-For": f"10.0.{i}.1", "X-Real-IP": f"10.1.{i}.1"},
                   json={"identifier": f"spray{i}", "password": "wrong password here"})
        codes.append(r.status_code)
    assert 429 in codes[30:]


# ------------------------------------------------------------------ ADV-07 header-based cache poisoning / overrides
def test_adv07_override_headers_ignored(factory, db):
    factory.user("hostpoison", login=False)
    c = factory.anon()
    c.post("/api/v1/auth/password-reset/request", json={"email": "hostpoison@example.com"},
           headers={**H, "X-Forwarded-Host": "evil.example", "X-Original-URL": "/admin", "X-Rewrite-URL": "/admin",
                    "Forwarded": "host=evil.example"})
    from tests.helpers import outbox
    assert "evil.example" not in (outbox(db, "hostpoison@example.com", "reset")[0].body or "")
    # method override must not turn a GET into a state change
    u = factory.user()
    r = u.client.get("/api/v1/auth/logout", headers={"X-HTTP-Method-Override": "POST", "X-Method-Override": "POST"})
    assert r.status_code in (404, 405)
    assert u.get("/api/v1/auth/session").json()["authenticated"] is True


# ------------------------------------------------------------------ ADV-08..11 races
def test_adv08_concurrent_registration_same_handle(factory, db):
    def go(i):
        c = factory.anon(f"10.9.{i}.1")
        return _register(c, "racehandle", f"race{i}@example.com").status_code
    with ThreadPoolExecutor(6) as ex:
        codes = list(ex.map(go, range(6)))
    assert codes.count(202) == 1 and all(c in (202, 409) for c in codes), codes
    assert db.scalar(select(func.count()).select_from(User).where(User.handle_norm == "racehandle")) == 1


def test_adv09_concurrent_dm_start_single_conversation(factory, db):
    a, b = factory.user(), factory.user(dm_policy="everyone")
    with ThreadPoolExecutor(8) as ex:
        ids = set(ex.map(lambda _: a.post("/api/v1/conversations", json={"handle": b.handle}).json()["id"], range(8)))
    assert len(ids) == 1
    assert db.scalar(select(func.count()).select_from(Conversation)) == 1


def test_adv10_concurrent_mutual_admin_demotion_keeps_an_admin(factory, db):
    a1 = factory.user(role="admin", mfa=True)
    a2 = factory.user(role="admin", mfa=True)

    def demote(pair):
        actor, target = pair
        return actor.post(f"/admin/api/v1/users/{target.id}/role",
                          json={"role": "user", "reason": "race test", "confirm": target.handle}).status_code
    with ThreadPoolExecutor(2) as ex:
        list(ex.map(demote, [(a1, a2), (a2, a1)]))
    db.expire_all()
    assert db.scalar(select(func.count()).select_from(User).where(User.role == "admin")) >= 1


def test_adv11_same_totp_code_raced_on_two_pending_logins(factory, db):
    u = factory.user(mfa=True, login=False)
    from tests.helpers import reset_totp_steps
    reset_totp_steps(db, u.id)
    c1, c2 = factory.anon(), factory.anon()
    t1 = c1.post("/api/v1/auth/login", headers=H, json={"identifier": u.handle, "password": PASSWORD}).json()["mfa_token"]
    t2 = c2.post("/api/v1/auth/login", headers=H, json={"identifier": u.handle, "password": PASSWORD}).json()["mfa_token"]
    code = pyotp.TOTP(u.totp_secret).now()

    def go(pair):
        c, t = pair
        return c.post("/api/v1/auth/login/mfa", headers=H, json={"mfa_token": t, "code": code}).status_code
    with ThreadPoolExecutor(2) as ex:
        codes = list(ex.map(go, [(c1, t1), (c2, t2)]))
    assert codes.count(200) == 1, codes


# ------------------------------------------------------------------ ADV-12..15 parser edge cases
@pytest.mark.parametrize("raw,ctype", [
    ('﻿{"identifier":"a","password":"b"}'.encode(), "application/json"),
    ('{"identifier":"a","password":"b"}'.encode("utf-16"), "application/json; charset=utf-16"),
    (b'{"identifier":"a","password":"b"}', "application/json-patch+json"),
    (b'{"identifier":"a","password":"b"}', "APPLICATION/JSON; boundary=x"),
])
def test_adv12_encoding_and_content_type_tricks(client, raw, ctype):
    r = client.post("/api/v1/auth/login", content=raw, headers={**H, "Content-Type": ctype})
    assert r.status_code in (400, 401, 415), r.status_code  # parsed identically or rejected; never 500


def test_adv13_email_case_variants_are_one_identity(factory, db):
    c = factory.anon()
    assert _register(c, "caseone", "Case.User@Example.COM").status_code == 202
    assert _register(factory.anon(), "casetwo", "case.user@example.com").status_code == 202
    assert db.scalar(select(func.count()).select_from(User).where(User.email == "case.user@example.com")) == 1
    assert db.scalar(select(User).where(User.handle_norm == "casetwo")) is None


def test_adv14_case_variant_keys_are_not_aliases(factory):
    u = factory.user()
    r = u.post("/api/v1/posts", json={"text": "a", "Text": "b"})
    assert r.status_code == 422


@pytest.mark.parametrize("body", [b'{"version": 1e400, "bio": "x"}', b'{"version": 100000000000000000000000000000, "bio": "x"}',
                                  b'{"version": -1, "bio": "x"}', b'{"version": true, "bio": "x"}'])
def test_adv15_numeric_edge_cases(factory, body):
    u = factory.user()
    r = u.client.patch("/api/v1/me", content=body, headers={**H, "X-CSRF-Token": u.csrf, "Content-Type": "application/json"})
    assert r.status_code in (400, 409, 422), (r.status_code, r.text[:200])


# ------------------------------------------------------------------ ADV-16 visibility changes propagate
def test_adv16_going_private_retroactively_hides_reposts_and_notifications(factory):
    author, reposter, reader = factory.user(), factory.user(), factory.user()
    reader.post(f"/api/v1/users/{reposter.handle}/follow")
    p = author.post("/api/v1/posts", json={"text": "was public"}).json()
    reposter.post(f"/api/v1/posts/{p['id']}/repost")
    assert any(i["id"] == p["id"] for i in reader.get("/api/v1/timeline/home").json()["items"])
    author.patch("/api/v1/me", json={"version": 1, "is_private": True})
    assert all(i["id"] != p["id"] for i in reader.get("/api/v1/timeline/home").json()["items"])
    assert reader.get(f"/api/v1/posts/{p['id']}").status_code == 404


def test_adv17_state_changes_never_reachable_via_get(app):
    """No GET route may perform a mutation (CSRF-by-image-tag / prefetch / crawler safety)."""
    from tests.test_routes import INVENTORY
    verbs = ("/follow", "/block", "/mute", "/like", "/repost", "/delete", "/logout", "/suspend", "/role",
             "/remove", "/resolve", "/read", "/revoke", "/accept", "/reject", "/warn")
    read_only_lists = ("/followers", "/following", "/likes", "/unread-count", "/follow-requests", "/blocks", "/mutes",
                       "/blocked-domains")
    for (m, p), _ in INVENTORY.items():
        if m == "GET" and not p.endswith(read_only_lists):
            assert not any(v in p for v in verbs), p


def test_adv18_session_tokens_are_high_entropy(factory):
    toks = set()
    for _ in range(5):
        u = factory.user()
        t = u.client.cookies.get("__Host-bastion_sid")
        assert len(t) >= 43 and len(base64.urlsafe_b64decode(t + "==")) >= 32
        toks.add(t)
    assert len(toks) == 5


def test_adv19_pow_difficulty_escalates_for_hammering_ip(factory):
    c = factory.anon("203.0.113.99")
    diffs = [c.get("/api/v1/auth/pow").json()["difficulty"] for _ in range(8)]
    assert diffs[-1] > diffs[0]


def test_adv20_revoked_token_cannot_mint_ws_ticket_or_connect(factory):
    u = factory.user()
    u.reauth()
    t = u.post("/api/v1/account/tokens", json={"name": "ws", "scopes": ["read"]}).json()
    api = factory.anon()
    ticket = api.post("/api/v1/ws/ticket", headers={"Authorization": f"Bearer {t['token']}"}).json()["ticket"]
    u.delete(f"/api/v1/account/tokens/{t['id']}")
    with pytest.raises(WebSocketDisconnect):
        with api.websocket_connect(f"/api/v1/ws?ticket={ticket}") as ws:
            ws.receive_json()


def test_adv21_login_does_not_reveal_unverified_or_deleted_state(factory):
    factory.user("unverif", verified=False, login=False)
    d = factory.user("deleted1")
    d.reauth()
    d.post("/api/v1/account/delete", json={"confirm_handle": "deleted1"})
    c = factory.anon()
    r1 = c.post("/api/v1/auth/login", headers=H, json={"identifier": "deleted1", "password": PASSWORD})
    r2 = c.post("/api/v1/auth/login", headers=H, json={"identifier": "never_existed", "password": PASSWORD})
    assert r1.status_code == r2.status_code == 401 and r1.json()["error"]["code"] == r2.json()["error"]["code"]


def test_adv22_timing_of_existing_vs_missing_email_on_register(factory):
    factory.user("timedreg", login=False)

    def t(email, handle):
        c = factory.anon()
        s = time.perf_counter()
        _register(c, handle, email)
        return time.perf_counter() - s
    known = sorted(t("timedreg@example.com", f"h{i}xx") for i in range(3))[1]
    unknown = sorted(t(f"fresh{i}@example.com", f"g{i}yy") for i in range(3))[1]
    assert abs(known - unknown) < max(known, unknown) * 0.8


# ------------------------------------------------------------------ code-review findings
def test_adv05b_concurrent_attach_same_media_never_500(factory, db):
    import io
    import os

    from PIL import Image
    from sqlalchemy import create_engine

    from app.worker import process_one
    u = factory.user()
    b = io.BytesIO()
    Image.new("RGB", (10, 10)).save(b, "PNG")
    mid = u.client.post("/api/v1/media?purpose=post", content=b.getvalue(),
                        headers={**H, "X-CSRF-Token": u.csrf}).json()["id"]
    eng = create_engine(os.environ["WORKER_DATABASE_URL"])
    process_one(eng)
    eng.dispose()
    with ThreadPoolExecutor(6) as ex:
        codes = list(ex.map(lambda i: u.post("/api/v1/posts", json={"text": f"race {i}", "media_ids": [mid]}).status_code,
                            range(6)))
    assert codes.count(201) == 1 and all(c in (201, 404, 409) for c in codes), codes


def test_adv06b_mfa_budget_is_per_account_across_ips(factory):
    u = factory.user(mfa=True, login=False)
    codes = []
    for i in range(23):
        c = factory.anon(f"10.77.{i}.1")
        t = c.post("/api/v1/auth/login", headers=H, json={"identifier": u.handle, "password": PASSWORD}).json()
        if "mfa_token" not in t:
            codes.append(429)
            continue
        codes.append(c.post("/api/v1/auth/login/mfa", headers=H, json={"mfa_token": t["mfa_token"],
                                                                        "code": "000000"}).status_code)
    assert 429 in codes[20:], codes


def test_adv07b_pat_scope_enforced_on_me(factory):
    u = factory.user()
    u.reauth()
    tok = u.post("/api/v1/account/tokens", json={"name": "dm-only", "scopes": ["dm"]}).json()["token"]
    assert factory.anon().get("/api/v1/me", headers={"Authorization": f"Bearer {tok}"}).status_code == 403


def test_adv08b_moderator_cannot_restore_admin_content(factory):
    m = factory.user(role="moderator", mfa=True)
    adm = factory.user(role="admin", mfa=True)
    p = adm.post("/api/v1/posts", json={"text": "removed by admin"}).json()
    assert adm.post(f"/admin/api/v1/posts/{p['id']}/remove", json={"reason": "own removal"}).status_code == 200
    assert m.post(f"/admin/api/v1/posts/{p['id']}/restore", json={"reason": "nope nope"}).status_code == 403


def test_adv09b_db_constraint_violation_maps_to_409_not_500(factory, monkeypatch):
    from sqlalchemy.exc import IntegrityError

    import app.api.posts as posts_api
    u = factory.user()

    def boom(*a, **k):
        raise IntegrityError("stmt", {}, Exception("duplicate key"))
    monkeypatch.setattr(posts_api.svc, "create_post", boom)
    r = u.post("/api/v1/posts", json={"text": "x"})
    assert r.status_code == 409 and "duplicate key" not in r.text


def test_adv10b_every_endpoint_has_a_baseline_rate_limit(factory):
    """Previously-unlimited endpoints (e.g. GET /api/v1/blocks) are now capped by the baseline budget."""
    u = factory.user(age_days=0)  # 'new' tier: 300/min
    codes = [u.get("/api/v1/blocks").status_code for _ in range(305)]
    assert codes[:290].count(200) == 290 and 429 in codes[295:]

"""Phase 2: authentication. [T-AUTH-*] [T-MFA-*] [T-RACE-TOKEN] [T-PAT-SCOPE] [T-CSRF]"""
from __future__ import annotations

import time
import uuid
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone

import pyotp
import pytest
from sqlalchemy import select, update

from app.models import ApiToken, AuditLog, RecoveryCode, User
from app.models import Session as DbSession
from tests.helpers import ORIGIN, PASSWORD, fresh_totp, token_from_outbox

H = {"Origin": ORIGIN}


def _register(client, handle="alice", email="alice@example.com", password=PASSWORD):
    from app.services.pow import solve
    ch = client.get("/api/v1/auth/pow").json()
    nonce = solve(ch["challenge"], ch["difficulty"])
    return client.post("/api/v1/auth/register", headers=H, json={
        "handle": handle, "email": email, "password": password,
        "pow": {"challenge": ch["challenge"], "nonce": nonce}})


def _login(client, ident, pw=PASSWORD):
    return client.post("/api/v1/auth/login", headers=H, json={"identifier": ident, "password": pw})


# ============================================================ registration & verification
def test_full_registration_verification_login_flow(client, db):
    r = _register(client)
    assert r.status_code == 202, r.text
    r = _login(client, "alice")
    assert r.status_code == 200
    csrf = r.json()["csrf_token"]
    # unverified accounts cannot post
    r = client.post("/api/v1/posts", headers={**H, "X-CSRF-Token": csrf}, json={"text": "hi"})
    assert r.status_code in (403, 404)  # 404 until posts router exists
    tok = token_from_outbox(db, "alice@example.com", "verify")
    # logged-in browser: CSRF applies to every unsafe request, including verification
    assert client.post("/api/v1/auth/verify-email", headers=H, json={"token": tok}).status_code == 403
    HC = {**H, "X-CSRF-Token": csrf}
    r = client.post("/api/v1/auth/verify-email", headers=HC, json={"token": tok})
    assert r.status_code == 200
    # [T-RACE-TOKEN] single-use
    r = client.post("/api/v1/auth/verify-email", headers=HC, json={"token": tok})
    assert r.status_code == 400
    db.expire_all()
    assert db.scalar(select(User.email_verified_at).where(User.handle_norm == "alice")) is not None


def test_session_cookie_attributes(client):
    _register(client)
    r = _login(client, "alice")
    sc = r.headers["set-cookie"]
    assert sc.startswith("__Host-bastion_sid=")
    low = sc.lower()
    assert "httponly" in low and "secure" in low and "samesite=lax" in low and "path=/" in low
    assert "domain=" not in low


def test_pow_required_and_single_use(client):
    from app.services.pow import solve
    r = client.post("/api/v1/auth/register", headers=H, json={
        "handle": "bob", "email": "bob@example.com", "password": PASSWORD,
        "pow": {"challenge": "v1.x.1.1.x.x", "nonce": "1"}})
    assert r.status_code == 400 and r.json()["error"]["code"] == "pow_invalid"
    ch = client.get("/api/v1/auth/pow").json()
    nonce = solve(ch["challenge"], ch["difficulty"])
    body = {"handle": "bob", "email": "bob@example.com", "password": PASSWORD,
            "pow": {"challenge": ch["challenge"], "nonce": nonce}}
    assert client.post("/api/v1/auth/register", headers=H, json=body).status_code == 202
    body["handle"] = "bob2"
    body["email"] = "bob2@example.com"
    r = client.post("/api/v1/auth/register", headers=H, json=body)  # replayed challenge
    assert r.status_code == 400 and r.json()["error"]["code"] == "pow_invalid"


def test_pow_bound_to_ip(app, factory):
    from app.services.pow import solve
    a, b = factory.anon("203.0.113.1"), factory.anon("192.0.2.200")
    ch = a.get("/api/v1/auth/pow").json()
    nonce = solve(ch["challenge"], ch["difficulty"])
    r = b.post("/api/v1/auth/register", headers=H, json={
        "handle": "carol", "email": "c@example.com", "password": PASSWORD,
        "pow": {"challenge": ch["challenge"], "nonce": nonce}})
    assert r.json()["error"]["code"] == "pow_invalid"


@pytest.mark.parametrize("pw,code", [
    ("short", "password_too_short"),
    ("password1234", "password_too_common"),
    ("aaaaaaaaaaaaaaaa", "password_too_common"),
    ("alice-is-my-name-ok", "password_contains_identity"),
    ("x" * 300, "password_too_long"),
])
def test_password_policy(client, pw, code):
    r = _register(client, password=pw)
    assert r.status_code == 400 and r.json()["error"]["code"] == code


@pytest.mark.parametrize("handle", ["ab", "a" * 31, "bad-handle", "admin", "héllo", "x y", "<script>"])
def test_handle_validation(client, handle):
    r = _register(client, handle=handle)
    assert r.status_code in (400, 422)


def test_registration_rate_limited_per_ip(factory):
    c = factory.anon("203.0.113.50")
    codes = [_register(c, handle=f"user{i}x", email=f"u{i}@example.com").status_code for i in range(7)]
    assert codes[:5] == [202] * 5 and 429 in codes[5:]


# ============================================================ enumeration [T-AUTH-ENUM]
def test_register_existing_email_indistinguishable(client, db, factory):
    factory.user("existing")
    r = _register(client, handle="newperson", email="existing@example.com")
    assert r.status_code == 202 and r.json() == {"status": "verification_sent"}
    assert token_from_outbox is not None
    kinds = [m.kind for m in __import__("tests.helpers", fromlist=["outbox"]).outbox(db, "existing@example.com")]
    assert "exists" in kinds  # owner is told; attacker learns nothing
    assert db.scalar(select(User).where(User.handle_norm == "newperson")) is None


def test_login_error_identical_for_unknown_and_wrong_password(client, factory):
    factory.user("realuser", login=False)
    r1 = _login(client, "realuser", "wrong password here!!")
    r2 = _login(client, "nosuchuser", "wrong password here!!")
    assert r1.status_code == r2.status_code == 401
    e1, e2 = r1.json()["error"], r2.json()["error"]
    assert (e1["code"], e1["message"]) == (e2["code"], e2["message"])


def test_login_timing_does_not_reveal_existence(client, factory):
    factory.user("timinguser", login=False)

    def t(ident):
        s = time.perf_counter()
        _login(client, ident, "wrong password here!!")
        return time.perf_counter() - s
    t(timinguser := "timinguser"), t("ghost")  # warm up
    known = sorted(t(timinguser) for _ in range(5))[2]
    unknown = sorted(t("ghost") for _ in range(5))[2]
    assert unknown > known * 0.5, (known, unknown)  # dummy hash runs for unknown users


def test_password_reset_request_always_202(client, factory, db):
    factory.user("resetme", login=False)
    a = client.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "resetme@example.com"})
    b = client.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "ghost@example.com"})
    c = client.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "not-an-email"})
    assert a.status_code == b.status_code == c.status_code == 202
    assert a.json() == b.json() == c.json()


# ============================================================ brute force [T-AUTH-BRUTE]
def test_account_brute_force_throttled_from_one_ip(factory):
    factory.user("victim", login=False)
    c = factory.anon("203.0.113.9")
    codes = [_login(c, "victim", f"wrong-password-{i}!!").status_code for i in range(12)]
    assert codes[:10] == [401] * 10 and codes[10:] == [429, 429]
    # even the correct password is refused while throttled (no oracle)
    assert _login(c, "victim").status_code == 429


def test_throttle_identical_for_nonexistent_account(factory):
    c = factory.anon("203.0.113.10")
    codes = [_login(c, "ghostaccount", f"wrong-password-{i}!!").status_code for i in range(12)]
    assert codes[:10] == [401] * 10 and codes[10:] == [429, 429]


def test_distributed_stuffing_against_one_account_slowed(factory):
    factory.user("popular", login=False)
    codes = []
    for i in range(55):
        c = factory.anon(f"10.1.{i // 250}.{i % 250 + 1}")
        codes.append(_login(c, "popular", f"guess-{i}-password!!").status_code)
    assert 429 in codes[50:]


def test_login_ip_limit_across_accounts(factory):
    c = factory.anon("203.0.113.77")
    codes = [_login(c, f"spray{i}", "Winter2024!Winter").status_code for i in range(32)]
    assert 429 in codes[30:]


def test_ratelimit_fails_closed_for_auth_when_backend_down(factory, monkeypatch):
    from app import ratelimit

    class Broken:
        def hit(self, *a, **k):
            raise ratelimit.LimiterUnavailable()

        def once(self, *a, **k):
            raise ratelimit.LimiterUnavailable()

        def reset(self):
            pass
    monkeypatch.setattr(ratelimit, "get_limiter", lambda: Broken())
    c = factory.anon()
    assert _login(c, "anyone").status_code == 503
    assert c.get("/api/v1/health").status_code == 200  # reads still work


# ============================================================ sessions [T-AUTH-FIXATION] [T-AUTH-REVOKE]
def test_session_fixation_prevented(factory, db):
    u = factory.user("fixme")
    old_cookie = u.client.cookies.get("__Host-bastion_sid")
    factory.login(u)  # log in again with the old cookie present
    new_cookie = u.client.cookies.get("__Host-bastion_sid")
    assert old_cookie != new_cookie
    from app.security.crypto import token_hash
    old = db.scalar(select(DbSession).where(DbSession.token_hash == token_hash(old_cookie)))
    assert old.revoked_at is not None


def test_attacker_chosen_cookie_is_ignored(factory):
    c = factory.anon()
    c.cookies.set("__Host-bastion_sid", "attacker-chosen-value", domain="testserver")
    assert c.get("/api/v1/auth/session").json() == {"authenticated": False}


def test_logout_revokes_server_side(factory):
    u = factory.user("leaver")
    stolen = u.client.cookies.get("__Host-bastion_sid")
    assert u.post("/api/v1/auth/logout").status_code == 204
    thief = factory.anon()
    thief.cookies.set("__Host-bastion_sid", stolen, domain="testserver")
    assert thief.get("/api/v1/auth/session").json()["authenticated"] is False


def test_revoke_other_session_and_cross_user_revoke_blocked(factory):
    a1 = factory.user("multi")
    a2 = factory.user("multi2")
    # second device for a1
    from tests.helpers import UserCtx, make_client
    dev2 = UserCtx(make_client(factory.app), "multi", a1.id, None, PASSWORD)
    factory.login(dev2)
    sessions = a1.get("/api/v1/account/sessions").json()["items"]
    assert len(sessions) == 2
    other = next(s for s in sessions if not s["current"])
    # [E-2] another user cannot revoke a1's session
    assert a2.delete(f"/api/v1/account/sessions/{other['id']}").status_code == 404
    assert dev2.get("/api/v1/auth/session").json()["authenticated"] is True
    assert a1.delete(f"/api/v1/account/sessions/{other['id']}").status_code == 204
    assert dev2.get("/api/v1/auth/session").json()["authenticated"] is False


def test_idle_and_absolute_expiry(factory, db):
    u = factory.user("sleepy")
    db.execute(update(DbSession).where(DbSession.user_id == u.id).values(
        last_seen_at=datetime.now(timezone.utc) - timedelta(days=8)))
    db.commit()
    assert u.get("/api/v1/auth/session").json()["authenticated"] is False
    factory.login(u)
    db.execute(update(DbSession).where(DbSession.user_id == u.id).values(
        expires_at=datetime.now(timezone.utc) - timedelta(seconds=1)))
    db.commit()
    assert u.get("/api/v1/auth/session").json()["authenticated"] is False


def test_privileged_roles_have_short_idle_timeout(factory, db):
    m = factory.user("modidle", role="moderator", mfa=True)
    db.execute(update(DbSession).where(DbSession.user_id == m.id).values(
        last_seen_at=datetime.now(timezone.utc) - timedelta(minutes=45)))
    db.commit()
    assert m.get("/api/v1/auth/session").json()["authenticated"] is False


def test_suspended_user_sessions_rejected(factory, db):
    u = factory.user("baduser")
    db.execute(update(User).where(User.id == u.id).values(status="suspended"))
    db.commit()
    assert u.get("/api/v1/auth/session").json()["authenticated"] is False
    r = _login(factory.anon(), "baduser")
    assert r.status_code == 403 and r.json()["error"]["code"] == "account_suspended"


# ============================================================ CSRF [T-CSRF]
def test_csrf_token_required_for_cookie_auth(factory):
    u = factory.user("csrfuser")
    r = u.client.post("/api/v1/auth/logout", headers={"Origin": ORIGIN})
    assert r.status_code == 403 and r.json()["error"]["code"] == "csrf_failed"
    r = u.client.post("/api/v1/auth/logout", headers={"Origin": ORIGIN, "X-CSRF-Token": "forged"})
    assert r.status_code == 403
    other = factory.user("csrfother")
    r = u.client.post("/api/v1/auth/logout", headers={"Origin": ORIGIN, "X-CSRF-Token": other.csrf})
    assert r.status_code == 403  # token bound to the session
    assert u.post("/api/v1/auth/logout").status_code == 204


def test_csrf_token_rotates_with_session(factory):
    u = factory.user("rotator")
    old = u.csrf
    factory.login(u)
    assert u.csrf != old
    r = u.client.patch("/api/v1/me", headers={"Origin": ORIGIN, "X-CSRF-Token": old}, json={"version": 1, "bio": "x"})
    assert r.status_code == 403


# ============================================================ password reset [T-RACE-TOKEN]
def test_password_reset_flow_revokes_everything_and_keeps_mfa(factory, db):
    u = factory.user("resetter", mfa=True)
    u.reauth()
    tok_r = u.post("/api/v1/account/tokens", json={"name": "t", "scopes": ["read"]})
    pat = tok_r.json()["token"]
    anon = factory.anon()
    anon.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "resetter@example.com"})
    tok = token_from_outbox(db, "resetter@example.com", "reset")
    new_pw = "a brand new passphrase 77"
    r = anon.post("/api/v1/auth/password-reset/confirm", headers=H, json={"token": tok, "new_password": new_pw})
    assert r.status_code == 200
    # token reuse
    r = anon.post("/api/v1/auth/password-reset/confirm", headers=H, json={"token": tok, "new_password": new_pw + "x"})
    assert r.status_code == 400
    # existing session and PAT are dead
    assert u.get("/api/v1/auth/session").json()["authenticated"] is False
    assert anon.get("/api/v1/me", headers={"Authorization": f"Bearer {pat}"}).status_code == 401
    # [S-4d] MFA still required after reset
    r = _login(anon, "resetter", new_pw)
    assert r.json().get("mfa_required") is True
    assert db.scalar(select(AuditLog).where(AuditLog.action == "account.password_reset")) is not None


def test_reset_token_race_only_one_wins(factory, db):
    factory.user("racer", login=False)
    anon = factory.anon()
    anon.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "racer@example.com"})
    tok = token_from_outbox(db, "racer@example.com", "reset")

    def go(i):
        c = factory.anon()
        return c.post("/api/v1/auth/password-reset/confirm", headers=H,
                      json={"token": tok, "new_password": f"parallel passphrase number {i}"}).status_code
    with ThreadPoolExecutor(8) as ex:
        results = list(ex.map(go, range(8)))
    assert results.count(200) == 1, results


def test_new_reset_link_invalidates_old(factory, db):
    factory.user("twolinks", login=False)
    anon = factory.anon()
    anon.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "twolinks@example.com"})
    first = token_from_outbox(db, "twolinks@example.com", "reset")
    anon.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "twolinks@example.com"})
    r = anon.post("/api/v1/auth/password-reset/confirm", headers=H,
                  json={"token": first, "new_password": "another good passphrase"})
    assert r.status_code == 400


def test_expired_reset_token_rejected(factory, db):
    from app.models import AuthToken
    factory.user("expired", login=False)
    anon = factory.anon()
    anon.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "expired@example.com"})
    tok = token_from_outbox(db, "expired@example.com", "reset")
    db.execute(update(AuthToken).values(created_at=datetime.now(timezone.utc) - timedelta(hours=2),
                                        expires_at=datetime.now(timezone.utc) - timedelta(minutes=1)))
    db.commit()
    r = anon.post("/api/v1/auth/password-reset/confirm", headers=H,
                  json={"token": tok, "new_password": "another good passphrase"})
    assert r.status_code == 400


def test_reset_link_uses_configured_origin_not_host_header(factory, db):
    factory.user("hosty", login=False)
    anon = factory.anon()
    anon.post("/api/v1/auth/password-reset/request", headers={**H, "X-Forwarded-Host": "evil.example"},
              json={"email": "hosty@example.com"})
    from tests.helpers import outbox
    body = outbox(db, "hosty@example.com", "reset")[0].body
    assert "https://testserver/reset-password#token=" in body and "evil" not in body


def test_bad_password_does_not_burn_reset_token(factory, db):
    factory.user("careful", login=False)
    anon = factory.anon()
    anon.post("/api/v1/auth/password-reset/request", headers=H, json={"email": "careful@example.com"})
    tok = token_from_outbox(db, "careful@example.com", "reset")
    r = anon.post("/api/v1/auth/password-reset/confirm", headers=H, json={"token": tok, "new_password": "short"})
    assert r.status_code == 400
    r = anon.post("/api/v1/auth/password-reset/confirm", headers=H,
                  json={"token": tok, "new_password": "finally a long passphrase"})
    assert r.status_code == 200


# ============================================================ MFA [T-MFA-*]
def _enable_totp(u):
    u.reauth()
    setup = u.post("/api/v1/account/mfa/totp/setup").json()
    secret = setup["secret"]
    r = u.post("/api/v1/account/mfa/totp/enable", json={"code": pyotp.TOTP(secret).now()})
    assert r.status_code == 200, r.text
    from tests.helpers import mark_totp_used
    mark_totp_used(secret)
    u.totp_secret = secret
    return secret, r.json()["recovery_codes"]


def test_totp_enrollment_requires_reauth(factory, db):
    u = factory.user("mfauser")
    db.execute(update(DbSession).where(DbSession.user_id == u.id).values(
        reauth_at=datetime.now(timezone.utc) - timedelta(hours=1)))
    db.commit()
    r = u.post("/api/v1/account/mfa/totp/setup")
    assert r.status_code == 403 and r.json()["error"]["code"] == "reauth_required"


def test_mfa_pending_state_grants_nothing(factory):
    """[T-MFA-PENDING] after the password step, no session exists; the mfa token is not a session."""
    u = factory.user("pending")
    secret, _ = _enable_totp(u)
    c = factory.anon()
    r = _login(c, "pending")
    body = r.json()
    assert body["mfa_required"] and "set-cookie" not in r.headers
    assert c.get("/api/v1/auth/session").json()["authenticated"] is False
    assert c.get("/api/v1/me").status_code == 401
    assert c.get("/api/v1/me", headers={"Authorization": f"Bearer {body['mfa_token']}"}).status_code == 401


def test_mfa_bruteforce_burns_pending_token(factory):
    u = factory.user("bruted")
    secret, _ = _enable_totp(u)
    c = factory.anon()
    tok = _login(c, "bruted").json()["mfa_token"]
    codes = []
    for i in range(6):
        r = c.post("/api/v1/auth/login/mfa", headers=H, json={"mfa_token": tok, "code": f"{i:06d}"})
        codes.append(r.json()["error"]["code"])
    assert codes[:5] == ["mfa_invalid"] * 5 and codes[5] == "mfa_token_invalid"
    # even the right code fails now
    r = c.post("/api/v1/auth/login/mfa", headers=H, json={"mfa_token": tok, "code": pyotp.TOTP(secret).now()})
    assert r.status_code == 401


def test_totp_replay_rejected(factory, db):
    u = factory.user("replayer")
    secret, _ = _enable_totp(u)
    from tests.helpers import reset_totp_steps
    reset_totp_steps(db, u.id)
    code = pyotp.TOTP(secret).now()
    c1, c2 = factory.anon(), factory.anon()
    t1 = _login(c1, "replayer").json()["mfa_token"]
    assert c1.post("/api/v1/auth/login/mfa", headers=H, json={"mfa_token": t1, "code": code}).status_code == 200
    t2 = _login(c2, "replayer").json()["mfa_token"]
    r = c2.post("/api/v1/auth/login/mfa", headers=H, json={"mfa_token": t2, "code": code})
    assert r.status_code == 401  # same code, different attacker session


def test_recovery_code_single_use_and_audited(factory, db):
    u = factory.user("recoverer")
    _, codes = _enable_totp(u)
    assert len(codes) == 10 and len(set(codes)) == 10
    c = factory.anon()
    tok = _login(c, "recoverer").json()["mfa_token"]
    assert c.post("/api/v1/auth/login/mfa", headers=H, json={"mfa_token": tok, "code": codes[0]}).status_code == 200
    c2 = factory.anon()
    tok = _login(c2, "recoverer").json()["mfa_token"]
    assert c2.post("/api/v1/auth/login/mfa", headers=H, json={"mfa_token": tok, "code": codes[0]}).status_code == 401
    assert db.scalar(select(AuditLog).where(AuditLog.action == "account.recovery_code_used")) is not None
    stored = db.execute(select(RecoveryCode.code_hash).where(RecoveryCode.user_id == u.id)).scalars().all()
    assert all(codes[0].encode() not in h for h in stored)  # hashed at rest


def test_totp_secret_encrypted_at_rest(factory, db):
    u = factory.user("encrypted")
    secret, _ = _enable_totp(u)
    blob = db.scalar(select(User.totp_secret_enc).where(User.id == u.id))
    assert secret.encode() not in blob


def test_disable_mfa_requires_fresh_code(factory):
    u = factory.user("disabler")
    _enable_totp(u)
    u.reauth()
    r = u.post("/api/v1/account/mfa/totp/disable", json={"code": "000000"})
    assert r.status_code == 401
    r = u.post("/api/v1/account/mfa/totp/disable", json={"code": u.totp()})
    assert r.status_code == 200, r.text


def test_staff_cannot_disable_mfa(factory):
    m = factory.user("modmfa", role="moderator", mfa=True)
    m.reauth()
    r = m.post("/api/v1/account/mfa/totp/disable", json={"code": m.totp()})
    assert r.status_code == 403


def test_reauth_requires_mfa_when_enabled(factory):
    u = factory.user("stepper", mfa=True)
    r = u.post("/api/v1/auth/reauth", json={"password": PASSWORD})
    assert r.status_code == 401 and r.json()["error"]["code"] == "mfa_required"


# ============================================================ password change
def test_password_change_rotates_session_and_kills_others(factory):
    u = factory.user("changer")
    from tests.helpers import UserCtx, make_client
    other = UserCtx(make_client(factory.app), "changer", u.id, None, PASSWORD)
    factory.login(other)
    r = u.post("/api/v1/account/password", json={"current_password": PASSWORD,
                                                 "new_password": "my new long passphrase"})
    assert r.status_code == 200
    u.csrf = r.json()["csrf_token"]
    assert u.get("/api/v1/auth/session").json()["authenticated"] is True
    assert other.get("/api/v1/auth/session").json()["authenticated"] is False


def test_password_change_wrong_current(factory):
    u = factory.user("wrongcur")
    r = u.post("/api/v1/account/password", json={"current_password": "not it at all!!",
                                                 "new_password": "my new long passphrase"})
    assert r.status_code == 401


# ============================================================ API tokens [T-PAT-SCOPE]
def test_pat_lifecycle_and_scope_enforcement(factory, db):
    u = factory.user("tokenuser")
    # a fresh login counts as recent authentication; an old session does not
    from app.models import Session as S
    db.execute(update(S).where(S.user_id == u.id).values(reauth_at=datetime.now(timezone.utc) - timedelta(hours=1)))
    db.commit()
    r = u.post("/api/v1/account/tokens", json={"name": "ci", "scopes": ["read"]})
    assert r.status_code == 403 and r.json()["error"]["code"] == "reauth_required"
    u.reauth()
    r = u.post("/api/v1/account/tokens", json={"name": "ci", "scopes": ["read"]})
    assert r.status_code == 201, r.text
    raw = r.json()["token"]
    assert raw.startswith("bst_")
    assert db.scalar(select(ApiToken.token_hash).where(ApiToken.user_id == u.id)) != raw.encode()
    api = factory.anon()
    auth = {"Authorization": f"Bearer {raw}"}
    assert api.get("/api/v1/me", headers=auth).status_code == 200
    # [E-3] PATs never reach account-security endpoints
    assert api.get("/api/v1/account/sessions", headers=auth).status_code == 403
    assert api.post("/api/v1/account/tokens", headers=auth,
                    json={"name": "x", "scopes": ["write"]}).status_code == 403
    assert api.get("/api/v1/account/export", headers=auth).status_code == 403
    # cookie + bearer together is ambiguous
    assert u.client.get("/api/v1/me", headers=auth).status_code == 400
    # revoke
    tid = r.json()["id"]
    assert u.delete(f"/api/v1/account/tokens/{tid}").status_code == 204
    assert api.get("/api/v1/me", headers=auth).status_code == 401


def test_pat_invalid_scope_rejected(factory):
    u = factory.user("scoper")
    u.reauth()
    r = u.post("/api/v1/account/tokens", json={"name": "x", "scopes": ["admin"]})
    assert r.status_code == 422


def test_other_users_token_cannot_be_revoked(factory):
    a, b = factory.user("tokA"), factory.user("tokB")
    a.reauth()
    tid = a.post("/api/v1/account/tokens", json={"name": "x", "scopes": ["read"]}).json()["id"]
    assert b.delete(f"/api/v1/account/tokens/{tid}").status_code == 404


def test_garbage_bearer_rejected(client):
    for v in ("Bearer bst_nope", "Bearer " + "a" * 500, "Basic abc", "Bearer"):
        assert client.get("/api/v1/me", headers={"Authorization": v}).status_code == 401


# ============================================================ email change
def test_email_change_flow(factory, db):
    u = factory.user("mover")
    u.reauth()
    assert u.post("/api/v1/account/email", json={"new_email": "Moved@Example.com"}).status_code == 202
    tok = token_from_outbox(db, "moved@example.com", "email_change")
    assert u.post("/api/v1/auth/verify-email", json={"token": tok}).status_code == 200
    db.expire_all()
    assert db.scalar(select(User.email).where(User.id == u.id)) == "moved@example.com"
    from tests.helpers import outbox
    assert any(m.kind == "security" for m in outbox(db, "mover@example.com"))


def test_email_change_to_taken_address_is_silent(factory, db):
    factory.user("owner1")
    u = factory.user("thief1")
    u.reauth()
    r = u.post("/api/v1/account/email", json={"new_email": "owner1@example.com"})
    assert r.status_code == 202
    from tests.helpers import outbox
    assert not [m for m in outbox(db, "owner1@example.com") if m.kind == "email_change"]


# ============================================================ mass assignment [T-AUTHZ-MASS]
@pytest.mark.parametrize("field,value", [("role", "admin"), ("email_verified_at", "2020-01-01"),
                                         ("status", "active"), ("strikes", 0), ("followers_count", 99999),
                                         ("id", str(uuid.uuid4())), ("handle", "newhandle")])
def test_profile_mass_assignment_rejected(factory, field, value):
    u = factory.user("massy")
    r = u.patch("/api/v1/me", json={"version": 1, field: value})
    assert r.status_code == 422


def test_profile_optimistic_locking(factory):
    u = factory.user("locker")
    assert u.patch("/api/v1/me", json={"version": 1, "bio": "first"}).status_code == 200
    r = u.patch("/api/v1/me", json={"version": 1, "bio": "stale"})
    assert r.status_code == 409
    assert u.me()["bio"] == "first"


def test_strict_bool_no_coercion(factory):
    u = factory.user("booly")
    assert u.patch("/api/v1/me", json={"version": 1, "is_private": "yes"}).status_code == 422


# ============================================================ export & deletion
def test_export_requires_reauth_and_returns_own_data(factory, db):
    u = factory.user("exporter")
    db.execute(update(DbSession).where(DbSession.user_id == u.id).values(
        reauth_at=datetime.now(timezone.utc) - timedelta(hours=1)))
    db.commit()
    assert u.get("/api/v1/account/export").status_code == 403
    u.reauth()
    r = u.get("/api/v1/account/export")
    assert r.status_code == 200
    data = r.json()
    assert data["profile"]["handle"] == "exporter"
    assert "password_hash" not in r.text and "totp" not in r.text


def test_account_deletion(factory, db):
    u = factory.user("goner")
    u.reauth()
    assert u.post("/api/v1/account/delete", json={"confirm_handle": "wrong"}).status_code == 400
    r = u.post("/api/v1/account/delete", json={"confirm_handle": "GONER"})
    assert r.status_code == 202
    assert u.get("/api/v1/auth/session").json()["authenticated"] is False
    assert _login(factory.anon(), "goner").status_code == 401
    db.expire_all()
    assert db.scalar(select(User.purge_after).where(User.id == u.id)) is not None


def test_last_admin_cannot_delete_self(factory):
    a = factory.user("soleadmin", role="admin", mfa=True)
    a.reauth()
    r = a.post("/api/v1/account/delete", json={"confirm_handle": "soleadmin"})
    assert r.status_code == 409

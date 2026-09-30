"""Admin & moderation. [T-AUTHZ-VERT] [T-ADMIN-*] [T-ADMIN-AUDIT]"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone

import pytest
from sqlalchemy import select, update

from app.models import AuditLog, User
from app.models import Session as DbSession

A = "/admin/api/v1"


def _stale_stepup(db, uid):
    db.execute(update(DbSession).where(DbSession.user_id == uid).values(
        mfa_at=datetime.now(timezone.utc) - timedelta(hours=1)))
    db.commit()


ADMIN_GETS = ["/me", "/reports", "/users?q=a", "/blocked-domains", "/audit", "/audit/verify"]


@pytest.mark.parametrize("path", ADMIN_GETS)
def test_regular_user_denied_everywhere(factory, path):
    u = factory.user()
    assert u.get(A + path).status_code == 403


@pytest.mark.parametrize("path", ADMIN_GETS)
def test_anonymous_denied(factory, path):
    assert factory.anon().get(A + path).status_code == 401


def test_staff_without_mfa_denied(factory):
    m = factory.user(role="moderator", mfa=False)
    r = m.get(A + "/me")
    assert r.status_code == 403 and r.json()["error"]["code"] == "mfa_required"


def test_stepup_required_and_satisfied_by_totp(factory, db):
    m = factory.user(role="moderator", mfa=True)
    assert m.get(A + "/me").status_code == 200  # fresh MFA login
    _stale_stepup(db, m.id)
    r = m.get(A + "/me")
    assert r.status_code == 403 and r.json()["error"]["code"] == "stepup_required"
    m.reauth()
    assert m.get(A + "/me").status_code == 200


def test_pat_never_reaches_admin_api(factory):
    adm = factory.user(role="admin", mfa=True)
    adm.reauth()
    pat = adm.post("/api/v1/account/tokens", json={"name": "x", "scopes": ["read", "write"]}).json()["token"]
    r = factory.anon().get(A + "/me", headers={"Authorization": f"Bearer {pat}"})
    assert r.status_code == 403 and r.json()["error"]["code"] == "session_required"


def test_admin_mutations_require_csrf(factory):
    adm = factory.user(role="admin", mfa=True)
    r = adm.client.post(A + "/blocked-domains", json={"domain": "x.example", "reason": "spam"},
                        headers={"Origin": "https://testserver"})
    assert r.status_code == 403 and r.json()["error"]["code"] == "csrf_failed"


def test_reason_required(factory):
    adm = factory.user(role="admin", mfa=True)
    assert adm.post(A + "/blocked-domains", json={"domain": "x.example"}).status_code == 422
    assert adm.post(A + "/blocked-domains", json={"domain": "x.example", "reason": ""}).status_code == 422


# ------------------------------------------------------------------ vertical escalation
def test_moderator_cannot_change_roles(factory):
    m = factory.user(role="moderator", mfa=True)
    target = factory.user(mfa=True)
    r = m.post(f"{A}/users/{target.id}/role", json={"role": "admin", "reason": "x" * 5, "confirm": target.handle})
    assert r.status_code == 403
    r = m.post(f"{A}/users/{m.id}/role", json={"role": "admin", "reason": "self promo", "confirm": m.handle})
    assert r.status_code == 403


def test_moderator_cannot_act_on_equal_or_higher_rank(factory):
    m1 = factory.user(role="moderator", mfa=True)
    m2 = factory.user(role="moderator", mfa=True)
    adm = factory.user(role="admin", mfa=True)
    for t in (m2, adm, m1):
        r = m1.post(f"{A}/users/{t.id}/suspend", json={"reason": "abuse!!", "confirm": t.handle})
        assert r.status_code == 403


def test_admin_cannot_change_own_role_or_demote_last_admin(factory):
    adm = factory.user(role="admin", mfa=True)
    r = adm.post(f"{A}/users/{adm.id}/role", json={"role": "user", "reason": "leaving", "confirm": adm.handle})
    assert r.status_code == 403
    adm2 = factory.user(role="admin", mfa=True)
    assert adm.post(f"{A}/users/{adm2.id}/role", json={"role": "user", "reason": "rotation",
                                                       "confirm": adm2.handle}).status_code == 200
    # adm is now the last admin; nobody else can demote them, and they can't demote themselves


def test_promotion_requires_target_mfa(factory):
    adm = factory.user(role="admin", mfa=True)
    t = factory.user(mfa=False)
    r = adm.post(f"{A}/users/{t.id}/role", json={"role": "moderator", "reason": "promote", "confirm": t.handle})
    assert r.status_code == 409 and r.json()["error"]["code"] == "target_mfa_required"


def test_role_change_requires_typed_confirmation(factory):
    adm = factory.user(role="admin", mfa=True)
    t = factory.user(mfa=True)
    r = adm.post(f"{A}/users/{t.id}/role", json={"role": "moderator", "reason": "promote", "confirm": "wrong"})
    assert r.status_code == 400


def test_role_change_rotates_target_sessions_and_is_audited(factory, db):
    adm = factory.user(role="admin", mfa=True)
    t = factory.user(mfa=True)
    r = adm.post(f"{A}/users/{t.id}/role", json={"role": "moderator", "reason": "trusted helper",
                                                 "confirm": t.handle})
    assert r.status_code == 200
    assert t.get("/api/v1/auth/session").json()["authenticated"] is False
    entry = db.scalar(select(AuditLog).where(AuditLog.action == "admin.role_changed"))
    assert entry.actor_id == adm.id and entry.details["new_role"] == "moderator"
    assert entry.details["reason"] == "trusted helper"


# ------------------------------------------------------------------ moderation flows
def test_suspend_kills_sessions_tokens_and_login(factory, db):
    m = factory.user(role="moderator", mfa=True)
    bad = factory.user()
    bad.reauth()
    pat = bad.post("/api/v1/account/tokens", json={"name": "x", "scopes": ["read"]}).json()["token"]
    assert m.post(f"{A}/users/{bad.id}/suspend", json={"reason": "spam", "confirm": "nope"}).status_code == 400
    r = m.post(f"{A}/users/{bad.id}/suspend", json={"reason": "spam wave", "confirm": bad.handle, "days": 7})
    assert r.status_code == 200
    assert bad.get("/api/v1/auth/session").json()["authenticated"] is False
    assert factory.anon().get("/api/v1/me", headers={"Authorization": f"Bearer {pat}"}).status_code == 401
    r = factory.anon().post("/api/v1/auth/login", headers={"Origin": "https://testserver"},
                            json={"identifier": bad.handle, "password": bad.password})
    assert r.status_code == 403
    assert db.scalar(select(AuditLog).where(AuditLog.action == "mod.user_suspended")) is not None
    # timed suspension lifts automatically once elapsed
    db.execute(update(User).where(User.id == bad.id).values(suspended_until=datetime.now(timezone.utc)))
    db.commit()
    r = factory.anon().post("/api/v1/auth/login", headers={"Origin": "https://testserver"},
                            json={"identifier": bad.handle, "password": bad.password})
    assert r.status_code == 200


def test_report_to_removal_flow(factory, db):
    m = factory.user(role="moderator", mfa=True)
    author, reporter = factory.user(), factory.user()
    p = author.post("/api/v1/posts", json={"text": "terrible content"}).json()
    reporter.post("/api/v1/reports", json={"target_type": "post", "target_id": p["id"], "reason": "hate"})
    items = m.get(A + "/reports").json()["items"]
    assert len(items) == 1 and items[0]["evidence"]["text"] == "terrible content"
    rid = items[0]["id"]
    assert m.post(f"{A}/posts/{p['id']}/remove", json={"reason": "hate speech"}).status_code == 200
    assert reporter.get(f"/api/v1/posts/{p['id']}").status_code == 404
    assert m.post(f"{A}/reports/{rid}/resolve", json={"outcome": "resolved", "reason": "removed"}).status_code == 200
    assert m.post(f"{A}/reports/{rid}/resolve", json={"outcome": "resolved", "reason": "again"}).status_code == 404
    assert m.post(f"{A}/posts/{p['id']}/restore", json={"reason": "appeal won"}).status_code == 200
    assert reporter.get(f"/api/v1/posts/{p['id']}").status_code == 200


def test_moderator_cannot_remove_admin_posts(factory):
    m = factory.user(role="moderator", mfa=True)
    adm = factory.user(role="admin", mfa=True)
    p = adm.post("/api/v1/posts", json={"text": "announcement"}).json()
    assert m.post(f"{A}/posts/{p['id']}/remove", json={"reason": "nope nope"}).status_code == 403


def test_moderator_cannot_manage_domains_or_audit(factory):
    m = factory.user(role="moderator", mfa=True)
    assert m.get(A + "/audit").status_code == 403
    assert m.post(A + "/blocked-domains", json={"domain": "a.example", "reason": "spam"}).status_code == 403


def test_domain_block_and_audit_chain(factory):
    adm = factory.user(role="admin", mfa=True)
    assert adm.post(A + "/blocked-domains", json={"domain": "Phish.Example", "reason": "phishing"}).status_code == 201
    u = factory.user()
    assert u.post("/api/v1/posts", json={"text": "https://login.phish.example/"}).status_code == 400
    log = adm.get(A + "/audit").json()["items"]
    assert any(e["action"] == "admin.domain_blocked" for e in log)
    assert adm.get(A + "/audit/verify").json()["ok"] is True


def test_admin_user_lookup_hides_email(factory):
    m = factory.user(role="moderator", mfa=True)
    factory.user("lookmeup")
    r = m.get(A + "/users?q=lookme")
    assert r.status_code == 200 and "email\"" not in r.text.replace("email_verified", "")


def test_like_wildcard_in_admin_search(factory):
    m = factory.user(role="moderator", mfa=True)
    factory.user("aaa_x")
    assert m.get(A + "/users?q=%25").json()["items"] == []


# ------------------------------------------------------------------ operator CLI (incident response)
def test_cli_force_reset_and_verify_audit(factory, db, capsys):
    from app import cli
    u = factory.user("forceme")
    assert cli.main(["force-reset", "--handle", "forceme"]) == 0
    assert u.get("/api/v1/auth/session").json()["authenticated"] is False
    r = factory.anon().post("/api/v1/auth/login", headers={"Origin": "https://testserver"},
                            json={"identifier": "forceme", "password": u.password})
    assert r.status_code == 401
    assert cli.main(["verify-audit"]) == 0
    assert "OK" in capsys.readouterr().out


def test_cli_revoke_all_sessions(factory):
    from app import cli
    a, b = factory.user(), factory.user()
    assert cli.main(["revoke-all-sessions"]) == 0
    assert a.get("/api/v1/auth/session").json()["authenticated"] is False
    assert b.get("/api/v1/auth/session").json()["authenticated"] is False


def test_cli_media_rescan_withdraws_tampered_derivative(factory, db):
    import io
    import os

    from PIL import Image
    from sqlalchemy import create_engine

    from app import cli, media_store
    from app.models import Media
    from app.worker import process_one
    u = factory.user()
    b = io.BytesIO()
    Image.new("RGB", (8, 8)).save(b, "PNG")
    mid = u.client.post("/api/v1/media?purpose=post", content=b.getvalue(),
                        headers={"Origin": "https://testserver", "X-CSRF-Token": u.csrf}).json()["id"]
    eng = create_engine(os.environ["WORKER_DATABASE_URL"])
    process_one(eng)
    eng.dispose()
    assert cli.main(["media-rescan"]) == 0
    m = db.get(Media, __import__("uuid").UUID(mid))
    media_store.safe_path("store", m.variants["large"]["key"]).write_bytes(b"<script>alert(1)</script>")
    assert cli.main(["media-rescan"]) == 1
    db.expire_all()
    assert db.get(Media, m.id).state == "failed"
    assert u.get(f"/api/v1/media/{mid}/large").status_code == 404


def test_cli_outbox_refused_in_production(monkeypatch, capsys):
    from app import cli, config
    prod = config.Settings(**{**config.get_settings().__dict__, "env": "production"})
    monkeypatch.setattr(config, "get_settings", lambda: prod)
    assert cli.outbox() == 2


def test_cli_purge_enforces_retention(factory, db):
    from datetime import datetime, timedelta, timezone

    from sqlalchemy import select, update

    from app import cli
    from app.models import Session as S
    from app.models import User
    u = factory.user("purgeme")
    u.reauth()
    u.post("/api/v1/account/delete", json={"confirm_handle": "purgeme"})
    old = datetime.now(timezone.utc) - timedelta(days=100)
    db.execute(update(User).where(User.id == u.id).values(purge_after=datetime.now(timezone.utc) - timedelta(seconds=1)))
    db.execute(update(S).where(S.user_id == u.id).values(revoked_at=old))
    db.commit()
    assert cli.main(["purge"]) == 0
    db.expire_all()
    assert db.get(User, u.id) is None  # hard-deleted, cascades removed owned rows
    assert db.scalar(select(S).where(S.user_id == u.id)) is None

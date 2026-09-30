"""Direct messages and reports. [T-AUTHZ-BOLA-DM] [T-DM-*] [T-REPORT-*]"""
from __future__ import annotations

import uuid

import pytest


def _conv(a, b):
    r = a.post("/api/v1/conversations", json={"handle": b.handle})
    assert r.status_code == 201, r.text
    return r.json()["id"]


def _send(u, cid, text="hi"):
    return u.post(f"/api/v1/conversations/{cid}/messages", json={"text": text})


def test_dm_happy_path(factory):
    a, b = factory.user(), factory.user(dm_policy="everyone")
    cid = _conv(a, b)
    assert _send(a, cid, "hello b").status_code == 201
    msgs = b.get(f"/api/v1/conversations/{cid}/messages").json()["items"]
    assert msgs[0]["text"] == "hello b"
    assert _conv(b, a) == cid  # one conversation per pair


def test_conversation_id_knowledge_grants_nothing(factory):
    """[T-AUTHZ-BOLA-DM] a third party with the conversation id gets 404 on every endpoint."""
    a, b, eve = factory.user(), factory.user(dm_policy="everyone"), factory.user()
    cid = _conv(a, b)
    r = _send(a, cid, "private")
    mid = r.json()["id"]
    assert eve.get(f"/api/v1/conversations/{cid}/messages").status_code == 404
    assert _send(eve, cid, "injected").status_code == 404
    assert eve.delete(f"/api/v1/conversations/{cid}/messages/{mid}").status_code == 404
    assert eve.post(f"/api/v1/conversations/{cid}/read").status_code == 404
    assert eve.delete(f"/api/v1/conversations/{cid}").status_code == 404
    assert eve.post("/api/v1/reports", json={"target_type": "message", "target_id": mid,
                                             "reason": "spam"}).status_code == 404
    assert all(c["id"] != cid for c in eve.get("/api/v1/conversations").json()["items"])
    assert eve.get(f"/api/v1/conversations/{uuid.uuid4()}/messages").status_code == 404


def test_dm_policy_following(factory):
    a, b = factory.user(), factory.user(dm_policy="following")
    r = a.post("/api/v1/conversations", json={"handle": b.handle})
    assert r.status_code == 403 and r.json()["error"]["code"] == "dm_not_allowed"
    b.post(f"/api/v1/users/{a.handle}/follow")
    cid = _conv(a, b)
    assert _send(a, cid).status_code == 201
    # b unfollows: a may no longer message... unless b has replied (consent)
    b.delete(f"/api/v1/users/{a.handle}/follow")
    assert _send(a, cid).status_code == 403
    b.post(f"/api/v1/users/{a.handle}/follow")
    assert _send(b, cid, "reply").status_code == 201
    b.delete(f"/api/v1/users/{a.handle}/follow")
    assert _send(a, cid).status_code == 201


def test_dm_policy_nobody(factory):
    a, b = factory.user(), factory.user(dm_policy="nobody")
    assert a.post("/api/v1/conversations", json={"handle": b.handle}).status_code == 403


def test_block_stops_existing_conversation(factory):
    a, b = factory.user(), factory.user(dm_policy="everyone")
    cid = _conv(a, b)
    _send(a, cid)
    _send(b, cid)
    b.post(f"/api/v1/users/{a.handle}/block")
    assert _send(a, cid).status_code == 403
    assert _send(b, cid).status_code == 403  # the blocker cannot keep messaging either


def test_unverified_cannot_dm(factory):
    a, b = factory.user(verified=False), factory.user(dm_policy="everyone")
    assert a.post("/api/v1/conversations", json={"handle": b.handle}).status_code == 403


def test_message_deletion_semantics(factory):
    a, b = factory.user(), factory.user(dm_policy="everyone")
    cid = _conv(a, b)
    mid = _send(a, cid, "oops").json()["id"]
    bm = _send(b, cid, "b's message").json()["id"]
    assert b.delete(f"/api/v1/conversations/{cid}/messages/{mid}").status_code == 404  # not the sender
    assert a.delete(f"/api/v1/conversations/{cid}/messages/{bm}").status_code == 404
    assert a.delete(f"/api/v1/conversations/{cid}/messages/{mid}").status_code == 204
    items = b.get(f"/api/v1/conversations/{cid}/messages").json()["items"]
    tomb = next(i for i in items if i["id"] == mid)
    assert tomb["deleted"] is True and "text" not in tomb
    # message id from another conversation cannot be deleted via this conversation
    c2 = factory.user(dm_policy="everyone")
    cid2 = _conv(a, c2)
    m2 = _send(a, cid2, "x").json()["id"]
    assert a.delete(f"/api/v1/conversations/{cid}/messages/{m2}").status_code == 404


def test_clear_conversation_is_per_user(factory):
    a, b = factory.user(), factory.user(dm_policy="everyone")
    cid = _conv(a, b)
    _send(a, cid, "before clear")
    a.delete(f"/api/v1/conversations/{cid}")
    assert a.get(f"/api/v1/conversations/{cid}/messages").json()["items"] == []
    assert len(b.get(f"/api/v1/conversations/{cid}/messages").json()["items"]) == 1


def test_message_size_limit(factory):
    a, b = factory.user(), factory.user(dm_policy="everyone")
    cid = _conv(a, b)
    assert _send(a, cid, "x" * 2001).status_code == 400
    assert _send(a, cid, "x" * 2000).status_code == 201
    assert _send(a, cid, "   ").status_code == 400


def test_new_account_new_conversation_limit(factory):
    a = factory.user(age_days=0)
    codes = []
    for _ in range(11):
        b = factory.user(dm_policy="everyone")
        codes.append(a.post("/api/v1/conversations", json={"handle": b.handle}).status_code)
    assert codes[:10] == [201] * 10 and codes[10] == 429


def test_cannot_attach_someone_elses_media_to_dm(factory, db):
    from datetime import datetime, timezone

    from app.models import Media
    a, b = factory.user(), factory.user(dm_policy="everyone")
    m = Media(owner_id=b.id, kind="image", purpose="dm", state="ready", sniffed_type="image/png",
              size_bytes=10, sha256=b"\x00" * 32, created_at=datetime.now(timezone.utc))
    db.add(m)
    db.commit()
    cid = _conv(a, b)
    r = a.post(f"/api/v1/conversations/{cid}/messages", json={"text": "x", "media_id": str(m.id)})
    assert r.status_code == 404


# ============================================================ reports
def test_report_dedup_and_evidence(factory, db):
    from app.models import Report
    a, b = factory.user(), factory.user()
    p = b.post("/api/v1/posts", json={"text": "abusive content"}).json()
    for _ in range(3):
        assert a.post("/api/v1/reports", json={"target_type": "post", "target_id": p["id"],
                                               "reason": "harassment", "details": "bad"}).status_code == 202
    rows = db.query(Report).all()
    assert len(rows) == 1 and rows[0].evidence["text"] == "abusive content"
    b.delete(f"/api/v1/posts/{p['id']}")
    db.expire_all()
    assert db.query(Report).one().evidence["text"] == "abusive content"  # evidence survives deletion


def test_cannot_report_invisible_post(factory):
    a, priv = factory.user(), factory.user(private=True)
    p = priv.post("/api/v1/posts", json={"text": "hidden"}).json()
    r = a.post("/api/v1/reports", json={"target_type": "post", "target_id": p["id"], "reason": "spam"})
    assert r.status_code == 404


def test_report_message_only_by_recipient(factory):
    a, b = factory.user(), factory.user(dm_policy="everyone")
    cid = _conv(a, b)
    mid = _send(a, cid, "creepy").json()["id"]
    assert a.post("/api/v1/reports", json={"target_type": "message", "target_id": mid,
                                           "reason": "spam"}).status_code == 404  # own message
    assert b.post("/api/v1/reports", json={"target_type": "message", "target_id": mid,
                                           "reason": "harassment"}).status_code == 202


@pytest.mark.parametrize("reason", ["", "SPAM", "other; DROP TABLE", None])
def test_report_reason_allowlist(factory, reason):
    a, b = factory.user(), factory.user()
    r = a.post("/api/v1/reports", json={"target_type": "user", "handle": b.handle, "reason": reason})
    assert r.status_code == 422


def test_report_rate_limited(factory):
    a = factory.user()
    targets = [factory.user() for _ in range(21)]
    codes = [a.post("/api/v1/reports", json={"target_type": "user", "handle": t.handle,
                                             "reason": "spam"}).status_code for t in targets]
    assert codes[:20] == [202] * 20 and codes[20] == 429

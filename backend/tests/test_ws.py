"""Realtime gateway. [T-WS-*]"""
from __future__ import annotations

import json
import uuid

import pytest
from starlette.websockets import WebSocketDisconnect

ORIGIN = "https://testserver"


def ws_connect(u, origin=ORIGIN, **kw):
    headers = {"Origin": origin} if origin else {}
    cookie = u.client.cookies.get("__Host-bastion_sid")
    if cookie:
        headers["Cookie"] = f"__Host-bastion_sid={cookie}"
    return u.client.websocket_connect("/api/v1/ws", headers=headers, **kw)


def expect_close(ctx, code=None):
    with pytest.raises(WebSocketDisconnect) as ei:
        with ctx as ws:
            for _ in range(50):
                ws.receive_json()
    if code is not None:
        assert ei.value.code == code, ei.value.code


def drain_until(ws, pred, limit=20):
    for _ in range(limit):
        m = ws.receive_json()
        if pred(m):
            return m
    raise AssertionError("expected message not received")


def assert_no_pending(ws):
    """Ordering: anything already queued would arrive before our pong."""
    ws.send_json({"type": "ping"})
    assert ws.receive_json() == {"type": "pong"}


def _dm_pair(factory):
    a, b = factory.user(), factory.user(dm_policy="everyone")
    cid = a.post("/api/v1/conversations", json={"handle": b.handle}).json()["id"]
    return a, b, cid


# ------------------------------------------------------------------ authentication
def test_cookie_auth_with_origin(factory):
    u = factory.user()
    with ws_connect(u) as ws:
        hello = ws.receive_json()
        assert hello["type"] == "hello" and hello["user_id"] == str(u.id)


@pytest.mark.parametrize("origin", [None, "https://evil.example", "null", "https://testserver.evil.example"])
def test_cswsh_bad_origin_rejected(factory, origin):
    """[T-WS-ORIGIN] cross-site WebSocket hijacking."""
    u = factory.user()
    expect_close(ws_connect(u, origin=origin))


def test_unauthenticated_rejected(factory):
    c = factory.anon()
    expect_close(c.websocket_connect("/api/v1/ws", headers={"Origin": ORIGIN}))


def test_ticket_auth_single_use(factory):
    u = factory.user()
    u.reauth()
    pat = u.post("/api/v1/account/tokens", json={"name": "bot", "scopes": ["read"]}).json()["token"]
    api = factory.anon()
    t = api.post("/api/v1/ws/ticket", headers={"Authorization": f"Bearer {pat}"}).json()["ticket"]
    with api.websocket_connect(f"/api/v1/ws?ticket={t}") as ws:
        assert ws.receive_json()["user_id"] == str(u.id)
    expect_close(api.websocket_connect(f"/api/v1/ws?ticket={t}"))  # [replay]


def test_forged_ticket_rejected(factory):
    expect_close(factory.anon().websocket_connect("/api/v1/ws?ticket=" + "A" * 43))


# ------------------------------------------------------------------ delivery & isolation
def test_dm_event_delivered_only_to_members(factory):
    """[T-WS-LEAK] cross-user message access."""
    a, b, cid = _dm_pair(factory)
    eve = factory.user()
    with ws_connect(b) as wb, ws_connect(eve) as we:
        wb.receive_json()
        we.receive_json()
        a.post(f"/api/v1/conversations/{cid}/messages", json={"text": "for b only"})
        m = drain_until(wb, lambda m: m["type"] == "message")
        assert m["message"]["text"] == "for b only" and m["conversation_id"] == cid
        assert_no_pending(we)


def test_subscribe_to_foreign_conversation_denied(factory):
    """[T-WS-SUBSCRIBE]"""
    a, b, cid = _dm_pair(factory)
    eve = factory.user()
    with ws_connect(eve) as we:
        we.receive_json()
        we.send_json({"type": "subscribe", "topic": f"conversation:{cid}"})
        assert we.receive_json() == {"type": "error", "code": "forbidden", "topic": f"conversation:{cid}"}
        we.send_json({"type": "typing", "conversation_id": cid})
        assert we.receive_json()["code"] == "not_subscribed"


@pytest.mark.parametrize("topic", ["user:someone", "conversation:../../x", "*", "conversation:*", 42, None,
                                   "conversation:" + "0" * 100])
def test_bad_topics(factory, topic):
    u = factory.user()
    with ws_connect(u) as ws:
        ws.receive_json()
        ws.send_json({"type": "subscribe", "topic": topic})
        assert ws.receive_json()["code"] == "bad_topic"


def test_typing_is_server_stamped_and_member_only(factory):
    """[T-WS-SPOOF] client-provided identity fields are ignored."""
    a, b, cid = _dm_pair(factory)
    with ws_connect(a) as wa, ws_connect(b) as wb:
        wa.receive_json()
        wb.receive_json()
        for w in (wa, wb):
            w.send_json({"type": "subscribe", "topic": f"conversation:{cid}"})
            assert w.receive_json()["type"] == "subscribed"
        wa.send_json({"type": "typing", "conversation_id": cid, "user_id": str(uuid.uuid4()), "from": "admin"})
        m = drain_until(wb, lambda m: m["type"] == "typing")
        assert m["user_id"] == str(a.id)


def test_block_revokes_typing_topic(factory):
    a, b, cid = _dm_pair(factory)
    with ws_connect(a) as wa, ws_connect(b) as wb:
        wa.receive_json()
        wb.receive_json()
        for w in (wa, wb):
            w.send_json({"type": "subscribe", "topic": f"conversation:{cid}"})
            w.receive_json()
        b.post(f"/api/v1/users/{a.handle}/block")
        assert_no_pending(wa)
        wa.send_json({"type": "typing", "conversation_id": cid})
        assert wa.receive_json()["code"] == "not_subscribed"
        assert_no_pending(wb)


# ------------------------------------------------------------------ protocol abuse
@pytest.mark.parametrize("frame", [
    {"type": "message", "conversation_id": "x", "text": "forged", "from": "victim"},
    {"type": "notification", "recipient": "someone"},
    {"type": "__close__"},
    {"no_type": 1},
])
def test_spoofed_event_types_close_connection(factory, frame):
    u = factory.user()
    with pytest.raises(WebSocketDisconnect) as ei:
        with ws_connect(u) as ws:
            ws.receive_json()
            ws.send_json(frame)
            ws.receive_json()
    assert ei.value.code == 1008


@pytest.mark.parametrize("raw", ['{"type":"ping","type":"subscribe"}', "not json", "[1,2]", '{"type": NaN}'])
def test_malformed_frames_close(factory, raw):
    u = factory.user()
    with pytest.raises(WebSocketDisconnect) as ei:
        with ws_connect(u) as ws:
            ws.receive_json()
            ws.send_text(raw)
            ws.receive_json()
    assert ei.value.code == 1008


def test_binary_frames_refused(factory):
    u = factory.user()
    with pytest.raises(WebSocketDisconnect) as ei:
        with ws_connect(u) as ws:
            ws.receive_json()
            ws.send_bytes(b"\x00\x01")
            ws.receive_json()
    assert ei.value.code == 1003


def test_oversized_frame_closes(factory):
    u = factory.user()
    with pytest.raises(WebSocketDisconnect) as ei:
        with ws_connect(u) as ws:
            ws.receive_json()
            ws.send_text(json.dumps({"type": "ping", "pad": "x" * 5000}))
            ws.receive_json()
    assert ei.value.code == 1009


def test_event_flooding_closes(factory):
    """[T-WS-FLOOD]"""
    u = factory.user()
    with pytest.raises(WebSocketDisconnect) as ei:
        with ws_connect(u) as ws:
            ws.receive_json()
            for _ in range(60):
                ws.send_json({"type": "ping"})
            for _ in range(100):
                ws.receive_json()
    assert ei.value.code == 4429


def test_connection_limit_per_user(factory):
    """[T-WS-CONNLIMIT] connection exhaustion."""
    u = factory.user()
    ctxs = [ws_connect(u) for _ in range(5)]
    socks = [c.__enter__() for c in ctxs]
    try:
        for s in socks:
            s.receive_json()
        expect_close(ws_connect(u))
    finally:
        for c in ctxs:
            c.__exit__(None, None, None)
    with ws_connect(u) as ws:  # slots are released on disconnect
        assert ws.receive_json()["type"] == "hello"


def test_session_revocation_closes_socket(factory):
    u = factory.user()
    from tests.helpers import PASSWORD, UserCtx, make_client
    other = UserCtx(make_client(factory.app), u.handle, u.id, None, PASSWORD)
    factory.login(other)
    with pytest.raises(WebSocketDisconnect) as ei:
        with ws_connect(other) as ws:
            ws.receive_json()
            u.post("/api/v1/account/sessions/revoke-others")
            ws.receive_json()
    assert ei.value.code == 4001


def test_slow_consumer_backpressure():
    """Queue overflow closes the connection rather than buffering without bound."""
    import asyncio

    from app.realtime import QUEUE_MAX, Connection

    async def go():
        c = Connection("c", "u", None, None, asyncio.Queue(maxsize=QUEUE_MAX), asyncio.get_running_loop())
        for i in range(QUEUE_MAX + 5):
            c.offer({"n": i})
        return c
    c = asyncio.run(go())
    assert c.closed and c.close_code == 1013


def test_hub_never_crosses_users():
    import asyncio

    from app.realtime import Connection, Hub

    async def go():
        h = Hub()
        loop = asyncio.get_running_loop()
        a = Connection("a", "user-a", None, None, asyncio.Queue(10), loop)
        b = Connection("b", "user-b", None, None, asyncio.Queue(10), loop)
        h.add(a, "ip", 5, 5, 10)
        h.add(b, "ip", 5, 5, 10)
        h.dispatch({"recipient": "user-a", "event": {"x": 1}})
        h.dispatch({"recipient": ["user-b"], "event": {"x": 2}})   # malformed recipient
        h.dispatch({"recipient": "user-b", "event": "not-a-dict"})
        await asyncio.sleep(0.01)
        return a.queue.qsize(), b.queue.qsize()
    assert asyncio.run(go()) == (1, 0)

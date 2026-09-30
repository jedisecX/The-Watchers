"""Realtime gateway: GET /api/v1/ws (WebSocket) and POST /api/v1/ws/ticket.

Authentication (either):
* browser: the session cookie, **and** an ``Origin`` header in ALLOWED_ORIGINS (CSWSH defence, S-7);
* API clients: ``?ticket=`` from POST /ws/ticket (single-use, 60 s, bound to the session/PAT).

Authorization:
* connection → an active user; per-user (5), per-IP (20) and global connection caps;
* events → only events whose server-computed recipient is this user (see realtime.Hub);
* subscribe → ``conversation:<uuid>`` only, membership checked against the DB, ≤20 topics;
* typing → membership re-checked and blocks honoured at send time, and the actor is stamped by
  the server (client-supplied identity fields are ignored; S-6).

Abuse controls: text frames ≤4 KiB, binary frames refused, token bucket 10 msg/s (burst 20),
typing ≤1 per 2 s per topic, bounded outbound queue (slow consumers are dropped), idle timeout,
session revalidation every 30 s, and revocation events close sockets immediately.

Close codes: 1003 unsupported data, 1008 policy violation, 1009 too big, 1013 overloaded/slow,
4001 session revoked/expired, 4003 account disabled, 4429 rate/connection limit.
"""
from __future__ import annotations

import asyncio
import json
import secrets
import time
import uuid
from datetime import datetime, timezone

from fastapi import APIRouter, Depends, WebSocket
from fastapi.concurrency import run_in_threadpool
from sqlalchemy import select
from sqlalchemy.orm import Session
from starlette.websockets import WebSocketDisconnect

from .. import ratelimit, strictjson
from ..authz import Principal, is_blocked, require
from ..db import get_db, new_session
from ..deps import StrictRoute, enforce_limit, origin_allowed, principal
from ..logging_setup import sec_event
from ..models import ApiToken, ConversationMember, User
from ..models import Session as DbSession
from ..realtime import QUEUE_MAX, Connection, broker, hub
from ..services import sessions, tokens

router = APIRouter(tags=["realtime"], route_class=StrictRoute)

MAX_FRAME = 4096
MAX_TOPICS = 20
PER_USER = 5
PER_IP = 20
GLOBAL_MAX = 10_000
IDLE_TIMEOUT = 120
REVALIDATE_EVERY = 30
BUCKET_RATE, BUCKET_BURST = 10.0, 20.0
CLIENT_TYPES = {"ping", "subscribe", "unsubscribe", "typing"}


@router.post("/api/v1/ws/ticket")
def ws_ticket(p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "read:auth")
    enforce_limit("ws_ticket", str(p.user_id), "anon")
    data = {"session_id": str(p.session.id)} if p.session else {"token_id": str(p.token.id)}
    t = tokens.issue(db, "ws_ticket", p.user_id, data, invalidate_previous=False)
    db.commit()
    return {"ticket": t, "expires_in": 60}


# --------------------------------------------------------------------------- auth helpers (sync, threadpool)


def _auth_cookie(token: str) -> tuple[str, str, None] | None:
    db = new_session()
    try:
        loaded = sessions.load_session(db, token)
        if not loaded:
            return None
        sess, user = loaded
        return str(user.id), str(sess.id), None
    finally:
        db.close()


def _auth_ticket(ticket: str) -> tuple[str, str | None, str | None] | None:
    db = new_session()
    try:
        tok = tokens.consume(db, "ws_ticket", ticket)
        db.commit()
        if tok is None or tok.user_id is None:
            return None
        ok = _still_valid(db, str(tok.user_id), tok.data.get("session_id"), tok.data.get("token_id"))
        return (str(tok.user_id), tok.data.get("session_id"), tok.data.get("token_id")) if ok else None
    finally:
        db.close()


def _still_valid(db: Session, user_id: str, session_id: str | None, token_id: str | None) -> bool:
    now = datetime.now(timezone.utc)
    user = db.get(User, uuid.UUID(user_id))
    if user is None or user.status != "active" or user.deleted_at is not None:
        return False
    if session_id:
        s = db.get(DbSession, uuid.UUID(session_id))
        return bool(s and s.revoked_at is None and s.expires_at > now and s.epoch == user.session_epoch
                    and now - s.last_seen_at <= sessions.idle_limit(user))
    if token_id:
        t = db.get(ApiToken, uuid.UUID(token_id))
        return bool(t and t.revoked_at is None and t.expires_at > now and t.epoch == user.session_epoch)
    return False


def _revalidate(user_id: str, session_id: str | None, token_id: str | None) -> bool:
    db = new_session()
    try:
        return _still_valid(db, user_id, session_id, token_id)
    finally:
        db.close()


def _member_and_peer(user_id: str, conv_id: uuid.UUID) -> tuple[bool, str | None]:
    """Returns (is_member, other_member_id if not blocked)."""
    db = new_session()
    try:
        uid = uuid.UUID(user_id)
        if db.get(ConversationMember, (conv_id, uid)) is None:
            return False, None
        other = db.scalar(select(ConversationMember.user_id).where(
            ConversationMember.conversation_id == conv_id, ConversationMember.user_id != uid))
        if other is None or is_blocked(db, uid, other):
            return True, None
        return True, str(other)
    finally:
        db.close()


def _parse_topic(topic) -> uuid.UUID | None:
    if not isinstance(topic, str) or not topic.startswith("conversation:") or len(topic) > 60:
        return None
    try:
        return uuid.UUID(topic.split(":", 1)[1])
    except ValueError:
        return None


# --------------------------------------------------------------------------- endpoint


@router.websocket("/api/v1/ws")
async def ws_endpoint(ws: WebSocket):
    st = ws.scope.get("state", {})
    ip = st.get("rl_ip", "unknown")
    # Connection-rate limit per IP (before any DB work).
    try:
        if not ratelimit.check("ws_connect_ip", f"ip:{ip}").allowed:
            await ws.close(code=4429)
            return
    except ratelimit.LimiterUnavailable:
        await ws.close(code=1013)
        return

    ticket = ws.query_params.get("ticket")
    cookie = ws.cookies.get(sessions.COOKIE_NAME)
    ident = None
    if ticket:
        ident = await run_in_threadpool(_auth_ticket, ticket[:128])
    elif cookie:
        # Cookie auth is ambient: require an allowed Origin, otherwise any site could open this socket.
        if not origin_allowed(ws.headers.get("origin")):
            sec_event("ws.origin_rejected", ip_prefix=st.get("ip_prefix"))
            await ws.close(code=1008)
            return
        ident = await run_in_threadpool(_auth_cookie, cookie)
    if ident is None:
        await ws.close(code=1008)
        return
    user_id, session_id, token_id = ident

    loop = asyncio.get_running_loop()
    conn = Connection(conn_id=secrets.token_hex(6), user_id=user_id, session_id=session_id, token_id=token_id,
                      queue=asyncio.Queue(maxsize=QUEUE_MAX), loop=loop)
    if not hub.add(conn, ip, PER_USER, PER_IP, GLOBAL_MAX):
        sec_event("ws.connection_limit", user_id=user_id, ip_prefix=st.get("ip_prefix"))
        await ws.close(code=4429)
        return
    await ws.accept()
    try:
        await ws.send_json({"type": "hello", "user_id": user_id, "conn_id": conn.conn_id})
        sender = asyncio.create_task(_sender(ws, conn))
        receiver = asyncio.create_task(_receiver(ws, conn))
        watchdog = asyncio.create_task(_watchdog(conn))
        done, pending = await asyncio.wait({sender, receiver, watchdog}, return_when=asyncio.FIRST_COMPLETED)
        for t in pending:
            t.cancel()
        conn.closed = True
        try:
            await ws.close(code=conn.close_code)
        except RuntimeError:
            pass
    finally:
        hub.remove(conn, ip)


async def _sender(ws: WebSocket, conn: Connection) -> None:
    while True:
        ev = await conn.queue.get()
        if "__close__" in ev:
            conn.close_code = ev["__close__"]
            return
        await ws.send_text(json.dumps(ev))


async def _watchdog(conn: Connection) -> None:
    while not conn.closed:
        await asyncio.sleep(REVALIDATE_EVERY)
        if not await run_in_threadpool(_revalidate, conn.user_id, conn.session_id, conn.token_id):
            conn.close(4001)
            return


async def _receiver(ws: WebSocket, conn: Connection) -> None:
    tokens_ = BUCKET_BURST
    last = time.monotonic()
    last_typing: dict[str, float] = {}
    while True:
        try:
            msg = await asyncio.wait_for(ws.receive(), timeout=IDLE_TIMEOUT)
        except asyncio.TimeoutError:
            conn.close_code = 1000
            return
        if msg["type"] == "websocket.disconnect":
            conn.closed = True
            return
        now = time.monotonic()
        tokens_ = min(BUCKET_BURST, tokens_ + (now - last) * BUCKET_RATE)
        last = now
        tokens_ -= 1
        if tokens_ < 0:
            sec_event("ws.flood", user_id=conn.user_id, conn_id=conn.conn_id)
            conn.close_code = 4429
            return
        if msg.get("bytes") is not None:
            conn.close_code = 1003
            return
        raw = msg.get("text") or ""
        if len(raw.encode()) > MAX_FRAME:
            conn.close_code = 1009
            return
        try:
            data = strictjson.loads(raw.encode())
        except strictjson.StrictJSONError:
            sec_event("ws.protocol_violation", user_id=conn.user_id, reason_code="bad_json")
            conn.close_code = 1008
            return
        t = data.get("type")
        if t not in CLIENT_TYPES:
            # Anything else (e.g. forged {"type":"message","from":...}) is a protocol violation.
            sec_event("ws.protocol_violation", user_id=conn.user_id, reason_code="bad_type")
            conn.close_code = 1008
            return
        if t == "ping":
            conn.offer({"type": "pong"})
        elif t == "subscribe":
            topic = data.get("topic")
            cid = _parse_topic(topic)
            if cid is None or len(conn.topics) >= MAX_TOPICS:
                conn.offer({"type": "error", "code": "bad_topic"})
                continue
            member, _ = await run_in_threadpool(_member_and_peer, conn.user_id, cid)
            if not member:
                sec_event("ws.subscribe_denied", user_id=conn.user_id, target_id=str(cid))
                conn.offer({"type": "error", "code": "forbidden", "topic": topic})
                continue
            conn.topics.add(topic)
            conn.offer({"type": "subscribed", "topic": topic})
        elif t == "unsubscribe":
            conn.topics.discard(data.get("topic"))
        elif t == "typing":
            cid = _parse_topic(f"conversation:{data.get('conversation_id')}")
            topic = f"conversation:{cid}"
            if cid is None or topic not in conn.topics:
                conn.offer({"type": "error", "code": "not_subscribed"})
                continue
            if now - last_typing.get(topic, 0) < 2.0:
                continue
            last_typing[topic] = now
            member, other = await run_in_threadpool(_member_and_peer, conn.user_id, cid)
            if not member:
                conn.topics.discard(topic)
                continue
            if other:
                broker.publish_user(other, {"type": "typing", "conversation_id": str(cid), "user_id": conn.user_id},
                                    topic=topic)



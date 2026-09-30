"""Realtime fan-out (THREAT-MODEL S-6, S-7, I-6, D-4).

Design choices that remove whole attack classes:

* **The server decides recipients.** Handlers publish ``(recipient_user_id, event)`` *after* the
  DB commit and *after* authorization. There are no client-named channels to subscribe to
  that could leak another user's data.
* **Per-connection delivery re-checks** ``recipient == connection.user_id``, so a broker bug or a
  poisoned pub/sub payload cannot cross users.
* **Opt-in topics** (conversation typing indicators) are authorised at subscribe time against
  the DB, and the membership is re-checked when the typing event is produced.
* **Bounded queues**: a slow consumer is disconnected, never allowed to grow memory.
* **Control events** (session revoked, user suspended) close affected sockets immediately.
"""
from __future__ import annotations

import asyncio
import json
import logging
import threading
import uuid
from dataclasses import dataclass, field
from typing import Any

from .config import get_settings

log = logging.getLogger("bastion.realtime")

QUEUE_MAX = 256
EVENTS_CHANNEL = "bastion:events"
CONTROL_CHANNEL = "bastion:control"


@dataclass(eq=False)
class Connection:
    conn_id: str
    user_id: str
    session_id: str | None
    token_id: str | None
    queue: asyncio.Queue
    loop: asyncio.AbstractEventLoop
    topics: set[str] = field(default_factory=set)
    closed: bool = False
    close_code: int = 1000

    def offer(self, event: dict) -> None:
        """Called on the connection's event loop."""
        if self.closed:
            return
        try:
            self.queue.put_nowait(event)
        except asyncio.QueueFull:
            # Backpressure: never buffer unboundedly for a slow or malicious reader.
            self.close(1013)

    def close(self, code: int) -> None:
        if not self.closed:
            self.closed = True
            self.close_code = code
            try:
                self.queue.put_nowait({"__close__": code})
            except asyncio.QueueFull:
                pass


class Hub:
    """In-process registry of live connections."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self.by_user: dict[str, set[Connection]] = {}
        self.by_ip: dict[str, int] = {}
        self.total = 0

    def add(self, c: Connection, ip: str, per_user: int, per_ip: int, global_max: int) -> bool:
        with self._lock:
            if self.total >= global_max:
                return False
            if len(self.by_user.get(c.user_id, ())) >= per_user or self.by_ip.get(ip, 0) >= per_ip:
                return False
            self.by_user.setdefault(c.user_id, set()).add(c)
            self.by_ip[ip] = self.by_ip.get(ip, 0) + 1
            self.total += 1
            return True

    def remove(self, c: Connection, ip: str) -> None:
        with self._lock:
            conns = self.by_user.get(c.user_id)
            if conns and c in conns:
                conns.discard(c)
                if not conns:
                    self.by_user.pop(c.user_id, None)
                self.by_ip[ip] = max(0, self.by_ip.get(ip, 1) - 1)
                if not self.by_ip[ip]:
                    self.by_ip.pop(ip, None)
                self.total -= 1

    def _snapshot(self, user_id: str) -> list[Connection]:
        with self._lock:
            return list(self.by_user.get(user_id, ()))

    def _all(self) -> list[Connection]:
        with self._lock:
            return [c for conns in self.by_user.values() for c in conns]

    def dispatch(self, envelope: dict) -> None:
        recipient = envelope.get("recipient")
        event = envelope.get("event")
        topic = envelope.get("topic")
        if not isinstance(recipient, str) or not isinstance(event, dict):
            return
        for c in self._snapshot(recipient):
            if c.user_id != recipient:  # defence in depth: never cross users
                continue
            if topic and topic not in c.topics:
                continue
            c.loop.call_soon_threadsafe(c.offer, event)

    def control(self, msg: dict) -> None:
        t = msg.get("type")
        for c in self._all():
            if t == "session_revoked" and c.session_id == msg.get("session_id"):
                c.loop.call_soon_threadsafe(c.close, 4001)
            elif t == "user_sessions_revoked" and c.user_id == msg.get("user_id") \
                    and c.session_id != msg.get("except_session_id"):
                c.loop.call_soon_threadsafe(c.close, 4001)
            elif t == "user_disabled" and c.user_id == msg.get("user_id"):
                c.loop.call_soon_threadsafe(c.close, 4003)
            elif t == "topic_revoked" and c.user_id == msg.get("user_id"):
                topic = msg.get("topic")
                c.loop.call_soon_threadsafe(c.topics.discard, topic)
            elif t == "topic_revoked_pair" and c.user_id in (msg.get("a"), msg.get("b")):
                # A block between two users: drop all conversation topics on both sides; clients
                # must re-subscribe, which re-runs the DB authorization check.
                c.loop.call_soon_threadsafe(c.topics.clear)


hub = Hub()


class LocalBroker:
    def publish_user(self, recipient: uuid.UUID | str, event: dict, topic: str | None = None) -> None:
        hub.dispatch({"recipient": str(recipient), "event": event, "topic": topic})

    def publish_control(self, msg: dict) -> None:
        hub.control(msg)

    def start(self) -> None:
        pass


class RedisBroker:
    """Cross-instance fan-out via Redis pub/sub. Payloads are re-validated on receipt."""

    def __init__(self, url: str) -> None:
        import redis

        self._r = redis.Redis.from_url(url, socket_timeout=2, socket_connect_timeout=2)
        self._started = False

    def publish_user(self, recipient: uuid.UUID | str, event: dict, topic: str | None = None) -> None:
        try:
            self._r.publish(EVENTS_CHANNEL, json.dumps({"recipient": str(recipient), "event": event, "topic": topic}))
        except Exception:  # noqa: BLE001 - realtime is best-effort; clients also poll
            log.warning("realtime.publish_failed", extra={"event": "realtime.publish_failed"})

    def publish_control(self, msg: dict) -> None:
        try:
            self._r.publish(CONTROL_CHANNEL, json.dumps(msg))
        except Exception:  # noqa: BLE001
            log.error("realtime.control_publish_failed", extra={"event": "realtime.control_failed"})
            hub.control(msg)  # at least enforce locally

    def start(self) -> None:
        if self._started:
            return
        self._started = True
        threading.Thread(target=self._run, name="redis-subscriber", daemon=True).start()

    def _run(self) -> None:  # pragma: no cover - exercised in integration environments
        import time

        while True:
            try:
                ps = self._r.pubsub(ignore_subscribe_messages=True)
                ps.subscribe(EVENTS_CHANNEL, CONTROL_CHANNEL)
                for m in ps.listen():
                    try:
                        data = json.loads(m["data"])
                    except (ValueError, TypeError):
                        continue
                    if not isinstance(data, dict):
                        continue
                    ch = m["channel"].decode() if isinstance(m["channel"], bytes) else m["channel"]
                    (hub.control if ch == CONTROL_CHANNEL else hub.dispatch)(data)
            except Exception:  # noqa: BLE001
                log.warning("realtime.subscriber_reconnect", extra={"event": "realtime.reconnect"})
                time.sleep(1)


class _BrokerProxy:
    _impl: Any = None

    def _get(self):
        if self._impl is None:
            url = get_settings().redis_url
            self._impl = RedisBroker(url) if url else LocalBroker()
        return self._impl

    def publish_user(self, recipient, event, topic=None):
        self._get().publish_user(recipient, event, topic)

    def publish_control(self, msg):
        self._get().publish_control(msg)

    def start(self):
        self._get().start()

    def use(self, impl) -> None:
        self._impl = impl


broker = _BrokerProxy()

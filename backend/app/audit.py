"""Tamper-evident audit log.

* ``row_hash = SHA-256(prev_hash || canonical_json(fields))``, which forms a hash chain.
* Appends are serialised with a transaction-scoped advisory lock so the chain never forks.
* The audit row is written in the *same transaction* as the action. If the audit insert
  fails, the privileged action rolls back (THREAT-MODEL §9).
* The DB role used by the app has INSERT/SELECT only, and a trigger rejects UPDATE/DELETE/
  TRUNCATE even for the table owner (migration 0002).
* ``verify_chain`` recomputes the chain and returns the first broken sequence number.
"""
from __future__ import annotations

import hashlib
import json
import uuid
from datetime import datetime, timezone
from typing import Any

from sqlalchemy import select, text
from sqlalchemy.orm import Session

from .logging_setup import sec_event
from .models import AuditLog

GENESIS = b"\x00" * 32
_LOCK_KEY = 0x6261737469_6F6E  # "bastion"


def _clean(v: Any) -> Any:
    if isinstance(v, dict):
        return {str(k)[:64]: _clean(x) for k, x in list(v.items())[:30]}
    if isinstance(v, (list, tuple)):
        return [_clean(x) for x in list(v)[:30]]
    if isinstance(v, bool) or v is None or isinstance(v, int):
        return v
    return str(v)[:500]


def _canonical(fields: dict[str, Any]) -> bytes:
    return json.dumps(fields, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode()


def _fields(row_like: dict[str, Any]) -> dict[str, Any]:
    return {
        "created_at": row_like["created_at"].astimezone(timezone.utc).isoformat(),
        "actor_id": str(row_like["actor_id"]) if row_like["actor_id"] else None,
        "actor_role": row_like["actor_role"],
        "action": row_like["action"],
        "target_type": row_like["target_type"],
        "target_id": row_like["target_id"],
        "ip_prefix": row_like["ip_prefix"],
        "request_id": row_like["request_id"],
        "details": row_like["details"],
    }


def compute_hash(prev: bytes, fields: dict[str, Any]) -> bytes:
    return hashlib.sha256(prev + _canonical(fields)).digest()


def record(
    db: Session,
    *,
    action: str,
    actor_id: uuid.UUID | None,
    actor_role: str = "user",
    target_type: str = "",
    target_id: str | uuid.UUID = "",
    ip_prefix: str = "",
    request_id: str = "",
    details: dict[str, Any] | None = None,
) -> AuditLog:
    """Append an audit entry inside the caller's transaction. Caller commits."""
    db.execute(text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_KEY})
    prev = db.execute(select(AuditLog.row_hash).order_by(AuditLog.seq.desc()).limit(1)).scalar_one_or_none()
    prev = prev or GENESIS
    row = {
        "created_at": datetime.now(timezone.utc),
        "actor_id": actor_id,
        "actor_role": actor_role,
        "action": action[:64],
        "target_type": target_type[:32],
        "target_id": str(target_id)[:64],
        "ip_prefix": ip_prefix[:64],
        "request_id": request_id[:64],
        "details": _clean(details or {}),
    }
    entry = AuditLog(**row, prev_hash=prev, row_hash=compute_hash(prev, _fields(row)))
    db.add(entry)
    db.flush()
    sec_event(f"audit.{action}", actor_id=str(actor_id) if actor_id else None,
              target_type=target_type, target_id=str(target_id))
    return entry


def verify_chain(db: Session, batch: int = 1000) -> tuple[bool, int | None, int]:
    """Returns (ok, first_bad_seq, rows_checked)."""
    prev = GENESIS
    last_seq = 0
    checked = 0
    while True:
        rows = db.execute(
            select(AuditLog).where(AuditLog.seq > last_seq).order_by(AuditLog.seq).limit(batch)
        ).scalars().all()
        if not rows:
            return True, None, checked
        for r in rows:
            fields = _fields({
                "created_at": r.created_at, "actor_id": r.actor_id, "actor_role": r.actor_role,
                "action": r.action, "target_type": r.target_type, "target_id": r.target_id,
                "ip_prefix": r.ip_prefix, "request_id": r.request_id, "details": r.details,
            })
            if r.prev_hash != prev or r.row_hash != compute_hash(prev, fields):
                return False, r.seq, checked
            prev = r.row_hash
            last_seq = r.seq
            checked += 1

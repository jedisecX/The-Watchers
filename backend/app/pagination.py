"""Keyset pagination with strictly-typed cursors.

Cursors are base64url(JSON [iso-timestamp, uuid]). They are *not* trusted: any decode/type error
is a 400. A cursor only positions the scan; the authorization/visibility predicates are always
applied by the query, so a forged cursor cannot widen what the caller sees (THREAT-MODEL T-1).
"""
from __future__ import annotations

import base64
import json
import uuid
from datetime import datetime

from fastapi import Query
from sqlalchemy import and_, or_

from .errors import ApiError

MAX_LIMIT = 50


class Page:
    def __init__(self, cursor: str | None = Query(default=None, max_length=200),
                 limit: int = Query(default=20, ge=1, le=MAX_LIMIT)):
        self.limit = limit
        self.after = decode(cursor) if cursor else None


def encode(ts: datetime, id_: uuid.UUID) -> str:
    return base64.urlsafe_b64encode(json.dumps([ts.isoformat(), str(id_)]).encode()).rstrip(b"=").decode()


def decode(cursor: str) -> tuple[datetime, uuid.UUID]:
    try:
        raw = base64.urlsafe_b64decode(cursor + "=" * (-len(cursor) % 4))
        ts_s, id_s = json.loads(raw)
        ts = datetime.fromisoformat(ts_s)
        if ts.tzinfo is None:
            raise ValueError
        return ts, uuid.UUID(id_s)
    except (ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ApiError(400, "bad_cursor", "Invalid pagination cursor.") from exc


def keyset_desc(q, ts_col, id_col, page: Page):
    if page.after:
        ts, id_ = page.after
        q = q.where(or_(ts_col < ts, and_(ts_col == ts, id_col < id_)))
    return q.order_by(ts_col.desc(), id_col.desc()).limit(page.limit + 1)


def keyset_asc(q, ts_col, id_col, page: Page):
    if page.after:
        ts, id_ = page.after
        q = q.where(or_(ts_col > ts, and_(ts_col == ts, id_col > id_)))
    return q.order_by(ts_col.asc(), id_col.asc()).limit(page.limit + 1)


def finish(rows: list, page: Page, key) -> tuple[list, str | None]:
    """``key(row) -> (ts, id)``; returns (items, next_cursor)."""
    more = len(rows) > page.limit
    rows = rows[: page.limit]
    nxt = encode(*key(rows[-1])) if more and rows else None
    return rows, nxt

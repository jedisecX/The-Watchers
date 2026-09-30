"""Abuse reports: POST /api/v1/reports

| Auth | Permission | Rate limit | Authorization |
|---|---|---|---|
| yes | report:create (verified) | report | reporter must be able to *see* the target: post visible, user visible, or member of the message's conversation |

Deduplicated: one open report per (reporter, target). A snapshot of the reported content is stored
as evidence so that later deletion by the author cannot destroy it. For messages, only the single
reported message is captured, never the whole conversation (privacy).
"""
from __future__ import annotations

import uuid
from typing import Literal

from fastapi import APIRouter, Depends
from pydantic import Field

from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.orm import Session

from ..authz import Principal, get_visible_post, get_visible_user, require, require_conversation_member
from ..db import get_db
from ..deps import StrictRoute, enforce_limit, principal
from ..errors import ApiError, not_found
from ..models import REPORT_REASONS, Message, Report, User
from ..schemas import In
from ..text import normalize

router = APIRouter(prefix="/api/v1/reports", tags=["reports"], route_class=StrictRoute)


class ReportIn(In):
    target_type: Literal["user", "post", "message"]
    target_id: uuid.UUID | None = None
    handle: str | None = Field(default=None, max_length=30)
    reason: Literal[REPORT_REASONS]  # type: ignore[valid-type]
    details: str = Field(default="", max_length=2000)


@router.post("", status_code=202)
def create_report(body: ReportIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "report:create")
    enforce_limit("report", str(p.user_id), "anon")
    evidence: dict = {}
    if body.target_type == "user":
        if not body.handle:
            raise ApiError(400, "handle_required", "Specify the account to report.")
        u = get_visible_user(db, p.user_id, body.handle)
        target_id, target_user = u.id, u.id
        evidence = {"handle": u.handle, "display_name": u.display_name, "bio": u.bio}
    elif body.target_type == "post":
        if body.target_id is None:
            raise ApiError(400, "target_required", "Specify the post to report.")
        post, author = get_visible_post(db, p.user_id, body.target_id)
        target_id, target_user = post.id, author.id
        evidence = {"text": post.body, "author": author.handle, "created_at": post.created_at.isoformat()}
    else:
        if body.target_id is None:
            raise ApiError(400, "target_required", "Specify the message to report.")
        msg = db.get(Message, body.target_id)
        if msg is None:
            raise not_found("Message")
        require_conversation_member(db, p.user_id, msg.conversation_id)
        if msg.sender_id == p.user_id or msg.deleted_at is not None:
            raise not_found("Message")
        sender = db.get(User, msg.sender_id)
        target_id, target_user = msg.id, msg.sender_id
        evidence = {"text": msg.body, "sender": sender.handle if sender else None,
                    "created_at": msg.created_at.isoformat()}
    if target_user == p.user_id:
        raise ApiError(400, "cannot_report_self", "You cannot report yourself.")
    db.execute(insert(Report).values(
        reporter_id=p.user_id, target_type=body.target_type, target_id=target_id, target_user_id=target_user,
        reason=body.reason, details=normalize(body.details, multiline=True)[:1000], evidence=evidence,
    ).on_conflict_do_nothing(index_elements=[Report.reporter_id, Report.target_type, Report.target_id],
                             index_where=Report.status == "open"))
    db.commit()
    return {"status": "received"}



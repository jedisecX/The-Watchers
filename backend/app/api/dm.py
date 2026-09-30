"""Direct messages: /api/v1/conversations*

Knowing a conversation id grants nothing: every endpoint calls ``require_conversation_member``
(THREAT-MODEL I-1). 1:1 conversations are unique per pair (``pair_key`` unique constraint).

Consent model (AB-4): a sender may message a recipient if no block exists either way AND
(the recipient has already replied in this conversation OR the recipient's ``dm_policy`` allows
it: ``everyone`` / ``following`` = recipient follows sender / ``nobody``).

| Endpoint | Permission | Rate limit |
|---|---|---|
| POST /conversations {handle} | dm:send | dm_new_conversation (only when newly created) |
| GET /conversations | read:auth | read |
| GET /conversations/{id}/messages | member | read |
| POST /conversations/{id}/messages | dm:send + member + consent | dm_send |
| DELETE /conversations/{id}/messages/{mid} | sender only (in SQL) | dm_send |
| POST /conversations/{id}/read | member | read |
| DELETE /conversations/{id} | member ("delete for me") | read |
"""
from __future__ import annotations

import uuid
from datetime import datetime, timezone

from fastapi import APIRouter, Depends, Response
from pydantic import Field
from sqlalchemy import and_, exists, or_, select, update
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.orm import Session, aliased

from ..authz import (
    Principal,
    assert_can_interact,
    get_visible_user,
    is_blocked,
    is_following,
    require,
    require_conversation_member,
    trust_tier,
)
from ..db import get_db
from ..deps import StrictRoute, enforce_limit, principal
from ..errors import ApiError, ensure, not_found
from ..logging_setup import sec_event
from ..models import Conversation, ConversationMember, Media, Message, User
from ..pagination import Page, finish, keyset_desc
from ..realtime import broker
from ..schemas import In
from ..serializers import iso, media_public, user_mini
from ..text import TextError, parse

router = APIRouter(prefix="/api/v1/conversations", tags=["dm"], route_class=StrictRoute)
MAX_DM_LEN = 2000


class StartIn(In):
    handle: str = Field(min_length=3, max_length=30)


class MessageIn(In):
    text: str = Field(default="", max_length=4000)
    media_id: uuid.UUID | None = None


def _pair_key(a: uuid.UUID, b: uuid.UUID) -> str:
    x, y = sorted([str(a), str(b)])
    return f"{x}:{y}"


def _other_member(db: Session, conv_id: uuid.UUID, me: uuid.UUID) -> User | None:
    return db.scalar(select(User).join(ConversationMember, ConversationMember.user_id == User.id)
                     .where(ConversationMember.conversation_id == conv_id, User.id != me))


def can_message(db: Session, sender: User, recipient: User, conv_id: uuid.UUID | None) -> bool:
    if recipient.status != "active" or recipient.deleted_at is not None:
        return False
    if is_blocked(db, sender.id, recipient.id):
        return False
    if conv_id is not None and db.scalar(select(exists().where(
            Message.conversation_id == conv_id, Message.sender_id == recipient.id))):
        return True  # recipient replied: consent established
    if recipient.dm_policy == "everyone":
        return True
    if recipient.dm_policy == "following":
        return is_following(db, recipient.id, sender.id)
    return False


def _msg_view(m: Message, media: Media | None = None) -> dict:
    if m.deleted_at is not None:
        return {"id": str(m.id), "sender_id": str(m.sender_id), "deleted": True, "created_at": iso(m.created_at)}
    return {"id": str(m.id), "sender_id": str(m.sender_id), "text": m.body, "deleted": False,
            "created_at": iso(m.created_at), "media": media_public(media) if media else None}


@router.post("", status_code=201)
def start_conversation(body: StartIn, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "dm:send")
    ensure(p.user is not None)
    target = get_visible_user(db, p.user_id, body.handle)
    if target.id == p.user.id:
        raise ApiError(400, "cannot_message_self", "You cannot message yourself.")
    assert_can_interact(db, p.user, target.id)
    key = _pair_key(p.user.id, target.id)
    conv = db.scalar(select(Conversation).where(Conversation.pair_key == key))
    if not can_message(db, p.user, target, conv.id if conv else None):
        raise ApiError(403, "dm_not_allowed", "This person isn't accepting messages from you.")
    if conv is None:
        enforce_limit("dm_new_conversation", str(p.user_id), trust_tier(p.user))
        # Race-safe get-or-create on the unique pair key.
        cid = db.execute(insert(Conversation).values(id=uuid.uuid4(), pair_key=key)
                         .on_conflict_do_nothing().returning(Conversation.id)).scalar_one_or_none()
        if cid is None:
            cid = db.scalar(select(Conversation.id).where(Conversation.pair_key == key))
        for uid in (p.user.id, target.id):
            db.execute(insert(ConversationMember).values(conversation_id=cid, user_id=uid).on_conflict_do_nothing())
        db.commit()
    else:
        cid = conv.id
    return {"id": str(cid), "with": user_mini(target)}


@router.get("")
def list_conversations(page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "read:auth")
    enforce_limit("read", str(p.user_id), trust_tier(p.user))
    me_m = aliased(ConversationMember)
    other_m = aliased(ConversationMember)
    other = aliased(User)
    q = (select(Conversation, other, me_m)
         .join(me_m, and_(me_m.conversation_id == Conversation.id, me_m.user_id == p.user_id))
         .join(other_m, and_(other_m.conversation_id == Conversation.id, other_m.user_id != p.user_id))
         .join(other, other.id == other_m.user_id)
         .where(or_(me_m.cleared_at.is_(None), Conversation.last_message_at > me_m.cleared_at)))
    rows = db.execute(keyset_desc(q, Conversation.last_message_at, Conversation.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[0].last_message_at, r[0].id))
    items = [{"id": str(c.id), "with": user_mini(o), "last_message_at": iso(c.last_message_at),
              "unread": m.last_read_at is None or c.last_message_at > m.last_read_at,
              "blocked": is_blocked(db, p.user_id, o.id)} for c, o, m in rows]
    return {"items": items, "next_cursor": nxt}


@router.get("/{conversation_id}/messages")
def list_messages(conversation_id: uuid.UUID, page: Page = Depends(), p: Principal = Depends(principal),
                  db: Session = Depends(get_db)):
    require(p, "read:auth")
    enforce_limit("read", str(p.user_id), trust_tier(p.user))
    member = require_conversation_member(db, p.user_id, conversation_id)
    q = select(Message, Media).outerjoin(Media, and_(Media.id == Message.media_id, Media.state == "ready")) \
        .where(Message.conversation_id == conversation_id)
    if member.cleared_at is not None:
        q = q.where(Message.created_at > member.cleared_at)
    rows = db.execute(keyset_desc(q, Message.created_at, Message.id, page)).all()
    rows, nxt = finish(rows, page, lambda r: (r[0].created_at, r[0].id))
    return {"items": [_msg_view(m, md) for m, md in rows], "next_cursor": nxt}


@router.post("/{conversation_id}/messages", status_code=201)
def send_message(conversation_id: uuid.UUID, body: MessageIn, p: Principal = Depends(principal),
                 db: Session = Depends(get_db)):
    require(p, "dm:send")
    ensure(p.user is not None)
    require_conversation_member(db, p.user.id, conversation_id)
    enforce_limit("dm_send", str(p.user_id), trust_tier(p.user))
    other = _other_member(db, conversation_id, p.user.id)
    if other is None or not can_message(db, p.user, other, conversation_id):
        sec_event("dm.send_denied", user_id=str(p.user_id), target_id=str(conversation_id))
        raise ApiError(403, "dm_not_allowed", "You can't send messages in this conversation.")
    text = ""
    if body.text.strip():
        try:
            text = parse(body.text, max_len=MAX_DM_LEN).text
        except TextError as exc:
            raise ApiError(400, exc.code, exc.message) from exc
    media = None
    if body.media_id is not None:
        media = db.execute(select(Media).where(Media.id == body.media_id).with_for_update()).scalar_one_or_none()
        if media is None or media.owner_id != p.user.id or media.purpose != "dm" or media.state != "ready":
            raise not_found("Media")
        if db.scalar(select(exists().where(Message.media_id == media.id))):
            raise not_found("Media")
    if not text and media is None:
        raise ApiError(400, "empty", "Message must not be empty.")
    now = datetime.now(timezone.utc)
    msg = Message(conversation_id=conversation_id, sender_id=p.user.id, body=text,
                  media_id=media.id if media else None, created_at=now)
    db.add(msg)
    db.execute(update(Conversation).where(Conversation.id == conversation_id).values(last_message_at=now))
    db.execute(update(ConversationMember).where(ConversationMember.conversation_id == conversation_id,
                                                ConversationMember.user_id == p.user.id).values(last_read_at=now))
    db.commit()
    view = _msg_view(msg, media)
    event = {"type": "message", "conversation_id": str(conversation_id), "message": view}
    # Recipients are computed here, server-side, from the membership table.
    broker.publish_user(other.id, event)
    broker.publish_user(p.user.id, event)
    return view


@router.delete("/{conversation_id}/messages/{message_id}", status_code=204)
def delete_message(conversation_id: uuid.UUID, message_id: uuid.UUID, p: Principal = Depends(principal),
                   db: Session = Depends(get_db)):
    """Unsend: sender-only, scoped in SQL. Content is erased for everyone; a tombstone remains."""
    require(p, "dm:send")
    require_conversation_member(db, p.user_id, conversation_id)
    enforce_limit("dm_send", str(p.user_id), trust_tier(p.user))
    res = db.execute(update(Message).where(
        Message.id == message_id, Message.conversation_id == conversation_id,
        Message.sender_id == p.user_id, Message.deleted_at.is_(None))
        .values(deleted_at=datetime.now(timezone.utc), body="", media_id=None))
    if res.rowcount != 1:
        raise not_found("Message")
    db.commit()
    other = _other_member(db, conversation_id, p.user_id)
    event = {"type": "message_deleted", "conversation_id": str(conversation_id), "message_id": str(message_id)}
    if other:
        broker.publish_user(other.id, event)
    broker.publish_user(p.user_id, event)
    return Response(status_code=204)


@router.post("/{conversation_id}/read")
def mark_read(conversation_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "read:auth")
    require_conversation_member(db, p.user_id, conversation_id)
    db.execute(update(ConversationMember).where(ConversationMember.conversation_id == conversation_id,
                                                ConversationMember.user_id == p.user_id)
               .values(last_read_at=datetime.now(timezone.utc)))
    db.commit()
    return {"status": "ok"}


@router.delete("/{conversation_id}", status_code=204)
def clear_conversation(conversation_id: uuid.UUID, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    """'Delete for me': hides history for the caller only; the other member's copy is untouched."""
    require(p, "read:auth")
    require_conversation_member(db, p.user_id, conversation_id)
    db.execute(update(ConversationMember).where(ConversationMember.conversation_id == conversation_id,
                                                ConversationMember.user_id == p.user_id)
               .values(cleared_at=datetime.now(timezone.utc)))
    db.commit()
    return Response(status_code=204)

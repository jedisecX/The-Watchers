"""Social graph mutations: follow / follow requests / block / mute.

| Endpoint | Permission | Rate limit | Notes |
|---|---|---|---|
| POST/DELETE /users/{h}/follow | follow | follow (trust-tiered) | self-follow 400; private → request; blocked → 404 |
| DELETE /users/{h}/follower | follow | follow | remove someone who follows you |
| GET /follow-requests | read:auth | read | incoming only |
| POST /follow-requests/{h}/accept, /reject | follow | follow | only the *target* can accept |
| POST/DELETE /users/{h}/block | block | block_mute | works even if they blocked you |
| POST/DELETE /users/{h}/mute | mute | block_mute | silent |
| GET /blocks, /mutes | read:auth | read | own lists only |
"""
from __future__ import annotations

from fastapi import APIRouter, Depends
from sqlalchemy import delete, select
from sqlalchemy.orm import Session

from ..authz import Principal, active_user_clause, assert_can_interact, get_visible_user, require, trust_tier
from ..db import get_db
from ..deps import StrictRoute, enforce_limit, principal
from ..errors import ApiError, ensure, not_found
from ..logging_setup import sec_event
from ..models import Block, FollowRequest, Mute, User
from ..pagination import Page
from ..serializers import user_mini
from ..services import notify, social

router = APIRouter(prefix="/api/v1", tags=["social"], route_class=StrictRoute)


def _limit(p: Principal, bucket: str) -> None:
    enforce_limit(bucket, str(p.user_id), trust_tier(p.user))


def _active_user(db: Session, handle: str) -> User:
    u = db.scalar(select(User).where(User.handle_norm == handle[:30].lower(), active_user_clause()))
    if not u:
        raise not_found("User")
    return u


@router.post("/users/{handle}/follow")
def follow(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "follow")
    _limit(p, "follow")
    ensure(p.user is not None)
    target = get_visible_user(db, p.user_id, handle)  # 404 if they blocked you
    if target.id == p.user.id:
        raise ApiError(400, "cannot_follow_self", "You cannot follow yourself.")
    assert_can_interact(db, p.user, target.id)  # 404 if you blocked them (unblock first)
    pending = []
    if target.is_private:
        from ..authz import is_following
        if is_following(db, p.user.id, target.id):
            return {"state": "following"}
        if social.request_follow(db, p.user.id, target.id):
            pending.append(notify.create(db, recipient=target.id, actor=p.user, type_="follow_request"))
        db.commit()
        notify.push(pending)
        return {"state": "requested"}
    if social.create_follow(db, p.user.id, target.id):
        pending.append(notify.create(db, recipient=target.id, actor=p.user, type_="follow"))
    db.commit()
    notify.push(pending)
    return {"state": "following"}


@router.delete("/users/{handle}/follow")
def unfollow(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "follow")
    _limit(p, "follow")
    target = _active_user(db, handle)
    social.remove_follow(db, p.user_id, target.id)
    db.execute(delete(FollowRequest).where(FollowRequest.requester_id == p.user_id,
                                           FollowRequest.target_id == target.id))
    db.commit()
    return {"state": "none"}


@router.delete("/users/{handle}/follower")
def remove_follower(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "follow")
    _limit(p, "follow")
    target = _active_user(db, handle)
    social.remove_follow(db, target.id, p.user_id)
    db.commit()
    return {"state": "removed"}


@router.get("/follow-requests")
def follow_requests(page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "read:auth")
    rows = db.execute(select(User, FollowRequest.created_at).join(FollowRequest, FollowRequest.requester_id == User.id)
                      .where(FollowRequest.target_id == p.user_id, active_user_clause())
                      .order_by(FollowRequest.created_at.desc()).limit(page.limit)).all()
    return {"items": [{"user": user_mini(u), "requested_at": ts.isoformat()} for u, ts in rows]}


@router.post("/follow-requests/{handle}/accept")
def accept_request(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "follow")
    ensure(p.user is not None)
    requester = _active_user(db, handle)
    # Only the *target* of a request can accept it: the request row is looked up by (requester, me).
    res = db.execute(delete(FollowRequest).where(FollowRequest.requester_id == requester.id,
                                                 FollowRequest.target_id == p.user.id)
                     .returning(FollowRequest.requester_id)).first()
    if not res:
        raise not_found("Follow request")
    pending = []
    if social.create_follow(db, requester.id, p.user.id):
        pending.append(notify.create(db, recipient=requester.id, actor=p.user, type_="follow_accept"))
    db.commit()
    notify.push(pending)
    return {"state": "accepted"}


@router.post("/follow-requests/{handle}/reject")
def reject_request(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "follow")
    requester = _active_user(db, handle)
    res = db.execute(delete(FollowRequest).where(FollowRequest.requester_id == requester.id,
                                                 FollowRequest.target_id == p.user_id)
                     .returning(FollowRequest.requester_id)).first()
    if not res:
        raise not_found("Follow request")
    db.commit()
    return {"state": "rejected"}


@router.post("/users/{handle}/block")
def block(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "block")
    _limit(p, "block_mute")
    target = _active_user(db, handle)  # deliberately NOT get_visible_user: you may block someone who blocked you
    if target.id == p.user_id:
        raise ApiError(400, "cannot_block_self", "You cannot block yourself.")
    social.block(db, p.user_id, target.id)
    db.commit()
    sec_event("social.block", user_id=str(p.user_id), target_id=str(target.id))
    from ..realtime import broker
    # Drop any typing-indicator subscriptions between the two users.
    broker.publish_control({"type": "topic_revoked_pair", "a": str(p.user_id), "b": str(target.id)})
    return {"state": "blocked"}


@router.delete("/users/{handle}/block")
def unblock(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "block")
    _limit(p, "block_mute")
    target = _active_user(db, handle)
    social.unblock(db, p.user_id, target.id)
    db.commit()
    return {"state": "none"}


@router.post("/users/{handle}/mute")
def mute(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "mute")
    _limit(p, "block_mute")
    target = _active_user(db, handle)
    if target.id == p.user_id:
        raise ApiError(400, "cannot_mute_self", "You cannot mute yourself.")
    social.mute(db, p.user_id, target.id)
    db.commit()
    return {"state": "muted"}


@router.delete("/users/{handle}/mute")
def unmute(handle: str, p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "mute")
    _limit(p, "block_mute")
    target = _active_user(db, handle)
    social.unmute(db, p.user_id, target.id)
    db.commit()
    return {"state": "none"}


@router.get("/blocks")
def list_blocks(page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "read:auth")
    rows = db.execute(select(User).join(Block, Block.blocked_id == User.id).where(Block.blocker_id == p.user_id)
                      .order_by(Block.created_at.desc()).limit(page.limit)).scalars().all()
    return {"items": [user_mini(u) for u in rows]}


@router.get("/mutes")
def list_mutes(page: Page = Depends(), p: Principal = Depends(principal), db: Session = Depends(get_db)):
    require(p, "read:auth")
    rows = db.execute(select(User).join(Mute, Mute.muted_id == User.id).where(Mute.muter_id == p.user_id)
                      .order_by(Mute.created_at.desc()).limit(page.limit)).scalars().all()
    return {"items": [user_mini(u) for u in rows]}


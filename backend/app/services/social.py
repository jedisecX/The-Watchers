"""Social-graph mutations. Every write is idempotent and race-safe:

``INSERT … ON CONFLICT DO NOTHING RETURNING`` and counters are only touched when a row was
actually inserted or deleted, so 50 parallel "follow" requests produce exactly one follow and
+1 on each counter (THREAT-MODEL T-3).
"""
from __future__ import annotations

import uuid

from sqlalchemy import and_, delete, or_, select, update
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.orm import Session

from ..models import Block, Follow, FollowRequest, Mute, User
from . import notify


def create_follow(db: Session, follower: uuid.UUID, followee: uuid.UUID) -> bool:
    if follower == followee:
        return False
    ins = db.execute(
        insert(Follow).values(follower_id=follower, followee_id=followee)
        .on_conflict_do_nothing().returning(Follow.follower_id)
    ).first()
    if not ins:
        return False
    db.execute(update(User).where(User.id == follower).values(following_count=User.following_count + 1))
    db.execute(update(User).where(User.id == followee).values(followers_count=User.followers_count + 1))
    return True


def remove_follow(db: Session, follower: uuid.UUID, followee: uuid.UUID) -> bool:
    res = db.execute(delete(Follow).where(Follow.follower_id == follower, Follow.followee_id == followee)
                     .returning(Follow.follower_id)).first()
    if not res:
        return False
    db.execute(update(User).where(User.id == follower).values(following_count=User.following_count - 1))
    db.execute(update(User).where(User.id == followee).values(followers_count=User.followers_count - 1))
    return True


def remove_all_follows(db: Session, user_id: uuid.UUID) -> None:
    for (other,) in db.execute(select(Follow.followee_id).where(Follow.follower_id == user_id)).all():
        remove_follow(db, user_id, other)
    for (other,) in db.execute(select(Follow.follower_id).where(Follow.followee_id == user_id)).all():
        remove_follow(db, other, user_id)
    db.execute(delete(FollowRequest).where(or_(FollowRequest.requester_id == user_id,
                                               FollowRequest.target_id == user_id)))


def request_follow(db: Session, requester: uuid.UUID, target: uuid.UUID) -> bool:
    ins = db.execute(insert(FollowRequest).values(requester_id=requester, target_id=target)
                     .on_conflict_do_nothing().returning(FollowRequest.requester_id)).first()
    return bool(ins)


def block(db: Session, blocker: uuid.UUID, blocked: uuid.UUID) -> bool:
    ins = db.execute(insert(Block).values(blocker_id=blocker, blocked_id=blocked)
                     .on_conflict_do_nothing().returning(Block.blocker_id)).first()
    # A block severs the relationship in both directions and clears pending requests.
    remove_follow(db, blocker, blocked)
    remove_follow(db, blocked, blocker)
    db.execute(delete(FollowRequest).where(or_(
        and_(FollowRequest.requester_id == blocker, FollowRequest.target_id == blocked),
        and_(FollowRequest.requester_id == blocked, FollowRequest.target_id == blocker))))
    notify.purge_between(db, blocker, blocked)
    return bool(ins)


def unblock(db: Session, blocker: uuid.UUID, blocked: uuid.UUID) -> bool:
    return bool(db.execute(delete(Block).where(Block.blocker_id == blocker, Block.blocked_id == blocked)
                           .returning(Block.blocker_id)).first())


def mute(db: Session, muter: uuid.UUID, muted: uuid.UUID) -> bool:
    return bool(db.execute(insert(Mute).values(muter_id=muter, muted_id=muted)
                           .on_conflict_do_nothing().returning(Mute.muter_id)).first())


def unmute(db: Session, muter: uuid.UUID, muted: uuid.UUID) -> bool:
    return bool(db.execute(delete(Mute).where(Mute.muter_id == muter, Mute.muted_id == muted)
                           .returning(Mute.muter_id)).first())

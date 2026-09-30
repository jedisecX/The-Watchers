"""Phases 3-5: social graph, posts, privacy, business logic, race conditions.
[T-AUTHZ-BOLA-*] [T-AUTHZ-HORIZ] [T-PRIV-ALTPATH] [T-RACE-*] [T-XSS-*] [T-INJ-SQL] [T-REDOS]"""
from __future__ import annotations

import time
import uuid
from concurrent.futures import ThreadPoolExecutor

import pytest
from sqlalchemy import func, select

from app.models import Follow, Like, Post, User

# ============================================================ posts basics


def _post(u, text="hello world", **kw):
    r = u.post("/api/v1/posts", json={"text": text, **kw})
    assert r.status_code == 201, r.text
    return r.json()


def test_create_and_read_post_with_entities(factory):
    a = factory.user("author")
    factory.user("friend")
    p = _post(a, "hi @friend see https://example.com/path?q=1 #Security and #bastion")
    kinds = [s["t"] for s in p["segments"]]
    assert kinds.count("mention") == 1 and kinds.count("link") == 1 and kinds.count("tag") == 2
    link = next(s for s in p["segments"] if s["t"] == "link")
    assert link["url"] == "https://example.com/path?q=1"
    anon = factory.anon()
    assert anon.get(f"/api/v1/posts/{p['id']}").json()["id"] == p["id"]


@pytest.mark.parametrize("payload", [
    "<script>alert(1)</script>",
    "<img src=x onerror=alert(1)>",
    "[click](javascript:alert(1))",
    "\"><svg/onload=alert(1)>",
    "{{7*7}} ${7*7} <%= 7*7 %>",
])
def test_xss_payloads_are_inert_plaintext(factory, payload):
    """[T-XSS-STORED] Stored as plaintext segments; no HTML is ever produced by the API."""
    a = factory.user()
    p = _post(a, payload)
    assert all(s["t"] == "text" for s in p["segments"])
    assert "".join(s["v"] for s in p["segments"]) == payload  # exact, un-rendered, un-evaluated
    assert p["text"] == payload


@pytest.mark.parametrize("url", ["javascript:alert(1)", "data:text/html,<script>", "vbscript:x",
                                 "https://user:pass@evil.example/", "http://-bad-.", "file:///etc/passwd"])
def test_unsafe_links_never_become_link_entities(factory, url):
    a = factory.user()
    p = _post(a, f"look {url}")
    assert not [s for s in p["segments"] if s["t"] == "link"]


def test_invisible_and_bidi_characters_stripped(factory):
    a = factory.user()
    p = _post(a, "safe\u202etxt.exe\u200b and \u2066x\u2069 \x07\x1bok")
    assert "\u202e" not in p["text"] and "\u200b" not in p["text"] and "\x07" not in p["text"] and "\x1b" not in p["text"]
    # NUL is rejected outright at the JSON boundary (ADV-01)
    assert a.post("/api/v1/posts", json={"text": "a\x00b"}).status_code == 400


def test_post_length_enforced_after_normalisation(factory):
    a = factory.user()
    assert a.post("/api/v1/posts", json={"text": "x" * 501}).status_code == 400
    assert a.post("/api/v1/posts", json={"text": "x" * 500}).status_code == 201


def test_emoji_zwj_preserved(factory):
    a = factory.user()
    fam = "\U0001F468‍\U0001F469‍\U0001F467"
    assert _post(a, f"family {fam}")["text"].endswith(fam)


def test_redos_resistance():
    """[T-REDOS] adversarial inputs parse in linear time."""
    from app.text import parse
    for s in ("#" * 499, "@" * 499, "http://" + "a." * 240, "a" * 499, "#a" * 249, "https://" + "/" * 490):
        t = time.perf_counter()
        try:
            parse(s, max_len=500)
        except Exception:
            pass
        assert time.perf_counter() - t < 0.05


def test_blocked_domain_rejected(factory, db):
    from app.models import BlockedDomain
    db.add(BlockedDomain(domain="evil.example", reason="phishing"))
    db.commit()
    a = factory.user()
    for u in ("https://evil.example/x", "https://sub.evil.example/", "https://EVIL.example"):
        r = a.post("/api/v1/posts", json={"text": f"go {u}"})
        assert r.status_code == 400 and r.json()["error"]["code"] == "blocked_link"


def test_duplicate_content_spam_limited(factory):
    a = factory.user()
    codes = [a.post("/api/v1/posts", json={"text": "BUY NOW cheap"}).status_code for _ in range(4)]
    assert codes == [201, 201, 201, 429]


def test_unverified_user_cannot_post(factory):
    u = factory.user(verified=False)
    r = u.post("/api/v1/posts", json={"text": "hi"})
    assert r.status_code == 403 and r.json()["error"]["code"] == "email_unverified"


def test_new_account_post_rate_limit(factory):
    u = factory.user(age_days=0)  # 'new' trust tier: 10/hour
    codes = [u.post("/api/v1/posts", json={"text": f"post {i}"}).status_code for i in range(11)]
    assert codes[:10] == [201] * 10 and codes[10] == 429


# ============================================================ horizontal authz [T-AUTHZ-HORIZ]
def test_cannot_delete_another_users_post(factory):
    a, b = factory.user(), factory.user()
    p = _post(a)
    assert b.delete(f"/api/v1/posts/{p['id']}").status_code == 404
    assert a.get(f"/api/v1/posts/{p['id']}").status_code == 200
    assert a.delete(f"/api/v1/posts/{p['id']}").status_code == 204
    assert a.get(f"/api/v1/posts/{p['id']}").status_code == 404
    assert a.delete(f"/api/v1/posts/{p['id']}").status_code == 404  # replay


def test_no_endpoint_edits_other_profiles(factory):
    a, b = factory.user(), factory.user()
    for m in ("PATCH", "PUT", "POST", "DELETE"):
        r = b.client.request(m, f"/api/v1/users/{a.handle}", json={"bio": "pwned"},
                             headers={"Origin": "https://testserver", "X-CSRF-Token": b.csrf})
        assert r.status_code in (404, 405)
    assert a.me()["bio"] == ""


def test_deleted_post_content_erased(factory, db):
    a = factory.user()
    p = _post(a, "secret regret #oops")
    a.delete(f"/api/v1/posts/{p['id']}")
    row = db.get(Post, uuid.UUID(p["id"]))
    assert row.body == "" and row.deleted_at is not None
    assert factory.user().get("/api/v1/hashtags/oops").json()["items"] == []


# ============================================================ follow logic
def test_cannot_follow_self(factory):
    a = factory.user()
    r = a.post(f"/api/v1/users/{a.handle}/follow")
    assert r.status_code == 400 and r.json()["error"]["code"] == "cannot_follow_self"


def test_follow_race_creates_one_edge(factory, db):
    """[T-RACE-FOLLOW] 20 concurrent follows -> exactly one row and +1 counters."""
    a, b = factory.user(), factory.user()
    with ThreadPoolExecutor(10) as ex:
        codes = list(ex.map(lambda _: a.post(f"/api/v1/users/{b.handle}/follow").status_code, range(20)))
    assert set(codes) == {200}
    db.expire_all()
    assert db.scalar(select(func.count()).select_from(Follow).where(Follow.followee_id == b.id)) == 1
    assert db.get(User, b.id).followers_count == 1
    assert db.get(User, a.id).following_count == 1


def test_like_race_and_repeat(factory, db):
    """[T-RACE-LIKE] liking the same post repeatedly/concurrently counts once."""
    a, b = factory.user(), factory.user()
    p = _post(a)
    with ThreadPoolExecutor(10) as ex:
        list(ex.map(lambda _: b.post(f"/api/v1/posts/{p['id']}/like").status_code, range(25)))
    db.expire_all()
    assert db.scalar(select(func.count()).select_from(Like).where(Like.post_id == uuid.UUID(p["id"]))) == 1
    assert db.get(Post, uuid.UUID(p["id"])).like_count == 1
    b.delete(f"/api/v1/posts/{p['id']}/like")
    b.delete(f"/api/v1/posts/{p['id']}/like")
    db.expire_all()
    assert db.get(Post, uuid.UUID(p["id"])).like_count == 0


def test_private_account_follow_requires_approval(factory):
    priv, fan, other = factory.user(private=True), factory.user(), factory.user()
    p = _post(priv, "for followers only")
    assert fan.get(f"/api/v1/posts/{p['id']}").status_code == 404
    assert fan.post(f"/api/v1/users/{priv.handle}/follow").json()["state"] == "requested"
    assert fan.get(f"/api/v1/posts/{p['id']}").status_code == 404
    # a third party cannot accept on the target's behalf
    assert other.post(f"/api/v1/follow-requests/{fan.handle}/accept").status_code == 404
    # nor can the requester accept their own request
    assert fan.post(f"/api/v1/follow-requests/{priv.handle}/accept").status_code == 404
    assert priv.post(f"/api/v1/follow-requests/{fan.handle}/accept").status_code == 200
    assert fan.get(f"/api/v1/posts/{p['id']}").status_code == 200


# ============================================================ blocks [bypassing blocks]
def test_block_hides_everything_and_prevents_interaction(factory):
    a, b = factory.user(), factory.user()
    b.post(f"/api/v1/users/{a.handle}/follow")
    p = _post(a, "public post by a")
    assert a.post(f"/api/v1/users/{b.handle}/block").status_code == 200
    # b can no longer see a at all (404, not 403: no block oracle)
    assert b.get(f"/api/v1/users/{a.handle}").status_code == 404
    assert b.get(f"/api/v1/posts/{p['id']}").status_code == 404
    assert b.get(f"/api/v1/users/{a.handle}/posts").status_code == 404
    assert b.post(f"/api/v1/posts/{p['id']}/like").status_code == 404
    assert b.post(f"/api/v1/posts/{p['id']}/repost").status_code == 404
    assert b.post("/api/v1/posts", json={"text": "reply", "reply_to_id": p["id"]}).status_code == 404
    assert b.post(f"/api/v1/users/{a.handle}/follow").status_code == 404
    assert b.post("/api/v1/conversations", json={"handle": a.handle}).status_code == 404
    # follow edge was severed; the blocker can still open the profile to see/undo the block
    rel = a.get(f"/api/v1/users/{b.handle}").json()["relationship"]
    assert rel["followed_by"] is False and rel["blocking"] is True
    assert a.post(f"/api/v1/users/{b.handle}/follow").status_code == 404
    assert a.post("/api/v1/conversations", json={"handle": b.handle}).status_code == 404
    # search and timelines exclude
    assert all(i["author"]["handle"] != a.handle for i in b.get("/api/v1/timeline/public").json()["items"])
    # the blocker also can't see the blocked user's content
    q = _post(b, "b's post")
    assert a.get(f"/api/v1/posts/{q['id']}").status_code == 404


def test_blocked_user_mentions_do_not_notify(factory):
    a, b = factory.user(), factory.user()
    a.post(f"/api/v1/users/{b.handle}/block")
    _post(b, f"hey @{a.handle}")
    assert a.get("/api/v1/notifications").json()["items"] == []


def test_can_block_someone_who_blocked_you(factory):
    a, b = factory.user(), factory.user()
    a.post(f"/api/v1/users/{b.handle}/block")
    assert b.post(f"/api/v1/users/{a.handle}/block").status_code == 200


def test_mute_is_silent_and_filters(factory):
    a, b = factory.user(), factory.user()
    a.post(f"/api/v1/users/{b.handle}/follow")
    a.post(f"/api/v1/users/{b.handle}/mute")
    _post(b, f"hi @{a.handle}")
    assert a.get("/api/v1/timeline/home").json()["items"] == []
    assert a.get("/api/v1/notifications").json()["items"] == []
    assert b.get(f"/api/v1/users/{a.handle}").status_code == 200  # b can't tell


# ============================================================ privacy alternate paths [T-PRIV-ALTPATH]
def test_followers_only_post_does_not_leak_via_any_path(factory):
    a = factory.user()
    follower, stranger = factory.user(), factory.user()
    follower.post(f"/api/v1/users/{a.handle}/follow")
    p = _post(a, f"secret plans #leaktest @{stranger.handle}", visibility="followers")
    pid = p["id"]
    anon = factory.anon()
    for who in (stranger, None):
        g = (who.get if who else anon.get)
        assert g(f"/api/v1/posts/{pid}").status_code == 404
        assert all(i["id"] != pid for i in g(f"/api/v1/users/{a.handle}/posts").json()["items"])
        assert all(i["id"] != pid for i in g("/api/v1/hashtags/leaktest").json()["items"])
        assert all(i["id"] != pid for i in g("/api/v1/timeline/public").json()["items"])
        assert g(f"/api/v1/posts/{pid}/replies").status_code == 404
    assert all(i["id"] != pid for i in stranger.get("/api/v1/search/posts?q=secret").json()["items"])
    # the mentioned stranger gets no notification to a post they can't see
    assert stranger.get("/api/v1/notifications").json()["items"] == []
    # reposting is refused
    assert follower.post(f"/api/v1/posts/{pid}/repost").status_code == 403
    # follower sees it everywhere
    assert follower.get(f"/api/v1/posts/{pid}").status_code == 200
    assert any(i["id"] == pid for i in follower.get("/api/v1/search/posts?q=secret").json()["items"])
    assert any(i["id"] == pid for i in follower.get("/api/v1/timeline/home").json()["items"])


def test_reply_to_followers_only_post_inherits_restriction(factory):
    a, f = factory.user(), factory.user()
    f.post(f"/api/v1/users/{a.handle}/follow")
    p = _post(a, "inner circle", visibility="followers")
    r = _post(f, "reply", reply_to_id=p["id"], visibility="public")
    assert r["visibility"] == "followers"


def test_unfollow_revokes_access(factory):
    a, f = factory.user(), factory.user()
    f.post(f"/api/v1/users/{a.handle}/follow")
    p = _post(a, "fo", visibility="followers")
    assert f.get(f"/api/v1/posts/{p['id']}").status_code == 200
    a.delete(f"/api/v1/users/{f.handle}/follower")
    assert f.get(f"/api/v1/posts/{p['id']}").status_code == 404


def test_private_account_lists_hidden(factory):
    priv, stranger = factory.user(private=True), factory.user()
    assert stranger.get(f"/api/v1/users/{priv.handle}/followers").status_code == 403
    assert factory.anon().get(f"/api/v1/users/{priv.handle}/following").status_code == 401


def test_likes_hidden_unless_opted_in(factory):
    a, b = factory.user(), factory.user()
    p = _post(b)
    a.post(f"/api/v1/posts/{p['id']}/like")
    assert b.get(f"/api/v1/users/{a.handle}/likes").status_code == 404
    assert a.get(f"/api/v1/users/{a.handle}/likes").status_code == 200
    a.patch("/api/v1/me", json={"version": 1, "show_likes": True})
    assert b.get(f"/api/v1/users/{a.handle}/likes").status_code == 200


def test_suspended_users_content_disappears(factory, db):
    from sqlalchemy import update
    a, b = factory.user(), factory.user()
    p = _post(a)
    db.execute(update(User).where(User.id == a.id).values(status="suspended"))
    db.commit()
    assert b.get(f"/api/v1/posts/{p['id']}").status_code == 404
    assert b.get(f"/api/v1/users/{a.handle}").status_code == 404


def test_undiscoverable_user_not_in_search(factory):
    a, b = factory.user("hideme_xyz"), factory.user()
    assert any(u["handle"] == "hideme_xyz" for u in b.get("/api/v1/search/users?q=hideme").json()["items"])
    a.patch("/api/v1/me", json={"version": 1, "discoverable": False})
    assert not b.get("/api/v1/search/users?q=hideme").json()["items"]


def test_search_requires_login(factory):
    anon = factory.anon()
    assert anon.get("/api/v1/search/posts?q=hello").status_code == 401
    assert anon.get("/api/v1/search/users?q=hello").status_code == 401


# ============================================================ injection [T-INJ-SQL]
@pytest.mark.parametrize("q", ["' OR 1=1 --", "a'); DROP TABLE users; --", "%", "_", "\\", "a & b | !c",
                               "<->", "x:*", "' UNION SELECT password_hash FROM users --"])
def test_search_injection_inert(factory, db, q):
    u = factory.user()
    _post(u, "harmless text")
    r1 = u.get("/api/v1/search/posts", params={"q": q})
    r2 = u.get("/api/v1/search/users", params={"q": q})
    assert r1.status_code in (200, 400) and r2.status_code in (200, 400)
    if q in ("%", "_"):
        assert r2.json().get("items", []) == [] or r2.status_code == 400
    assert db.scalar(select(func.count()).select_from(User)) >= 1  # table still there
    assert "password_hash" not in r1.text + r2.text


@pytest.mark.parametrize("cursor", ["x", "e30", "W10", "WyJub3QtYS1kYXRlIiwgIngiXQ", "' OR 1=1", "A" * 199])
def test_bad_cursors_rejected(factory, cursor):
    u = factory.user()
    r = u.get("/api/v1/timeline/public", params={"cursor": cursor})
    assert r.status_code == 400


def test_pagination_limit_capped(factory):
    u = factory.user()
    assert u.get("/api/v1/timeline/public?limit=1000").status_code == 422
    assert u.get("/api/v1/timeline/public?limit=0").status_code == 422


def test_pagination_walks_all_items(factory):
    u = factory.user()
    ids = {_post(u, f"post number {i}")["id"] for i in range(7)}
    seen, cursor = set(), None
    for _ in range(10):
        r = u.get("/api/v1/timeline/public", params={"limit": 3, **({"cursor": cursor} if cursor else {})}).json()
        seen |= {i["id"] for i in r["items"]}
        cursor = r["next_cursor"]
        if not cursor:
            break
    assert ids <= seen


def test_invalid_uuid_path_is_422_not_500(factory):
    u = factory.user()
    for path in ("/api/v1/posts/not-a-uuid", "/api/v1/posts/1%20OR%201=1", "/api/v1/posts/../../etc/passwd"):
        assert u.get(path).status_code in (404, 422)


# ============================================================ timeline correctness
def test_home_timeline_includes_reposts_of_followees(factory):
    a, b, c = factory.user(), factory.user(), factory.user()
    a.post(f"/api/v1/users/{b.handle}/follow")
    p = _post(c, "original by c")
    b.post(f"/api/v1/posts/{p['id']}/repost")
    items = a.get("/api/v1/timeline/home").json()["items"]
    assert any(i["id"] == p["id"] and i.get("reposted_by", {}).get("handle") == b.handle for i in items)


def test_notifications_are_private_and_deduplicated(factory):
    a, b, c = factory.user(), factory.user(), factory.user()
    p = _post(a)
    for _ in range(3):
        b.post(f"/api/v1/posts/{p['id']}/like")
        b.delete(f"/api/v1/posts/{p['id']}/like")
    b.post(f"/api/v1/posts/{p['id']}/like")
    items = a.get("/api/v1/notifications").json()["items"]
    assert len([i for i in items if i["type"] == "like"]) == 1
    assert c.get("/api/v1/notifications").json()["items"] == []
    assert a.get("/api/v1/notifications/unread-count").json()["count"] == 1
    a.post("/api/v1/notifications/read")
    assert a.get("/api/v1/notifications/unread-count").json()["count"] == 0

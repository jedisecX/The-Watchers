from __future__ import annotations

import itertools
import re
import uuid
from datetime import datetime, timedelta, timezone

import pyotp
from sqlalchemy import select, update
from starlette.testclient import TestClient

BASE = "https://testserver"
ORIGIN = "https://testserver"
PASSWORD = "correct horse battery staple 42"
_ip = itertools.count(10)


def make_client(app, ip: str | None = None) -> TestClient:
    ip = ip or f"198.51.100.{next(_ip) % 250 + 1}"
    return TestClient(app, base_url=BASE, client=(ip, 50000))


class UserCtx:
    def __init__(self, client: TestClient, handle: str, user_id: uuid.UUID, csrf: str | None, password: str,
                 totp_secret: str | None = None):
        self.client = client
        self.handle = handle
        self.id = user_id
        self.csrf = csrf
        self.password = password
        self.totp_secret = totp_secret

    def _h(self, extra=None):
        h = {"Origin": ORIGIN}
        if self.csrf:
            h["X-CSRF-Token"] = self.csrf
        h.update(extra or {})
        return h

    def get(self, url, **kw):
        return self.client.get(url, **kw)

    def post(self, url, json=None, headers=None, **kw):
        return self.client.post(url, json=json, headers=self._h(headers), **kw)

    def put(self, url, headers=None, **kw):
        return self.client.put(url, headers=self._h(headers), **kw)

    def patch(self, url, json=None, headers=None, **kw):
        return self.client.patch(url, json=json, headers=self._h(headers), **kw)

    def delete(self, url, headers=None, **kw):
        return self.client.request("DELETE", url, headers=self._h(headers), **kw)

    def totp(self) -> str:
        """A code the server has not seen yet (it rejects replayed time-steps)."""
        try:
            return fresh_totp(self.totp_secret)
        except RuntimeError:
            from app.db import new_session
            db = new_session()
            reset_totp_steps(db, self.id)
            db.close()
            _last_step[self.totp_secret] = 0
            return fresh_totp(self.totp_secret)

    def reauth(self):
        body = {"password": self.password}
        if self.totp_secret:
            body["code"] = self.totp()
        r = self.post("/api/v1/auth/reauth", json=body)
        assert r.status_code == 200, r.text
        return r

    def me(self):
        return self.get("/api/v1/me").json()


_last_step: dict[str, int] = {}


def mark_totp_used(secret: str) -> None:
    import time
    _last_step[secret] = int(time.time() // 30)


def fresh_totp(secret: str) -> str:
    """Generate a TOTP for a step not used before in this test run (the server rejects replays).
    Uses future steps within the +1 skew window, then back-dates the server's last step if needed."""
    import time
    step = int(time.time() // 30)
    last = _last_step.get(secret, 0)
    use = max(step - 1, last + 1)
    if use > step + 1:
        raise RuntimeError("exhausted TOTP window; reset users.totp_last_step in test")
    _last_step[secret] = use
    return pyotp.TOTP(secret).generate_otp(use)


def reset_totp_steps(db, user_id):
    from app.models import User
    db.execute(update(User).where(User.id == user_id).values(totp_last_step=0))
    db.commit()


def outbox(db, to: str, kind: str | None = None):
    from app.models import EmailOutbox
    q = select(EmailOutbox).where(EmailOutbox.to_addr == to).order_by(EmailOutbox.created_at.desc())
    if kind:
        q = q.where(EmailOutbox.kind == kind)
    return db.execute(q).scalars().all()


def token_from_outbox(db, to: str, kind: str) -> str:
    db.expire_all()
    mails = outbox(db, to, kind)
    assert mails, f"no {kind} mail to {to}"
    m = re.search(r"#token=([A-Za-z0-9_\-%]+)", mails[0].body or "")
    assert m
    return m.group(1)


class Factory:
    def __init__(self, app):
        self.app = app
        self.n = itertools.count(1)

    def user(self, handle: str | None = None, *, verified: bool = True, role: str = "user", mfa: bool = False,
             private: bool = False, dm_policy: str = "everyone", age_days: int = 30, login: bool = True,
             ip: str | None = None) -> UserCtx:
        from app.db import new_session
        from app.models import User
        from app.security.passwords import hash_password
        from app.services import mfa as mfasvc

        handle = handle or f"user{next(self.n)}_{uuid.uuid4().hex[:6]}"
        now = datetime.now(timezone.utc)
        db = new_session()
        u = User(handle=handle, handle_norm=handle.lower(), email=f"{handle.lower()}@example.com",
                 password_hash=hash_password(PASSWORD), role=role, is_private=private, dm_policy=dm_policy,
                 email_verified_at=now if verified else None, created_at=now - timedelta(days=age_days))
        db.add(u)
        db.flush()
        secret = None
        if mfa:
            secret = pyotp.random_base32(32)
            u.totp_secret_enc = mfasvc.enc(u.id, secret)
            u.mfa_enabled_at = now
        db.commit()
        uid = u.id
        db.close()
        client = make_client(self.app, ip)
        ctx = UserCtx(client, handle, uid, None, PASSWORD, secret)
        if login:
            self.login(ctx)
        return ctx

    def login(self, ctx: UserCtx) -> None:
        h = {"Origin": ORIGIN}
        if ctx.csrf:  # re-login from a browser that already holds a session: CSRF applies
            h["X-CSRF-Token"] = ctx.csrf
        r = ctx.client.post("/api/v1/auth/login", json={"identifier": ctx.handle, "password": ctx.password},
                            headers=h)
        assert r.status_code == 200, r.text
        data = r.json()
        if data.get("mfa_required"):
            r = ctx.client.post("/api/v1/auth/login/mfa", headers=h,
                                json={"mfa_token": data["mfa_token"], "code": ctx.totp()})
            assert r.status_code == 200, r.text
            data = r.json()
        ctx.csrf = data["csrf_token"]

    def anon(self, ip: str | None = None) -> TestClient:
        return make_client(self.app, ip)

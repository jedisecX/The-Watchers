"""WebAuthn / passkeys. [T-PASSKEY-*]"""
from __future__ import annotations

import base64
import copy
import json
from hashlib import sha256
from struct import pack

import pytest
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec
from soft_webauthn import SoftWebauthnDevice

from tests.helpers import ORIGIN

H = {"Origin": ORIGIN}


def b64u(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode()


def unb64u(s: str) -> bytes:
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


class UVDevice(SoftWebauthnDevice):
    """soft-webauthn with the User-Verified flag set (the server requires UV)."""

    def create_json(self, options: dict, origin: str) -> dict:
        pk = copy.deepcopy(options)
        pk["challenge"] = unb64u(pk["challenge"])
        pk["user"]["id"] = unb64u(pk["user"]["id"])
        cred = self.create({"publicKey": pk}, origin)
        att = cred["response"]["attestationObject"]
        from soft_webauthn import cbor
        obj = cbor.decode(att)
        ad = bytearray(obj["authData"])
        ad[32] |= 0x04  # UV
        obj["authData"] = bytes(ad)
        return {"id": b64u(self.credential_id), "rawId": b64u(self.credential_id), "type": "public-key",
                "response": {"clientDataJSON": b64u(cred["response"]["clientDataJSON"]),
                             "attestationObject": b64u(cbor.encode(obj))}}

    def get_json(self, options: dict, origin: str, *, sign_count: int | None = None, uv: bool = True) -> dict:
        if sign_count is None:
            self.sign_count += 1
        else:
            self.sign_count = sign_count
        client_data = json.dumps({"type": "webauthn.get", "challenge": options["challenge"],
                                  "origin": origin}).encode()
        auth_data = sha256(self.rp_id.encode()).digest() + bytes([0x05 if uv else 0x01]) + pack(">I", self.sign_count)
        sig = self.private_key.sign(auth_data + sha256(client_data).digest(), ec.ECDSA(hashes.SHA256()))
        return {"id": b64u(self.credential_id), "rawId": b64u(self.credential_id), "type": "public-key",
                "response": {"clientDataJSON": b64u(client_data), "authenticatorData": b64u(auth_data),
                             "signature": b64u(sig), "userHandle": b64u(self.user_handle)}}


def _register_passkey(u) -> UVDevice:
    u.reauth()
    begin = u.post("/api/v1/account/passkeys/register/begin").json()
    dev = UVDevice()
    cred = dev.create_json(begin["options"], ORIGIN)
    r = u.post("/api/v1/account/passkeys/register/finish",
               json={"challenge_id": begin["challenge_id"], "credential": cred, "name": "Laptop"})
    assert r.status_code == 201, r.text
    return dev


def _login_begin(c):
    return c.post("/api/v1/auth/passkey/login/begin", headers=H).json()


def test_passkey_register_and_passwordless_login(factory):
    u = factory.user("pkuser")
    dev = _register_passkey(u)
    c = factory.anon()
    b = _login_begin(c)
    assert b["options"]["userVerification"] == "required"
    r = c.post("/api/v1/auth/passkey/login/finish", headers=H,
               json={"challenge_id": b["challenge_id"], "credential": dev.get_json(b["options"], ORIGIN)})
    assert r.status_code == 200, r.text
    assert r.json()["user"]["handle"] == "pkuser"


def test_passkey_challenge_replay_rejected(factory):
    u = factory.user("pkreplay")
    dev = _register_passkey(u)
    c = factory.anon()
    b = _login_begin(c)
    assertion = dev.get_json(b["options"], ORIGIN)
    assert c.post("/api/v1/auth/passkey/login/finish", headers=H,
                  json={"challenge_id": b["challenge_id"], "credential": assertion}).status_code == 200
    c2 = factory.anon()
    r = c2.post("/api/v1/auth/passkey/login/finish", headers=H,
                json={"challenge_id": b["challenge_id"], "credential": assertion})
    assert r.status_code == 400


def test_passkey_wrong_origin_rejected(factory):
    """Phishing relay: assertion produced for another origin must fail."""
    u = factory.user("pkphish")
    dev = _register_passkey(u)
    c = factory.anon()
    b = _login_begin(c)
    r = c.post("/api/v1/auth/passkey/login/finish", headers=H,
               json={"challenge_id": b["challenge_id"],
                     "credential": dev.get_json(b["options"], "https://evil.example")})
    assert r.status_code == 400


def test_passkey_without_user_verification_rejected(factory):
    u = factory.user("pknouv")
    dev = _register_passkey(u)
    c = factory.anon()
    b = _login_begin(c)
    r = c.post("/api/v1/auth/passkey/login/finish", headers=H,
               json={"challenge_id": b["challenge_id"], "credential": dev.get_json(b["options"], ORIGIN, uv=False)})
    assert r.status_code == 400


def test_passkey_sign_count_regression_rejected(factory):
    u = factory.user("pkclone")
    dev = _register_passkey(u)
    c = factory.anon()
    b = _login_begin(c)
    assert c.post("/api/v1/auth/passkey/login/finish", headers=H, json={
        "challenge_id": b["challenge_id"], "credential": dev.get_json(b["options"], ORIGIN, sign_count=10)}).status_code == 200
    c = factory.anon()  # attacker with a cloned authenticator, from another browser
    b = _login_begin(c)
    r = c.post("/api/v1/auth/passkey/login/finish", headers=H, json={
        "challenge_id": b["challenge_id"], "credential": dev.get_json(b["options"], ORIGIN, sign_count=5)})
    assert r.status_code == 400


def test_passkey_tampered_signature_rejected(factory):
    u = factory.user("pktamper")
    dev = _register_passkey(u)
    c = factory.anon()
    b = _login_begin(c)
    a = dev.get_json(b["options"], ORIGIN)
    sig = bytearray(unb64u(a["response"]["signature"]))
    sig[-1] ^= 0xFF
    a["response"]["signature"] = b64u(bytes(sig))
    r = c.post("/api/v1/auth/passkey/login/finish", headers=H, json={"challenge_id": b["challenge_id"], "credential": a})
    assert r.status_code == 400


def test_passkey_registration_challenge_bound_to_user(factory):
    a, b = factory.user("pkA"), factory.user("pkB")
    a.reauth()
    begin = a.post("/api/v1/account/passkeys/register/begin").json()
    cred = UVDevice().create_json(begin["options"], ORIGIN)
    b.reauth()
    r = b.post("/api/v1/account/passkeys/register/finish",
               json={"challenge_id": begin["challenge_id"], "credential": cred, "name": "x"})
    assert r.status_code == 400


def test_passkey_reauth_sets_mfa_for_admin_stepup(factory):
    u = factory.user("pkstep")
    dev = _register_passkey(u)
    b = u.post("/api/v1/auth/reauth/passkey/begin").json()
    r = u.post("/api/v1/auth/reauth/passkey/finish",
               json={"challenge_id": b["challenge_id"], "credential": dev.get_json(b["options"], ORIGIN)})
    assert r.status_code == 200 and r.json()["mfa"] is True


def test_cannot_use_other_users_passkey_for_reauth(factory):
    a, b = factory.user("pkownerA"), factory.user("pkownerB")
    dev_a = _register_passkey(a)
    _register_passkey(b)
    begin = b.post("/api/v1/auth/reauth/passkey/begin").json()
    r = b.post("/api/v1/auth/reauth/passkey/finish",
               json={"challenge_id": begin["challenge_id"], "credential": dev_a.get_json(begin["options"], ORIGIN)})
    assert r.status_code == 400


def test_passkey_delete_scoped_to_owner(factory):
    a, b = factory.user("pkdelA"), factory.user("pkdelB")
    _register_passkey(a)
    pid = a.get("/api/v1/account/passkeys").json()["items"][0]["id"]
    b.reauth()
    assert b.delete(f"/api/v1/account/passkeys/{pid}").status_code == 404
    assert a.delete(f"/api/v1/account/passkeys/{pid}").status_code == 204


@pytest.mark.parametrize("cred", [{}, {"id": "!!"}, {"rawId": "A" * 5000}, {"id": 5}])
def test_malformed_assertions(factory, cred):
    c = factory.anon()
    b = _login_begin(c)
    r = c.post("/api/v1/auth/passkey/login/finish", headers=H, json={"challenge_id": b["challenge_id"], "credential": cred})
    assert r.status_code == 400

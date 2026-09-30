"""Phase 1: boundary infrastructure. [T-HEADERS] [T-API-*] [T-HOSTHDR] [T-AUDIT-*] [T-LOG-REDACT] [T-CACHE]"""
from __future__ import annotations

import json
import logging

import pytest
from sqlalchemy import text

from tests.helpers import ORIGIN


# ------------------------------------------------------------------ security headers [T-HEADERS]
def test_api_security_headers(client):
    r = client.get("/api/v1/health")
    assert r.status_code == 200
    h = r.headers
    assert h["x-content-type-options"] == "nosniff"
    assert h["x-frame-options"] == "DENY"
    assert "default-src 'none'" in h["content-security-policy"]
    assert "frame-ancestors 'none'" in h["content-security-policy"]
    assert h["strict-transport-security"].startswith("max-age=63072000")
    assert h["cache-control"] == "no-store"
    assert h["referrer-policy"] == "same-origin"
    assert h["cross-origin-opener-policy"] == "same-origin"
    assert "camera=()" in h["permissions-policy"]
    assert "server" not in {k.lower() for k in h.keys()}
    assert "x-request-id" in h


def test_frontend_csp_is_strict():
    from app.middleware import frontend_csp
    csp = frontend_csp()
    assert "script-src 'self'" in csp
    assert "unsafe-inline" not in csp and "unsafe-eval" not in csp
    assert "require-trusted-types-for 'script'" in csp
    assert "object-src 'none'" in csp and "base-uri 'none'" in csp and "frame-ancestors 'none'" in csp


# ------------------------------------------------------------------ host header [T-HOSTHDR]
def test_unknown_host_rejected(client):
    r = client.get("/api/v1/health", headers={"Host": "evil.example"})
    assert r.status_code == 400


# ------------------------------------------------------------------ strict JSON [T-API-DUPKEY]
@pytest.mark.parametrize("body,code", [
    (b'{"identifier":"a","identifier":"b","password":"x"}', "invalid_json"),
    (b'{"identifier":"a","password":NaN}', "invalid_json"),
    (b'{"identifier":"a","password":Infinity}', "invalid_json"),
    (b"[1,2,3]", "invalid_json"),
    (b'{"a":' * 40 + b"1" + b"}" * 40, "invalid_json"),
    (b"\xff\xfe{}", "invalid_json"),
    (b'{"identifier":"\\ud800","password":"x"}', "invalid_json"),
    (b"{not json", "invalid_json"),
])
def test_strict_json_rejections(client, body, code):
    r = client.post("/api/v1/auth/login", content=body,
                    headers={"Content-Type": "application/json", "Origin": ORIGIN})
    assert r.status_code == 400, r.text
    assert r.json()["error"]["code"] == code


def test_wrong_content_type_rejected(client):
    r = client.post("/api/v1/auth/login", content=b"identifier=a&password=b",
                    headers={"Content-Type": "application/x-www-form-urlencoded", "Origin": ORIGIN})
    assert r.status_code == 415


def test_text_plain_json_rejected(client):
    """text/plain is a CORS 'simple' content type; accepting it would allow form-based CSRF."""
    r = client.post("/api/v1/auth/login", content=b'{"identifier":"a","password":"b"}',
                    headers={"Content-Type": "text/plain", "Origin": ORIGIN})
    assert r.status_code == 415


def test_unknown_fields_rejected(client):
    r = client.post("/api/v1/auth/login", json={"identifier": "a", "password": "b", "role": "admin"},
                    headers={"Origin": ORIGIN})
    assert r.status_code == 422
    assert any(d["type"] == "extra_forbidden" for d in r.json()["error"]["details"])


# ------------------------------------------------------------------ body limits [T-API-BODYLIMIT]
def test_oversized_body_rejected_by_content_length(client):
    big = b'{"identifier":"' + b"a" * (70 * 1024) + b'","password":"x"}'
    r = client.post("/api/v1/auth/login", content=big,
                    headers={"Content-Type": "application/json", "Origin": ORIGIN})
    assert r.status_code == 413


def test_oversized_chunked_body_rejected(client):
    def gen():
        yield b'{"identifier":"'
        for _ in range(80):
            yield b"a" * 1024
        yield b'","password":"x"}'
    r = client.post("/api/v1/auth/login", content=gen(),
                    headers={"Content-Type": "application/json", "Origin": ORIGIN})
    assert r.status_code == 413


# ------------------------------------------------------------------ parameter pollution [T-API-HPP]
def test_duplicate_query_params_rejected(client):
    r = client.get("/api/v1/health?a=1&a=2")
    assert r.status_code == 400
    assert r.json()["error"]["code"] == "duplicate_parameter"


def test_null_byte_query_rejected(client):
    r = client.get("/api/v1/health?a=%00")
    assert r.status_code == 400


def test_long_query_rejected(client):
    r = client.get("/api/v1/health?a=" + "x" * 3000)
    assert r.status_code == 414


# ------------------------------------------------------------------ errors [T-API-ERRORS]
def test_unknown_api_route_is_json_404(client):
    r = client.get("/api/v1/does-not-exist")
    assert r.status_code == 404
    assert r.json()["error"]["code"] == "not_found"


def test_cache_deception_path_suffix_not_served(client):
    """/api/v1/me/x.css must not resolve to /me (web cache deception) [T-CACHE]."""
    r = client.get("/api/v1/me/x.css")
    assert r.status_code == 404
    assert r.headers["cache-control"] == "no-store"


def test_validation_errors_do_not_echo_input(client):
    payload = "<script>alert(1)</script>"
    r = client.post("/api/v1/auth/login", json={"identifier": payload, "password": 123},
                    headers={"Origin": ORIGIN})
    assert r.status_code == 422
    assert payload not in r.text


def test_internal_errors_are_generic(app):
    from fastapi.testclient import TestClient

    @app.get("/api/v1/_boom_test")
    def boom():
        raise RuntimeError("secret internal detail SELECT * FROM users")

    app.router.routes.insert(0, app.router.routes.pop())  # ahead of the /api catch-all
    c = TestClient(app, base_url="https://testserver", raise_server_exceptions=False)
    r = c.get("/api/v1/_boom_test")
    assert r.status_code == 500
    assert r.json()["error"]["code"] == "internal_error"
    assert "secret internal detail" not in r.text and "Traceback" not in r.text
    app.router.routes.pop(0)


# ------------------------------------------------------------------ cross-origin [T-CSRF] (login CSRF)
def test_cross_origin_post_rejected_even_unauthenticated(client):
    r = client.post("/api/v1/auth/login", json={"identifier": "a", "password": "b"},
                    headers={"Origin": "https://evil.example"})
    assert r.status_code == 403
    assert r.json()["error"]["code"] == "bad_origin"


def test_sec_fetch_site_cross_site_rejected(client):
    r = client.post("/api/v1/auth/login", json={"identifier": "a", "password": "b"},
                    headers={"Sec-Fetch-Site": "cross-site"})
    assert r.status_code == 403


# ------------------------------------------------------------------ audit log [T-AUDIT-IMMUTABLE] [T-AUDIT-CHAIN]
def _write_audit(db, n=3):
    from app import audit
    for i in range(n):
        audit.record(db, action=f"test.action{i}", actor_id=None, actor_role="system", details={"i": i})
    db.commit()


def test_audit_chain_verifies(db):
    from app import audit
    _write_audit(db, 5)
    ok, bad, n = audit.verify_chain(db)
    assert ok and bad is None and n == 5


def test_audit_log_is_append_only_for_app_role(db):
    _write_audit(db, 1)
    from sqlalchemy.exc import ProgrammingError, DBAPIError
    for stmt in ("UPDATE audit_log SET action='x'", "DELETE FROM audit_log", "TRUNCATE audit_log"):
        with pytest.raises((ProgrammingError, DBAPIError)):
            db.execute(text(stmt))
        db.rollback()


def test_audit_log_trigger_blocks_owner_too(migrator_engine, db):
    _write_audit(db, 1)
    from sqlalchemy.exc import DBAPIError
    with pytest.raises(DBAPIError):
        with migrator_engine.begin() as c:
            c.execute(text("UPDATE audit_log SET action='tampered'"))


def test_audit_tampering_detected(migrator_engine, db):
    """Simulates a DBA who disables the trigger and edits a row: the hash chain exposes it."""
    from app import audit
    _write_audit(db, 4)
    with migrator_engine.begin() as c:
        c.execute(text("ALTER TABLE audit_log DISABLE TRIGGER audit_log_no_update"))
        c.execute(text("UPDATE audit_log SET details='{\"i\": 99}' WHERE seq = 3"))
        c.execute(text("ALTER TABLE audit_log ENABLE TRIGGER audit_log_no_update"))
    db.expire_all()
    ok, bad, _ = audit.verify_chain(db)
    assert not ok and bad == 3


# ------------------------------------------------------------------ worker role least privilege
def test_worker_role_cannot_read_users():
    import os
    from sqlalchemy import create_engine
    from sqlalchemy.exc import ProgrammingError
    eng = create_engine(os.environ["WORKER_DATABASE_URL"])
    with eng.connect() as c:
        with pytest.raises(ProgrammingError):
            c.execute(text("SELECT * FROM users"))
    with eng.connect() as c:
        with pytest.raises(ProgrammingError):
            c.execute(text("UPDATE media SET owner_id = gen_random_uuid()"))
    eng.dispose()


# ------------------------------------------------------------------ logging [T-LOG-REDACT]
def test_log_redaction():
    from app.logging_setup import JsonFormatter
    rec = logging.LogRecord("x", logging.INFO, __file__, 1, "login with bst_abcdefghijklmnop", (), None)
    rec.password = "hunter2hunter2"
    rec.headers = {"authorization": "Bearer abc", "cookie": "__Host-bastion_sid=zzz", "accept": "json"}
    rec.user_id = "u1"
    out = json.loads(JsonFormatter().format(rec))
    blob = json.dumps(out)
    assert "hunter2" not in blob and "zzz" not in blob and "Bearer abc" not in blob
    assert "bst_abcdefghijklmnop" not in blob
    assert out["user_id"] == "u1" and out["headers"]["accept"] == "json"


def test_log_injection_is_neutralised():
    from app.logging_setup import JsonFormatter
    rec = logging.LogRecord("x", logging.INFO, __file__, 1, "user said\n{\"level\":\"CRITICAL\"}", (), None)
    line = JsonFormatter().format(rec)
    assert "\n" not in line  # one record == one line


# ------------------------------------------------------------------ config safety
def test_production_config_refuses_insecure_defaults(monkeypatch):
    from app.config import ConfigError, load_settings
    monkeypatch.setenv("ENV", "production")
    monkeypatch.setenv("PUBLIC_BASE_URL", "http://example.com")
    with pytest.raises(ConfigError):
        load_settings()
    monkeypatch.setenv("PUBLIC_BASE_URL", "https://example.com")
    monkeypatch.delenv("REDIS_URL", raising=False)
    with pytest.raises(ConfigError):
        load_settings()


def test_secrets_can_come_from_files(tmp_path, monkeypatch):
    from app.config import load_settings
    f = tmp_path / "sk"
    f.write_text("file-based-secret-key-0123456789abcdef-xyz\n")
    monkeypatch.setenv("SECRET_KEY_FILE", str(f))
    assert load_settings().secret_key == b"file-based-secret-key-0123456789abcdef-xyz"


# ------------------------------------------------------------------ field encryption
def test_field_encryption_binds_aad_and_rotates(monkeypatch):
    from app.security import crypto
    blob = crypto.encrypt_field(b"seed", b"row-1")
    assert crypto.decrypt_field(blob, b"row-1") == b"seed"
    with pytest.raises(ValueError):
        crypto.decrypt_field(blob, b"row-2")  # ciphertext moved to another row
    tampered = blob[:-1] + bytes([blob[-1] ^ 1])
    with pytest.raises(ValueError):
        crypto.decrypt_field(tampered, b"row-1")

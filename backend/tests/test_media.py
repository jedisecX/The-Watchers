"""Upload pipeline. [T-UPLOAD-*]"""
from __future__ import annotations

import io
import os
import shutil
import struct
import subprocess
import uuid
import zlib
from pathlib import Path

import pytest
from PIL import Image
from sqlalchemy import create_engine

from app.models import Media

H = {"Origin": "https://testserver"}


@pytest.fixture(scope="module")
def worker_engine():
    eng = create_engine(os.environ["WORKER_DATABASE_URL"])
    yield eng
    eng.dispose()


def run_worker(engine, n=10):
    from app.worker import process_one
    for _ in range(n):
        if not process_one(engine):
            break


def png_bytes(w=64, h=48, color=(200, 30, 30)) -> bytes:
    b = io.BytesIO()
    Image.new("RGB", (w, h), color).save(b, "PNG")
    return b.getvalue()


def jpeg_with_gps() -> bytes:
    img = Image.new("RGB", (80, 60), (10, 120, 200))
    exif = Image.Exif()
    exif[0x010F] = "SecretCam Maker"          # Make
    exif[0x0131] = "SecretSoftware 1.0"       # Software
    gps = {1: "N", 2: (51.0, 30.0, 0.0), 3: "W", 4: (0.0, 7.0, 0.0)}
    exif[0x8825] = gps
    b = io.BytesIO()
    img.save(b, "JPEG", exif=exif.tobytes())
    return b.getvalue()


def upload(u, data: bytes, purpose="post", ctype="application/octet-stream"):
    return u.client.post(f"/api/v1/media?purpose={purpose}", content=data,
                         headers={**H, "X-CSRF-Token": u.csrf, "Content-Type": ctype})


def fetch_variant(u, media_id, variant):
    return u.get(f"/api/v1/media/{media_id}/{variant}")


# ------------------------------------------------------------------ happy path
def test_image_upload_process_and_serve(factory, worker_engine, db):
    u = factory.user()
    r = upload(u, png_bytes())
    assert r.status_code == 202, r.text
    mid = r.json()["id"]
    assert r.json()["state"] == "pending"
    # pending media is never served, and cannot be attached
    assert fetch_variant(u, mid, "large").status_code == 404
    assert u.post("/api/v1/posts", json={"text": "x", "media_ids": [mid]}).status_code == 404
    run_worker(worker_engine)
    st = u.get(f"/api/v1/media/{mid}").json()
    assert st["state"] == "ready" and set(st["variants"]) == {"large", "thumb"}
    r = fetch_variant(u, mid, "large")
    assert r.status_code == 200
    assert r.headers["content-type"] == "image/webp"
    assert r.headers["x-content-type-options"] == "nosniff"
    assert "sandbox" in r.headers["content-security-policy"]
    assert r.headers["content-disposition"].startswith("inline")
    assert r.content[:4] == b"RIFF" and r.content[8:12] == b"WEBP"
    # quarantine original was deleted
    m = db.get(Media, uuid.UUID(mid))
    assert m.quarantine_key is None
    assert not any(Path(os.environ["MEDIA_QUARANTINE_DIR"]).iterdir())


def test_client_content_type_ignored_mime_confusion(factory, worker_engine):
    """Claiming image/png while sending HTML is rejected; claiming text/html while sending a PNG is fine."""
    u = factory.user()
    assert upload(u, b"<html><script>alert(1)</script></html>" + b" " * 20, ctype="image/png").status_code == 415
    r = upload(u, png_bytes(), ctype="text/html")
    assert r.status_code == 202 and r.json()["kind"] == "image"


@pytest.mark.parametrize("payload,label", [
    (b'<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script></svg>', "svg"),
    (b"<svg onload=alert(1)>" + b" " * 32, "svg-short"),
    (b"\x7fELF\x02\x01\x01" + b"\x00" * 64, "elf"),
    (b"MZ\x90\x00" + b"\x00" * 64, "pe"),
    (b"<?php system($_GET['c']); ?>" + b" " * 20, "php"),
    (b"#!/bin/sh\nrm -rf /\n" + b" " * 20, "shell"),
    (b"%PDF-1.7\n" + b" " * 64, "pdf"),
    (b"PK\x03\x04" + b"\x00" * 64, "zip"),
    (b"\xef\xbb\xbf<html>" + b" " * 32, "bom-html"),
])
def test_dangerous_formats_rejected(factory, payload, label):
    u = factory.user()
    r = upload(u, payload, ctype="image/jpeg")
    assert r.status_code == 415, label
    assert not any(Path(os.environ["MEDIA_QUARANTINE_DIR"]).iterdir())  # nothing left behind


def test_polyglot_jpeg_html_payload_destroyed(factory, worker_engine):
    """[T-UPLOAD-POLYGLOT] JPEG with trailing HTML/JS and a comment segment carrying script."""
    b = io.BytesIO()
    Image.new("RGB", (40, 40), (0, 200, 0)).save(b, "JPEG")
    jpg = b.getvalue()
    marker = b"<script>alert('polyglot')</script>"
    com = b"\xff\xfe" + struct.pack(">H", len(marker) + 2) + marker
    poly = jpg[:2] + com + jpg[2:] + b"<html>" + marker + b"</html>"
    u = factory.user()
    mid = upload(u, poly).json()["id"]
    run_worker(worker_engine)
    for v in ("large", "thumb"):
        out = fetch_variant(u, mid, v).content
        assert marker not in out and b"<html>" not in out


def test_gifar_zip_payload_destroyed(factory, worker_engine):
    b = io.BytesIO()
    Image.new("P", (20, 20), 3).save(b, "GIF")
    gifar = b.getvalue() + b"PK\x03\x04" + b"evil.class" + b"\x00" * 100
    u = factory.user()
    mid = upload(u, gifar).json()["id"]
    run_worker(worker_engine)
    out = fetch_variant(u, mid, "large").content
    assert b"PK\x03\x04" not in out and b"evil.class" not in out


def test_exif_gps_stripped(factory, worker_engine):
    """[T-UPLOAD-EXIF]"""
    raw = jpeg_with_gps()
    assert b"SecretCam" in raw
    u = factory.user()
    mid = upload(u, raw).json()["id"]
    run_worker(worker_engine)
    out = fetch_variant(u, mid, "large").content
    assert b"SecretCam" not in out and b"SecretSoftware" not in out and b"Exif" not in out
    assert not Image.open(io.BytesIO(out)).getexif()


def _png_chunk(t: bytes, data: bytes) -> bytes:
    return struct.pack(">I", len(data)) + t + data + struct.pack(">I", zlib.crc32(t + data) & 0xFFFFFFFF)


def test_decompression_bomb_rejected(factory, worker_engine, db):
    """[T-UPLOAD-BOMB] tiny file declaring 50000x50000 pixels: rejected from the header, never decoded."""
    ihdr = struct.pack(">IIBBBBB", 50000, 50000, 8, 2, 0, 0, 0)
    idat = zlib.compress(b"\x00" * 1024, 9)
    bomb = b"\x89PNG\r\n\x1a\n" + _png_chunk(b"IHDR", ihdr) + _png_chunk(b"IDAT", idat) + _png_chunk(b"IEND", b"")
    assert len(bomb) < 2000
    u = factory.user()
    mid = upload(u, bomb).json()["id"]
    run_worker(worker_engine)
    st = u.get(f"/api/v1/media/{mid}").json()
    assert st["state"] == "failed" and st["error_code"] == "too_many_pixels"


def test_real_zlib_bomb_bounded(factory, worker_engine):
    """A 7000x7000 PNG compresses to a few KB but expands to ~147 MB of pixels: within the pixel limit,
    so it is processed, but under the worker's memory/CPU rlimits. It must finish (ready or failed), never hang."""
    img = Image.new("RGB", (7000, 7000), (0, 0, 0))
    b = io.BytesIO()
    img.save(b, "PNG", optimize=True)
    assert len(b.getvalue()) < 1_000_000
    u = factory.user()
    mid = upload(u, b.getvalue()).json()["id"]
    run_worker(worker_engine)
    assert u.get(f"/api/v1/media/{mid}").json()["state"] in ("ready", "failed")


def test_oversized_image_rejected_while_streaming(factory):
    u = factory.user()
    big = png_bytes()[:64] + b"\x00" * (21 * 1024 * 1024)
    r = upload(u, big)
    assert r.status_code == 413
    assert not any(Path(os.environ["MEDIA_QUARANTINE_DIR"]).iterdir())


@pytest.mark.parametrize("data", [
    b"\xff\xd8\xff\xe0" + b"\x00" * 200,                     # JPEG header, garbage body
    b"\x89PNG\r\n\x1a\n" + b"\x00" * 200,                    # PNG signature, no chunks
    b"GIF89a" + b"\xff" * 200,
    b"RIFF\x00\x00\x00\x00WEBPVP8 " + b"\xff" * 200,
])
def test_malformed_media_fails_safely(factory, worker_engine, data):
    """[T-UPLOAD-MALFORMED]"""
    u = factory.user()
    mid = upload(u, data).json()["id"]
    run_worker(worker_engine)
    st = u.get(f"/api/v1/media/{mid}").json()
    assert st["state"] == "failed"
    assert fetch_variant(u, mid, "large").status_code == 404


def test_signature_container_mismatch_rejected(factory, worker_engine):
    """PNG bytes behind a JPEG signature prefix: the decoder's view must match the sniffed type."""
    u = factory.user()
    data = b"\xff\xd8\xff" + png_bytes()
    mid = upload(u, data).json()["id"]
    run_worker(worker_engine)
    assert u.get(f"/api/v1/media/{mid}").json()["state"] == "failed"


# ------------------------------------------------------------------ traversal & injection
@pytest.mark.parametrize("purpose", ["../../etc/passwd", "post/../../x", "avatar%00.php", "POST", ""])
def test_purpose_allowlist(factory, purpose):
    u = factory.user()
    r = u.client.post("/api/v1/media", params={"purpose": purpose}, content=png_bytes(),
                      headers={**H, "X-CSRF-Token": u.csrf})
    assert r.status_code == 422


@pytest.mark.parametrize("variant", ["..%2f..%2fetc%2fpasswd", "../../../etc/passwd", "large.php", "large%00",
                                     "a" * 40, "__proto__"])
def test_variant_traversal(factory, worker_engine, variant):
    """[T-UPLOAD-TRAVERSAL]"""
    u = factory.user()
    mid = upload(u, png_bytes()).json()["id"]
    run_worker(worker_engine)
    assert u.get(f"/api/v1/media/{mid}/{variant}").status_code in (400, 404)  # NUL is rejected at the edge


def test_safe_path_refuses_escape():
    from app import media_store
    for bad in ("../x", "/etc/passwd", "a/b", "", "..", "ZZ", "abc\x00"):
        with pytest.raises(ValueError):
            media_store.safe_path("store", bad)


def test_no_shell_in_media_code():
    """[T-INJ-CMD] static guard: decoders are invoked with argv lists only."""
    src = Path(__file__).resolve().parents[1] / "app"
    for f in ("media_process.py", "worker.py"):
        text = (src / f).read_text()
        assert "shell=True" not in text and "os.system" not in text and "os.popen" not in text


def test_decoder_env_has_no_secrets(monkeypatch, tmp_path):
    from app import worker
    captured = {}

    def fake_run(cmd, **kw):
        captured.update(kw)
        raise subprocess.TimeoutExpired(cmd, 1)
    monkeypatch.setattr(worker.subprocess, "run", fake_run)
    worker.run_sandboxed("image/png", "post", tmp_path / "x", tmp_path)
    env = captured["env"]
    assert not any(k in env for k in ("DATABASE_URL", "SECRET_KEY", "WORKER_DATABASE_URL", "FIELD_ENCRYPTION_KEYS"))
    assert captured["shell"] is False and captured["preexec_fn"] is not None


# ------------------------------------------------------------------ authorization on serving
def test_post_media_follows_post_visibility(factory, worker_engine):
    a, follower, stranger = factory.user(), factory.user(), factory.user()
    follower.post(f"/api/v1/users/{a.handle}/follow")
    mid = upload(a, png_bytes()).json()["id"]
    run_worker(worker_engine)
    # unattached: only the owner
    assert fetch_variant(stranger, mid, "thumb").status_code == 404
    r = a.post("/api/v1/posts", json={"text": "pic", "media_ids": [mid], "visibility": "followers"})
    assert r.status_code == 201
    assert fetch_variant(follower, mid, "thumb").status_code == 200
    assert fetch_variant(stranger, mid, "thumb").status_code == 404
    assert factory.anon().get(f"/api/v1/media/{mid}/thumb").status_code == 404


def test_dm_media_only_for_members(factory, worker_engine):
    a, b, eve = factory.user(), factory.user(dm_policy="everyone"), factory.user()
    mid = upload(a, png_bytes(), purpose="dm").json()["id"]
    run_worker(worker_engine)
    cid = a.post("/api/v1/conversations", json={"handle": b.handle}).json()["id"]
    assert a.post(f"/api/v1/conversations/{cid}/messages", json={"media_id": mid}).status_code == 201
    assert fetch_variant(b, mid, "large").status_code == 200
    assert fetch_variant(eve, mid, "large").status_code == 404
    # media cannot be reused in a second message/post
    assert a.post(f"/api/v1/conversations/{cid}/messages", json={"media_id": mid}).status_code == 404


def test_media_cannot_be_hijacked_by_other_user(factory, worker_engine):
    a, b = factory.user(), factory.user()
    mid = upload(a, png_bytes()).json()["id"]
    run_worker(worker_engine)
    assert b.post("/api/v1/posts", json={"text": "stolen", "media_ids": [mid]}).status_code == 404
    assert b.get(f"/api/v1/media/{mid}").status_code == 404
    assert b.delete(f"/api/v1/media/{mid}").status_code == 404
    assert b.patch("/api/v1/me", json={"version": 1, "avatar_media_id": mid}).status_code == 404


def test_avatar_flow_and_purpose_binding(factory, worker_engine):
    a, b = factory.user(), factory.user()
    post_mid = upload(a, png_bytes()).json()["id"]
    av_mid = upload(a, png_bytes(300, 200), purpose="avatar").json()["id"]
    run_worker(worker_engine)
    # a post image can't be used as avatar (purpose-bound)
    assert a.patch("/api/v1/me", json={"version": 1, "avatar_media_id": post_mid}).status_code == 404
    assert a.patch("/api/v1/me", json={"version": 1, "avatar_media_id": av_mid}).status_code == 200
    r = factory.anon().get(f"/api/v1/media/{av_mid}/avatar")
    assert r.status_code == 200
    assert Image.open(io.BytesIO(r.content)).size == (400, 400)
    a.post(f"/api/v1/users/{b.handle}/block")
    assert b.get(f"/api/v1/media/{av_mid}/avatar").status_code == 404


def test_unverified_cannot_upload(factory):
    u = factory.user(verified=False)
    assert upload(u, png_bytes()).status_code == 403


def test_pending_quota(factory):
    u = factory.user()
    codes = [upload(u, png_bytes()).status_code for _ in range(11)]
    assert codes[:10] == [202] * 10 and codes[10] == 429


def test_upload_requires_csrf(factory):
    u = factory.user()
    r = u.client.post("/api/v1/media?purpose=post", content=png_bytes(), headers=H)
    assert r.status_code == 403


# ------------------------------------------------------------------ video
ffmpeg = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg not installed")


@ffmpeg
def test_video_transcode_strips_metadata(factory, worker_engine, tmp_path):
    src = tmp_path / "in.mp4"
    subprocess.run(["ffmpeg", "-loglevel", "error", "-f", "lavfi", "-i", "testsrc=duration=1:size=320x240:rate=10",
                    "-metadata", "title=SECRET_TITLE_XYZ", "-metadata", "location=+51.5-000.1/",
                    "-c:v", "libx264", "-pix_fmt", "yuv420p", "-y", str(src)], check=True)
    assert b"SECRET_TITLE_XYZ" in src.read_bytes()
    u = factory.user()
    r = upload(u, src.read_bytes())
    assert r.status_code == 202 and r.json()["kind"] == "video"
    mid = r.json()["id"]
    run_worker(worker_engine)
    st = u.get(f"/api/v1/media/{mid}").json()
    assert st["state"] == "ready", st
    out = fetch_variant(u, mid, "video").content
    assert b"SECRET_TITLE_XYZ" not in out and b"+51.5" not in out
    assert fetch_variant(u, mid, "video").headers["content-type"] == "video/mp4"
    assert fetch_variant(u, mid, "poster_large").status_code == 200


@ffmpeg
def test_video_hls_playlist_trick_rejected(factory, worker_engine):
    """A playlist disguised behind an allowed signature must not make ffmpeg read local files/URLs."""
    u = factory.user()
    fake = b"\x00\x00\x00\x18ftypisom\x00\x00\x00\x00isom" + b"#EXTM3U\n#EXTINF:1,\nfile:///etc/passwd\n"
    mid = upload(u, fake).json()["id"]
    run_worker(worker_engine)
    assert u.get(f"/api/v1/media/{mid}").json()["state"] == "failed"


def test_avatar_must_be_image(factory, tmp_path):
    u = factory.user()
    fake_mp4 = b"\x00\x00\x00\x18ftypisom" + b"\x00" * 64
    assert upload(u, fake_mp4, purpose="avatar").status_code == 415

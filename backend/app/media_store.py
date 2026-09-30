"""Media storage primitives: signature sniffing and safe paths.

* Types are decided ONLY by magic bytes. The client's Content-Type and filename are ignored
  (there is no filename: uploads are raw bodies).
* Allow-list: JPEG, PNG, GIF, WebP, MP4/MOV (ISO-BMFF with allow-listed brands), WebM/Matroska.
  Everything else (SVG, HTML, XML, PDF, ZIP, executables, scripts…) is rejected.
* Storage keys are server-generated random hex; paths are resolved and verified to stay inside
  the configured root (defence in depth against traversal, CWE-22).
"""
from __future__ import annotations

import os
import secrets
from pathlib import Path

from .config import get_settings

IMAGE_TYPES = {"image/jpeg", "image/png", "image/gif", "image/webp"}
VIDEO_TYPES = {"video/mp4", "video/webm"}
MAX_IMAGE_BYTES = 20 * 1024 * 1024
MAX_VIDEO_BYTES = 100 * 1024 * 1024
MP4_BRANDS = {b"isom", b"iso2", b"iso4", b"iso5", b"iso6", b"mp41", b"mp42", b"avc1", b"M4V ", b"qt  ", b"dash", b"MSNV"}
SERVE_TYPES = {"image/webp", "video/mp4"}  # the only types we ever *serve* (derivatives)


def sniff(head: bytes) -> str | None:
    if head.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    if head.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if head[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    if len(head) >= 12 and head[:4] == b"RIFF" and head[8:12] == b"WEBP":
        return "image/webp"
    if len(head) >= 12 and head[4:8] == b"ftyp" and head[8:12] in MP4_BRANDS:
        return "video/mp4"
    if head.startswith(b"\x1a\x45\xdf\xa3"):
        return "video/webm"
    return None


def kind_of(mime: str) -> str:
    return "video" if mime in VIDEO_TYPES else "image"


def max_bytes(mime: str) -> int:
    return MAX_VIDEO_BYTES if mime in VIDEO_TYPES else MAX_IMAGE_BYTES


def new_key() -> str:
    return secrets.token_hex(16)


def _root(which: str) -> Path:
    s = get_settings()
    return Path(s.media_quarantine_dir if which == "quarantine" else s.media_store_dir).resolve()


def safe_path(which: str, key: str) -> Path:
    if not key or not all(c in "0123456789abcdef" for c in key) or len(key) > 64:
        raise ValueError("invalid storage key")
    root = _root(which)
    p = (root / key).resolve()
    if p.parent != root:
        raise ValueError("path escapes storage root")
    return p


def open_exclusive(path: Path):
    """Create a new file that must not already exist, owner-only permissions, never following symlinks."""
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    return os.fdopen(fd, "wb")


def remove_quietly(path: Path) -> None:
    try:
        path.unlink()
    except FileNotFoundError:
        pass

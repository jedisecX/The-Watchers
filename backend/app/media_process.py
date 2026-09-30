"""Untrusted-media decoder. Runs as a *separate process* per file (THREAT-MODEL TB3, D-2, E-5).

Invoked by the worker as:  python -m app.media_process <sniffed_type> <purpose> <input> <outdir>

It is started with rlimits (address space, CPU, file size, open files), a wall-clock timeout and an
**empty environment**. It has no DB credentials, no secrets and no network need. It writes
derivatives into <outdir> and prints a JSON result on stdout. Any exception → non-zero exit.

Images: header-only dimension check BEFORE decoding → decode → apply EXIF orientation → rebuild
from raw pixels → encode WebP. Nothing from the original container (EXIF/GPS, ICC, XMP, comments,
trailing polyglot payloads, extra chunks) can survive, because we serialise pixels only.

Video: ffprobe (demuxer allow-list, ``-protocol_whitelist file`` so a crafted playlist cannot
read local files or fetch URLs) → limits on duration/dimensions/streams → ffmpeg transcode to
H.264/AAC MP4 with all metadata and chapters dropped → poster frame via the image path.
"""
from __future__ import annotations

import json
import subprocess
import sys
import warnings
from pathlib import Path

MAX_PIXELS = 40_000_000
MAX_SIDE = 8192
MAX_VIDEO_SECONDS = 140
MAX_VIDEO_SIDE = 4096
VIDEO_DEMUXERS = {"mov,mp4,m4a,3gp,3g2,mj2": "mp4", "matroska,webm": "matroska"}
PIL_FORMATS = {"image/jpeg": "JPEG", "image/png": "PNG", "image/gif": "GIF", "image/webp": "WEBP"}

VARIANTS = {
    "post": [("large", 2048, False), ("thumb", 480, False)],
    "dm": [("large", 2048, False), ("thumb", 480, False)],
    "avatar": [("avatar", 400, True), ("avatar_small", 96, True)],
}


class Reject(Exception):
    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


def _image(sniffed: str, purpose: str, src: Path, out: Path, prefix: str = "") -> dict:
    from PIL import Image, ImageOps

    Image.MAX_IMAGE_PIXELS = MAX_PIXELS
    warnings.simplefilter("error", Image.DecompressionBombWarning)
    try:
        img = Image.open(src)  # parses the header only
    except Image.DecompressionBombError as exc:
        raise Reject("too_many_pixels") from exc
    except Exception as exc:
        raise Reject("undecodable") from exc
    if img.format != PIL_FORMATS.get(sniffed):
        raise Reject("type_mismatch")  # container disagrees with signature → parser confusion attempt
    w, h = img.size
    if w < 1 or h < 1 or w > MAX_SIDE or h > MAX_SIDE or w * h > MAX_PIXELS:
        raise Reject("too_many_pixels")
    try:
        img.seek(0)  # animated GIF/WebP: first frame only
        img.load()
        img = ImageOps.exif_transpose(img)
    except (Image.DecompressionBombError, Image.DecompressionBombWarning) as exc:
        raise Reject("too_many_pixels") from exc
    except Exception as exc:
        raise Reject("undecodable") from exc
    mode = "RGBA" if (img.mode in ("RGBA", "LA", "PA") or "transparency" in img.info) else "RGB"
    img = img.convert(mode)
    # Rebuild from raw pixels: guarantees no metadata/ancillary data survives.
    clean = Image.frombytes(mode, img.size, img.tobytes())
    result = {"width": clean.width, "height": clean.height, "variants": {}}
    for name, size, square in VARIANTS[purpose]:
        v = ImageOps.fit(clean, (size, size), Image.Resampling.LANCZOS) if square else clean.copy()
        if not square:
            v.thumbnail((size, size), Image.Resampling.LANCZOS)
        fname = f"{prefix}{name}.webp"
        v.save(out / fname, format="WEBP", quality=85, method=4)
        result["variants"][prefix + name] = {"file": fname, "content_type": "image/webp",
                                             "width": v.width, "height": v.height}
    return result


def _run(cmd: list[str], timeout: int) -> subprocess.CompletedProcess:
    # Argument vector, never a shell string (THREAT-MODEL E-6).
    return subprocess.run(cmd, capture_output=True, timeout=timeout, check=False, shell=False)  # noqa: S603


def _video(src: Path, out: Path) -> dict:
    probe = _run(["ffprobe", "-v", "error", "-protocol_whitelist", "file", "-print_format", "json",
                  "-show_format", "-show_streams", "--", str(src)], 30)
    if probe.returncode != 0:
        raise Reject("undecodable")
    try:
        info = json.loads(probe.stdout)
        fmt = info["format"]["format_name"]
        duration = float(info["format"].get("duration", 0))
        streams = info["streams"]
    except (ValueError, KeyError, TypeError) as exc:
        raise Reject("undecodable") from exc
    demux = VIDEO_DEMUXERS.get(fmt)
    if demux is None:
        raise Reject("format_not_allowed")
    vids = [s for s in streams if s.get("codec_type") == "video"]
    auds = [s for s in streams if s.get("codec_type") == "audio"]
    if len(vids) != 1 or len(auds) > 1 or len(streams) > 4:
        raise Reject("bad_streams")
    w, h = int(vids[0].get("width", 0)), int(vids[0].get("height", 0))
    if not (0 < w <= MAX_VIDEO_SIDE and 0 < h <= MAX_VIDEO_SIDE):
        raise Reject("too_many_pixels")
    if not (0 < duration <= MAX_VIDEO_SECONDS):
        raise Reject("too_long")
    dst = out / "video.mp4"
    cmd = ["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-protocol_whitelist", "file",
           "-f", demux, "-i", str(src), "-map", "0:v:0"]
    if auds:
        cmd += ["-map", "0:a:0", "-c:a", "aac", "-b:a", "96k"]
    cmd += ["-t", str(MAX_VIDEO_SECONDS), "-vf", "scale='min(1280,iw)':-2", "-c:v", "libx264", "-preset",
            "veryfast", "-crf", "28", "-pix_fmt", "yuv420p", "-map_metadata", "-1", "-map_chapters", "-1",
            "-movflags", "+faststart", "-threads", "2", "-f", "mp4", "-y", str(dst)]
    if _run(cmd, 120).returncode != 0 or not dst.exists():
        raise Reject("transcode_failed")
    poster_png = out / "poster_src.png"
    if _run(["ffmpeg", "-nostdin", "-loglevel", "error", "-protocol_whitelist", "file", "-i", str(dst),
             "-frames:v", "1", "-f", "image2", "-y", str(poster_png)], 30).returncode != 0:
        raise Reject("transcode_failed")
    poster = _image("image/png", "post", poster_png, out, prefix="poster_")
    poster_png.unlink()
    variants = {"video": {"file": "video.mp4", "content_type": "video/mp4", "width": min(w, 1280), "height": None}}
    variants.update(poster["variants"])
    return {"width": w, "height": h, "duration_ms": int(duration * 1000), "variants": variants}


def main(argv: list[str]) -> int:
    sniffed, purpose, src, out = argv[1], argv[2], Path(argv[3]), Path(argv[4])
    if purpose not in VARIANTS:
        print(json.dumps({"error": "bad_purpose"}))
        return 2
    try:
        if sniffed.startswith("image/"):
            res = _image(sniffed, purpose, src, out)
        elif sniffed.startswith("video/") and purpose in ("post", "dm"):
            res = _video(src, out)
        else:
            raise Reject("type_not_allowed")
    except Reject as r:
        print(json.dumps({"error": r.code}))
        return 3
    except subprocess.TimeoutExpired:
        print(json.dumps({"error": "timeout"}))
        return 4
    except MemoryError:
        print(json.dumps({"error": "resource_limit"}))
        return 5
    print(json.dumps(res))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))

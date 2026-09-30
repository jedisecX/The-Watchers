"""Media worker (separate container, DB role ``bastion_worker``: media table only).

    python -m app.worker            # run forever
    python -m app.worker --once     # process one job (tests/ops)

Each job: claim with FOR UPDATE SKIP LOCKED → run ``app.media_process`` in a sandboxed child
(rlimits + timeout + empty env) → move derivatives to random names in the media store →
mark ready/failed → always delete the quarantined original. Stuck jobs (worker crash) are
re-claimed after 5 minutes, at most 3 attempts.
"""
from __future__ import annotations

import json
import logging
import os
import shutil
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path

from sqlalchemy import create_engine, text
from sqlalchemy.engine import Engine

from . import media_store
from .config import get_settings
from .logging_setup import configure

log = logging.getLogger("bastion.worker")

LIMITS = {
    "image": {"as": 768 * 1024 * 1024, "cpu": 20, "wall": 30},
    "video": {"as": 2048 * 1024 * 1024, "cpu": 240, "wall": 300},
}
MAX_OUTPUT = 300 * 1024 * 1024


def _preexec(kind: str):
    import resource

    lim = LIMITS[kind]

    def apply():  # runs in the child between fork and exec
        os.setsid()
        resource.setrlimit(resource.RLIMIT_AS, (lim["as"], lim["as"]))
        resource.setrlimit(resource.RLIMIT_CPU, (lim["cpu"], lim["cpu"]))
        resource.setrlimit(resource.RLIMIT_FSIZE, (MAX_OUTPUT, MAX_OUTPUT))
        resource.setrlimit(resource.RLIMIT_NOFILE, (64, 64))
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    return apply


def run_sandboxed(sniffed: str, purpose: str, src: Path, outdir: Path) -> dict:
    kind = media_store.kind_of(sniffed)
    backend_dir = str(Path(__file__).resolve().parents[1])
    # Empty environment: the decoder never sees DB URLs, secrets, or proxy settings.
    env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "PYTHONPATH": backend_dir, "LC_ALL": "C.UTF-8",
           "OMP_NUM_THREADS": "1", "MALLOC_ARENA_MAX": "2"}
    try:
        proc = subprocess.run(  # noqa: S603 - fixed argv, no shell
            [sys.executable, "-s", "-m", "app.media_process", sniffed, purpose, str(src), str(outdir)],
            capture_output=True, timeout=LIMITS[kind]["wall"], env=env, cwd=str(outdir),
            preexec_fn=_preexec(kind), check=False, shell=False,
        )
    except subprocess.TimeoutExpired:
        return {"error": "timeout"}
    try:
        res = json.loads(proc.stdout.decode("utf-8", "replace").strip().splitlines()[-1])
    except (ValueError, IndexError):
        # Crash (e.g. SIGSEGV in a decoder, or killed by RLIMIT): treat as hostile input.
        return {"error": "decoder_crash" if proc.returncode < 0 else "resource_limit"}
    if proc.returncode != 0 and "error" not in res:
        return {"error": "decoder_failed"}
    return res


CLAIM_SQL = text("""
UPDATE media SET state = 'processing', claimed_at = now(), attempts = attempts + 1
WHERE id = (
  SELECT id FROM media
  WHERE (state = 'pending')
     OR (state = 'processing' AND claimed_at < now() - interval '5 minutes' AND attempts < 3)
  ORDER BY created_at
  FOR UPDATE SKIP LOCKED
  LIMIT 1)
RETURNING id, sniffed_type, purpose, quarantine_key, attempts
""")


def process_one(engine: Engine) -> bool:
    with engine.begin() as c:
        row = c.execute(CLAIM_SQL).first()
    if row is None:
        with engine.begin() as c:  # give up on jobs that keep crashing the worker
            c.execute(text("UPDATE media SET state='failed', error_code='too_many_attempts', quarantine_key=NULL "
                           "WHERE state='processing' AND attempts >= 3 AND claimed_at < now() - interval '5 minutes'"))
        return False
    media_id, sniffed, purpose, qkey, _attempts = row
    src = media_store.safe_path("quarantine", qkey) if qkey else None
    store_root = Path(get_settings().media_store_dir).resolve()
    workdir = Path(tempfile.mkdtemp(prefix="job-", dir=store_root))
    try:
        if src is None or not src.exists():
            result = {"error": "missing_source"}
        else:
            result = run_sandboxed(sniffed, purpose, src, workdir)
        if "error" in result:
            with engine.begin() as c:
                c.execute(text("UPDATE media SET state='failed', error_code=:e, processed_at=now(), "
                               "quarantine_key=NULL WHERE id=:id"), {"e": str(result["error"])[:32], "id": media_id})
            log.warning("media.rejected", extra={"event": "media.decode_failed", "media_id": str(media_id),
                                                  "error_code": result["error"]})
            return True
        variants = {}
        for name, v in result["variants"].items():
            produced = (workdir / v["file"]).resolve()
            if produced.parent != workdir.resolve() or not produced.is_file():
                raise RuntimeError("decoder produced an unexpected path")
            if v["content_type"] not in media_store.SERVE_TYPES:
                raise RuntimeError("decoder produced a non-allow-listed type")
            key = media_store.new_key()
            os.replace(produced, media_store.safe_path("store", key))
            variants[name] = {"key": key, "content_type": v["content_type"], "width": v.get("width"),
                              "height": v.get("height"), "size": media_store.safe_path("store", key).stat().st_size}
        with engine.begin() as c:
            c.execute(text("UPDATE media SET state='ready', variants=CAST(:v AS jsonb), width=:w, height=:h, "
                           "duration_ms=:d, processed_at=:t, quarantine_key=NULL, error_code=NULL WHERE id=:id"),
                      {"v": json.dumps(variants), "w": result.get("width"), "h": result.get("height"),
                       "d": result.get("duration_ms"), "t": datetime.now(timezone.utc), "id": media_id})
        log.info("media.ready", extra={"event": "media.ready", "media_id": str(media_id)})
        return True
    finally:
        if src is not None:
            media_store.remove_quietly(src)  # the original never outlives processing
        shutil.rmtree(workdir, ignore_errors=True)


def worker_engine() -> Engine:
    url = os.environ.get("WORKER_DATABASE_URL")
    if not url and os.environ.get("WORKER_DATABASE_URL_FILE"):
        url = Path(os.environ["WORKER_DATABASE_URL_FILE"]).read_text().strip()
    if not url:
        raise SystemExit("WORKER_DATABASE_URL is required")
    return create_engine(url, pool_pre_ping=True, pool_size=2,
                         connect_args={"options": "-c statement_timeout=10000"})


def main() -> None:
    configure(get_settings().log_level)
    eng = worker_engine()
    once = "--once" in sys.argv
    while True:
        try:
            did = process_one(eng)
        except Exception:  # noqa: BLE001 - keep the worker alive; job will be retried/failed
            log.exception("worker.error", extra={"event": "worker.error"})
            did = False
        if once:
            return
        if not did:
            time.sleep(1.0)


if __name__ == "__main__":
    main()

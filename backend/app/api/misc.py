"""Operational endpoints.

| Endpoint | Auth | Notes |
|---|---|---|
| GET /api/v1/health | none | liveness + DB reachability; reveals nothing else |
| POST /api/v1/csp-report | none | CSP violation reports; rate-limited, size-capped, fields allow-listed |
"""
from __future__ import annotations

import json
import logging

from fastapi import APIRouter, Depends, Request, Response
from sqlalchemy import text
from sqlalchemy.orm import Session

from ..authz import Principal
from ..db import get_db
from ..deps import enforce_limit, principal

router = APIRouter(tags=["ops"])
log = logging.getLogger("bastion.csp")


@router.get("/api/v1/health")
def health(db: Session = Depends(get_db)):
    try:
        db.execute(text("SELECT 1"))
    except Exception:  # noqa: BLE001
        return Response('{"status":"degraded"}', status_code=503, media_type="application/json")
    return {"status": "ok"}


@router.post("/api/v1/csp-report", status_code=204)
async def csp_report(request: Request, p: Principal = Depends(principal)):
    """Browsers post ``application/csp-report``; this route deliberately bypasses StrictRoute's JSON
    content-type rule. Only a handful of fields are logged, and they are truncated."""
    enforce_limit("csp_report", f"ip:{p.rl_ip}", "anon")
    raw = await request.body()
    try:
        data = json.loads(raw[:8192]) if raw else {}
        rep = data.get("csp-report", {}) if isinstance(data, dict) else {}
    except ValueError:
        rep = {}
    if isinstance(rep, dict):
        log.warning("csp.violation", extra={
            "event": "csp.violation",
            "directive": str(rep.get("violated-directive", ""))[:100],
            "blocked": str(rep.get("blocked-uri", ""))[:200],
            "doc": str(rep.get("document-uri", ""))[:200],
        })
    return Response(status_code=204)

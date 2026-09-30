"""Structured JSON logging with defence-in-depth redaction.

Rules:
* Never log request or response bodies.
* Any field whose *key* looks sensitive is replaced with ``[REDACTED]``, recursively.
* Values that look like our token formats (``bst_…`` PATs, long base64url strings in messages)
  are masked, as a backstop against accidental logging.
* Log records are JSON objects, so user-controlled strings cannot forge extra log lines
  (CWE-117 log injection): newlines are escaped by the JSON encoder.
"""
from __future__ import annotations

import json
import logging
import re
import sys
import time
from typing import Any

SENSITIVE_KEY = re.compile(
    r"(pass(word)?|secret|token|cookie|authori[sz]ation|session|csrf|otp|totp|recovery|code|private|api[_-]?key|credential|signature|body|message_text)",
    re.I,
)
TOKEN_VALUE = re.compile(r"(bst_[A-Za-z0-9_\-]{8,}|[A-Za-z0-9_\-]{40,})")
_STD = set(vars(logging.LogRecord("", 0, "", 0, "", (), None)).keys()) | {"message", "asctime", "taskName"}
_SAFE_KEYS = {"event", "path", "method", "status", "duration_ms", "request_id", "user_id", "actor_id",
              "ip_prefix", "route", "bucket", "action", "target_type", "target_id", "reason_code",
              "count", "media_id", "state", "kind", "error_code", "conn_id"}


def redact(value: Any, key: str = "") -> Any:
    if key and key not in _SAFE_KEYS and SENSITIVE_KEY.search(key):
        return "[REDACTED]"
    if isinstance(value, dict):
        return {str(k): redact(v, str(k)) for k, v in list(value.items())[:50]}
    if isinstance(value, (list, tuple)):
        return [redact(v) for v in list(value)[:50]]
    if isinstance(value, str):
        v = value[:1000]
        return TOKEN_VALUE.sub("[REDACTED-TOKEN]", v)
    if isinstance(value, (int, float, bool)) or value is None:
        return value
    return redact(str(value))


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        out: dict[str, Any] = {
            "ts": time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(record.created)) + f".{int(record.msecs):03d}Z",
            "level": record.levelname,
            "logger": record.name,
            "msg": redact(record.getMessage()),
        }
        for k, v in record.__dict__.items():
            if k not in _STD:
                out[k] = redact(v, k)
        if record.exc_info:
            # Exception *type* only; tracebacks can contain secrets from locals/args in some libs.
            et = record.exc_info[0]
            out["exc_type"] = et.__name__ if et else None
        return json.dumps(out, ensure_ascii=True, default=str)


def configure(level: str = "INFO") -> None:
    root = logging.getLogger()
    if any(isinstance(h.formatter, JsonFormatter) for h in root.handlers):
        return
    h = logging.StreamHandler(sys.stdout)
    h.setFormatter(JsonFormatter())
    root.handlers = [h]
    root.setLevel(level)
    for noisy in ("uvicorn.access",):
        logging.getLogger(noisy).setLevel(logging.WARNING)


security_log = logging.getLogger("bastion.security")


def sec_event(event: str, **fields: Any) -> None:
    """Emit a security event. Callers pass identifiers only, never secrets or message content."""
    security_log.info(event, extra={"event": event, **fields})

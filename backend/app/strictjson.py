"""Strict JSON decoding, used for every request body.

Defends against parser differentials (T-7): a proxy/WAF and this app must never disagree
about what a body means. So we reject:

* duplicate object keys (``{"a":1,"a":2}``)
* ``NaN``, ``Infinity``, ``-Infinity``
* nesting deeper than ``MAX_DEPTH``
* non-UTF-8 bodies and lone surrogates
* top-level values other than objects
"""
from __future__ import annotations

import json
from typing import Any

MAX_DEPTH = 32


class StrictJSONError(ValueError):
    pass


def _pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for k, v in pairs:
        if k in out:
            raise StrictJSONError("duplicate_key")
        out[k] = v
    return out


def _const(_: str) -> Any:
    raise StrictJSONError("invalid_constant")


def _depth(obj: Any, limit: int = MAX_DEPTH) -> None:
    stack = [(obj, 1)]
    while stack:
        cur, d = stack.pop()
        if d > limit:
            raise StrictJSONError("too_deep")
        if isinstance(cur, dict):
            stack.extend((v, d + 1) for v in cur.values())
        elif isinstance(cur, list):
            stack.extend((v, d + 1) for v in cur)


def _no_surrogates(obj: Any) -> None:
    stack = [obj]
    while stack:
        cur = stack.pop()
        if isinstance(cur, str):
            if "\x00" in cur:  # never meaningful, and unstorable in Postgres text (finding ADV-01)
                raise StrictJSONError("nul_byte")
            try:
                cur.encode("utf-8")
            except UnicodeEncodeError as exc:
                raise StrictJSONError("invalid_unicode") from exc
        elif isinstance(cur, dict):
            for k, v in cur.items():
                stack.append(k)
                stack.append(v)
        elif isinstance(cur, list):
            stack.extend(cur)


def loads(raw: bytes) -> dict[str, Any]:
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise StrictJSONError("invalid_encoding") from exc
    # Nesting check on the raw text first prevents recursion-depth abuse inside json itself.
    depth = 0
    in_str = esc = False
    for ch in text:
        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == '"':
                in_str = False
        elif ch == '"':
            in_str = True
        elif ch in "[{":
            depth += 1
            if depth > MAX_DEPTH:
                raise StrictJSONError("too_deep")
        elif ch in "]}":
            depth -= 1
    try:
        obj = json.loads(text, object_pairs_hook=_pairs, parse_constant=_const)
    except StrictJSONError:
        raise
    except (ValueError, RecursionError) as exc:
        raise StrictJSONError("malformed") from exc
    if not isinstance(obj, dict):
        raise StrictJSONError("not_an_object")
    _depth(obj)
    _no_surrogates(obj)
    return obj

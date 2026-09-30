"""Uniform error envelope: ``{"error": {"code": str, "message": str, "request_id": str}}``.

Messages are static strings chosen by the server. They never echo user input and never
include stack traces, SQL, or internal identifiers.
"""
from __future__ import annotations

import logging

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

log = logging.getLogger("bastion.errors")


class ApiError(Exception):
    def __init__(self, status: int, code: str, message: str, headers: dict[str, str] | None = None):
        super().__init__(code)
        self.status = status
        self.code = code
        self.message = message
        self.headers = headers or {}


def not_found(what: str = "Resource") -> ApiError:
    return ApiError(404, "not_found", f"{what} not found.")


def forbidden(message: str = "You are not allowed to do that.") -> ApiError:
    return ApiError(403, "forbidden", message)


def unauthorized() -> ApiError:
    return ApiError(401, "unauthenticated", "Authentication required.")


def bad_request(code: str, message: str) -> ApiError:
    return ApiError(400, code, message)


def conflict(code: str, message: str) -> ApiError:
    return ApiError(409, code, message)


def _rid(request: Request) -> str:
    return getattr(request.state, "request_id", "")


def error_response(request: Request, status: int, code: str, message: str, headers: dict | None = None) -> JSONResponse:
    return JSONResponse(
        {"error": {"code": code, "message": message, "request_id": _rid(request)}},
        status_code=status,
        headers=headers,
    )


_HTTP_CODES = {
    400: ("bad_request", "Bad request."),
    401: ("unauthenticated", "Authentication required."),
    403: ("forbidden", "You are not allowed to do that."),
    404: ("not_found", "Resource not found."),
    405: ("method_not_allowed", "Method not allowed."),
    413: ("payload_too_large", "Request body too large."),
    415: ("unsupported_media_type", "Unsupported content type."),
    429: ("rate_limited", "Too many requests."),
}


def install(app: FastAPI) -> None:
    @app.exception_handler(ApiError)
    async def _api_error(request: Request, exc: ApiError):
        return error_response(request, exc.status, exc.code, exc.message, exc.headers)

    @app.exception_handler(StarletteHTTPException)
    async def _http_error(request: Request, exc: StarletteHTTPException):
        code, msg = _HTTP_CODES.get(exc.status_code, ("error", "Request failed."))
        return error_response(request, exc.status_code, code, msg)

    @app.exception_handler(RequestValidationError)
    async def _validation(request: Request, exc: RequestValidationError):
        # Report *where* validation failed (field paths + error types) but never echo input values.
        details = []
        for e in exc.errors()[:20]:
            loc = [str(p) for p in e.get("loc", ())][:6]
            details.append({"field": ".".join(loc), "type": str(e.get("type", "invalid"))[:40]})
        if any(d["type"] == "extra_forbidden" for d in details):
            log.warning("api.schema_violation", extra={"event": "api.schema_violation", "path": request.url.path})
        return JSONResponse(
            {"error": {"code": "validation_error", "message": "Request validation failed.",
                       "request_id": _rid(request), "details": details}},
            status_code=422,
        )

    @app.exception_handler(Exception)
    async def _unhandled(request: Request, exc: Exception):
        log.error("unhandled_exception", exc_info=exc, extra={"event": "http.unhandled", "path": request.url.path})
        return error_response(request, 500, "internal_error", "An internal error occurred.")


def ensure(condition: object) -> None:
    """Runtime invariant check that survives ``python -O`` (unlike ``assert``). Never use assert for
    security: it is stripped under optimisation (bandit B101)."""
    if not condition:
        raise ApiError(401, "unauthenticated", "Authentication required.")

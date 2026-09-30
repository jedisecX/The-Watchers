"""Application factory."""
from __future__ import annotations

from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from starlette.middleware.trustedhost import TrustedHostMiddleware

from . import errors
from .config import get_settings
from .logging_setup import configure as configure_logging
from .middleware import (
    BodyLimitMiddleware,
    QueryPollutionMiddleware,
    RequestContextMiddleware,
    SecurityHeadersMiddleware,
)

FRONTEND_DIR = Path(__file__).resolve().parents[2] / "frontend"


def create_app() -> FastAPI:
    s = get_settings()
    configure_logging(s.log_level)

    @asynccontextmanager
    async def lifespan(_app):
        from .realtime import broker
        broker.start()
        yield

    app = FastAPI(
        lifespan=lifespan,
        title="Bastion API",
        version="1.0.0",
        # Interactive docs are off in production (reduces surface; the OpenAPI JSON is published in docs/).
        docs_url=None if s.is_production else "/api/docs",
        redoc_url=None,
        openapi_url=None if s.is_production else "/api/openapi.json",
    )
    errors.install(app)

    from .api import account, auth, misc

    for r in (auth.router, account.router, misc.router):
        app.include_router(r)
    for mod in ("users", "posts", "social", "notifications", "dm", "media", "reports", "search", "ws", "admin"):
        try:
            m = __import__(f"app.api.{mod}", fromlist=["router"])
        except ModuleNotFoundError as exc:
            if exc.name != f"app.api.{mod}":
                raise
            continue
        app.include_router(m.router)

    @app.api_route("/api/{rest:path}", methods=["GET", "POST", "PUT", "PATCH", "DELETE"], include_in_schema=False)
    def api_404(rest: str):
        # Unknown API paths get a JSON 404 and never fall through to the SPA (T-6 cache deception).
        return JSONResponse({"error": {"code": "not_found", "message": "Resource not found.", "request_id": ""}},
                            status_code=404)

    if (FRONTEND_DIR / "assets").is_dir() and not s.is_production:
        # In production nginx serves static assets; this is for development convenience.
        app.mount("/assets", StaticFiles(directory=FRONTEND_DIR / "assets"), name="assets")

        @app.get("/{path:path}", include_in_schema=False)
        def spa(path: str):
            return FileResponse(FRONTEND_DIR / ("admin.html" if path.startswith("admin") else "index.html"),
                                headers={"Cache-Control": "no-cache"})

    # Middleware: added innermost first. Effective order (outer→inner):
    # RequestContext → TrustedHost → SecurityHeaders → BodyLimit → QueryPollution → app
    app.add_middleware(QueryPollutionMiddleware)
    app.add_middleware(BodyLimitMiddleware)
    app.add_middleware(SecurityHeadersMiddleware)
    app.add_middleware(TrustedHostMiddleware, allowed_hosts=list(s.allowed_hosts))
    app.add_middleware(RequestContextMiddleware)
    return app

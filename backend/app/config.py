"""Runtime configuration.

Every secret is read from the environment, or from a file named by ``<NAME>_FILE``
(Docker/Kubernetes secrets convention). Nothing secret has a default in production:
``Settings.validate()`` refuses to start with an insecure configuration.
"""
from __future__ import annotations

import base64
import ipaddress
import os
from dataclasses import dataclass, field
from functools import lru_cache
from urllib.parse import urlsplit


class ConfigError(RuntimeError):
    pass


def _raw(name: str, default: str | None = None) -> str | None:
    file_var = os.environ.get(f"{name}_FILE")
    if file_var:
        try:
            with open(file_var, encoding="utf-8") as fh:
                return fh.read().strip()
        except OSError as exc:  # pragma: no cover - deployment error
            raise ConfigError(f"cannot read secret file for {name}") from exc
    return os.environ.get(name, default)


def _int(name: str, default: int) -> int:
    v = _raw(name)
    return default if v in (None, "") else int(v)


def _bool(name: str, default: bool) -> bool:
    v = _raw(name)
    if v in (None, ""):
        return default
    return v.strip().lower() in {"1", "true", "yes", "on"}


def _list(name: str, default: str = "") -> list[str]:
    v = _raw(name, default) or ""
    return [x.strip() for x in v.split(",") if x.strip()]


@dataclass(frozen=True)
class Settings:
    env: str
    database_url: str
    redis_url: str | None
    secret_key: bytes
    field_encryption_keys: tuple[bytes, ...]
    public_base_url: str
    allowed_hosts: tuple[str, ...]
    allowed_origins: tuple[str, ...]
    trusted_proxies: tuple[ipaddress.IPv4Network | ipaddress.IPv6Network, ...]
    webauthn_rp_id: str
    webauthn_rp_name: str
    argon2_time_cost: int
    argon2_memory_kib: int
    argon2_parallelism: int
    argon2_max_concurrency: int
    smtp_host: str | None
    smtp_port: int
    smtp_user: str | None
    smtp_password: str | None
    smtp_from: str
    smtp_starttls: bool
    mail_backend: str  # "smtp" | "outbox-only"
    media_quarantine_dir: str
    media_store_dir: str
    media_serve_mode: str  # "direct" | "x-accel"
    media_x_accel_prefix: str
    pow_base_difficulty: int
    session_idle_minutes: int
    session_absolute_days: int
    priv_session_idle_minutes: int
    stepup_minutes: int
    log_level: str
    hsts_preload: bool
    extra: dict = field(default_factory=dict)

    @property
    def is_production(self) -> bool:
        return self.env == "production"

    @property
    def is_test(self) -> bool:
        return self.env == "test"

    def validate(self) -> None:
        if self.env not in {"development", "test", "production"}:
            raise ConfigError("ENV must be development, test or production")
        if len(self.secret_key) < 32:
            raise ConfigError("SECRET_KEY must be at least 32 bytes")
        if not self.field_encryption_keys:
            raise ConfigError("FIELD_ENCRYPTION_KEYS is required")
        for k in self.field_encryption_keys:
            if len(k) != 32:
                raise ConfigError("each FIELD_ENCRYPTION_KEYS entry must decode to 32 bytes")
        if self.media_serve_mode not in {"direct", "x-accel"}:
            raise ConfigError("MEDIA_SERVE_MODE must be direct or x-accel")
        if self.is_production:
            if not self.public_base_url.startswith("https://"):
                raise ConfigError("PUBLIC_BASE_URL must be https in production")
            if not self.redis_url:
                raise ConfigError("REDIS_URL is required in production (shared rate limits)")
            if self.mail_backend != "smtp" or not self.smtp_host:
                raise ConfigError("SMTP must be configured in production")
            if b"insecure" in self.secret_key or b"change-me" in self.secret_key:
                raise ConfigError("SECRET_KEY looks like a placeholder")
            if self.argon2_memory_kib < 19 * 1024:
                raise ConfigError("argon2 memory below OWASP minimum (19 MiB)")
            if self.pow_base_difficulty < 16:
                raise ConfigError("POW_BASE_DIFFICULTY too low for production")


def _decode_key(s: str) -> bytes:
    try:
        return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))
    except Exception as exc:
        raise ConfigError("FIELD_ENCRYPTION_KEYS entries must be urlsafe base64") from exc


def load_settings() -> Settings:
    env = (_raw("ENV", "development") or "development").strip()
    base = (_raw("PUBLIC_BASE_URL", "http://localhost:8000") or "").rstrip("/")
    parts = urlsplit(base)
    host = parts.hostname or "localhost"
    origin = f"{parts.scheme}://{parts.netloc}"
    secret = _raw("SECRET_KEY")
    if not secret:
        if env == "production":
            raise ConfigError("SECRET_KEY is required")
        secret = "dev-insecure-secret-key-do-not-use-in-production-000"
    fek = _list("FIELD_ENCRYPTION_KEYS")
    if not fek:
        if env == "production":
            raise ConfigError("FIELD_ENCRYPTION_KEYS is required")
        fek = [base64.urlsafe_b64encode(b"\x00" * 32).decode()]
    proxies = []
    for cidr in _list("TRUSTED_PROXIES", ""):
        proxies.append(ipaddress.ip_network(cidr, strict=False))
    s = Settings(
        env=env,
        database_url=_raw("DATABASE_URL", "postgresql+psycopg://bastion_app:bastion_app@localhost/bastion") or "",
        redis_url=_raw("REDIS_URL") or None,
        secret_key=secret.encode(),
        field_encryption_keys=tuple(_decode_key(k) for k in fek),
        public_base_url=base,
        allowed_hosts=tuple(_list("ALLOWED_HOSTS", host)),
        allowed_origins=tuple(_list("ALLOWED_ORIGINS", origin)),
        trusted_proxies=tuple(proxies),
        webauthn_rp_id=_raw("WEBAUTHN_RP_ID", host) or host,
        webauthn_rp_name=_raw("WEBAUTHN_RP_NAME", "Bastion") or "Bastion",
        argon2_time_cost=_int("ARGON2_TIME_COST", 3),
        argon2_memory_kib=_int("ARGON2_MEMORY_KIB", 64 * 1024),
        argon2_parallelism=_int("ARGON2_PARALLELISM", 4),
        argon2_max_concurrency=_int("ARGON2_MAX_CONCURRENCY", 8),
        smtp_host=_raw("SMTP_HOST") or None,
        smtp_port=_int("SMTP_PORT", 587),
        smtp_user=_raw("SMTP_USER") or None,
        smtp_password=_raw("SMTP_PASSWORD") or None,
        smtp_from=_raw("SMTP_FROM", "Bastion <no-reply@localhost>") or "",
        smtp_starttls=_bool("SMTP_STARTTLS", True),
        mail_backend=_raw("MAIL_BACKEND", "outbox-only") or "outbox-only",
        media_quarantine_dir=_raw("MEDIA_QUARANTINE_DIR", "/tmp/bastion/quarantine") or "",
        media_store_dir=_raw("MEDIA_STORE_DIR", "/tmp/bastion/media") or "",
        media_serve_mode=_raw("MEDIA_SERVE_MODE", "direct") or "direct",
        media_x_accel_prefix=_raw("MEDIA_X_ACCEL_PREFIX", "/_protected_media/") or "",
        pow_base_difficulty=_int("POW_BASE_DIFFICULTY", 18),
        session_idle_minutes=_int("SESSION_IDLE_MINUTES", 7 * 24 * 60),
        session_absolute_days=_int("SESSION_ABSOLUTE_DAYS", 30),
        priv_session_idle_minutes=_int("PRIV_SESSION_IDLE_MINUTES", 30),
        stepup_minutes=_int("STEPUP_MINUTES", 15),
        log_level=_raw("LOG_LEVEL", "INFO") or "INFO",
        hsts_preload=_bool("HSTS_PRELOAD", False),
    )
    s.validate()
    return s


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return load_settings()

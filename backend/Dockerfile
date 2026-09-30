# syntax=docker/dockerfile:1.7
# Pin the base image by digest in production (Renovate/Dependabot keeps it current):
#   ARG PYTHON_IMAGE=python:3.11-slim-bookworm@sha256:<digest>
ARG PYTHON_IMAGE=python:3.11-slim-bookworm

# ---------------------------------------------------------------- build: install hash-locked deps
FROM ${PYTHON_IMAGE} AS build
ENV PIP_NO_CACHE_DIR=1 PIP_DISABLE_PIP_VERSION_CHECK=1
WORKDIR /build
COPY requirements.txt .
# --require-hashes: every artifact must match the lockfile hash (tamper/dependency-confusion defence).
# --only-binary: no arbitrary setup.py execution at build time.
RUN python -m venv /opt/venv \
 && /opt/venv/bin/pip install --require-hashes --no-deps --only-binary=:all: -r requirements.txt

# ---------------------------------------------------------------- common runtime
FROM ${PYTHON_IMAGE} AS runtime
RUN groupadd --gid 10001 bastion \
 && useradd --uid 10001 --gid bastion --no-create-home --shell /usr/sbin/nologin bastion \
 && rm -rf /var/lib/apt/lists/*
COPY --from=build /opt/venv /opt/venv
WORKDIR /app
# Code is owned by root and read-only for the service user: a compromised process cannot modify it.
COPY --chown=root:root --chmod=0644 alembic.ini ./
COPY --chown=root:root app ./app
COPY --chown=root:root migrations ./migrations
RUN find /app -type d -exec chmod 0755 {} + && find /app -type f -exec chmod 0644 {} + \
 && python -m compileall -q /app/app
ENV PATH=/opt/venv/bin:$PATH PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PYTHONHASHSEED=random
USER 10001:10001

# ---------------------------------------------------------------- api
FROM runtime AS api
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
  CMD ["python", "-c", "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/api/v1/health', timeout=2).status == 200 else 1)"]
# Proxy headers are handled by the app's TRUSTED_PROXIES logic only (no double interpretation).
CMD ["uvicorn", "app.main:create_app", "--factory", "--host", "0.0.0.0", "--port", "8000", \
     "--no-server-header", "--no-proxy-headers", "--ws-max-size", "4096", "--ws-ping-interval", "20", \
     "--limit-concurrency", "2000", "--timeout-keep-alive", "10", "--h11-max-incomplete-event-size", "16384"]

# ---------------------------------------------------------------- media worker (only image with decoders)
FROM runtime AS worker
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg \
 && rm -rf /var/lib/apt/lists/*
USER 10001:10001
CMD ["python", "-m", "app.worker"]

# ---------------------------------------------------------------- one-shot jobs (migrate, mail, purge)
FROM runtime AS jobs
CMD ["python", "-m", "app.cli", "mail-loop"]

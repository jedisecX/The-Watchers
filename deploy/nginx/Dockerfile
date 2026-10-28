# syntax=docker/dockerfile:1.7
# Build from the repository root:  docker build -f deploy/nginx/Dockerfile .
# Pin by digest in production: nginxinc/nginx-unprivileged:1.27-alpine@sha256:<digest>
FROM nginxinc/nginx-unprivileged:1.31-alpine
COPY --chown=root:root deploy/nginx/nginx.conf /etc/nginx/nginx.conf
COPY --chown=root:root deploy/nginx/security_headers.conf deploy/nginx/spa_headers.conf /etc/nginx/snippets/
COPY --chown=root:root frontend/ /usr/share/bastion/
USER 101

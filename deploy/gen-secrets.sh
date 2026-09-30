#!/bin/sh
# Generates every secret as a separate file with 0600 permissions. Nothing is ever committed:
# deploy/secrets/ is in .gitignore and CI's gitleaks scan would flag it.
set -eu
umask 077
D="$(dirname "$0")/secrets"
mkdir -p "$D"
rnd() { openssl rand -base64 48 | tr -d '\n=+/' | cut -c1-40; }
[ -f "$D/pg_superuser_password" ] || rnd > "$D/pg_superuser_password"
for r in app worker migrator; do
  [ -f "$D/pg_${r}_password" ] || rnd > "$D/pg_${r}_password"
done
[ -f "$D/redis_password" ] || rnd > "$D/redis_password"
[ -f "$D/secret_key" ] || openssl rand -base64 64 | tr -d '\n' > "$D/secret_key"
[ -f "$D/field_encryption_keys" ] || openssl rand -base64 32 | tr '+/' '-_' | tr -d '\n' > "$D/field_encryption_keys"
# Connection URLs are secrets too (they embed passwords).
printf 'postgresql+psycopg://bastion_app:%s@postgres/bastion' "$(cat "$D/pg_app_password")" > "$D/database_url"
printf 'postgresql+psycopg://bastion_worker:%s@postgres/bastion' "$(cat "$D/pg_worker_password")" > "$D/worker_database_url"
printf 'postgresql+psycopg://bastion_migrator:%s@postgres/bastion' "$(cat "$D/pg_migrator_password")" > "$D/migration_database_url"
printf 'redis://:%s@redis:6379/0' "$(cat "$D/redis_password")" > "$D/redis_url"
[ -f "$D/smtp_password" ] || : > "$D/smtp_password"
if [ ! -f "$D/tls_cert" ]; then
  echo "NOTE: place your TLS certificate and key at $D/tls_cert and $D/tls_key (e.g. from ACME)."
fi
chmod 600 "$D"/*
echo "secrets written to $D"

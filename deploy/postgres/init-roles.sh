#!/bin/sh
# Bootstraps least-privilege roles. Runs once as the Postgres superuser (docker-entrypoint-initdb.d).
# Passwords come from mounted secret files, never from this script.
set -eu
APP_PW=$(cat "${BASTION_APP_PASSWORD_FILE}")
WORKER_PW=$(cat "${BASTION_WORKER_PASSWORD_FILE}")
MIGRATOR_PW=$(cat "${BASTION_MIGRATOR_PASSWORD_FILE}")
DB="${POSTGRES_DB:-bastion}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$DB" \
  -v app_pw="$APP_PW" -v worker_pw="$WORKER_PW" -v migrator_pw="$MIGRATOR_PW" -v db="$DB" <<'SQL'
CREATE ROLE bastion_migrator LOGIN PASSWORD :'migrator_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE bastion_app      LOGIN PASSWORD :'app_pw'      NOSUPERUSER NOCREATEDB NOCREATEROLE CONNECTION LIMIT 100;
CREATE ROLE bastion_worker   LOGIN PASSWORD :'worker_pw'   NOSUPERUSER NOCREATEDB NOCREATEROLE CONNECTION LIMIT 10;
ALTER DATABASE :"db" OWNER TO bastion_migrator;
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO bastion_app, bastion_worker;
ALTER SCHEMA public OWNER TO bastion_migrator;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO bastion_app, bastion_worker;
SQL

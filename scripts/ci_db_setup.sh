#!/bin/sh
# Creates the least-privilege role layout (same as deploy/postgres/init-roles.sh) for CI/dev.
set -eu
: "${PGHOST:=localhost}" "${PGUSER:=postgres}"
export PGHOST PGUSER
psql -v ON_ERROR_STOP=1 -q <<'SQL'
CREATE ROLE bastion_migrator LOGIN PASSWORD 'dev_migrator_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE bastion_app LOGIN PASSWORD 'dev_app_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE bastion_worker LOGIN PASSWORD 'dev_worker_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE bastion OWNER bastion_migrator;
CREATE DATABASE bastion_test OWNER bastion_migrator;
SQL
for db in bastion bastion_test; do
  psql -v ON_ERROR_STOP=1 -q -d "$db" <<SQL
REVOKE ALL ON DATABASE $db FROM PUBLIC;
GRANT CONNECT ON DATABASE $db TO bastion_app, bastion_worker;
ALTER SCHEMA public OWNER TO bastion_migrator;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO bastion_app, bastion_worker;
SQL
done
echo "roles and databases ready"

#!/usr/bin/env bash
# Runs the whole Pest suite against real MySQL 8.4 and PostgreSQL 17 servers in throwaway Docker
# containers (payload search uses JSON columns, which behave differently per database).
set -uo pipefail
cd "$(dirname "$0")/.."

FAILED=0
cleanup() { docker rm -f wcon-it-mysql wcon-it-pg >/dev/null 2>&1; }
trap cleanup EXIT
cleanup

docker run -d --name wcon-it-mysql -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=console -p 127.0.0.1:33184:3306 mysql:8.4 >/dev/null
docker run -d --name wcon-it-pg -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=console -p 127.0.0.1:54384:5432 postgres:17 >/dev/null

wait_for() { local i; for i in $(seq 1 90); do "$@" >/dev/null 2>&1 && return 0; sleep 1; done; return 1; }
wait_for docker exec wcon-it-mysql mysql -uroot -psecret -e 'select 1' console || { echo "MySQL did not start"; exit 1; }
wait_for docker exec wcon-it-pg psql -U postgres -d console -c 'select 1' || { echo "PostgreSQL did not start"; exit 1; }

echo "== MySQL 8.4";      WC_DB=mysql WC_DB_PORT=33184 vendor/bin/pest || FAILED=1
echo "== PostgreSQL 17";  WC_DB=pgsql WC_DB_PORT=54384 vendor/bin/pest || FAILED=1
exit $FAILED

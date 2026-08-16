#!/bin/sh
# TerraChain development helpers.
# Usage:
#   bin/dev.sh server   start the PHP dev server (127.0.0.1:8081)
#   bin/dev.sh stop     stop the dev server
#   bin/dev.sh db-reset reload database (drop, schema, seed)
#   bin/dev.sh test     full verification: DB reset + C build + API/unit suites

set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${TC_PORT:-8081}"
LOG=/tmp/opencode/tc-server.log
PID=/tmp/opencode/tc.pid
DBA="mysql -h 127.0.0.1 -P 33306 -u terrachain -pterrachain_local_dev"

case "${1:-}" in
  server)
    [ -f "$PID" ] && kill "$(cat "$PID" 2>/dev/null)" 2>/dev/null || true
    cd "$ROOT"
    setsid php -S "127.0.0.1:$PORT" public/index.php < /dev/null > "$LOG" 2>&1 &
    echo $! > "$PID"
    sleep 1
    echo "server pid $(cat "$PID") — log $LOG"
    ;;
  stop)
    [ -f "$PID" ] && kill "$(cat "$PID" 2>/dev/null)" 2>/dev/null || true
    echo "stopped"
    ;;
  db-reset)
    cd "$ROOT"
    $DBA -e "DROP DATABASE IF EXISTS terrachain; CREATE DATABASE terrachain CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    $DBA terrachain < database/schema.sql
    $DBA terrachain < database/seed.sql
    echo "database reset"
    ;;
  test)
    cd "$ROOT"
    "$0" db-reset
    make -C c test
    c/c_tests/e2e_verify.php
    php tests/run_api_tests.php
    php tests/run_unit_tests.php
    ;;
  *)
    echo "usage: $0 {server|stop|db-reset|test}" >&2
    exit 2
    ;;
esac
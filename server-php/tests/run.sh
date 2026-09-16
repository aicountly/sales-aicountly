#!/usr/bin/env bash
# Run the Sales integration tests against a throwaway PostgreSQL database and a
# local stub standing in for Books and Inventory.
#
#   server-php/tests/run.sh
#
# Requires: php with pdo_pgsql, and a reachable PostgreSQL.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DB_NAME="${TEST_DB_NAME:-sales_test}"
DB_USER="${TEST_DB_USER:-sales_test}"
DB_PASS="${TEST_DB_PASS:-sales_test}"
DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${TEST_DB_PORT:-5432}"
STUB_PORT="${STUB_PORT:-8791}"

cat > "$ROOT/.env" <<ENVEOF
APP_ENV=local
APP_PRODUCT_KEY=sales
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
BOOKS_API_BASE=http://127.0.0.1:$STUB_PORT
INVENTORY_API_BASE=http://127.0.0.1:$STUB_PORT
MANAGE_API_BASE=http://127.0.0.1:$STUB_PORT
BOOKS_SERVICE_KEY=test-books-key
INVENTORY_SERVICE_KEY=test-inventory-key
ENVEOF

php "$ROOT/bin/migrate.php" > /dev/null

php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /dev/null 2>&1 &
STUB_PID=$!
trap 'kill $STUB_PID 2>/dev/null || true' EXIT

# Wait for the stub rather than sleeping a guessed amount.
for _ in $(seq 1 40); do
  if curl -fsS --noproxy '*' "http://127.0.0.1:$STUB_PORT/api/companyinfo?comp_id=1" > /dev/null 2>&1; then break; fi
  sleep 0.25
done

php "$ROOT/tests/integration.php"

#!/bin/bash
# =============================================================
# Ejecuta los seeds PostgreSQL en orden
# Uso: bash database/seeds/postgres/run_seeds.sh
# =============================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$SCRIPT_DIR/../../../.env"

if [ -f "$ENV_FILE" ]; then
  while IFS='=' read -r key value; do
    [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
    export "$key=$value"
  done < "$ENV_FILE"
fi

PG_HOST="${POSTGRES_HOST:-127.0.0.1}"
PG_PORT="${POSTGRES_PORT:-5432}"
PG_DB="${POSTGRES_DB:-puntoDB}"
PG_USER="${POSTGRES_USER:-punto}"
export PGPASSWORD="${POSTGRES_PASSWORD:-punto123}"

echo "======================================================="
echo "Punto POS - Seeds PostgreSQL"
echo "Base de datos: $PG_DB @ $PG_HOST:$PG_PORT"
echo "======================================================="

run_seed() {
  local file="$1"
  echo -n "  → $(basename "$file") ... "
  /Applications/Postgres.app/Contents/Versions/18/bin/psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_DB" -f "$file" -q
  echo "OK"
}

run_seed "$SCRIPT_DIR/01_master_admin.sql"
run_seed "$SCRIPT_DIR/02_sample_company.sql"

echo ""
echo "Seeds ejecutados correctamente."
echo ""
echo "Credenciales master admin:"
echo "  Panel SaaS  http://localhost:8002/main  →  master@local.test / admin123"
echo "======================================================="

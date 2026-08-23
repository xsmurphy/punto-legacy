#!/bin/bash
# =============================================================
# run_stock_all_outlets_test.sh — arnés de la agregación de stock sobre TODAS
# las sucursales de la compañía (`Inventory::getAllItemStock($outlet, true)`).
#
# Mismo patrón que run_partition_row_movement_test.sh / run_sale_void_test.sh:
# Postgres descartable en Docker + schema + migraciones + los fixtures del
# seed compartido de verify_chain (dos tenants aislados). El arnés PHP agrega
# LO SUYO encima (dos sucursales extra + ítems + movimientos) sin tocar ese
# seed.sql.
#
# Diferencia con los hermanos: acá el Postgres descartable es 18-alpine, la
# misma major que corre en producción. Lo que se está verificando es
# agregación en el motor (array_agg + JOIN, DISTINCT ON, casts numeric), no
# lógica PHP, así que conviene medirlo contra la versión real.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_stock_all_outlets_test.sh
#
# Para apuntar a un Postgres ya migrado/seedeado, exportá
# POSTGRES_HOST/PORT/DB/USER/PASSWORD + VERIFY_CHAIN_ALLOW_EXISTING_DB=1.
#
# Requiere: docker, php (>=8.1). Exit code: 0 si el test pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Wrapper compartido: exige la linea canonica de resumen, no solo exit 0.
# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"
API_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$API_DIR/.." && pwd)"
VERIFY_CHAIN_DIR="$API_DIR/lib/Sales/verify_chain"

OWN_DOCKER=0
CONTAINER_NAME="punto_stock_all_outlets_test_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_stock_all_outlets_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_stock_all_outlets_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

  # Credential helper del host roto en este entorno (ver context/06):
  # DOCKER_CONFIG apunta a un config.json vacío para no depender de él.
  DOCKER_CONFIG_DIR="$(mktemp -d)"
  echo '{}' > "$DOCKER_CONFIG_DIR/config.json"
  export DOCKER_CONFIG="$DOCKER_CONFIG_DIR"

  POSTGRES_HOST=127.0.0.1
  POSTGRES_PORT=$(( (RANDOM % 5000) + 55000 ))
  POSTGRES_DB=puntoDB
  POSTGRES_USER=punto
  POSTGRES_PASSWORD=punto123
  export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD

  docker run -d --name "$CONTAINER_NAME" \
    -e POSTGRES_DB="$POSTGRES_DB" -e POSTGRES_USER="$POSTGRES_USER" -e POSTGRES_PASSWORD=$POSTGRES_PASSWORD \
    -p "$POSTGRES_PORT:5432" postgres:18-alpine >/dev/null

  echo -n "[run_stock_all_outlets_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_stock_all_outlets_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_stock_all_outlets_test.sh] corriendo migrate.php..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"

  echo "[run_stock_all_outlets_test.sh] cargando fixtures (seed.sql de verify_chain)..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
else
  if [ "${VERIFY_CHAIN_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_stock_all_outlets_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test INSERTA sucursales, ítems y movimientos de stock de prueba" >&2
    echo "  contra esa base. Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    VERIFY_CHAIN_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_stock_all_outlets_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
  echo "[run_stock_all_outlets_test.sh] cargando fixtures (seed.sql de verify_chain, idempotente)..."
  export PGPASSWORD=$POSTGRES_PASSWORD
  psql -h "$POSTGRES_HOST" -p "${POSTGRES_PORT:-5432}" -U "${POSTGRES_USER:-punto}" -d "${POSTGRES_DB:-puntoDB}" \
    -v ON_ERROR_STOP=1 < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
fi

# ── 2. Arnés (integración, contra el Postgres de arriba) ───────────────────
echo ""
echo "[run_stock_all_outlets_test.sh] === agregación de stock multi-sucursal ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/stock_all_outlets_test.php"

echo ""
echo "[run_stock_all_outlets_test.sh] TODO OK."

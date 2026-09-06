#!/bin/bash
# =============================================================
# run_production_batch_test.sh — arnés del LOTE DE PRODUCCIÓN MULTI-PLATO
# (context/70-viandas.md, etapa B "Producción por lote").
#
# Lo que verifica, en una línea: tomar {plato, cantidad} × N, explotar todas
# las recetas y AGREGAR POR INSUMO da el número correcto —dos platos que
# comparten un insumo suman, la merma se aplica por nivel, un subproducto con
# stock propio no se re-explota, un insumo sin control de inventario devuelve
# necesidad y no faltante— y confirmar el lote mueve el stock por el mismo
# `ProductionService::complete()` de siempre, dejando el COGS correcto.
# Ver el docblock de production_batch_test.php.
#
# Mismo patrón que run_order_item_cancel_test.sh (Docker Postgres descartable +
# schema + migraciones + fixtures del tenant "Verify PY") — reusa ESE seed.sql
# en vez de reinventar company/outlet/register. Los ítems y recetas propios del
# lote los crea el arnés (idempotentes), porque ninguna receta del seed sirve:
# todas son de producción DIRECTA y el lote produce platos con stock propio.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_production_batch_test.sh
#
# Por defecto levanta su PROPIO Postgres descartable en Docker, aplica
# schema + migraciones (necesita la 193, que crea `production_batch` y la
# columna `production_order.batchid`) + fixtures, corre el test, y lo destruye
# al terminar. Para apuntar a un Postgres ya migrado/seedeado, exportá
# POSTGRES_HOST/PORT/DB/USER/PASSWORD antes de llamar.
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
CONTAINER_NAME="punto_production_batch_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_production_batch_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_production_batch_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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

  # postgres:16 y no una menor: varias migs del set usan sintaxis PG16+.
  # Prod corre 18.4.
  docker run -d --name "$CONTAINER_NAME" \
    -e POSTGRES_DB="$POSTGRES_DB" -e POSTGRES_USER="$POSTGRES_USER" -e POSTGRES_PASSWORD=$POSTGRES_PASSWORD \
    -p "$POSTGRES_PORT:5432" postgres:16-alpine >/dev/null

  echo -n "[run_production_batch_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_production_batch_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_production_batch_test.sh] corriendo migrate.php (incluye la 193, production_batch)..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"

  echo "[run_production_batch_test.sh] cargando fixtures (seed.sql de verify_chain)..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
else
  if [ "${PRODUCTION_BATCH_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_production_batch_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test CREA ítems, recetas, lotes y órdenes de producción contra esa base," >&2
    echo "  y MUEVE STOCK real del tenant fixture." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    PRODUCTION_BATCH_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_production_batch_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
  echo "[run_production_batch_test.sh] cargando fixtures (seed.sql de verify_chain, idempotente)..."
  export PGPASSWORD=$POSTGRES_PASSWORD
  psql -h "$POSTGRES_HOST" -p "${POSTGRES_PORT:-5432}" -U "${POSTGRES_USER:-punto}" -d "${POSTGRES_DB:-puntoDB}" \
    -v ON_ERROR_STOP=1 < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
fi

# ── 2. Lote de producción multi-plato ──────────────────────────────────────
echo ""
echo "[run_production_batch_test.sh] === lote multi-plato (agregación, merma, subproducto, D1, COGS) ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/production_batch_test.php"

echo ""
echo "[run_production_batch_test.sh] TODO OK."

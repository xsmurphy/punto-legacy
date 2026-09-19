#!/bin/bash
# =============================================================
# run_plan_in_place_test.sh — arnés de los planes SIN versionado (owner
# 2026-09-15, context/34 §F4 SUPERSEDED): edición en el lugar de
# PlanAdminService y la mig 222 que consolida los planes archivados, contra el
# catálogo de producción replicado.
#
# Mismo patrón que run_stock_ledger_test.sh / run_drawer_cash_count_test.sh:
# Docker Postgres descartable + schema + migraciones + fixture propio.
#
# El arnés BORRA el catálogo de planes y arma el suyo: solo corre contra una
# base descartable.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_plan_in_place_test.sh
#
# Para apuntar a un Postgres ya migrado, exportá POSTGRES_HOST/PORT/DB/USER/
# PASSWORD antes de llamar (+ VERIFY_CHAIN_ALLOW_EXISTING_DB=1: el arnés
# ESCRIBE).
#
# Requiere: docker, php (>=8.1). Exit code: 0 si el test pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Wrapper compartido: exige la línea canónica de resumen, no solo exit 0.
# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"
API_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$API_DIR/.." && pwd)"

OWN_DOCKER=0
CONTAINER_NAME="punto_plan_in_place_test_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_plan_in_place_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -fv "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_plan_in_place_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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
    -p "$POSTGRES_PORT:5432" postgres:16-alpine >/dev/null

  echo -n "[run_plan_in_place_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_plan_in_place_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_plan_in_place_test.sh] corriendo migrate.php..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"

else
  if [ "${VERIFY_CHAIN_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_plan_in_place_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test ESCRIBE: BORRA el catálogo de planes y mueve todas las" >&2
    echo "  companies al plan 0. Solo contra una base descartable." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    VERIFY_CHAIN_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_plan_in_place_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
fi

# ── 2. Planes (integración, contra el Postgres de arriba) ──────────────────
echo ""
echo "[run_plan_in_place_test.sh] === Planes: edición en el lugar + mig 222 ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/plan_in_place_test.php"

echo ""
echo "[run_plan_in_place_test.sh] TODO OK."

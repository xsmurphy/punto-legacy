#!/bin/bash
# =============================================================
# run_admin_tenant_reads_test.sh — arnés de las lecturas del realm /admin:
# listado de tenants, semáforo de salud y planes (los tres rotos en prod el
# 2026-08-25: role varchar comparado contra entero, keys de config leídas como
# columnas, y CaseInsensitiveArray contra un typehint `array`).
#
# Mismo patrón que run_stock_ledger_test.sh / run_drawer_cash_count_test.sh:
# Docker Postgres descartable + schema + migraciones + fixture propio.
#
# NO usa el seed.sql de verify_chain: su contacto admin tiene main='admin' y
# acá hace falta controlar main/role exactamente. El arnés arma dos tenants
# propios (dueño legacy '1' y dueño con rol UUID) y borra sus filas al terminar.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_admin_tenant_reads_test.sh
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
CONTAINER_NAME="punto_admin_tenant_reads_test_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_admin_tenant_reads_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_admin_tenant_reads_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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

  echo -n "[run_admin_tenant_reads_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_admin_tenant_reads_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  # Empresa master como la creaban las instalaciones viejas. Va ANTES de
  # migrate: la columna isInternal la agrega la mig 114 (default 0), que es
  # exactamente el estado que la mig 173 tiene que corregir. Sembrarla acá es
  # lo que hace que el caso K pruebe la migración y no el estado inicial.
  echo "[run_admin_tenant_reads_test.sh] sembrando la empresa master en su estado viejo..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -c \
    "INSERT INTO company (companyId, status, plan, balance, isParent, config)
     VALUES ('00000000-0000-0000-0000-000000000001', 'active', 0, 0.00, TRUE,
             '{\"settingName\":\"Master Admin\"}')
     ON CONFLICT (companyId) DO NOTHING;" >/dev/null

  echo "[run_admin_tenant_reads_test.sh] corriendo migrate.php..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"

  # El seed corre DESPUÉS de la migración, como en un deploy real: su ON
  # CONFLICT no puede revertir el flag que la 173 acaba de poner.
  echo "[run_admin_tenant_reads_test.sh] cargando el seed de la empresa master..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$API_DIR/database/seeds/postgres/01_master_admin.sql" >/dev/null
else
  if [ "${VERIFY_CHAIN_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_admin_tenant_reads_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test ESCRIBE (crea y borra dos companies de prueba con sus" >&2
    echo "  contactos y su rol owner) contra esa base." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    VERIFY_CHAIN_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_admin_tenant_reads_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
fi

# ── 2. Lecturas de /admin (integración, contra el Postgres de arriba) ──────
echo ""
echo "[run_admin_tenant_reads_test.sh] === Lecturas de /admin: tenants + salud + planes ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/admin_tenant_reads_test.php"

echo ""
echo "[run_admin_tenant_reads_test.sh] TODO OK."

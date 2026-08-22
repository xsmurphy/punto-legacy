#!/bin/bash
# =============================================================
# run_db_error_visibility_test.sh — arnés del CONTRATO DE ERRORES del
# wrapper PDO (api/includes/lib/DB.php): las queries que fallan LANZAN
# `Punto\Api\Support\DbQueryException` en vez de devolver `false` silencioso.
#
# Mismo patrón que run_sale_void_test.sh / run_return_d2_d3_test.sh (Postgres
# descartable en Docker + schema + migraciones), pero NO necesita fixtures:
# el test solo ejercita el wrapper contra tablas del schema base.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_db_error_visibility_test.sh
#
# Para apuntar a un Postgres ya migrado, exportá POSTGRES_HOST/PORT/DB/
# USER/PASSWORD antes de llamar (el test NO deja datos: todas sus escrituras
# fallan a propósito o corren dentro de transacciones que se revierten).
#
# Requiere: docker, php (>=8.1). Exit code: 0 si el test pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$API_DIR/.." && pwd)"

OWN_DOCKER=0
CONTAINER_NAME="punto_db_error_test_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_db_error_visibility_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_db_error_visibility_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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

  echo -n "[run_db_error_visibility_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_db_error_visibility_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_db_error_visibility_test.sh] corriendo migrate.php..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"
else
  echo "[run_db_error_visibility_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
fi

# ── 2. Test del contrato de errores ────────────────────────────────────────
echo ""
echo "[run_db_error_visibility_test.sh] === contrato de errores del wrapper DB ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
php -d variables_order=EGPCS -d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING' \
  "$SCRIPT_DIR/db_error_visibility_test.php"

echo ""
echo "[run_db_error_visibility_test.sh] TODO OK."

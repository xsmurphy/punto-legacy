#!/bin/bash
# =============================================================
# run_stock_count_open_mode_test.sh — arnés del CONTEO NO CIEGO (context/63
# F2): que el modo lo decida la PERSONA (`inventory.count.open`) sobre el piso
# del comercio (`stockCountBlind`), que lo resuelva UN solo lugar para el panel
# y para la caja, que sin poder resolverlo se cuente a ciegas, y —lo que de
# verdad importa— que cuando el modo es ciego el esperado NO VIAJE en la
# respuesta, en vez de viajar y esconderse en el frontend.
# Ver `api/tests/stock_count_open_mode_test.php`.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_stock_count_open_mode_test.sh
#
# Levanta su propio Postgres descartable en Docker, corre las migraciones y
# carga los fixtures del tenant "Verify PY". NO toca la base de producción.
#
# Para apuntar a un Postgres ya migrado, exportá POSTGRES_HOST/PORT/DB/USER/
# PASSWORD antes de llamar (el arnés ESCRIBE: solo bases descartables).
#
# Requiere: docker; php (>=8.1) con pdo_pgsql. Exit code: 0 si el test pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$API_DIR/.." && pwd)"
VERIFY_CHAIN_DIR="$API_DIR/lib/Sales/verify_chain"

# Nombres propios (no compartidos con otros arneses ni con prod): dos agentes
# corriendo a la vez no se pisan, y el cleanup no borra containers ajenos.
CONTAINER_NAME="punto_stock_count_open_pg_$$"
OWN_DOCKER=0

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_stock_count_open_mode_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"

if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_stock_count_open_mode_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

  # Credential helper del host roto en este entorno (ver context/06):
  # DOCKER_CONFIG apunta a un config.json vacío para no depender de él.
  DOCKER_CONFIG_DIR="$(mktemp -d)"
  echo '{}' > "$DOCKER_CONFIG_DIR/config.json"
  export DOCKER_CONFIG="$DOCKER_CONFIG_DIR"

  POSTGRES_DB=puntoDB
  POSTGRES_USER=punto
  POSTGRES_PASSWORD=punto123
  POSTGRES_HOST=127.0.0.1
  POSTGRES_PORT=$(( (RANDOM % 5000) + 55000 ))
  export POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD POSTGRES_HOST POSTGRES_PORT

  docker run -d --name "$CONTAINER_NAME" \
    -e POSTGRES_DB -e POSTGRES_USER -e POSTGRES_PASSWORD \
    -p "$POSTGRES_PORT:5432" postgres:16-alpine >/dev/null

  echo -n "[run_stock_count_open_mode_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_stock_count_open_mode_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -q \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -q \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_stock_count_open_mode_test.sh] corriendo migrate.php..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"

  echo "[run_stock_count_open_mode_test.sh] cargando fixtures (seed.sql de verify_chain)..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -q \
    < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
else
  if [ "${STOCK_COUNT_OPEN_TEST_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_stock_count_open_mode_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test ESCRIBE (roles, contactos y sesiones de conteo) en esa base." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    STOCK_COUNT_OPEN_TEST_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_stock_count_open_mode_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
  echo "[run_stock_count_open_mode_test.sh] cargando fixtures (seed.sql de verify_chain, idempotente)..."
  export PGPASSWORD=$POSTGRES_PASSWORD
  psql -h "$POSTGRES_HOST" -p "${POSTGRES_PORT:-5432}" \
    -U "${POSTGRES_USER:-punto}" -d "${POSTGRES_DB:-puntoDB}" \
    -v ON_ERROR_STOP=1 -q < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
fi

echo ""
echo "[run_stock_count_open_mode_test.sh] === conteo NO ciego: el modo lo decide la persona ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
export JWT_SECRET="${JWT_SECRET:-test-secret-stock-count-open}"
harness_run "$SCRIPT_DIR/stock_count_open_mode_test.php"

echo ""
echo "[run_stock_count_open_mode_test.sh] TODO OK."

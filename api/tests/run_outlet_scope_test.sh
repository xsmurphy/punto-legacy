#!/bin/bash
# =============================================================
# run_outlet_scope_test.sh — arnés del ALCANCE POR SUCURSAL del realm `api`:
# que una API key vea las sucursales ASIGNADAS a su usuario (`contact_outlet`) y
# solo esas, que el consolidado sume exactamente ese conjunto, que una sucursal
# ajena dé 403 y no un vacío, y que `panel` y `pos-app` no cambien.
# Ver `api/tests/outlet_scope_test.php`.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_outlet_scope_test.sh
#
# Dos modos (mismo patrón que run_drawer_cash_count_test.sh):
#
#   1. HOST con php+pdo_pgsql: levanta su propio Postgres descartable en
#      Docker y corre php local.
#   2. TODO EN DOCKER (`OUTLET_SCOPE_TEST_IN_DOCKER=1`): además del Postgres, corre
#      el PHP dentro de la imagen `punto-php-test` en una red propia. Es el
#      modo del servidor de Punto (167.71.165.221), donde no hay php con
#      pdo_pgsql en el host. NO toca la base de producción: levanta la suya.
#
# El arnés lanza subprocesos `php` propios (cada caso necesita un proceso
# virgen: `VIEW_OUTLET_IDS` y `OUTLET_ID` son CONSTANTES y no se redefinen). En
# el modo 2 esos subprocesos salen del `php` del contenedor, que es el correcto.
#
# Para apuntar a un Postgres ya migrado, exportá POSTGRES_HOST/PORT/DB/USER/
# PASSWORD antes de llamar (el arnés ESCRIBE: solo bases descartables).
#
# Requiere: docker; php (>=8.1) en el modo 1. Exit code: 0 si el test pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$API_DIR/.." && pwd)"

IN_DOCKER="${OUTLET_SCOPE_TEST_IN_DOCKER:-0}"
PHP_IMAGE="${OUTLET_SCOPE_TEST_PHP_IMAGE:-punto-php-test}"

# Nombres propios (no compartidos con otros arneses ni con prod): dos agentes
# corriendo a la vez no se pisan, y el cleanup no borra containers ajenos.
CONTAINER_NAME="punto_outlet_scope_pg_$$"
NETWORK_NAME="punto_outlet_scope_net_$$"
OWN_DOCKER=0
OWN_NETWORK=0

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_outlet_scope_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
  if [ "$OWN_NETWORK" = "1" ]; then
    docker network rm "$NETWORK_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

if [ "$IN_DOCKER" = "1" ]; then
  php() {
    local args=() a
    for a in "$@"; do
      args+=("${a/#$REPO_ROOT//app}")
    done
    docker run --rm --network "$NETWORK_NAME" \
      -v "$REPO_ROOT:/app" -w /app \
      -e POSTGRES_HOST -e POSTGRES_PORT -e POSTGRES_DB -e POSTGRES_USER -e POSTGRES_PASSWORD \
      -e JWT_SECRET \
      "$PHP_IMAGE" php "${args[@]}"
  }
  export -f php
fi

# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_outlet_scope_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

  # Credential helper del host roto en este entorno (ver context/06):
  # DOCKER_CONFIG apunta a un config.json vacío para no depender de él.
  DOCKER_CONFIG_DIR="$(mktemp -d)"
  echo '{}' > "$DOCKER_CONFIG_DIR/config.json"
  export DOCKER_CONFIG="$DOCKER_CONFIG_DIR"

  POSTGRES_DB=puntoDB
  POSTGRES_USER=punto
  POSTGRES_PASSWORD=punto123
  export POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD

  if [ "$IN_DOCKER" = "1" ]; then
    OWN_NETWORK=1
    docker network create "$NETWORK_NAME" >/dev/null
    POSTGRES_HOST="$CONTAINER_NAME"
    POSTGRES_PORT=5432
    docker run -d --name "$CONTAINER_NAME" --network "$NETWORK_NAME" \
      -e POSTGRES_DB -e POSTGRES_USER -e POSTGRES_PASSWORD \
      postgres:16-alpine >/dev/null
  else
    POSTGRES_HOST=127.0.0.1
    POSTGRES_PORT=$(( (RANDOM % 5000) + 55000 ))
    docker run -d --name "$CONTAINER_NAME" \
      -e POSTGRES_DB -e POSTGRES_USER -e POSTGRES_PASSWORD \
      -p "$POSTGRES_PORT:5432" postgres:16-alpine >/dev/null
  fi
  export POSTGRES_HOST POSTGRES_PORT

  echo -n "[run_outlet_scope_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_outlet_scope_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_outlet_scope_test.sh] corriendo migrate.php..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"
else
  if [ "${OUTLET_SCOPE_TEST_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_outlet_scope_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test ESCRIBE (tenants, cajas y ventas de prueba) en esa base." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    OUTLET_SCOPE_TEST_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_outlet_scope_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
fi

# ── 2. Arnés (integración, contra el Postgres de arriba) ───────────────────
echo ""
echo "[run_outlet_scope_test.sh] === alcance por sucursal (realm api) ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/outlet_scope_test.php"

echo ""
echo "[run_outlet_scope_test.sh] TODO OK."

#!/bin/bash
# =============================================================
# run_drawer_count_by_method_test.sh — arnés del arqueo POR MEDIO DE PAGO del
# cierre de caja (mig 169): una fila congelada por medio en `drawer_count`, el
# efectivo que sigue siendo solo efectivo en `drawer` (mig 164), la
# compatibilidad con un cierre sin desglose y los cierres históricos sin filas.
# Ver `api/tests/drawer_count_by_method_test.php`.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_drawer_count_by_method_test.sh
#
# Dos modos (mismo patrón que run_psp_payment_methods_test.sh):
#
#   1. HOST con php+pdo_pgsql: levanta su propio Postgres descartable en
#      Docker y corre php local.
#   2. TODO EN DOCKER (`DRAWER_TEST_IN_DOCKER=1`): además del Postgres, corre
#      el PHP dentro de la imagen `punto-php-test` en una red propia. Es el
#      modo del servidor de Punto (167.71.165.221), donde no hay php con
#      pdo_pgsql en el host. NO toca la base de producción: levanta la suya.
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

IN_DOCKER="${DRAWER_TEST_IN_DOCKER:-0}"
PHP_IMAGE="${DRAWER_TEST_PHP_IMAGE:-punto-php-test}"

# Nombres propios (no compartidos con otros arneses ni con prod): dos agentes
# corriendo a la vez no se pisan, y el cleanup no borra containers ajenos.
CONTAINER_NAME="punto_drawer_bymethod_pg_$$"
NETWORK_NAME="punto_drawer_bymethod_net_$$"
OWN_DOCKER=0
OWN_NETWORK=0

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_drawer_count_by_method_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
  if [ "$OWN_NETWORK" = "1" ]; then
    docker network rm "$NETWORK_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# En el modo Docker, `php` se resuelve a un contenedor efímero de la imagen de
# test. `harness_run` (el guard anti falso-verde compartido) invoca `php` como
# comando, así que esta función lo intercepta sin duplicar el guard. Los paths
# absolutos del host se reescriben al mount /app del contenedor.
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
  echo "[run_drawer_count_by_method_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

  # Credential helper del host roto en este entorno (ver context/06):
  # DOCKER_CONFIG apunta a un config.json vacío para no depender de él.
  DOCKER_CONFIG_DIR="$(mktemp -d)"
  echo '{}' > "$DOCKER_CONFIG_DIR/config.json"
  export DOCKER_CONFIG="$DOCKER_CONFIG_DIR"

  POSTGRES_DB=puntoDB
  POSTGRES_USER=punto
  POSTGRES_PASSWORD=punto123
  # Exportadas ACA, antes de los `docker run`: mas abajo se pasan con `-e VAR`
  # sin valor, que le dice a docker "tomala del entorno".
  export POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD

  if [ "$IN_DOCKER" = "1" ]; then
    OWN_NETWORK=1
    docker network create "$NETWORK_NAME" >/dev/null
    # Dentro de la red, el Postgres se resuelve por nombre de contenedor.
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

  echo -n "[run_drawer_count_by_method_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_drawer_count_by_method_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_drawer_count_by_method_test.sh] corriendo migrate.php..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"
else
  if [ "${DRAWER_TEST_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_drawer_count_by_method_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test ESCRIBE (tenant, cajas y ventas de prueba) en esa base." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    DRAWER_TEST_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_drawer_count_by_method_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
fi

# ── 2. Arnés (integración, contra el Postgres de arriba) ───────────────────
echo ""
echo "[run_drawer_count_by_method_test.sh] === arqueo por medio de pago (mig 169) ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/drawer_count_by_method_test.php"

echo ""
echo "[run_drawer_count_by_method_test.sh] TODO OK."

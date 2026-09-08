#!/bin/bash
# =============================================================
# run_geo_catalog_test.sh — arnés del CATÁLOGO GEOGRÁFICO FISCAL (mig 207).
#
# Lo que verifica, en una línea: el sync del catálogo (departamento → distrito
# → ciudad) es idempotente, arma bien la jerarquía, no borra nunca lo que el
# origen deja de mencionar, y el endpoint filtra los hijos por su padre — que
# es de lo que depende que el domicilio fiscal de un establecimiento se elija
# de una lista en vez de tipearse de memoria. Ver el docblock de
# geo_catalog_test.php.
#
# NO llama a la API del proveedor: la fuente se inyecta con el shape REAL
# verificado el 2026-09-08 (ver FakeGeoSource). Su ambiente dev es inestable y
# un test que depende de él es un test que no se corre.
#
# Mismo patrón que run_order_cancel_test.sh (Docker Postgres descartable +
# schema + migraciones). NO necesita fixtures de tenant: el catálogo es dato de
# PLATAFORMA, sin companyid.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_geo_catalog_test.sh
#
# Por defecto levanta su PROPIO Postgres descartable en Docker, aplica schema +
# migraciones (necesita la 207), corre el test y lo destruye al terminar. Para
# apuntar a un Postgres ya migrado, exportá POSTGRES_HOST/PORT/DB/USER/PASSWORD
# antes de llamar.
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

OWN_DOCKER=0
CONTAINER_NAME="punto_geo_catalog_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_geo_catalog_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_geo_catalog_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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

  echo -n "[run_geo_catalog_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_geo_catalog_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_geo_catalog_test.sh] corriendo migrate.php (incluye la 207, que crea el catálogo)..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"
else
  if [ "${GEO_CATALOG_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_geo_catalog_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test ESCRIBE en el catálogo geográfico de esa base (tablas geo_*)." >&2
    echo "  Borra al terminar exactamente los códigos que insertó, pero si esa base" >&2
    echo "  tiene el catálogo real sincronizado, la corrida toca las filas de" >&2
    echo "  CAPITAL (1) y ASUNCION (1) y las deja borradas hasta el próximo sync." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    GEO_CATALOG_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_geo_catalog_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
fi

# ── 2. Catálogo geográfico: sync idempotente, jerarquía y lectura ──────────
echo ""
echo "[run_geo_catalog_test.sh] === catálogo geográfico (sync idempotente, jerarquía, filtros) ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/geo_catalog_test.php"

echo ""
echo "[run_geo_catalog_test.sh] TODO OK."

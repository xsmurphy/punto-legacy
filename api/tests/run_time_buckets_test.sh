#!/bin/bash
# =============================================================
# run_time_buckets_test.sh — arnes de la GRANULARIDAD de las series temporales
# (`api/lib/Support/TimeBuckets.php`): dia / semana ISO / mes segun el largo
# del rango, bordes parciales, y que la expresion SQL corte igual que keyFor().
#
# El bloque A es puro. El bloque B necesita un Postgres cualquiera (no usa el
# schema de Punto): sin POSTGRES_HOST levanta uno descartable en Docker.
#
# Uso (desde la raiz del repo):
#   bash api/tests/run_time_buckets_test.sh
#
# Exit code: 0 si paso.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"

OWN_DOCKER=0
CONTAINER_NAME="punto_time_buckets_test_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

if [ -z "${POSTGRES_HOST:-}" ] && command -v docker >/dev/null 2>&1; then
  OWN_DOCKER=1
  DOCKER_CONFIG_DIR="$(mktemp -d)"
  echo '{}' > "$DOCKER_CONFIG_DIR/config.json"
  export DOCKER_CONFIG="$DOCKER_CONFIG_DIR"

  POSTGRES_HOST=127.0.0.1
  POSTGRES_PORT=$(( (RANDOM % 5000) + 55000 ))
  POSTGRES_DB=postgres
  POSTGRES_USER=postgres
  POSTGRES_PASSWORD=punto123
  export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD

  echo "[run_time_buckets_test.sh] levantando Postgres descartable..."
  docker run -d --name "$CONTAINER_NAME" -e POSTGRES_PASSWORD=$POSTGRES_PASSWORD \
    -p "$POSTGRES_PORT:5432" postgres:16-alpine >/dev/null
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" pg_isready -U postgres -h 127.0.0.1 >/dev/null 2>&1; then break; fi
    sleep 1
  done
  sleep 1
fi

echo "[run_time_buckets_test.sh] === Granularidad de series temporales ==="
harness_run "$SCRIPT_DIR/time_buckets_test.php"

echo ""
echo "[run_time_buckets_test.sh] TODO OK."

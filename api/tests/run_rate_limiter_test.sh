#!/bin/bash
# =============================================================
# run_rate_limiter_test.sh — arnés del rate limiter con store en Redis
# (Punto\Api\RateLimit\RateLimiter + Punto\Api\Http\ClientIp).
#
# Mismo patrón que run_sale_void_test.sh (servicio descartable en Docker,
# destruido al terminar), pero acá el servicio es Redis, no Postgres: el
# limiter no toca la base.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_rate_limiter_test.sh
#
# Levanta un `redis:alpine` descartable en un puerto libre, corre el test y lo
# destruye. Para apuntar a un Redis ya existente, exportá REDIS_URL antes de
# llamar (NO uses el Redis de producción — el test escribe y borra keys
# `rl:test:*`).
#
# Requiere: docker, php (>=8.1) con la extensión `redis` (phpredis).
# Exit code: 0 si el test pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

OWN_DOCKER=0
CONTAINER_NAME="punto_rate_limiter_test_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_rate_limiter_test.sh] deteniendo Redis descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 0. phpredis: sin la extensión el test no puede correr ──────────────────
if ! php -m | grep -qx redis; then
  echo "[run_rate_limiter_test.sh] ERROR: falta la extensión phpredis en el PHP local." >&2
  echo "  El container de prod la tiene (api/Dockerfile la instala)." >&2
  echo "  En macOS: brew install php-redis  (o pecl install redis)" >&2
  exit 1
fi

# ── 1. Redis: propio (Docker) o el que indique el caller ───────────────────
if [ -z "${REDIS_URL:-}" ]; then
  OWN_DOCKER=1
  echo "[run_rate_limiter_test.sh] sin REDIS_URL — levantando Redis descartable en Docker..."

  # Credential helper del host roto en este entorno (ver context/06):
  # DOCKER_CONFIG apunta a un config.json vacío para no depender de él.
  DOCKER_CONFIG_DIR="$(mktemp -d)"
  echo '{}' > "$DOCKER_CONFIG_DIR/config.json"
  export DOCKER_CONFIG="$DOCKER_CONFIG_DIR"

  REDIS_PORT=$(( (RANDOM % 5000) + 46000 ))
  docker run -d --name "$CONTAINER_NAME" -p "127.0.0.1:$REDIS_PORT:6379" redis:alpine >/dev/null

  echo -n "[run_rate_limiter_test.sh] esperando Redis"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" redis-cli PING >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  export REDIS_URL="redis://127.0.0.1:${REDIS_PORT}/0"
else
  echo "[run_rate_limiter_test.sh] usando Redis existente: ${REDIS_URL%%\?*}"
  echo "[run_rate_limiter_test.sh] AVISO: el test escribe y borra keys 'rl:test:*'."
fi

# ── 2. Test del rate limiter ───────────────────────────────────────────────
php -d variables_order=EGPCS -d 'error_reporting=E_ALL & ~E_DEPRECATED' \
  "$SCRIPT_DIR/rate_limiter_test.php"

echo ""
echo "[run_rate_limiter_test.sh] TODO OK."

#!/bin/bash
# =============================================================
# Ejecuta todos los seeds en orden
# Uso: bash database/seeds/run_seeds.sh
# =============================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$SCRIPT_DIR/../../.env"
if [ -f "$ENV_FILE" ]; then
  while IFS='=' read -r key value; do
    [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
    export "$key=$value"
  done < "$ENV_FILE"
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-encomdb}"
DB_USER="${DB_USER:-encom}"
DB_PASSWORD="${DB_PASSWORD:-encom123}"

echo "======================================================="
echo "Punto POS - Seeds de desarrollo local"
echo "Base de datos: $DB_NAME @ $DB_HOST:$DB_PORT"
echo "======================================================="

# Detectar cliente MySQL
MYSQL_BIN=""
for m in mysql /opt/homebrew/bin/mysql /usr/local/bin/mysql; do
  command -v "$m" &>/dev/null && MYSQL_BIN="$m" && break
  [ -f "$m" ] && MYSQL_BIN="$m" && break
done

# Detectar Docker
DOCKER_BIN=""
for d in docker /Applications/Docker.app/Contents/Resources/bin/docker; do
  command -v "$d" &>/dev/null && DOCKER_BIN="$d" && break
  [ -f "$d" ] && DOCKER_BIN="$d" && break
done

# Buscar contenedor MySQL corriendo
MYSQL_CONTAINER=""
if [ -n "$DOCKER_BIN" ]; then
  MYSQL_CONTAINER=$("$DOCKER_BIN" ps --format '{{.Names}}' 2>/dev/null | grep -i mysql | head -1)
fi

run_seed() {
  local file="$1"
  local name="$(basename "$file")"
  echo -n "  → $name ... "

  if [ -n "$MYSQL_BIN" ]; then
    "$MYSQL_BIN" -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$file" 2>/dev/null
  elif [ -n "$MYSQL_CONTAINER" ]; then
    "$DOCKER_BIN" exec -i "$MYSQL_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$file" 2>/dev/null
  else
    echo "ERROR: No se encontró cliente MySQL ni contenedor Docker"
    exit 1
  fi

  echo "OK"
}

run_seed "$SCRIPT_DIR/01_base.sql"
run_seed "$SCRIPT_DIR/02_panel_user.sql"
run_seed "$SCRIPT_DIR/03_catalog.sql"
run_seed "$SCRIPT_DIR/04_sample_items.sql"

echo ""
echo "Seeds ejecutados correctamente."
echo ""
echo "Credenciales:"
echo "  APP   http://localhost:8000  →  admin@local.test / admin123"
echo "  Panel http://localhost:8001  →  admin@local.test / admin123"
echo "======================================================="

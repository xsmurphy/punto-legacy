#!/bin/bash
# =============================================================
# run_order_cancel_test.sh — arnés de la ANULACIÓN DE COMANDA en sus tres
# granos (ítem suelto, orden entera, sesión de mesa), más el reporte y el
# bloque del cierre de caja que las muestran.
#
# Lo que verifica, en una línea: hacer desaparecer algo de una comanda —una
# línea, la orden completa o la mesa que las contiene— exige motivo y permiso
# del OPERADOR del PIN, respeta la ventana configurada por el comercio
# (`settingOrderItemCancelWindowMinutes`, 0 = apagada) salvo que el operador
# tenga `pos.order.item.cancel.late`, queda registrado a nombre de la PERSONA y
# no de la tablet compartida, aparece en el reporte SIN contar la plata dos
# veces y figura en el cierre de caja — que a ciegas el servidor no manda.
# Ver el docblock de order_cancel_test.php.
#
# Mismo patrón que run_shift_close_gate_test.sh (Docker Postgres descartable +
# schema + migraciones + fixtures del tenant "Verify PY") — reusa ESE seed.sql
# en vez de reinventar company/outlet/register, así que depende de ese archivo
# pero no lo modifica.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_order_cancel_test.sh
#
# Por defecto levanta su PROPIO Postgres descartable en Docker, aplica
# schema + migraciones (necesita la 190 y la 191, que propagan las claves a los
# roles, y la 200, que reemplaza el índice del reporte) + fixtures, corre el
# test, y lo destruye al
# terminar. Para apuntar a un Postgres ya migrado/seedeado, exportá
# POSTGRES_HOST/PORT/DB/USER/PASSWORD antes de llamar.
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
VERIFY_CHAIN_DIR="$API_DIR/lib/Sales/verify_chain"

OWN_DOCKER=0
CONTAINER_NAME="punto_order_cancel_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_order_cancel_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_order_cancel_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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

  echo -n "[run_order_cancel_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_order_cancel_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_order_cancel_test.sh] corriendo migrate.php (incluye 190/191 backfill de claves y 200 índice del reporte)..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"

  echo "[run_order_cancel_test.sh] cargando fixtures (seed.sql de verify_chain)..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
else
  if [ "${ORDER_CANCEL_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_order_cancel_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test CREA órdenes, mesas, roles y contactos de prueba contra esa" >&2
    echo "  base, ABRE Y CIERRA un turno de caja, y toca el config del tenant" >&2
    echo "  fixture y el control a ciegas de la caja (los restaura al terminar)." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    ORDER_CANCEL_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_order_cancel_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
  echo "[run_order_cancel_test.sh] cargando fixtures (seed.sql de verify_chain, idempotente)..."
  export PGPASSWORD=$POSTGRES_PASSWORD
  psql -h "$POSTGRES_HOST" -p "${POSTGRES_PORT:-5432}" -U "${POSTGRES_USER:-punto}" -d "${POSTGRES_DB:-puntoDB}" \
    -v ON_ERROR_STOP=1 < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
fi

# ── 2. Anulación de ítems de comanda ───────────────────────────────────────
echo ""
echo "[run_order_cancel_test.sh] === anulación de comanda (3 granos, reporte, cierre de caja) ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/order_cancel_test.php"

echo ""
echo "[run_order_cancel_test.sh] TODO OK."

#!/bin/bash
# =============================================================
# run_invoice_auth_expired_test.sh — arnés del TIMBRADO VENCIDO: el corte de
# emisión y su preaviso.
#
# Lo que verifica, en una línea: una caja con el timbrado caído NO puede
# facturar (422 con `invoice_auth_expired` y la fecha), pero la venta que se
# COBRÓ dentro de la vigencia y sincroniza después SÍ pasa —se compara contra la
# fecha de la OPERACIÓN, no contra el reloj del servidor—; una caja sin
# vencimiento cargado no se bloquea; la venta ya emitida que llega por la cola
# se acepta y queda MARCADA en vez de rechazada; y el job de avisos elige las
# ventanas 7 / 3 / vencido y es idempotente.
# Ver el docblock de invoice_auth_expired_test.php.
#
# Mismo patrón que run_order_cancel_test.sh (Docker Postgres descartable +
# schema + migraciones + fixtures del tenant "Verify PY") — reusa ESE seed.sql
# en vez de reinventar company/outlet/register, así que depende de ese archivo
# pero no lo modifica.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_invoice_auth_expired_test.sh
#
# Por defecto levanta su PROPIO Postgres descartable en Docker, aplica
# schema + migraciones + fixtures, corre el test, y lo destruye al terminar.
# Para apuntar a un Postgres ya migrado/seedeado, exportá
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
CONTAINER_NAME="punto_invauth_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_invoice_auth_expired_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_invoice_auth_expired_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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

  echo -n "[run_invoice_auth_expired_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_invoice_auth_expired_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_invoice_auth_expired_test.sh] corriendo migrate.php..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"

  echo "[run_invoice_auth_expired_test.sh] cargando fixtures (seed.sql de verify_chain)..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
else
  if [ "${INVOICE_AUTH_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_invoice_auth_expired_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test CREA VENTAS REALES contra esa base, parea un dispositivo," >&2
    echo "  toma la tenencia de la caja fixture y REESCRIBE su timbrado y sus" >&2
    echo "  marcas de aviso (los deja limpios al terminar)." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    INVOICE_AUTH_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_invoice_auth_expired_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
  echo "[run_invoice_auth_expired_test.sh] cargando fixtures (seed.sql de verify_chain, idempotente)..."
  export PGPASSWORD=$POSTGRES_PASSWORD
  psql -h "$POSTGRES_HOST" -p "${POSTGRES_PORT:-5432}" -U "${POSTGRES_USER:-punto}" -d "${POSTGRES_DB:-puntoDB}" \
    -v ON_ERROR_STOP=1 < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
fi

# ── 2. Timbrado vencido ────────────────────────────────────────────────────
echo ""
echo "[run_invoice_auth_expired_test.sh] === timbrado vencido (corte de emisión, marca de la cola, avisos) ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/invoice_auth_expired_test.php"

echo ""
echo "[run_invoice_auth_expired_test.sh] TODO OK."

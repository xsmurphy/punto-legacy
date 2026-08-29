#!/bin/bash
# =============================================================
# run_open_invoices_outlet_scope_test.sh — arnés del SCOPE POR SUCURSAL de
# Cuentas por Cobrar/Pagar (OpenInvoicesService).
#
# Cierra el reporte del tester del 2026-08-28: «en cuentas por cobrar y cuentas
# por pagar aún se mezclan las informaciones de otras sucursales». Verifica
# además las dos cosas que es fácil romper "arreglando" esto — que el saldo de
# un contacto siga siendo company-wide, y que un pago cobrado en otra sucursal
# siga descontando. Ver el docblock de open_invoices_outlet_scope_test.php.
#
# Mismo patrón que run_pos_token_only_precedence_test.sh: Postgres descartable +
# schema + migraciones, corre el arnés y destruye todo al terminar.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_open_invoices_outlet_scope_test.sh
#
# Para apuntar a un Postgres ya migrado/seedeado:
#   POSTGRES_HOST/PORT/DB/USER/PASSWORD + VERIFY_CHAIN_ALLOW_EXISTING_DB=1
#
# Requiere: docker (o un Postgres externo), php (>=8.1). Exit code: 0 si pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"
API_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$API_DIR/.." && pwd)"
VERIFY_CHAIN_DIR="$API_DIR/lib/Sales/verify_chain"

OWN_DOCKER=0
CONTAINER_NAME="punto_open_invoices_scope_test_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_open_invoices_outlet_scope_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_open_invoices_outlet_scope_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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

  echo -n "[run_open_invoices_outlet_scope_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_open_invoices_outlet_scope_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_open_invoices_outlet_scope_test.sh] corriendo migrate.php..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"

  echo "[run_open_invoices_outlet_scope_test.sh] cargando fixtures (seed.sql de verify_chain)..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
else
  if [ "${VERIFY_CHAIN_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_open_invoices_outlet_scope_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test CREA una company/outlet/item de prueba contra esa base." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    VERIFY_CHAIN_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_open_invoices_outlet_scope_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
fi

# ── 2. Arnés (integración, contra el Postgres de arriba) ───────────────────
echo ""
echo "[run_open_invoices_outlet_scope_test.sh] === scope por sucursal de cuentas por cobrar/pagar ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/open_invoices_outlet_scope_test.php"

echo ""
echo "[run_open_invoices_outlet_scope_test.sh] TODO OK."

#!/bin/bash
# =============================================================
# run_wallet_test.sh — arnés de la WALLET MULTI-NIVEL, F1
# (context/74-wallet-multinivel.md, mig 232 + WalletService).
#
# Cubre las invariantes de §8 contra Postgres real: saldo = suma, nunca
# negativo (servicio + CHECK de la BD), append-only (trigger), transferencia
# atómica, jerarquía de un nivel dentro del comercio, modo de facturación
# grabado en la carga, orden por seq, aislamiento multi-tenant, y la carrera
# real de dos cajas pagando el mismo saldo (dos procesos PHP, cada uno con su
# conexión). Ver el docblock de `wallet_test.php`.
#
# Mismo patrón que run_production_batch_test.sh (Docker Postgres descartable +
# schema + migraciones + fixtures "Verify PY" de verify_chain/seed.sql).
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_wallet_test.sh
#
# Para apuntar a un Postgres ya migrado, exportá POSTGRES_HOST/PORT/DB/USER/
# PASSWORD y WALLET_ALLOW_EXISTING_DB=1.
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
CONTAINER_NAME="punto_wallet_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run_wallet_test.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run_wallet_test.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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

  echo -n "[run_wallet_test.sh] esperando Postgres"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run_wallet_test.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run_wallet_test.sh] corriendo migrate.php (incluye la 232, wallet)..."
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"

  echo "[run_wallet_test.sh] cargando fixtures (seed.sql de verify_chain)..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
else
  if [ "${WALLET_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run_wallet_test.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero" >&2
    echo "  este test CREA clientes, bolsillos y movimientos de saldo contra esa base," >&2
    echo "  y PRENDE/APAGA el módulo wallet del tenant fixture." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    WALLET_ALLOW_EXISTING_DB=1 bash $0" >&2
    exit 1
  fi
  echo "[run_wallet_test.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
  echo "[run_wallet_test.sh] cargando fixtures (seed.sql de verify_chain, idempotente)..."
  export PGPASSWORD=$POSTGRES_PASSWORD
  psql -h "$POSTGRES_HOST" -p "${POSTGRES_PORT:-5432}" -U "${POSTGRES_USER:-punto}" -d "${POSTGRES_DB:-puntoDB}" \
    -v ON_ERROR_STOP=1 < "$VERIFY_CHAIN_DIR/seed.sql" >/dev/null
fi

# ── 2. Wallet ──────────────────────────────────────────────────────────────
echo ""
echo "[run_wallet_test.sh] === wallet F1 (saldo, negativos, append-only, transferencia, jerarquía, carrera) ==="
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
harness_run "$SCRIPT_DIR/wallet_test.php"

# ── 3. Wallet en la caja (F2) ──────────────────────────────────────────────
echo ""
echo "[run_wallet_test.sh] === wallet F2 (carga como venta, consumo con saldo, permisos del operador) ==="
harness_run "$SCRIPT_DIR/wallet_pos_test.php"

# ── 4. Reporte de bolsillos (context/74 §13) ───────────────────────────────
echo ""
echo "[run_wallet_test.sh] === reporte de bolsillos (lista congelada, KPIs, diferencias, alcance) ==="
harness_run "$SCRIPT_DIR/wallet_report_test.php"

# ── 5. Reversa de cargas: anulación y nota de crédito (context/74 §13) ─────
echo ""
echo "[run_wallet_test.sh] === reversa de cargas (anulación, NC parcial, saldo usado, carrera, reporte) ==="
harness_run "$SCRIPT_DIR/wallet_reversal_test.php"

echo ""
echo "[run_wallet_test.sh] TODO OK."

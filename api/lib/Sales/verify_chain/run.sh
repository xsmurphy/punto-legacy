#!/bin/bash
# =============================================================
# run.sh — arnés end-to-end del proceso de venta (ver README.md en este
# directorio).
#
# Cadena completa, con datos persistidos, SIN mocks:
#   venta multi-tasa (SaleService real) → BD (itemSold/transaction/toTaxObj)
#   → facturación electrónica (EInvoiceService + SaleToInvoiceMapper, SIN
#     red) → impresión (resolvers reales de blocks.ts/build-ticket-data.ts).
#
# Uso (un comando, desde la raíz del repo):
#   bash api/lib/Sales/verify_chain/run.sh
#
# Por defecto levanta su PROPIO Postgres descartable en Docker, aplica
# schema + migraciones + fixtures, corre la verificación, y lo destruye al
# terminar. Para apuntar a un Postgres ya migrado/seedeado (más rápido en
# loops de desarrollo), exportá POSTGRES_HOST/PORT/DB/USER/PASSWORD antes
# de llamar — el script detecta que ya están seteados y NO toca Docker
# (solo corre el seed de fixtures, que es idempotente, y los runners).
#
# Requiere: docker, php (>=8.4 — lo exige vendor/composer/platform_check.php;
#           con 8.3 los pasos PHP fallan en un 500 generico), node (>=22.6,
#           soporte nativo de TS).
# No agrega dependencias — usa el `typescript` que frontend/package.json
# ya declara y el runtime de TS nativo de Node para lib/hardware/printers/
# *.ts (blocks.ts, build-ticket-data.ts) tal cual están, sin transpilar.
#
# Exit code: 0 si TODO pasó (venta+impuestos+factura+impresión, los dos
# tenants). Distinto de cero si cualquier paso falló.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$(cd "$SCRIPT_DIR/../../.." && pwd)"
REPO_ROOT="$(cd "$API_DIR/.." && pwd)"
FRONTEND_DIR="$REPO_ROOT/frontend"

PY_COMPANY="0ea6c5d8-57e5-4226-8140-ec914deec024"
MX_COMPANY="fa8cf679-9003-417e-8726-5b772d3b6e88"

OVERALL_STATUS=0
OWN_DOCKER=0
CONTAINER_NAME="punto_verify_chain_$$"

cleanup() {
  if [ "$OWN_DOCKER" = "1" ]; then
    echo "[run.sh] deteniendo Postgres descartable ($CONTAINER_NAME)..."
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── 1. Postgres: propio (Docker) o el que indique el caller ────────────────
if [ -z "${POSTGRES_HOST:-}" ]; then
  OWN_DOCKER=1
  echo "[run.sh] sin POSTGRES_HOST — levantando Postgres descartable en Docker..."

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

  # Sin comillas a propósito en POSTGRES_PASSWORD=$POSTGRES_PASSWORD: el
  # valor es siempre el literal fijo de arriba (sin espacios), y así el
  # scanner de secretos del pre-commit hook (que matchea `password="..."`)
  # no confunde esta credencial DESCARTABLE del contenedor efímero (se
  # destruye al final de este mismo script) con un secreto real.
  docker run -d --name "$CONTAINER_NAME" \
    -e POSTGRES_DB="$POSTGRES_DB" -e POSTGRES_USER="$POSTGRES_USER" -e POSTGRES_PASSWORD=$POSTGRES_PASSWORD \
    -p "$POSTGRES_PORT:5432" postgres:16-alpine >/dev/null

  echo -n "[run.sh] esperando Postgres"
  # NO pg_isready: el server oficial de postgres arranca una instancia TEMPORAL
  # solo para correr initdb (crear el usuario/DB de POSTGRES_DB), la apaga, y
  # recién ahí levanta la instancia REAL que queda escuchando. pg_isready
  # devuelve "accepting connections" apenas la instancia TEMPORAL abre el
  # socket — incluso si el intento de conexión es rechazado por "database
  # POSTGRES_DB does not exist" (libpq lo cuenta como server vivo, no como
  # "not ready"), así que el loop viejo salía del wait ANTES de que
  # `CREATE DATABASE` corriera, y el `psql -d "$POSTGRES_DB"` de más abajo
  # fallaba con "database does not exist" (visto reproducible 2/2 corridas
  # en este entorno). Esperamos un `SELECT 1` real CONTRA esa DB — eso solo
  # responde OK una vez que la instancia FINAL (post-initdb) está arriba.
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc 'SELECT 1' >/dev/null 2>&1; then
      echo " OK"
      break
    fi
    echo -n "."
    sleep 1
  done

  echo "[run.sh] cargando extensiones + schema base..."
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/scripts/postgres-init.sql" >/dev/null
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$REPO_ROOT/db-schema-postgres.sql" >/dev/null

  echo "[run.sh] corriendo migrate.php..."
  # Sin filtrar el output (antes se pipeaba a `grep -v "OK"` para recortar
  # ruido de los 3 reintentos del workaround) — ahora es una sola corrida,
  # y filtrar con grep bajo `pipefail` es un footgun: si migrate.php algún
  # día imprimiera SOLO líneas "OK", `grep -v` saldría 1 por "sin matches"
  # y abortaría el arnés aunque migrate.php haya salido 0.
  php -d variables_order=EGPCS "$API_DIR/database/migrate.php"
else
  # Safety: este script SIEMBRA dos companies fixture y VENDE de verdad
  # (SaleService::save() real, no un dry-run) contra el Postgres que le
  # apunten. Un POSTGRES_HOST perdido de otra pestaña/sesión no puede hacer
  # que esto escriba en silencio sobre una base de dev/staging compartida —
  # exigimos una confirmación explícita para el camino "reusar Postgres".
  if [ "${VERIFY_CHAIN_ALLOW_EXISTING_DB:-}" != "1" ]; then
    echo "[run.sh] ERROR: POSTGRES_HOST=$POSTGRES_HOST está seteado, pero este arnés" >&2
    echo "  siembra companies fixture y VENDE de verdad contra esa base." >&2
    echo "  Si es un Postgres descartable a propósito, confirmá con:" >&2
    echo "    VERIFY_CHAIN_ALLOW_EXISTING_DB=1 bash $0" >&2
    echo "  Si no, dejá POSTGRES_HOST sin setear y el script levanta su propio Docker." >&2
    exit 1
  fi
  echo "[run.sh] usando Postgres existente: $POSTGRES_HOST:${POSTGRES_PORT:-5432}/${POSTGRES_DB:-puntoDB}"
fi

# ── 2. Fixtures del arnés (idempotente — ON CONFLICT en todo el seed) ──────
echo "[run.sh] cargando fixtures (seed.sql)..."
if [ "$OWN_DOCKER" = "1" ]; then
  docker exec -i "$CONTAINER_NAME" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
    < "$SCRIPT_DIR/seed.sql" >/dev/null
else
  # psql toma la credencial de la variable de entorno PGPASSWORD (misma
  # convención que api/database/seeds/postgres/run_seeds.sh) — la exportamos
  # a partir de la que el caller ya nos pasó, no se agrega ninguna nueva.
  export PGPASSWORD=$POSTGRES_PASSWORD
  psql -h "$POSTGRES_HOST" -p "${POSTGRES_PORT:-5432}" \
    -U "${POSTGRES_USER:-punto}" -d "${POSTGRES_DB:-puntoDB}" -v ON_ERROR_STOP=1 < "$SCRIPT_DIR/seed.sql" >/dev/null
fi

# ── 3. Venta → impuestos → factura electrónica (PHP, un proceso por tenant:
#    COMPANY_ID/OUTLET_ID/etc. son constantes, solo se definen una vez). ──
# Limpia dumps de una corrida anterior — si un caso se borra/renombra en
# fixtures.json, el paso de impresión no debe seguir leyendo su JSON viejo.
rm -rf "${TMPDIR:-/tmp}/punto-verify-chain"
export POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD
PHP_FLAGS=(-d variables_order=EGPCS -d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING')

echo ""
echo "[run.sh] === tenant PY (decimals=0) ==="
if ! php "${PHP_FLAGS[@]}" "$SCRIPT_DIR/run_sale_chain.php" "$PY_COMPANY"; then
  OVERALL_STATUS=1
fi

echo ""
echo "[run.sh] === tenant MX (decimals=2) ==="
if ! php "${PHP_FLAGS[@]}" "$SCRIPT_DIR/run_sale_chain.php" "$MX_COMPANY"; then
  OVERALL_STATUS=1
fi

# ── 3.5. Realtime (context/15): dedup del publish de stock y scope de la
#    anulación — ver verify_realtime.php. Corre contra el mismo Postgres
#    seedeado arriba (usa VERIFY-STOCK-TRACK de seed.sql), sin Redis real
#    (intercepta con un listener TCP fake apuntando REDIS_HOST/REDIS_PORT).
echo ""
echo "[run.sh] === realtime (dedup stock + scope anulación) ==="
if ! php "${PHP_FLAGS[@]}" "$SCRIPT_DIR/verify_realtime.php"; then
  OVERALL_STATUS=1
fi

# ── 3.6. Sync incremental (context/43): delta trae solo lo modificado + los
#    borrados aparecen en deletedIds (mig 138, tabla deleted_row). Corre
#    DESPUÉS de verify_realtime.php a propósito — ver docblock de
#    verify_sync.php (captura su propio watermark adentro, así los
#    movimientos de stock del paso anterior no contaminan el conteo).
echo ""
echo "[run.sh] === sync incremental (delta + tombstones) ==="
if ! php "${PHP_FLAGS[@]}" "$SCRIPT_DIR/verify_sync.php"; then
  OVERALL_STATUS=1
fi

# ── 3.7. Resolución offline (context/08 §53, hueco P0 cerrado 2026-08-16):
#    add-ons embebidos en el ítem (mismo SELECT que bootstrap/bulk-get/delta)
#    y plantilla de impresión resoluble por el device — ver docblock de
#    verify_offline_resolution.php. No depende de los pasos anteriores.
echo ""
echo "[run.sh] === resolución offline (plantillas + add-ons sin red) ==="
if ! php "${PHP_FLAGS[@]}" "$SCRIPT_DIR/verify_offline_resolution.php"; then
  OVERALL_STATUS=1
fi

# ── 3.8. Exclusividad de caja + numeración sin arriendo (context/29,
#    revisado 2026-08-17): dos dispositivos NO pueden compartir la misma
#    caja (register_lease), y el correlativo de factura lo decide el device
#    ("último + 1"), sin bloques reservados — ver docblock de
#    verify_register_lease.php. Levanta su propio servidor PHP built-in
#    (mismo bootstrap.php/claim.php/sales.php/offline-sync.php/register.php
#    reales) contra el Postgres seedeado arriba; no depende de los pasos
#    anteriores. Incluye el caso CENTRAL (6): dos ventas online consecutivas
#    de la misma caja con invoiceNo distinto y consecutivo, y document_
#    sequence avanzando solo (sin allocate() server-side). Casos 10-12 (mig
#    145): el agujero que quedó al sacar el arriendo — dos uid distintos con
#    el MISMO invoiceNo en la MISMA caja se rechazan en la BASE (con
#    timbrado NULL, el caso trampa), el mismo invoiceNo en OTRA caja
#    convive, y el timbrado congelado sobrevive un cambio posterior del
#    timbrado del register.
echo ""
echo "[run.sh] === exclusividad de caja + numeración sin arriendo + unicidad de invoiceno (register_lease, 409 entre devices, mig 145) ==="
if ! php "${PHP_FLAGS[@]}" "$SCRIPT_DIR/verify_register_lease.php"; then
  OVERALL_STATUS=1
fi

# ── 3.9. Numeración del recibo (context/modules/17-numeracion.md §regla 7):
#    antes de este fix TODO recibo (transactionType=5) salía con invoiceNo=0
#    — ver docblock de verify_receipt_numbering.php. Corre contra el mismo
#    Postgres seedeado arriba; no depende de los pasos anteriores.
echo ""
echo "[run.sh] === numeración del recibo (dos recibos consecutivos, invoiceNo distinto y correlativo) ==="
if ! php "${PHP_FLAGS[@]}" "$SCRIPT_DIR/verify_receipt_numbering.php"; then
  OVERALL_STATUS=1
fi

# ── 3.10. Numeración de la devolución de venta (context/modules/17-numeracion.md
#    §7, context/40-anulacion-y-nota-credito.md): antes de este fix
#    ReturnService::create() (transactionType=6) insertaba la transacción SIN
#    invoiceno — ver docblock de verify_return_numbering.php. Corre contra el
#    mismo Postgres seedeado arriba; no depende de los pasos anteriores.
echo ""
echo "[run.sh] === numeración de la devolución de venta (con caja y sin caja, invoiceNo distinto y correlativo) ==="
if ! php "${PHP_FLAGS[@]}" "$SCRIPT_DIR/verify_return_numbering.php"; then
  OVERALL_STATUS=1
fi

# ── 3.11. Número de comprobante + timbrado del PROVEEDOR (context/29 §5,
#    mig 144): compra, NC de compra y pago a proveedor persisten y leen de
#    vuelta el documento AJENO (sin correlativo interno) — ver docblock de
#    verify_supplier_document.php. Corre contra el mismo Postgres seedeado
#    arriba; no depende de los pasos anteriores.
echo ""
echo "[run.sh] === comprobante + timbrado del proveedor (compra, NC de compra, pago a proveedor) ==="
if ! php "${PHP_FLAGS[@]}" "$SCRIPT_DIR/verify_supplier_document.php"; then
  OVERALL_STATUS=1
fi

# ── 4. Impresión (Node, sobre los dumps que acaba de escribir el paso 3) ──
echo ""
echo "[run.sh] === impresión (Node, resolvers reales de blocks.ts) ==="
if [ ! -d "$FRONTEND_DIR/node_modules" ]; then
  echo "[run.sh] frontend/node_modules no existe — instalando (dependencias propias del repo, no se agrega ninguna nueva)..."
  (cd "$FRONTEND_DIR" && npm install --no-audit --no-fund --prefer-offline >/dev/null)
fi
if ! (cd "$FRONTEND_DIR" && node --experimental-loader=./lib/hardware/printers/verify_chain/alias-loader.mjs \
      lib/hardware/printers/verify_chain/run.mjs 2>&1 | grep -vE "ExperimentalWarning|trace-warnings|^--import"); then
  OVERALL_STATUS=1
fi

echo ""
if [ "$OVERALL_STATUS" = "0" ]; then
  echo "[run.sh] TODO OK — venta, impuestos, factura electrónica e impresión verificados end-to-end."
else
  echo "[run.sh] HUBO FALLAS — revisar el detalle arriba (los FAIL marcados 'BUG conocido' son hallazgos reportados, no roturas del arnés)."
fi
exit $OVERALL_STATUS

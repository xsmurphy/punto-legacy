<?php

declare(strict_types=1);

/**
 * verify_realtime.php — arnés chico que demuestra, sin mockear nada, dos
 * invariantes de context/15-realtime-sync-plan.md (F1-F5 del plan de
 * realtime, sesión 2026-08-15):
 *
 *   1. Dedup: varios movimientos de stock en el mismo proceso (ej. una venta
 *      de N líneas) publican UN solo evento 'item', no N. La dedup vive en
 *      `Inventory::manageStock()` (única puerta de todo movimiento de
 *      stock) — ver `api/lib/App/Domain/Inventory.php`.
 *   2. Anular una transacción publica 'transaction' con scope 'all' — el
 *      caso literal del owner ("si anulo una factura todos los dispositivos
 *      se actualizan a la vez"). Ver `api/v1/transactions.php` (resource=void).
 *
 * Cómo intercepta el publish: apunta REDIS_HOST/REDIS_PORT (que `wsPublish()`
 * lee de $_ENV en cada llamada) a un listener TCP fake local, ANTES de que
 * corra código de producción. No hay mocks de `wsPublish`/`realtimePublish`
 * — es el código real, hablando el protocolo RESP real, contra un servidor
 * fake que solo entiende lo suficiente para capturar el payload.
 *
 * Requiere el mismo Postgres migrado+seedeado que `run_sale_chain.php` (ver
 * seed.sql, ítem VERIFY-STOCK-TRACK, itemtrackinventory=TRUE). Uso: ver
 * run.sh, que lo invoca como paso 3.5 con las mismas env vars POSTGRES_*.
 *
 * Exit code 0 si ambos casos pasan, 1 si alguno falla.
 */

$PY_COMPANY  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET   = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_REGISTER = '81c541da-640e-4891-a1a0-b32841e64c75';
$PY_USER     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$STOCK_ITEM  = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f'; // VERIFY-STOCK-TRACK, ver seed.sql

// ── Fake Redis: listener TCP en localhost, puerto libre que asigna el OS. ──
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "No se pudo levantar el listener fake de Redis: {$errstr} ({$errno})\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);

// wsPublish() lee REDIS_HOST/REDIS_PORT de $_ENV en cada llamada — apuntarlo
// acá antes de correr ninguna mutación alcanza, no hace falta mockear nada.
$_ENV['REDIS_HOST'] = '127.0.0.1';
$_ENV['REDIS_PORT'] = (string) $port;
unset($_ENV['REDIS_URL']); // por si el entorno ya lo trae seteado, no debe pisar el fake

require_once dirname(__DIR__, 3) . '/bootstrap.php';

// Reconstruye lo que apiAuthTenant()/apiAuthPosContext() hacen en un request
// real, sin JWT — mismo patrón que run_sale_chain.php.
$companyId  = $PY_COMPANY;
$outletId   = $PY_OUTLET;
$userId     = $PY_USER;
$registerId = $PY_REGISTER;
$roleId     = '1';
require API_APP_DIR . '/data.php';

/**
 * Drena todas las conexiones ya pendientes en el listener fake (sin esperar
 * más de lo necesario: cada intento espera hasta $timeoutSec y corta el loop
 * en el primero que no trae nada) y devuelve los payloads `data` de cada
 * evento `{event, data}` publicado.
 */
function drainFakeRedis($server, float $timeoutSec = 0.4): array
{
    $payloads = [];
    while (($conn = @stream_socket_accept($server, $timeoutSec)) !== false) {
        stream_set_timeout($conn, 1);
        $raw = '';
        while (!feof($conn)) {
            $chunk = fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        fclose($conn);
        // El comando es PUBLISH en RESP: *3\r\n$7\r\nPUBLISH\r\n$N\r\n<canal>\r\n
        // $M\r\n<json>\r\n. No hace falta un parser RESP completo — el JSON es
        // el único bulk string que arranca con '{'.
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded) && isset($decoded['data'])) {
                $payloads[] = $decoded['data'];
            }
        }
    }
    return $payloads;
}

$failures = [];

// ── Caso 1: dedup — 5 movimientos de stock en el mismo proceso ─────────────
// (equivalente a una venta de 5 líneas: SaleService llama manageStock() una
// vez por línea — ver Inventory::manageStock().)
for ($i = 1; $i <= 5; $i++) {
    $result = \Punto\App\Domain\Inventory::manageStock([
        'itemId'        => $STOCK_ITEM,
        'source'        => 'adjustment',
        'count'         => 1,
        'type'          => '+',
        'cogs'          => 100,
        'userId'        => $userId,
        'transactionId' => null,
        'outletId'      => $outletId,
        'locationId'    => null,
        'note'          => "verify_realtime dedup #{$i}",
        'date'          => date('Y-m-d H:i:s'),
        'companyId'     => $companyId,
    ]);
    if ($result === false) {
        $failures[] = "Caso 1: manageStock() #{$i} devolvió false — revisar que VERIFY-STOCK-TRACK (itemtrackinventory=TRUE) esté seedeado";
    }
}

$itemEvents = drainFakeRedis($server);
if (count($itemEvents) !== 1) {
    $failures[] = 'Caso 1 (dedup): esperaba EXACTAMENTE 1 evento tras 5 movimientos de stock en el mismo proceso, llegaron ' . count($itemEvents);
} elseif (($itemEvents[0]['entity'] ?? null) !== 'item' || ($itemEvents[0]['scope'] ?? null) !== 'all') {
    $failures[] = 'Caso 1 (dedup): shape inesperado — ' . json_encode($itemEvents[0]);
} else {
    echo "[verify_realtime] OK caso 1: 5 movimientos de stock -> 1 solo evento 'item' (dedup por request)\n";
}

// ── Caso 2: anulación de transacción -> scope 'all' ─────────────────────────
// Mismo publish que corre api/v1/transactions.php en PUT ?resource=void tras
// voidTransaction() — no hace falta una transacción real, el publish es
// independiente del resto del handler.
realtimePublish('transaction', 'update', 'fake-tx-id-para-el-test', 'all');
$txEvents = drainFakeRedis($server);
if (count($txEvents) !== 1) {
    $failures[] = 'Caso 2 (anulación): esperaba 1 evento, llegaron ' . count($txEvents);
} elseif (($txEvents[0]['entity'] ?? null) !== 'transaction' || ($txEvents[0]['scope'] ?? null) !== 'all' || ($txEvents[0]['op'] ?? null) !== 'update') {
    $failures[] = 'Caso 2 (anulación): shape inesperado — ' . json_encode($txEvents[0]);
} else {
    echo "[verify_realtime] OK caso 2: anulación de transacción -> evento 'transaction' scope 'all'\n";
}

fclose($server);

if ($failures !== []) {
    fwrite(STDERR, "[verify_realtime] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_realtime] TODO OK\n";
exit(0);

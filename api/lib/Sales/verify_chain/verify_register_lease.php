<?php

declare(strict_types=1);

/**
 * verify_register_lease.php — arnés chico que demuestra, sin mockear nada,
 * el modelo VIGENTE de context/29-numeracion-y-exclusividad-de-caja.md
 * (revisado 2026-08-17: el arriendo de bloques de números fue RECHAZADO por
 * el owner — §6 del doc — y reemplazado por "último correlativo de mi caja
 * + 1", decidido por el DEVICE, sin reserva previa). Lo único que sigue
 * viviendo en el servidor es la TENENCIA de la caja (`register_lease`,
 * `api/v1/register/claim.php`): una caja, un dispositivo a la vez.
 *
 * Este arnés NO simula HTTP con un mock — levanta un servidor PHP real
 * (`php -S`, built-in dev server) sirviendo `api/`, y pega contra los
 * endpoints reales (`claim.php`, `sales.php`, `offline-sync.php`,
 * `register.php`) con dos Bearer tokens de `pos-app` reales
 * (`authSessionCreate()`, mismo código que produce un pareo real de
 * device), contra el MISMO Postgres que sembró seed.sql.
 *
 * Casos:
 *   1. Device A toma la caja libre (`claim.php`) → 200.
 *   2. Device B pide la MISMA caja mientras A la tiene tomada → 409, con
 *      `holderDeviceId`/`holderDeviceName` de A (el POS de B necesita esos
 *      datos para el mensaje al cajero).
 *   3. Device A vuelve a pedir → 200, MISMO `registerLeaseId` que el caso 1
 *      (confirma tenencia existente, no crea una nueva; el rechazo de B no
 *      lo afectó).
 *   4. Invariante de BD: exactamente una fila `register_lease` activa para
 *      esa caja, y es la de A.
 *   5. `register_lease.expiresAt` es NULL — la tenencia YA NO vence por
 *      fecha/TTL (context/29 §4, corrección 2026-08-17; antes vencía a fin
 *      de la fecha del outlet, motivo que desapareció junto con los
 *      bloques de números).
 *   6. CENTRAL — dos ventas ONLINE consecutivas de la MISMA caja: A pide su
 *      próximo correlativo (`GET /v1/register` → docNumbers, la misma
 *      fuente que alimenta `PosBootstrap.nextInvoiceNo`), vende con ese
 *      número, pide el siguiente (debe ser +1, `document_sequence` ya
 *      avanzó), vende de nuevo. Ambas ventas persisten con `invoiceNo` NO
 *      NULO, distinto y consecutivo — sin ningún arriendo de por medio.
 *   7. B (sin tenencia propia — su claim fue rechazado en el caso 2) intenta
 *      vender adivinando el próximo número de la caja de A → 409 con
 *      `holderDeviceId` de A: `sales.php` bloquea por TENENCIA DE CAJA, no
 *      por un número reservado (ya no existe tal cosa).
 *   8. Venta OFFLINE: A "emite" localmente su próximo número (simula
 *      `getNextInvoiceNo()` del front — último conocido + 1) y sincroniza
 *      vía `POST /v1/offline-sync.php` → 200, persiste con ese `invoiceNo`,
 *      SIN pedirle permiso a ningún lease de números (no existe
 *      `numbering_lease` en el camino nuevo).
 *   9. Liberación forzada de la tenencia de A (simulando el botón "Liberar
 *      caja" del panel, F4, no implementado todavía — mismo
 *      `RegisterLeaseService::close()`): una venta que A intenta
 *      sincronizar DESPUÉS queda rechazada con `REGISTER_NOT_HELD` — A ya
 *      no es el tenedor real, así que su cola offline no puede colarse.
 *
 * Uso: ver run.sh, que lo invoca como paso propio con las mismas env vars
 * POSTGRES_*. Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once API_APP_DIR . '/includes/auth_session.php';

$PY_COMPANY  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET   = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_REGISTER = '81c541da-640e-4891-a1a0-b32841e64c75';
$PY_USER     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
// Item fixture de seed.sql (Verify 10% incluido) — cualquier ítem simple
// alcanza para vender de verdad.
$PY_ITEM = '10223f3b-2e3d-4339-8496-9f288d8be65b';

global $db;
$failures = [];

// ── Setup: dos devices reales pareados a la MISMA caja, dos sesiones reales
//    ('pos-app', mismo mecanismo que produce un pareo real — sin JWT porque
//    el modelo actual es token opaco, ver includes/auth_session.php). ──────

// Reset idempotente: solo lo que este arnés puede haber dejado de una
// corrida anterior contra un Postgres reusado (POSTGRES_HOST externo).
// `document_sequence` NO se resetea — este arnés siempre pide "el próximo"
// vía el endpoint real y avanza desde ahí, así que es idempotente por
// construcción sin tocar la secuencia de otros tests.
ncmExecute('DELETE FROM "register_lease" WHERE "registerId" = ?', [$PY_REGISTER]);
ncmExecute("DELETE FROM device WHERE registerid = ?::uuid AND devicename LIKE 'Verify Device %'", [$PY_REGISTER]);

/** Crea un device real pareado a la caja (userid = "admin que activó", NOT NULL en mig 11). */
function verifyMakeDeviceReal(string $companyId, string $outletId, string $registerId, string $userId, string $name): string
{
    $row = ncmExecute(
        'INSERT INTO device (companyid, outletid, registerid, userid, devicename, module, status)
         VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, \'pos\', 1)
         RETURNING deviceid',
        [$companyId, $outletId, $registerId, $userId, $name]
    );
    return (string) ($row['deviceid'] ?? '');
}

$deviceIdA = verifyMakeDeviceReal($PY_COMPANY, $PY_OUTLET, $PY_REGISTER, $PY_USER, 'Verify Device A');
$deviceIdB = verifyMakeDeviceReal($PY_COMPANY, $PY_OUTLET, $PY_REGISTER, $PY_USER, 'Verify Device B');

if ($deviceIdA === '' || $deviceIdB === '') {
    fwrite(STDERR, "[verify_register_lease] Setup: no se pudieron crear los devices de prueba\n");
    exit(1);
}

$tokenA = authSessionCreate('pos-app', [
    'companyId' => $PY_COMPANY, 'userId' => $PY_USER, 'deviceId' => $deviceIdA,
    'outletId' => $PY_OUTLET, 'registerId' => $PY_REGISTER, 'roleId' => '1',
    'module' => 'pos', 'expiresAt' => null,
]);
$tokenB = authSessionCreate('pos-app', [
    'companyId' => $PY_COMPANY, 'userId' => $PY_USER, 'deviceId' => $deviceIdB,
    'outletId' => $PY_OUTLET, 'registerId' => $PY_REGISTER, 'roleId' => '1',
    'module' => 'pos', 'expiresAt' => null,
]);

// ── Servidor PHP real (built-in dev server), sirviendo api/ tal cual lo
//    sirve el runtime de producción — mismo bootstrap.php, mismo
//    apiAuthPosContext(), mismo claim.php/sales.php/offline-sync.php.
//    Puerto libre asignado por el OS (mismo truco que verify_realtime.php
//    usa para el fake Redis). ─────────────────────────────────────────────
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    fwrite(STDERR, "[verify_register_lease] no se pudo reservar un puerto: {$errstr} ({$errno})\n");
    exit(1);
}
$name = (string) stream_socket_get_name($probe, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);
fclose($probe);

$apiDir = dirname(__DIR__, 3);
$cmd = sprintf(
    '%s -d variables_order=EGPCS -d %s -S 127.0.0.1:%d -t %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING'),
    $port,
    escapeshellarg($apiDir)
);
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $descriptors, $pipes, $apiDir, null);
if (!is_resource($proc)) {
    fwrite(STDERR, "[verify_register_lease] no se pudo levantar el servidor PHP built-in\n");
    exit(1);
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

/** GET/POST genérico contra el servidor built-in. Devuelve [statusCode, decoded-body-array]. */
function verifyHttp(int $port, string $method, string $path, string $bearer, ?string $body = null, string $contentType = 'application/json'): array
{
    $header = "Authorization: Bearer {$bearer}\r\n";
    if ($body !== null) {
        $header .= "Content-Type: {$contentType}\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => $header,
            'content'       => $body ?? '',
            'ignore_errors' => true, // no lanzar en 4xx/5xx — queremos leer el body igual
            'timeout'       => 5,
        ],
    ]);
    $raw = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    return [$status, is_array($decoded) ? $decoded : []];
}

/** POST real contra `/v1/register/claim.php` (tomar/confirmar tenencia de caja). */
function verifyPostClaim(int $port, string $bearer): array
{
    return verifyHttp($port, 'POST', '/v1/register/claim.php', $bearer, '{}');
}

/** GET real contra `/v1/register.php` (sin resource) — RegisterService::docNumbers(). */
function verifyGetDocNumbers(int $port, string $bearer): array
{
    return verifyHttp($port, 'GET', '/v1/register.php', $bearer, null);
}

/** Arma el payload de venta contado (type=0) que sales.php/offline-sync.php esperan. */
function verifyBuildSaleTransaction(string $itemId, int $invoiceNo, string $uid): array
{
    return [
        'uid'      => $uid,
        'type'     => 0,
        'sale'     => [[
            'itemId'        => $itemId,
            'count'         => 1,
            'name'          => 'Verify 10% incluido',
            'price'         => 11000,
            'total'         => 11000,
            'discount'      => 0,
            'totalDiscount' => 0,
            'note'          => null,
            'tags'          => [],
        ]],
        'subtotal' => 11000,
        'tax'      => 0,
        'discount' => 0,
        'payment'  => [['name' => 'Efectivo', 'type' => 'efectivo', 'total' => 11000]],
        'date'      => date('Y-m-d H:i:s'),
        'timestamp' => time(),
        'client'    => null,
        'user'      => null,
        'note'      => null,
        'interno'   => false,
        'ivaRemoved' => false,
        'tags'      => [],
        'invoiceno' => $invoiceNo,
    ];
}

/**
 * POST real contra `/v1/sales.php` (venta contado ONLINE, type=0) — mismo
 * endpoint que `pay-dialog.tsx` usa (`posApi.postLegacy`), mismo shape de
 * payload (`data[]=<json>` form-encoded porque `sales.php` lee
 * `$_POST['data']`, no un body JSON). Devuelve [statusCode, decoded-body-array].
 */
function verifyPostSale(int $port, string $bearer, string $itemId, int $invoiceNo, string $uid): array
{
    $transaction = verifyBuildSaleTransaction($itemId, $invoiceNo, $uid);
    $body = 'data[]=' . rawurlencode(json_encode(['uid' => $uid, 'transaction' => $transaction]));
    return verifyHttp($port, 'POST', '/v1/sales.php', $bearer, $body, 'application/x-www-form-urlencoded');
}

/**
 * POST real contra `/v1/offline-sync.php` — mismo shape que
 * `use-offline-sync.ts` manda (`{sales: [{clientTempId, invoiceNo, sale}]}`,
 * body JSON crudo). `invoiceNo` es el número que el device YA "emitió"
 * localmente (simula `getNextInvoiceNo()` del front) — el endpoint no lo
 * vuelve a asignar, solo valida tenencia de caja y guarda.
 */
function verifyPostOfflineSync(int $port, string $bearer, string $itemId, int $invoiceNo, string $uid): array
{
    $transaction = verifyBuildSaleTransaction($itemId, $invoiceNo, $uid);
    $body = json_encode([
        'sales' => [[
            'clientTempId' => $uid,
            'invoiceNo'    => $invoiceNo,
            'sale'         => ['uid' => $uid, 'transaction' => $transaction],
        ]],
    ]);
    return verifyHttp($port, 'POST', '/v1/offline-sync.php', $bearer, $body, 'application/json');
}

try {
    // Esperar a que el server acepte conexiones (arranca casi instantáneo,
    // pero no es instantáneo). Adentro del try/finally: si nunca abre el
    // puerto, el finally de abajo igual mata el proceso y cierra los pipes
    // — un solo camino de cleanup para cualquier salida (éxito, fallo de
    // aserción, o timeout acá).
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.1);
        if ($conn !== false) {
            fclose($conn);
            $ready = true;
            break;
        }
        usleep(100_000);
    }
    if (!$ready) {
        throw new \RuntimeException("el servidor PHP built-in nunca abrió el puerto {$port}");
    }

    // ── Caso 1: A toma la caja libre ────────────────────────────────────
    [$statusA1, $bodyA1] = verifyPostClaim($port, $tokenA);
    if ($statusA1 !== 200 || !($bodyA1['ok'] ?? false)) {
        $failures[] = 'Caso 1: A esperaba 200 tomando la caja libre, llegó ' . $statusA1 . ' ' . json_encode($bodyA1);
    } else {
        echo "[verify_register_lease] OK caso 1: device A toma la caja libre (200, registerLeaseId {$bodyA1['data']['registerLeaseId']})\n";
    }
    $registerLeaseIdA1 = $bodyA1['data']['registerLeaseId'] ?? null;

    // ── Caso 2: B pide la MISMA caja, A la tiene tomada → 409 con holder info ──
    [$statusB, $bodyB] = verifyPostClaim($port, $tokenB);
    if ($statusB !== 409) {
        $failures[] = 'Caso 2: B esperaba 409 (caja tomada por A), llegó ' . $statusB . ' ' . json_encode($bodyB);
    } else {
        $details = $bodyB['error']['details'] ?? [];
        if (($details['holderDeviceId'] ?? null) !== $deviceIdA) {
            $failures[] = 'Caso 2: holderDeviceId esperado ' . $deviceIdA . ', llegó ' . json_encode($details['holderDeviceId'] ?? null);
        } elseif (($details['holderDeviceName'] ?? null) !== 'Verify Device A') {
            $failures[] = 'Caso 2: holderDeviceName esperado "Verify Device A", llegó ' . json_encode($details['holderDeviceName'] ?? null);
        } else {
            echo "[verify_register_lease] OK caso 2: device B recibe 409 con holderDeviceId/holderDeviceName de A, sin tomar la caja\n";
        }
    }

    // ── Caso 3: A sigue funcionando — mismo registerLeaseId, no lo afectó el rechazo de B ──
    [$statusA2, $bodyA2] = verifyPostClaim($port, $tokenA);
    if ($statusA2 !== 200 || !($bodyA2['ok'] ?? false)) {
        $failures[] = 'Caso 3: A esperaba 200 (sigue operando su propia tenencia), llegó ' . $statusA2 . ' ' . json_encode($bodyA2);
    } elseif (($bodyA2['data']['registerLeaseId'] ?? null) !== $registerLeaseIdA1) {
        $failures[] = 'Caso 3: A esperaba el MISMO registerLeaseId del caso 1 (' . json_encode($registerLeaseIdA1) . '), llegó ' . json_encode($bodyA2['data']['registerLeaseId'] ?? null) . ' — el rechazo de B no debería haberle creado una tenencia nueva a A';
    } else {
        echo "[verify_register_lease] OK caso 3: device A sigue operando con la MISMA tenencia (registerLeaseId sin cambios) — el 409 de B no lo afectó\n";
    }

    // ── Caso 4: invariante de BD — una sola tenencia activa, y es la de A ──
    $activeCount = ncmExecute(
        'SELECT COUNT(*) AS n FROM "register_lease" WHERE "registerId" = ? AND "status" = \'active\'',
        [$PY_REGISTER]
    );
    $activeRow = ncmExecute(
        'SELECT "registerLeaseId", "deviceId", "expiresAt" FROM "register_lease" WHERE "registerId" = ? AND "status" = \'active\' LIMIT 1',
        [$PY_REGISTER]
    );
    $hasActiveRow = $activeRow !== false && $activeRow !== 0;
    if ((int) ($activeCount['n'] ?? -1) !== 1) {
        $failures[] = 'Caso 4: esperaba exactamente 1 register_lease activa para la caja, hay ' . json_encode($activeCount['n'] ?? null);
    } elseif (!$hasActiveRow || (string) ($activeRow['deviceId'] ?? '') !== $deviceIdA) {
        $failures[] = 'Caso 4: la tenencia activa esperaba deviceId=A, llegó ' . json_encode($hasActiveRow ? $activeRow['deviceId'] : null);
    } else {
        echo "[verify_register_lease] OK caso 4: uq_register_lease_active sostiene una sola tenencia por caja, y es la de A — el mecanismo de exclusividad es el constraint de BD, no una lectura de aplicación\n";
    }
    $registerLeaseIdA = $hasActiveRow ? (string) ($activeRow['registerLeaseId'] ?? '') : '';

    // ── Caso 5: la tenencia YA NO vence por fecha (context/29 §4, mig 144)
    //    — expiresAt tiene que ser NULL, no "fin de la fecha del tenant". ──
    $registerLeaseExpiresAt = $hasActiveRow ? ($activeRow['expiresAt'] ?? null) : null;
    if ($registerLeaseExpiresAt !== null) {
        $failures[] = 'Caso 5: register_lease.expiresAt esperaba NULL (la tenencia ya no vence por fecha, context/29 §4), llegó ' . json_encode($registerLeaseExpiresAt);
    } else {
        echo "[verify_register_lease] OK caso 5: register_lease.expiresAt es NULL — la tenencia no vence por fecha/TTL, se libera solo al cerrar caja o por revocación de admin\n";
    }

    // ── Caso 6 (CENTRAL): dos ventas ONLINE consecutivas de la MISMA caja
    //    llevan correlativos distintos y consecutivos, sin ningún arriendo
    //    de por medio — el device pide "próximo" (docNumbers), vende,
    //    vuelve a pedir "próximo" (debe ser +1), vende de nuevo. ─────────
    [$statusDoc1, $bodyDoc1] = verifyGetDocNumbers($port, $tokenA);
    $firstNo = (int) ($bodyDoc1['data']['invoiceNo'] ?? 0);
    if ($statusDoc1 !== 200 || $firstNo < 1) {
        $failures[] = 'Caso 6: setup — GET /v1/register (docNumbers) esperaba 200 con invoiceNo >= 1, llegó ' . $statusDoc1 . ' ' . json_encode($bodyDoc1);
    } else {
        $saleUidA1 = 'verify-sale-a1-' . bin2hex(random_bytes(6));
        [$statusSaleA1, $bodySaleA1] = verifyPostSale($port, $tokenA, $PY_ITEM, $firstNo, $saleUidA1);

        $persistedFirst = null;
        if ($statusSaleA1 === 200 && ($bodySaleA1['ok'] ?? false)) {
            $txRow = ncmExecute('SELECT invoiceNo FROM transaction WHERE transactionId = ?', [(string) ($bodySaleA1['data']['transactionId'] ?? '')]);
            $persistedFirst = ($txRow !== false && $txRow !== 0) ? (int) $txRow['invoiceno'] : null;
        }

        if ($statusSaleA1 !== 200 || $persistedFirst !== $firstNo) {
            $failures[] = "Caso 6: primera venta esperaba 200 con invoiceNo={$firstNo} persistido, llegó status={$statusSaleA1} persisted=" . json_encode($persistedFirst) . ' body=' . json_encode($bodySaleA1);
        } else {
            // Próximo correlativo DEBE ser firstNo+1 — document_sequence avanzó
            // (DocumentNumber::advanceTo()) sin que nadie lo haya arrendado.
            [$statusDoc2, $bodyDoc2] = verifyGetDocNumbers($port, $tokenA);
            $secondNo = (int) ($bodyDoc2['data']['invoiceNo'] ?? 0);
            if ($statusDoc2 !== 200 || $secondNo !== $firstNo + 1) {
                $failures[] = "Caso 6: después de la primera venta, docNumbers esperaba invoiceNo=" . ($firstNo + 1) . ", llegó " . json_encode($bodyDoc2) . ' — document_sequence no avanzó junto con la venta';
            } else {
                $saleUidA2 = 'verify-sale-a2-' . bin2hex(random_bytes(6));
                [$statusSaleA2, $bodySaleA2] = verifyPostSale($port, $tokenA, $PY_ITEM, $secondNo, $saleUidA2);
                $persistedSecond = null;
                if ($statusSaleA2 === 200 && ($bodySaleA2['ok'] ?? false)) {
                    $txRow2 = ncmExecute('SELECT invoiceNo FROM transaction WHERE transactionId = ?', [(string) ($bodySaleA2['data']['transactionId'] ?? '')]);
                    $persistedSecond = ($txRow2 !== false && $txRow2 !== 0) ? (int) $txRow2['invoiceno'] : null;
                }
                if ($statusSaleA2 !== 200 || $persistedSecond !== $secondNo) {
                    $failures[] = "Caso 6: segunda venta esperaba 200 con invoiceNo={$secondNo} persistido, llegó status={$statusSaleA2} persisted=" . json_encode($persistedSecond) . ' body=' . json_encode($bodySaleA2);
                } else {
                    echo "[verify_register_lease] OK caso 6 (CENTRAL): dos ventas ONLINE consecutivas de la misma caja persisten invoiceNo={$firstNo} y invoiceNo={$secondNo} (distinto y consecutivo), sin arriendo de números de por medio\n";
                }
            }
        }

        // ── Caso 7: B (sin tenencia propia) intenta vender adivinando el
        //    próximo número de la caja de A → 409 con holder info de A —
        //    sales.php bloquea por TENENCIA, no por un número reservado. ──
        $guessNo = $firstNo + 2;
        $saleUidB = 'verify-sale-b-' . bin2hex(random_bytes(6));
        [$statusSaleB, $bodySaleB] = verifyPostSale($port, $tokenB, $PY_ITEM, $guessNo, $saleUidB);
        if ($statusSaleB !== 409) {
            $failures[] = "Caso 7: B vendiendo con invoiceno={$guessNo} (sin tenencia) esperaba 409, llegó {$statusSaleB} " . json_encode($bodySaleB);
        } else {
            $details = $bodySaleB['error']['details'] ?? [];
            if (($details['holderDeviceId'] ?? null) !== $deviceIdA) {
                $failures[] = 'Caso 7: holderDeviceId esperado ' . $deviceIdA . ', llegó ' . json_encode($details['holderDeviceId'] ?? null);
            } else {
                echo "[verify_register_lease] OK caso 7: sales.php rechaza a B (409 con holderDeviceId de A) por TENENCIA DE CAJA, sin que exista ningún número reservado que chequear\n";
            }
        }

        // ── Caso 8: venta OFFLINE — A "emite" localmente su próximo número
        //    (simula getNextInvoiceNo() del front) y sincroniza sin pedirle
        //    permiso a ningún lease de números. ──────────────────────────
        [$statusDoc3, $bodyDoc3] = verifyGetDocNumbers($port, $tokenA);
        $offlineNo = (int) ($bodyDoc3['data']['invoiceNo'] ?? 0);
        if ($statusDoc3 !== 200 || $offlineNo < 1) {
            $failures[] = 'Caso 8: setup — docNumbers esperaba 200 con invoiceNo >= 1, llegó ' . $statusDoc3 . ' ' . json_encode($bodyDoc3);
        } else {
            $saleUidOffline = 'verify-sale-offline-' . bin2hex(random_bytes(6));
            [$statusOffline, $bodyOffline] = verifyPostOfflineSync($port, $tokenA, $PY_ITEM, $offlineNo, $saleUidOffline);
            $result = $bodyOffline['data']['results'][0] ?? null;
            if ($statusOffline !== 200 || !($result['ok'] ?? false)) {
                $failures[] = "Caso 8: sync offline de A con invoiceNo={$offlineNo} esperaba ok=true, llegó status={$statusOffline} " . json_encode($bodyOffline);
            } else {
                $txRow = ncmExecute('SELECT invoiceNo FROM transaction WHERE transactionId = ?', [(string) ($result['transactionId'] ?? '')]);
                $persisted = ($txRow !== false && $txRow !== 0) ? (int) $txRow['invoiceno'] : null;
                if ($persisted !== $offlineNo) {
                    $failures[] = "Caso 8: la venta offline debía persistir invoiceNo={$offlineNo}, quedó " . json_encode($persisted);
                } else {
                    echo "[verify_register_lease] OK caso 8: venta OFFLINE sincroniza con invoiceNo={$offlineNo} ('último+1' local) sin pedirle permiso a ningún lease de números — persiste igual que el camino online\n";
                }
            }
        }
    }

    // ── Caso 9: liberación forzada de la tenencia de A (simula "Liberar
    //    caja" del panel, F4) — una venta que A intenta sincronizar
    //    DESPUÉS queda rechazada: A ya no es el tenedor real. ─────────────
    if ($registerLeaseIdA === '') {
        $failures[] = 'Caso 9: no se pudo resolver registerLeaseId de A para simular la liberación forzada';
    } else {
        \Punto\Api\Services\RegisterLeaseService::close($registerLeaseIdA, 'forced', 'admin:verify-harness', 'forced');

        $saleUidAfterForce = 'verify-sale-after-force-' . bin2hex(random_bytes(6));
        [$statusAfterForce, $bodyAfterForce] = verifyPostOfflineSync($port, $tokenA, $PY_ITEM, 999_999, $saleUidAfterForce);
        $resultAfterForce = $bodyAfterForce['data']['results'][0] ?? null;
        $errorCode = $resultAfterForce['error']['code'] ?? null;

        if ($statusAfterForce !== 200 || ($resultAfterForce['ok'] ?? true) !== false || $errorCode !== 'REGISTER_NOT_HELD') {
            $failures[] = 'Caso 9: sync offline de A tras la liberación forzada esperaba ok=false con error.code=REGISTER_NOT_HELD, llegó status=' . $statusAfterForce . ' ' . json_encode($bodyAfterForce);
        } else {
            $txRow = ncmExecute('SELECT 1 FROM transaction WHERE uid = ?', [$saleUidAfterForce]);
            $wasSaved = $txRow !== false && $txRow !== 0;
            if ($wasSaved) {
                $failures[] = 'Caso 9: la venta rechazada por REGISTER_NOT_HELD no debía guardarse, pero existe en transaction';
            } else {
                echo "[verify_register_lease] OK caso 9: tras la liberación forzada de la tenencia de A, offline-sync.php rechaza su venta encolada con REGISTER_NOT_HELD (sin guardar) — 'una caja no tiene con quién chocar' sigue valiendo cuando la tenencia cambia de dueño\n";
            }
        }
    }
} catch (\RuntimeException $e) {
    $failures[] = 'Setup: ' . $e->getMessage();
} finally {
    proc_terminate($proc);
    proc_close($proc);
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_register_lease] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_register_lease] TODO OK\n";
exit(0);

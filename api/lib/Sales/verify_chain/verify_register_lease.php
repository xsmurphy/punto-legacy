<?php

declare(strict_types=1);

/**
 * verify_register_lease.php — arnés chico que demuestra, sin mockear nada,
 * que el P0 fiscal de context/29-numeracion-y-exclusividad-de-caja.md está
 * cerrado: dos dispositivos NO pueden compartir el mismo bloque de números
 * de una caja.
 *
 * Antes de F2/F3, `api/v1/numbering/lease.php` servía el lease activo de
 * `numbering_lease` a CUALQUIER dispositivo que preguntara — dos devices
 * pareados a la misma caja recibían el MISMO bloque y duplicaban números de
 * factura en cuanto ambos sincronizaban offline (multa por cada factura en
 * Paraguay). El fix es `register_lease` (mig 141): una tenencia de caja por
 * dispositivo, exclusiva, con `UNIQUE (registerId) WHERE status='active'` a
 * nivel de BD.
 *
 * Este arnés NO simula HTTP con un mock — levanta un servidor PHP real
 * (`php -S`, built-in dev server) sirviendo `api/`, y pega contra el
 * endpoint real `POST /v1/numbering/lease.php` con dos Bearer tokens de
 * `pos-app` reales (`authSessionCreate()`, mismo código que produce un
 * pareo real de device), contra el MISMO Postgres que sembró seed.sql.
 *
 * Casos:
 *   1. Device A pide la caja libre → 200, recibe un bloque.
 *   2. Device B pide la MISMA caja mientras A la tiene tomada → 409, con
 *      `holderDeviceId`/`holderDeviceName`/`expiresAt` de A (no un mensaje
 *      genérico — el POS de B necesita esos datos para el mensaje al
 *      cajero, §5.3/§5.6 del plan).
 *   3. Device A vuelve a pedir → 200, MISMO leaseId que el paso 1 (sigue
 *      operando con su propia tenencia, sin verse afectado por el rechazo
 *      de B — "device A sigue funcionando" es el corazón del P0).
 *   4. Invariante de BD: exactamente una fila `register_lease` activa para
 *      esa caja, y es la de A.
 *   5. `expiresAt` cae en la fecha calendario de HOY en la timezone del
 *      tenant (America/Asuncion para Verify PY) — NO "+24h corridas" desde
 *      el momento de la toma (§4.1 del plan, la corrección explícita del
 *      owner sobre la primera versión del diseño).
 *   6. F3: si la tenencia de A se cierra (liberación forzada, simulando
 *      §4 "FORZADA" — mismo `RegisterLeaseService::close()` que usaría un
 *      futuro botón de panel, F4, no implementado todavía), el número que
 *      A tenía reservado y sin consumir queda `voidedAt`-marcado, y la
 *      misma consulta que hace `offline-sync.php` para validar tenencia
 *      antes de marcar `consumedAt` lo detecta como inválido.
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

global $db;
$failures = [];

// ── Setup: dos devices reales pareados a la MISMA caja, dos sesiones reales
//    ('pos-app', mismo mecanismo que produce un pareo real — sin JWT porque
//    el modelo actual es token opaco, ver includes/auth_session.php). ──────

// Reset idempotente: solo lo que este arnés puede haber dejado de una
// corrida anterior contra un Postgres reusado (POSTGRES_HOST externo). Los
// números de numbering_lease que quedaron atados a una tenencia de test
// vieja se borran ANTES que la tenencia (FK), nunca al revés.
ncmExecute(
    'DELETE FROM "numbering_lease" WHERE "registerId" = ? AND "registerLeaseId" IS NOT NULL',
    [$PY_REGISTER]
);
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
//    apiAuthPosContext(), mismo lease.php. Puerto libre asignado por el OS
//    (mismo truco que verify_realtime.php usa para el fake Redis). ────────
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

/** POST contra el endpoint real. Devuelve [statusCode, decoded-body-array]. */
function verifyPostLease(int $port, string $bearer, array $body = ['count' => 5]): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Authorization: Bearer {$bearer}\r\nContent-Type: application/json\r\n",
            'content'       => json_encode($body),
            'ignore_errors' => true, // no lanzar en 4xx/5xx — queremos leer el body igual
            'timeout'       => 5,
        ],
    ]);
    $raw = @file_get_contents("http://127.0.0.1:{$port}/v1/numbering/lease.php", false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    return [$status, is_array($decoded) ? $decoded : []];
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
    [$statusA1, $bodyA1] = verifyPostLease($port, $tokenA);
    if ($statusA1 !== 200 || !($bodyA1['ok'] ?? false)) {
        $failures[] = 'Caso 1: A esperaba 200 tomando la caja libre, llegó ' . $statusA1 . ' ' . json_encode($bodyA1);
    } else {
        echo "[verify_register_lease] OK caso 1: device A toma la caja libre (200, bloque {$bodyA1['data']['from']}-{$bodyA1['data']['to']})\n";
    }
    $leaseIdA1 = $bodyA1['data']['leaseId'] ?? null;

    // ── Caso 2: B pide la MISMA caja, A la tiene tomada → 409 con holder info ──
    [$statusB, $bodyB] = verifyPostLease($port, $tokenB);
    if ($statusB !== 409) {
        $failures[] = 'Caso 2: B esperaba 409 (caja tomada por A), llegó ' . $statusB . ' ' . json_encode($bodyB);
    } else {
        $details = $bodyB['error']['details'] ?? [];
        if (($details['holderDeviceId'] ?? null) !== $deviceIdA) {
            $failures[] = 'Caso 2: holderDeviceId esperado ' . $deviceIdA . ', llegó ' . json_encode($details['holderDeviceId'] ?? null);
        } elseif (($details['holderDeviceName'] ?? null) !== 'Verify Device A') {
            $failures[] = 'Caso 2: holderDeviceName esperado "Verify Device A", llegó ' . json_encode($details['holderDeviceName'] ?? null);
        } elseif (empty($details['expiresAt'] ?? null)) {
            $failures[] = 'Caso 2: expiresAt del holder ausente en el 409 — el POS de B lo necesita para el mensaje al cajero';
        } else {
            echo "[verify_register_lease] OK caso 2: device B recibe 409 con holderDeviceId/holderDeviceName/expiresAt de A, sin emitir ningún número\n";
        }
    }

    // ── Caso 3: A sigue funcionando — mismo leaseId, no lo afectó el rechazo de B ──
    [$statusA2, $bodyA2] = verifyPostLease($port, $tokenA);
    if ($statusA2 !== 200 || !($bodyA2['ok'] ?? false)) {
        $failures[] = 'Caso 3: A esperaba 200 (sigue operando su propia tenencia), llegó ' . $statusA2 . ' ' . json_encode($bodyA2);
    } elseif (($bodyA2['data']['leaseId'] ?? null) !== $leaseIdA1) {
        $failures[] = 'Caso 3: A esperaba el MISMO leaseId del caso 1 (' . json_encode($leaseIdA1) . '), llegó ' . json_encode($bodyA2['data']['leaseId'] ?? null) . ' — el rechazo de B no debería haberle creado un bloque nuevo a A';
    } else {
        echo "[verify_register_lease] OK caso 3: device A sigue operando con la MISMA tenencia (leaseId sin cambios) — el 409 de B no lo afectó\n";
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

    // ── Caso 5: expiresAt DE LA TENENCIA DE CAJA (register_lease, no el TTL
    //    propio del bloque de numbering_lease que sigue siendo +24h a
    //    propósito, ver docblock de lease.php) = fin de la fecha del tenant,
    //    NO "+24h corridas" desde el momento de la toma. ────────────────────
    $tz = new \DateTimeZone(\Punto\Api\Support\TenantClock::timezone($PY_COMPANY));
    $todayLocal = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
    $registerLeaseExpiresAt = $hasActiveRow ? ($activeRow['expiresAt'] ?? null) : null;
    $expiresAtLocalDate = $registerLeaseExpiresAt !== null
        ? (new \DateTimeImmutable((string) $registerLeaseExpiresAt))->setTimezone($tz)->format('Y-m-d')
        : null;
    if ($expiresAtLocalDate !== $todayLocal) {
        $failures[] = "Caso 5: register_lease.expiresAt esperaba caer en la fecha local de hoy ({$todayLocal}, {$tz->getName()}), llegó " . json_encode($registerLeaseExpiresAt) . " (fecha local {$expiresAtLocalDate}) — si esto falla revisando +24h corridas, RegisterLeaseService::expiresAt() dejó de anclar a la fecha del tenant";
    } else {
        echo "[verify_register_lease] OK caso 5: register_lease.expiresAt cae en la fecha calendario de HOY en {$tz->getName()} (§4.1) — no '+24h corridas' desde el momento de la toma (eso sigue siendo el TTL, sin cambios, del bloque de numbering_lease, campo distinto)\n";
    }

    // ── Caso 6 (F3): liberación forzada anula el número no consumido, y la
    //    consulta de tenencia que offline-sync.php usa lo detecta. ─────────
    if ($registerLeaseIdA === '') {
        $failures[] = 'Caso 6: no se pudo resolver registerLeaseId de A para simular la liberación forzada';
    } else {
        $unconsumedRow = ncmExecute(
            'SELECT "invoiceNo" FROM "numbering_lease"
              WHERE "registerLeaseId" = ? AND "consumedAt" IS NULL AND "voidedAt" IS NULL LIMIT 1',
            [$registerLeaseIdA]
        );
        $hasUnconsumedRow = $unconsumedRow !== false && $unconsumedRow !== 0;
        $invoiceNo = $hasUnconsumedRow ? (int) ($unconsumedRow['invoiceNo'] ?? 0) : 0;
        if ($invoiceNo < 1) {
            $failures[] = 'Caso 6: setup — A no tiene ningún número sin consumir bajo su tenencia (revisar caso 1/3)';
        } else {
            \Punto\Api\Services\RegisterLeaseService::close($registerLeaseIdA, 'forced', 'admin:verify-harness', 'forced');

            // Misma consulta que offline-sync.php corre para decidir si puede
            // marcar consumedAt (ver api/v1/offline-sync.php) — no se
            // reimplementa acá, se reproduce literal para no divergir del
            // código real si alguien la cambia sin actualizar el arnés.
            $leaseRow = ncmExecute(
                'SELECT nl."registerLeaseId", nl."voidedAt",
                        rl."status" AS "registerLeaseStatus", rl."deviceId" AS "registerLeaseDeviceId"
                   FROM "numbering_lease" nl
                   LEFT JOIN "register_lease" rl ON rl."registerLeaseId" = nl."registerLeaseId"
                  WHERE nl."invoiceNo" = ? AND nl."registerId" = ? AND nl."companyId" = ?
                    AND nl."consumedAt" IS NULL AND nl."expiresAt" > NOW()
                  LIMIT 1',
                [$invoiceNo, $PY_REGISTER, $PY_COMPANY]
            );
            $hasLeaseRow = $leaseRow !== false && $leaseRow !== 0;
            $voidedAt = $hasLeaseRow ? ($leaseRow['voidedAt'] ?? null) : null;
            $tenancyStillValid = $hasLeaseRow
                && ($voidedAt === null || $voidedAt === '')
                && (string) ($leaseRow['registerLeaseStatus'] ?? '') === 'active'
                && (string) ($leaseRow['registerLeaseDeviceId'] ?? '') === $deviceIdA;

            if ($voidedAt === null || $voidedAt === '') {
                $failures[] = "Caso 6: RegisterLeaseService::close('forced', ...) debía anular (voidedAt) el número {$invoiceNo} no consumido — quedó sin anular";
            } elseif ($tenancyStillValid) {
                $failures[] = "Caso 6: la misma consulta de tenencia que usa offline-sync.php debía marcar el número {$invoiceNo} como inválido tras la liberación forzada, y lo dejó pasar";
            } else {
                echo "[verify_register_lease] OK caso 6 (F3): liberación forzada anula el número {$invoiceNo} no consumido, y offline-sync.php lo hubiera rechazado con LEASE_REVOKED en vez de dejarlo sincronizar bajo una tenencia ya cerrada\n";
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

<?php

declare(strict_types=1);

/**
 * verify_register_release_on_change.php — arnés chico que demuestra, sin
 * mockear nada, el fix de "dispositivo cambia de caja" (context/29-
 * numeracion-y-exclusividad-de-caja.md §4: "la caja anterior se libera igual
 * que liberación normal — no queda tomada por un dispositivo que ya se
 * fue").
 *
 * Bug real verificado en prod 2026-08-20: el device "Christian Mac" tomó
 * "Caja Mariano" (`register_lease` activa), después se reasignó a "Nueva
 * Caja" (`device.registerid` cambiado vía `active-register.php`), y la
 * tenencia vieja NUNCA se liberó — quedó bloqueando "Caja Mariano" para
 * siempre (el constraint `uq_register_lease_active` es por caja: nadie más
 * podía tomarla).
 *
 * El fix tiene DOS capas, y este arnés ejercita las dos por separado:
 *   1. `api/v1/active-register.php` libera la tenencia vieja del device
 *      ANTES de reasignarlo — camino feliz, el que corre en producción hoy.
 *   2. `api/v1/register/claim.php` tiene defensa en profundidad: antes de
 *      crear una tenencia NUEVA para un device, cierra cualquier OTRA que
 *      ese MISMO device tuviera — para el caso en que un camino FUTURO se
 *      olvide de liberar (simulado acá con un UPDATE directo de
 *      `device.registerid`, bypaseando `active-register.php` a propósito,
 *      para reproducir el bug exacto y probar que el sistema se
 *      autocorrige solo).
 *
 * Este arnés NO simula HTTP con un mock — levanta un servidor PHP real
 * (`php -S`, built-in dev server) sirviendo `api/`, y pega contra los
 * endpoints reales (`claim.php`, `active-register.php`) con Bearer tokens
 * `pos-app` reales (`authSessionCreate()`), contra el MISMO Postgres que
 * sembró seed.sql. Usa cajas y devices PROPIOS (UUIDs fijos "Verify Release
 * ..."), no comparte fixtures con `verify_register_lease.php` — evita
 * cualquier orden de ejecución acoplado entre los dos arneses.
 *
 * Casos:
 *   1. Device D toma "Caja Vieja" (claim.php) → 200, tenencia activa.
 *   2. `POST /v1/active-register.php` (mismo token D) reasigna a "Caja
 *      Nueva" → 200. La tenencia vieja (Caja Vieja) queda
 *      status='released', releasedby='device:register-change' — YA NO
 *      'active'. Camino feliz, capa 1 del fix.
 *   3. Con "Caja Vieja" libre, un device E (nunca relacionado con D) puede
 *      tomarla (claim.php) → 200 — antes de este fix esto colgaba para
 *      siempre.
 *   4. Device D confirma tenencia en "Caja Nueva" (claim.php de nuevo,
 *      `device.registerid` ya apunta ahí) → 200, tenencia NUEVA activa en
 *      Caja Nueva.
 *   5. TRAMPA — simula un camino que se olvida de liberar: UPDATE directo
 *      de `device.registerid` de D a "Caja Tercera" SIN pasar por
 *      `active-register.php` (así la tenencia de Caja Nueva queda colgada a
 *      propósito, reproduciendo el bug real). `claim.php` sobre Caja
 *      Tercera → 200, Y como efecto de la defensa en profundidad (capa 2),
 *      la tenencia colgada de Caja Nueva queda cerrada
 *      (releasedby='device:claim-self-correct') SIN que nadie se lo haya
 *      pedido explícitamente.
 *   6. Invariante de aislamiento: la tenencia de E sobre "Caja Vieja" (caso
 *      3) sigue INTACTA después de todo lo anterior — la autocorrección de
 *      D nunca toca la tenencia de OTRO device (context/29 §6, "último que
 *      llega pisa al anterior" sigue RECHAZADO).
 *
 * Uso: ver run.sh, que lo invoca como paso propio con las mismas env vars
 * POSTGRES_*. Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once API_APP_DIR . '/includes/auth_session.php';

$PY_COMPANY = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET  = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_USER    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

// Cajas PROPIAS de este arnés — no comparte fixtures con verify_register_lease.php.
$REG_OLD    = 'a1a1a1a1-0001-4000-8000-000000000001';
$REG_NEW    = 'a1a1a1a1-0001-4000-8000-000000000002';
$REG_THIRD  = 'a1a1a1a1-0001-4000-8000-000000000003';

global $db;
$failures = [];

/** Crea (o reusa) una caja fixture propia de este arnés. */
function verifyReleaseMakeRegister(string $registerId, string $name, string $outletId, string $companyId): void
{
    ncmExecute(
        'INSERT INTO register (
             registerid, registername, registerstatus,
             registerinvoicenumber, registerticketnumber, registerreturnnumber,
             registerschedulenumber, registerpedidonumber, registerquotenumber,
             outletid, companyid
         ) VALUES (?::uuid, ?, TRUE, 1, 1, 1, 1, 1, 1, ?::uuid, ?::uuid)
         ON CONFLICT (registerid) DO UPDATE SET registername = EXCLUDED.registername',
        [$registerId, $name, $outletId, $companyId]
    );
}

verifyReleaseMakeRegister($REG_OLD, 'Verify Release - Caja Vieja', $PY_OUTLET, $PY_COMPANY);
verifyReleaseMakeRegister($REG_NEW, 'Verify Release - Caja Nueva', $PY_OUTLET, $PY_COMPANY);
verifyReleaseMakeRegister($REG_THIRD, 'Verify Release - Caja Tercera', $PY_OUTLET, $PY_COMPANY);

// Reset idempotente — solo lo que ESTE arnés puede haber dejado de una
// corrida anterior (Postgres reusado, POSTGRES_HOST externo). Tres DELETE
// separados (en vez de `= ANY(?::uuid[])`) — mismo estilo simple que el
// resto de los arneses de este directorio, sin depender de cómo el driver
// bindee un array literal.
ncmExecute('DELETE FROM "register_lease" WHERE registerid = ?', [$REG_OLD]);
ncmExecute('DELETE FROM "register_lease" WHERE registerid = ?', [$REG_NEW]);
ncmExecute('DELETE FROM "register_lease" WHERE registerid = ?', [$REG_THIRD]);
ncmExecute("DELETE FROM device WHERE devicename LIKE 'Verify Release %'");

/** Crea un device real pareado a la caja dada. */
function verifyReleaseMakeDevice(string $companyId, string $outletId, string $registerId, string $userId, string $name): string
{
    $row = ncmExecute(
        'INSERT INTO device (companyid, outletid, registerid, userid, devicename, module, status)
         VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, \'pos\', 1)
         RETURNING deviceid',
        [$companyId, $outletId, $registerId, $userId, $name]
    );
    return (string) ($row['deviceid'] ?? '');
}

$deviceIdD = verifyReleaseMakeDevice($PY_COMPANY, $PY_OUTLET, $REG_OLD, $PY_USER, 'Verify Release Device D');
$deviceIdE = verifyReleaseMakeDevice($PY_COMPANY, $PY_OUTLET, $REG_OLD, $PY_USER, 'Verify Release Device E');

if ($deviceIdD === '' || $deviceIdE === '') {
    fwrite(STDERR, "[verify_register_release_on_change] Setup: no se pudieron crear los devices de prueba\n");
    exit(1);
}

$tokenD = authSessionCreate('pos-app', [
    'companyId' => $PY_COMPANY, 'userId' => $PY_USER, 'deviceId' => $deviceIdD,
    'outletId' => $PY_OUTLET, 'registerId' => $REG_OLD, 'roleId' => '1',
    'module' => 'pos', 'expiresAt' => null,
]);
$tokenE = authSessionCreate('pos-app', [
    'companyId' => $PY_COMPANY, 'userId' => $PY_USER, 'deviceId' => $deviceIdE,
    'outletId' => $PY_OUTLET, 'registerId' => $REG_OLD, 'roleId' => '1',
    'module' => 'pos', 'expiresAt' => null,
]);

// ── Servidor PHP real (built-in dev server), mismo criterio que
//    verify_register_lease.php — puerto libre asignado por el OS. ─────────
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    fwrite(STDERR, "[verify_register_release_on_change] no se pudo reservar un puerto: {$errstr} ({$errno})\n");
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
    fwrite(STDERR, "[verify_register_release_on_change] no se pudo levantar el servidor PHP built-in\n");
    exit(1);
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

/** GET/POST genérico contra el servidor built-in. Devuelve [statusCode, decoded-body-array]. */
function verifyReleaseHttp(int $port, string $method, string $path, string $bearer, ?string $body = null, string $contentType = 'application/json'): array
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
            'ignore_errors' => true,
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

function verifyReleasePostClaim(int $port, string $bearer): array
{
    return verifyReleaseHttp($port, 'POST', '/v1/register/claim.php', $bearer, '{}');
}

function verifyReleasePostActiveRegister(int $port, string $bearer, string $registerId): array
{
    return verifyReleaseHttp($port, 'POST', '/v1/active-register.php', $bearer, json_encode(['registerId' => $registerId]));
}

/**
 * Estado actual de la tenencia de una caja, directo de BD (para asserts).
 *
 * `ncmRow()` (api/includes/lib/DB.php) y no un `return $row` pelado: TODA fila
 * que sale del DB layer es un `CaseInsensitiveArray`, que se lee como array
 * (ArrayAccess) pero NO satisface el typehint `array` — cruzarlo por un
 * `return` declarado `: ?array` tira TypeError en runtime. Es la conversión
 * canónica del proyecto para el caso "el valor cruza una frontera que exige
 * array de verdad"; ver el docblock de `ncmRow()`.
 */
function verifyReleaseLeaseRow(string $registerId): ?array
{
    $row = ncmExecute(
        'SELECT registerleaseid, deviceid, "status", releasedby
           FROM "register_lease"
          WHERE registerid = ?::uuid
          ORDER BY takenat DESC
          LIMIT 1',
        [$registerId]
    );
    return ($row !== false && $row !== 0) ? ncmRow($row) : null;
}

try {
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

    // ── Caso 1: D toma Caja Vieja ───────────────────────────────────────
    [$status1, $body1] = verifyReleasePostClaim($port, $tokenD);
    if ($status1 !== 200 || !($body1['ok'] ?? false)) {
        $failures[] = 'Caso 1: D esperaba 200 tomando Caja Vieja, llegó ' . $status1 . ' ' . json_encode($body1);
    } else {
        echo "[verify_register_release_on_change] OK caso 1: D toma Caja Vieja (200)\n";
    }

    // ── Caso 2: active-register.php reasigna D a Caja Nueva — la tenencia
    //    vieja se libera SOLA (capa 1 del fix). ─────────────────────────
    [$status2, $body2] = verifyReleasePostActiveRegister($port, $tokenD, $REG_NEW);
    if ($status2 !== 200 || !($body2['ok'] ?? false)) {
        $failures[] = 'Caso 2: active-register.php esperaba 200 reasignando D a Caja Nueva, llegó ' . $status2 . ' ' . json_encode($body2);
    } else {
        $devRow = ncmExecute('SELECT registerid FROM device WHERE deviceid = ?::uuid', [$deviceIdD]);
        $devRegisterId = ($devRow !== false && $devRow !== 0) ? (string) $devRow['registerId'] : '';
        if ($devRegisterId !== $REG_NEW) {
            $failures[] = "Caso 2: device D esperaba registerid={$REG_NEW} tras active-register.php, quedó " . json_encode($devRegisterId);
        } else {
            $oldLease = verifyReleaseLeaseRow($REG_OLD);
            if ($oldLease === null) {
                $failures[] = 'Caso 2: Caja Vieja debía tener una fila register_lease (aunque liberada), no se encontró ninguna';
            } elseif ((string) $oldLease['status'] === 'active') {
                $failures[] = 'Caso 2 — BUG REAL (context/29 §4): la tenencia de Caja Vieja seguía \'active\' después de reasignar el device a Caja Nueva — quedaría bloqueada para siempre';
            } elseif ((string) ($oldLease['releasedby'] ?? '') !== 'device:register-change') {
                $failures[] = "Caso 2: la tenencia de Caja Vieja se liberó, pero releasedby esperaba 'device:register-change', llegó " . json_encode($oldLease['releasedby'] ?? null);
            } else {
                echo "[verify_register_release_on_change] OK caso 2: active-register.php reasigna D a Caja Nueva Y libera SOLA la tenencia vieja (status={$oldLease['status']}, releasedby=device:register-change)\n";
            }
        }
    }

    // ── Caso 3: con Caja Vieja libre, E (device ajeno a D) puede tomarla ──
    [$status3, $body3] = verifyReleasePostClaim($port, $tokenE);
    if ($status3 !== 200 || !($body3['ok'] ?? false)) {
        $failures[] = 'Caso 3 — BUG REAL: E esperaba 200 tomando Caja Vieja (recién liberada), llegó ' . $status3 . ' ' . json_encode($body3) . ' — antes del fix esto quedaba bloqueado para siempre';
    } else {
        echo "[verify_register_release_on_change] OK caso 3: E toma Caja Vieja apenas se liberó (200) — ya no queda colgada\n";
    }
    $leaseIdE = $body3['data']['registerLeaseId'] ?? null;

    // ── Caso 4: D confirma tenencia en Caja Nueva (su contexto ya apunta ahí) ──
    [$status4, $body4] = verifyReleasePostClaim($port, $tokenD);
    if ($status4 !== 200 || !($body4['ok'] ?? false)) {
        $failures[] = 'Caso 4: D esperaba 200 tomando Caja Nueva, llegó ' . $status4 . ' ' . json_encode($body4);
    } else {
        echo "[verify_register_release_on_change] OK caso 4: D toma tenencia nueva en Caja Nueva (200)\n";
    }

    // ── Caso 5 (TRAMPA) — simula un camino que se OLVIDA de liberar: UPDATE
    //    directo de device.registerid (bypass de active-register.php a
    //    propósito) — reproduce el bug real. La defensa en profundidad de
    //    claim.php (capa 2) tiene que autocorregirlo. ────────────────────
    ncmExecute('UPDATE device SET registerid = ?::uuid WHERE deviceid = ?::uuid', [$REG_THIRD, $deviceIdD]);

    [$status5, $body5] = verifyReleasePostClaim($port, $tokenD);
    if ($status5 !== 200 || !($body5['ok'] ?? false)) {
        $failures[] = 'Caso 5: D esperaba 200 tomando Caja Tercera (tras el UPDATE directo), llegó ' . $status5 . ' ' . json_encode($body5);
    } else {
        $newLease = verifyReleaseLeaseRow($REG_NEW);
        if ($newLease === null) {
            $failures[] = 'Caso 5: Caja Nueva debía tener una fila register_lease (aunque liberada), no se encontró ninguna';
        } elseif ((string) $newLease['status'] === 'active') {
            $failures[] = 'Caso 5 — BUG REAL (defensa en profundidad rota): la tenencia colgada de D en Caja Nueva seguía \'active\' después de que D tomó Caja Tercera — claim.php no se autocorrigió';
        } elseif ((string) ($newLease['releasedby'] ?? '') !== 'device:claim-self-correct') {
            $failures[] = "Caso 5: la tenencia colgada de Caja Nueva se cerró, pero releasedby esperaba 'device:claim-self-correct', llegó " . json_encode($newLease['releasedby'] ?? null);
        } else {
            echo "[verify_register_release_on_change] OK caso 5: claim.php se autocorrige — al tomar Caja Tercera, cierra SOLO la tenencia colgada que D mismo tenía en Caja Nueva (releasedby=device:claim-self-correct)\n";
        }
    }

    // ── Caso 6 — invariante de aislamiento: la tenencia de E en Caja Vieja
    //    (caso 3) sigue INTACTA — la autocorrección de D nunca tocó la
    //    tenencia de OTRO device (context/29 §6). ───────────────────────
    $eLease = verifyReleaseLeaseRow($REG_OLD);
    if ($eLease === null || (string) $eLease['status'] !== 'active') {
        $failures[] = 'Caso 6 — BUG REAL (aislamiento roto): la tenencia de E en Caja Vieja debía seguir \'active\', quedó ' . json_encode($eLease);
    } elseif ((string) $eLease['deviceid'] !== $deviceIdE || ($leaseIdE !== null && (string) $eLease['registerleaseid'] !== (string) $leaseIdE)) {
        $failures[] = 'Caso 6 — BUG REAL: la tenencia activa de Caja Vieja ya no es la de E (¿se pisó con la de otro device?), quedó ' . json_encode($eLease);
    } else {
        echo "[verify_register_release_on_change] OK caso 6: la tenencia de E en Caja Vieja quedó INTACTA — la autocorrección de D nunca toca la tenencia de OTRO device\n";
    }
} catch (\RuntimeException $e) {
    $failures[] = 'Setup: ' . $e->getMessage();
} finally {
    proc_terminate($proc);
    proc_close($proc);
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_register_release_on_change] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_register_release_on_change] TODO OK\n";
exit(0);

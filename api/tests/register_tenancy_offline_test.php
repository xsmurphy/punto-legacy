<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Tenencia de caja y venta offline — el incidente del 2026-08-23.
 *
 * El caso real: un cajero vendió sin conexión, imprimió el ticket, el cliente
 * se fue; al volver la red el sync rechazó la venta con "la caja fue liberada,
 * tomada por otro dispositivo, o cerrada". La caja ya estaba tomada por OTRO
 * dispositivo antes de esa venta, y el POS lo dejó vender igual porque offline
 * no verificaba tenencia.
 *
 * Este arnés cubre la mitad SERVIDOR del arreglo, contra Postgres real (nada
 * mockeado): que el rechazo diga POR QUÉ pasó, y que la causa determine si la
 * venta encolada se puede recuperar o no. La mitad DEVICE —el gate que impide
 * empezar la venta sin tenencia confirmada, con o sin red— es lógica pura del
 * front y se verifica en `frontend/lib/pos/__tests__/register-tenancy.test.ts`
 * (`evaluateGrant`), donde se puede probar el vencimiento sin esperar 12 h.
 *
 * Casos:
 *   A. Device SIN tenencia no puede vender: `holderConflict()` devuelve
 *      conflicto y `offline-sync` lo traduce a un rechazo. Antes ésta era la
 *      única defensa y llegaba tarde — ahora es la última, no la única.
 *   B. Device CON tenencia vigente vende: `holderConflict()` devuelve null y
 *      la venta encolada pasa el gate. Regresión del arreglo: prevenir el
 *      caso A no puede romper el camino feliz offline.
 *   C. Tenencia revocada mientras el device estaba offline (el caso
 *      inevitable): la venta se rechaza, PERO con un código recuperable
 *      (`REGISTER_RELEASED`) porque la caja quedó libre — el device la retoma
 *      y la misma venta, con el mismo número, entra. Se verifica que después
 *      del re-claim `holderConflict()` vuelva a dar null.
 *   D. Los tres motivos se distinguen entre sí: `taken_by_other` / `revoked` /
 *      `released` / `never_held` producen códigos y mensajes DISTINTOS, y solo
 *      `taken_by_other` es terminal.
 *
 * Uso (necesita Postgres migrado + seed.sql de verify_chain cargado — ver
 * `run_register_tenancy_offline_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/register_tenancy_offline_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/services/RegisterLeaseService.php';

use Punto\Api\Services\RegisterLeaseService;

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

// Dos dispositivos propios de este arnés — el seed no trae ninguno.
$deviceA = 'aaaaaaaa-0000-4000-8000-000000000001'; // el que "está en la caja"
$deviceB = 'bbbbbbbb-0000-4000-8000-000000000002'; // el que se la lleva

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures): void
{
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

global $db;

// ── Fixtures ─────────────────────────────────────────────────────────────────
// Estado limpio: este arnés se puede correr dos veces seguidas contra la misma
// base sin arrastrar tenencias de la corrida anterior.
ncmExecute('DELETE FROM "register_lease" WHERE registerid = ?', [$registerId]);
ncmExecute('DELETE FROM device WHERE deviceid IN (?::uuid, ?::uuid)', [$deviceA, $deviceB]);

foreach ([[$deviceA, 'Tablet Mostrador'], [$deviceB, 'Tablet Barra']] as [$devId, $devName]) {
    ncmExecute(
        'INSERT INTO device (deviceid, companyid, userid, outletid, registerid, devicename, status)
         VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, 1)',
        [$devId, $companyId, $userId, $outletId, $registerId, $devName]
    );
}

/**
 * Toma la caja para un device, igual que `claim.php` — el INSERT desnudo, sin
 * el advisory lock (este arnés es secuencial, no hay concurrencia que
 * serializar; lo que se prueba es la lectura de tenencia, no el lock).
 */
function takeLease(string $registerId, string $companyId, string $outletId, string $deviceId): string
{
    $row = ncmExecute(
        'INSERT INTO "register_lease" (companyid, outletid, registerid, deviceid, "status")
         VALUES (?, ?, ?, ?, \'active\') RETURNING registerleaseid',
        [$companyId, $outletId, $registerId, $deviceId]
    );
    return (string) $row['registerLeaseId'];
}

echo "=== Tenencia de caja y venta offline (incidente 2026-08-23) ===\n\n";

// ── Caso A — device SIN tenencia no puede vender ─────────────────────────────
echo "-- A. Device sin tenencia --\n";

$conflict = RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA);
check(
    'A1 caja nunca tomada -> conflicto (no puede vender)',
    $conflict !== null,
    'holderConflict() devolvió null: dejaría pasar una venta sin tenencia',
    $failures
);
check(
    'A2 el motivo es never_held, no un genérico',
    ($conflict['reason'] ?? '') === 'never_held',
    'reason=' . var_export($conflict['reason'] ?? null, true),
    $failures
);

[$codeA, $msgA] = RegisterLeaseService::conflictMessage($conflict ?? []);
check(
    'A3 el código es REGISTER_NEVER_HELD',
    $codeA === 'REGISTER_NEVER_HELD',
    "code=$codeA",
    $failures
);

// ── Caso B — device CON tenencia vigente vende y sincroniza ──────────────────
echo "\n-- B. Device con tenencia vigente --\n";

$leaseA = takeLease($registerId, $companyId, $outletId, $deviceA);
check(
    'B1 la tenencia quedó activa',
    $leaseA !== '',
    'no se pudo crear register_lease',
    $failures
);
check(
    'B2 el tenedor NO tiene conflicto (la venta offline entra)',
    RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA) === null,
    'holderConflict() devolvió conflicto para el tenedor real',
    $failures
);

// La otra cara del invariante: el que NO es tenedor sí choca.
$conflictB = RegisterLeaseService::holderConflict($registerId, $companyId, $deviceB);
check(
    'B3 otro device SÍ choca contra la misma caja',
    $conflictB !== null && ($conflictB['reason'] ?? '') === 'taken_by_other',
    'reason=' . var_export($conflictB['reason'] ?? null, true),
    $failures
);
check(
    'B4 el conflicto nombra al tenedor real (para el mensaje al cajero)',
    ($conflictB['holderDeviceId'] ?? null) === $deviceA
        && ($conflictB['holderDeviceName'] ?? '') === 'Tablet Mostrador',
    'holder=' . var_export($conflictB['holderDeviceName'] ?? null, true),
    $failures
);

// ── Caso C — tenencia revocada mientras el device estaba offline ─────────────
// El caso genuinamente inevitable: el device tenía la caja, se fue sin red, y
// un admin se la liberó desde el panel. La venta ya está impresa.
echo "\n-- C. Tenencia revocada estando offline (caso inevitable) --\n";

RegisterLeaseService::close($leaseA, 'forced', 'admin:' . $userId, 'forced');

$conflictC = RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA);
check(
    'C1 la venta encolada se rechaza (no se guarda con tenencia ajena)',
    $conflictC !== null,
    'holderConflict() devolvió null tras la revocación',
    $failures
);
check(
    'C2 el motivo es revoked — la caja quedó LIBRE, no tomada por otro',
    ($conflictC['reason'] ?? '') === 'revoked'
        && ($conflictC['holderDeviceId'] ?? null) === null,
    'reason=' . var_export($conflictC['reason'] ?? null, true),
    $failures
);
check(
    'C3 el rechazo dice que fue un administrador (releasedBy viaja)',
    str_starts_with((string) ($conflictC['releasedBy'] ?? ''), 'admin:'),
    'releasedBy=' . var_export($conflictC['releasedBy'] ?? null, true),
    $failures
);

[$codeC, $msgC] = RegisterLeaseService::conflictMessage($conflictC ?? []);
check(
    'C4 el código es RECUPERABLE (REGISTER_RELEASED), no terminal',
    $codeC === 'REGISTER_RELEASED',
    "code=$codeC — la venta impresa quedaría muerta en la cola",
    $failures
);
check(
    'C5 el mensaje le dice al cajero que la caja está libre y qué hacer',
    str_contains($msgC, 'libre') && str_contains($msgC, 'tomarla'),
    "msg=$msgC",
    $failures
);

// La venta NO se pierde: el device retoma la caja libre y el mismo número entra.
$leaseA2 = takeLease($registerId, $companyId, $outletId, $deviceA);
check(
    'C6 el device puede retomar la caja (estaba libre)',
    $leaseA2 !== '' && $leaseA2 !== $leaseA,
    'no se pudo crear una tenencia nueva tras la revocación',
    $failures
);
check(
    'C7 tras retomarla, la MISMA venta encolada ya no tiene conflicto',
    RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA) === null,
    'holderConflict() sigue rechazando después del re-claim',
    $failures
);

// ── Caso D — los motivos de rechazo se distinguen entre sí ───────────────────
echo "\n-- D. Los motivos de rechazo se distinguen --\n";

// D-taken: el device A cierra normal y el B toma la caja.
RegisterLeaseService::close($leaseA2, 'released', 'device:close', 'released');
$conflictReleased = RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA);
check(
    'D1 cierre normal -> released (distinto de revoked)',
    ($conflictReleased['reason'] ?? '') === 'released',
    'reason=' . var_export($conflictReleased['reason'] ?? null, true),
    $failures
);

$leaseB = takeLease($registerId, $companyId, $outletId, $deviceB);
$conflictTaken = RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA);
check(
    'D2 tomada por otro -> taken_by_other',
    ($conflictTaken['reason'] ?? '') === 'taken_by_other',
    'reason=' . var_export($conflictTaken['reason'] ?? null, true),
    $failures
);

$msgs = [];
$codes = [];
foreach ([
    'never_held' => ['reason' => 'never_held', 'holderDeviceId' => null, 'holderDeviceName' => null],
    'released'   => $conflictReleased,
    'revoked'    => $conflictC,
    'taken'      => $conflictTaken,
] as $label => $c) {
    [$code, $msg] = RegisterLeaseService::conflictMessage($c ?? []);
    $codes[$label] = $code;
    $msgs[$label]  = $msg;
}

check(
    'D3 los cuatro mensajes son distintos entre sí',
    count(array_unique($msgs)) === 4,
    'mensajes repetidos: ' . json_encode($msgs, JSON_UNESCAPED_UNICODE),
    $failures
);
check(
    'D4 solo "tomada por otro" es terminal (REGISTER_TAKEN)',
    $codes['taken'] === 'REGISTER_TAKEN'
        && $codes['released'] === 'REGISTER_RELEASED'
        && $codes['revoked'] === 'REGISTER_RELEASED'
        && $codes['never_held'] === 'REGISTER_NEVER_HELD',
    json_encode($codes),
    $failures
);
check(
    'D5 el mensaje terminal nombra al dispositivo que la tiene',
    str_contains($msgs['taken'], 'Tablet Barra'),
    'msg=' . $msgs['taken'],
    $failures
);
check(
    'D6 ninguno de los cuatro repite el texto viejo de tres causas juntas',
    !str_contains(implode(' ', $msgs), 'liberada, tomada por otro dispositivo, o cerrada'),
    'sigue el mensaje ambiguo original',
    $failures
);

echo "\n--- mensajes por causa ---\n";
foreach ($msgs as $label => $msg) {
    echo "  [$label / {$codes[$label]}] $msg\n";
}

// ── Limpieza ─────────────────────────────────────────────────────────────────
ncmExecute('DELETE FROM "register_lease" WHERE registerid = ?', [$registerId]);
ncmExecute('DELETE FROM device WHERE deviceid IN (?::uuid, ?::uuid)', [$deviceA, $deviceB]);

echo "\n";
harnessFinish($failures, $checks);

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
 *   E. Confirmar NO es tomar (bug del owner, 2026-09-01): `claim()` con
 *      `$acquire = false` no escribe una tenencia nueva aunque la caja esté
 *      libre, y `$acquire = true` tampoco se la quita a otro dispositivo. Es
 *      lo que impide que el latido de 5 min del POS se apropie de toda caja
 *      que quede libre — el motivo por el que un segundo dispositivo con la
 *      misma caja asignada nunca lograba facturar.
 *   G. La liberación del ADMIN es autoritativa (owner, 2026-09-09): tras un
 *      `close(..., 'forced', 'admin:…')`, ESE dispositivo no vuelve a tomar ESA
 *      caja por un camino automático — hace falta que alguien toque "Tomar
 *      caja" en el propio aparato. El veto es contra el DEVICE, no contra la
 *      caja (otro aparato la toma normalmente), se DERIVA de `register_lease`
 *      sin estado nuevo, y no lo dispara un `forced` de los caminos del propio
 *      device.
 *   H. DRENAR ≠ VENDER: un device vetado igual puede SUBIR lo que ya emitió e
 *      imprimió (§53) — solo `taken_by_other` frena una venta encolada. Es lo
 *      que le sacó al drenaje de la cola la necesidad de re-tomar la caja, que
 *      era el único camino automático que adquiría.
 *   F. La otra cara de `register_lease`: un dispositivo que tuvo una caja NO
 *      se puede borrar (es la cadena de auditoría de qué aparato emitió qué),
 *      y uno sin ningún rastro operativo sí. Verifica de paso el SQL de
 *      `DeviceHistoryService` contra el schema real — cuatro tablas cuyo modo
 *      de falla histórico es el casing de identificadores (mig 150).
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

// ── Caso E — confirmar NO es tomar (bug del owner, 2026-09-01) ──────────────
// El reporte: dos dispositivos con la MISMA caja asignada; el primero la
// libera y el segundo sigue sin poder facturar. Causa: `claim.php` hacía
// "confirmá O tomá" en la misma llamada y el POS lo disparaba cada 5 minutos
// tuviera o no la caja, así que el primero se la volvía a llevar solo apenas
// quedaba libre. Quién facturaba lo decidía un timer, no una persona.
//
// Lo que se prueba acá es la mitad SERVIDOR del arreglo:
// `RegisterLeaseService::claim()` con `$acquire = false` no escribe NADA
// cuando la caja está libre. Es el invariante que hace inútil al latido como
// mecanismo de apropiación.
echo "\n-- E. Confirmar no es tomar (acquire) --\n";

/** Filas de tenencia de esta caja, en cualquier estado — el testigo de "no insertó". */
$leaseRowCount = static function (string $registerId): int {
    $row = ncmExecute(
        'SELECT count(*)::int AS n FROM "register_lease" WHERE registerid = ?',
        [$registerId]
    );
    return (int) ($row['n'] ?? 0);
};
/** Tenencias ACTIVAS — como mucho una, por el índice único parcial de mig 141. */
$activeLeaseCount = static function (string $registerId): int {
    $row = ncmExecute(
        'SELECT count(*)::int AS n FROM "register_lease" WHERE registerid = ? AND "status" = \'active\'',
        [$registerId]
    );
    return (int) ($row['n'] ?? 0);
};

// E1 — caja tomada por B: A pregunta y recibe el conflicto, sin escribir.
$rowsBefore = $leaseRowCount($registerId);
$outE1 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceA, RegisterLeaseService::ACQUIRE_NONE);
check(
    'E1 con la caja tomada por otro, claim(acquire=false) rechaza',
    $outE1['registerLeaseId'] === null
        && ($outE1['conflict']['reason'] ?? '') === 'taken_by_other',
    json_encode($outE1, JSON_UNESCAPED_UNICODE),
    $failures
);

// La caja queda LIBRE: B cierra su tenencia como en un cierre de caja normal.
RegisterLeaseService::close($leaseB, 'released', 'device:close', 'released');
check(
    'E2 la caja quedó libre tras el cierre de B',
    $activeLeaseCount($registerId) === 0,
    'todavía hay una tenencia activa',
    $failures
);

// E3 — EL CASO DEL BUG: caja libre + acquire=false ⇒ NO se inserta nada.
$rowsBefore = $leaseRowCount($registerId);
$outE3 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceA, RegisterLeaseService::ACQUIRE_NONE);
check(
    'E3 caja LIBRE + acquire=false -> NO inserta ninguna fila',
    $leaseRowCount($registerId) === $rowsBefore && $activeLeaseCount($registerId) === 0,
    'la caja se tomó sola: filas ' . $rowsBefore . ' -> ' . $leaseRowCount($registerId),
    $failures
);
check(
    'E4 y el rechazo dice que está LIBRE, no que la tiene otro',
    $outE3['registerLeaseId'] === null
        && in_array($outE3['conflict']['reason'] ?? '', ['released', 'revoked', 'never_held'], true)
        && ($outE3['conflict']['holderDeviceId'] ?? null) === null,
    'reason=' . var_export($outE3['conflict']['reason'] ?? null, true),
    $failures
);

// E5 — el acto explícito del cajero sí la toma.
$outE5 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceA, RegisterLeaseService::ACQUIRE_OPERATOR);
check(
    'E5 acquire=true sobre caja libre SÍ la toma',
    $outE5['registerLeaseId'] !== null
        && $outE5['created'] === true
        && $outE5['conflict'] === null
        && $activeLeaseCount($registerId) === 1,
    json_encode($outE5, JSON_UNESCAPED_UNICODE),
    $failures
);

// E6 — el latido del TENEDOR sigue funcionando: confirma sin escribir.
$rowsBefore = $leaseRowCount($registerId);
$outE6 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceA, RegisterLeaseService::ACQUIRE_NONE);
check(
    'E6 el tenedor confirma con acquire=false, misma tenencia y sin filas nuevas',
    $outE6['registerLeaseId'] === $outE5['registerLeaseId']
        && $outE6['created'] === false
        && $leaseRowCount($registerId) === $rowsBefore,
    json_encode($outE6, JSON_UNESCAPED_UNICODE),
    $failures
);

// E7 — `acquire=true` NUNCA le saca la caja a otro (§6, "el último que llega
// pisa al anterior" fue RECHAZADO). El flag habilita tomar lo LIBRE, no
// desalojar.
$outE7 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceB, RegisterLeaseService::ACQUIRE_OPERATOR);
check(
    'E7 acquire=true NO le quita la caja al tenedor actual',
    $outE7['registerLeaseId'] === null
        && ($outE7['conflict']['reason'] ?? '') === 'taken_by_other'
        && RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA) === null,
    json_encode($outE7, JSON_UNESCAPED_UNICODE),
    $failures
);

// ── Caso I — el candado del que ahora depende todo EXISTE ──────────────────
// Al aceptar una venta ya emitida con la caja libre (caso H), lo único que
// impide duplicar un correlativo dentro de una misma caja es el índice único de
// la mig 145. Y ese índice se crea CONDICIONALMENTE: el bloque DO de
// `145_transaction_invoiceno_uniqueness.sql` hace `RAISE WARNING` + `RETURN` sin
// crearlo si encuentra duplicados preexistentes. O sea que puede FALTAR en una
// base concreta sin que nada avise.
//
// Este check lo vuelve explícito: si el índice no está, este arnés falla en vez
// de que el agujero aparezca en producción con un correlativo repetido.
echo "\n-- I. El índice único de mig 145 está creado --\n";

$idx = ncmExecute("SELECT to_regclass('public.uq_transaction_expedition_invoiceno')::text AS idx");
check(
    'I1 uq_transaction_expedition_invoiceno existe (mig 145 lo crea condicionalmente)',
    $idx !== false && $idx !== 0 && ($idx['idx'] ?? null) !== null,
    'el índice NO está: la mig 145 abortó su creación por duplicados preexistentes, '
        . 'y sin él nada impide dos ventas con el mismo invoiceNo en la misma caja',
    $failures
);

// ── Caso G — la liberación del ADMIN es autoritativa (owner, 2026-09-09) ────
// El incidente verificado en producción: el owner liberó una caja desde el
// panel DOS veces y la tablet la volvió a tomar SOLA las dos, porque el drenaje
// de la cola offline adquiría en cada ciclo de sync. Desde el teléfono la caja
// se veía ocupada para siempre — bloqueo mutuo.
//
// La regla, textual: "si yo libero como administrador una caja, un cajero no
// puede pasar por encima de mi acción y retomarla".
//
// Se prueba la mitad SERVIDOR, que es donde tiene que vivir: la tablet puede
// estar offline con un bundle viejo y re-adquirir al reconectar, así que un
// arreglo solo-cliente perdería la decisión remota del owner sin que nadie se
// entere.
echo "\n-- G. El veto del administrador --\n";

// El admin libera la caja que deviceA tomó en E5.
RegisterLeaseService::close($outE5['registerLeaseId'], 'forced', 'admin:' . $userId, 'forced');

$vetoConflict = RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA);
check(
    'G1 el veto se DERIVA de register_lease, sin estado nuevo',
    RegisterLeaseService::isAdminRevoked($vetoConflict),
    'isAdminRevoked=false — conflict=' . json_encode($vetoConflict, JSON_UNESCAPED_UNICODE),
    $failures
);

// G2 — EL BUG: la caja está LIBRE y el device liberado la pide automáticamente.
$rowsBefore = $leaseRowCount($registerId);
$outG2 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceA, RegisterLeaseService::ACQUIRE_AUTO);
check(
    'G2 caja libre + adquisición AUTOMÁTICA del device liberado -> RECHAZADA',
    $outG2['registerLeaseId'] === null
        && ($outG2['conflict']['reason'] ?? '') === 'revoked'
        && $activeLeaseCount($registerId) === 0
        && $leaseRowCount($registerId) === $rowsBefore,
    'la tablet se la volvió a tomar sola: ' . json_encode($outG2, JSON_UNESCAPED_UNICODE),
    $failures
);
check(
    'G3 el rechazo NO miente diciendo que la tiene otro dispositivo',
    ($outG2['conflict']['holderDeviceId'] ?? null) === null,
    'holderDeviceId=' . var_export($outG2['conflict']['holderDeviceId'] ?? null, true),
    $failures
);

// G4 — el veto es contra el DISPOSITIVO, no contra la caja. Es el objetivo
// entero: el teléfono del owner tiene que poder tomarla.
$outG4 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceB, RegisterLeaseService::ACQUIRE_AUTO);
check(
    'G4 OTRO dispositivo SÍ la toma (el veto es del device, no de la caja)',
    $outG4['registerLeaseId'] !== null && $outG4['created'] === true,
    'el veto se pegó a la caja y bloqueó al otro aparato: ' . json_encode($outG4, JSON_UNESCAPED_UNICODE),
    $failures
);

// Vuelve a quedar libre para probar la salida del veto.
RegisterLeaseService::close($outG4['registerLeaseId'], 'released', 'device:close', 'released');

// G5 — el acto humano explícito en el propio aparato SÍ la toma. Es la única
// salida del veto, y es la que la regla del owner pide: alguien tiene que
// decidirlo, no el software.
$outG5 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceA, RegisterLeaseService::ACQUIRE_OPERATOR);
check(
    'G5 "Tomar caja" del cajero (ACQUIRE_OPERATOR) levanta el veto',
    $outG5['registerLeaseId'] !== null
        && $outG5['created'] === true
        && $activeLeaseCount($registerId) === 1,
    'el cajero no puede recuperar su propia caja: ' . json_encode($outG5, JSON_UNESCAPED_UNICODE),
    $failures
);
check(
    'G6 tomada de nuevo, el veto quedó consumido (no es permanente)',
    !RegisterLeaseService::isAdminRevoked(
        RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA)
    ),
    'el veto sobrevivió a la toma explícita — la tablet quedaría vetada para siempre',
    $failures
);

// G7 — solo el ADMIN veta. Un `forced` de los caminos del propio aparato
// (revocar/desparear/cambiar de caja) NO deja al device sin poder reconectar
// solo: ahí no hubo una decisión remota que respetar.
RegisterLeaseService::close($outG5['registerLeaseId'], 'forced', 'device:' . $deviceA, 'forced');
$deviceForced = RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA);
check(
    'G7 un forced NO-admin (releasedBy device:…) no veta',
    ($deviceForced['reason'] ?? '') === 'revoked'
        && !RegisterLeaseService::isAdminRevoked($deviceForced),
    'reason=' . var_export($deviceForced['reason'] ?? null, true)
        . ' releasedBy=' . var_export($deviceForced['releasedBy'] ?? null, true),
    $failures
);
$outG7 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceA, RegisterLeaseService::ACQUIRE_AUTO);
check(
    'G8 y por eso la adquisición automática sigue entrando en ese caso',
    $outG7['registerLeaseId'] !== null,
    json_encode($outG7, JSON_UNESCAPED_UNICODE),
    $failures
);

// G9 — el contrato de `$acquire` es cerrado. Un valor que no está en el enum
// no puede caer en "tomá igual" por un cast silencioso: es exactamente el modo
// de falla del `(bool) $_POST['acquire']` que este cambio también cerró (el
// string "false" castea a true en PHP).
$rejectedBadIntent = false;
try {
    RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceB, 'si');
} catch (\InvalidArgumentException $e) {
    $rejectedBadIntent = true;
}
check(
    'G9 un $acquire fuera del enum es un error, no una adquisición',
    $rejectedBadIntent,
    'claim() aceptó un intent inválido',
    $failures
);

// ── Caso H — DRENAR ≠ VENDER: qué frena una venta YA EMITIDA ────────────────
// `offline-sync.php` dejó de exigir tenencia activa para subir una venta que el
// cajero ya emitió e imprimió: solo la frena `taken_by_other`. Sin ese cambio,
// el drenaje tenía que RE-TOMAR la caja, y ese era el único camino automático
// que adquiría — la puerta por la que entró el bug del owner.
//
// Acá se prueba el PREDICADO que usa el endpoint (el endpoint mismo necesita
// HTTP). Lo que protege el correlativo no es este chequeo sino
// `uq_transaction_expedition_invoiceno` (mig 145), que sigue intacto.
echo "\n-- H. Drenar no es vender --\n";

$drainBlocks = static fn (?array $c): bool => $c !== null && ($c['reason'] ?? '') === 'taken_by_other';

// H1 — device vetado por el admin, caja libre: la venta impresa SUBE.
RegisterLeaseService::close($outG7['registerLeaseId'], 'forced', 'admin:' . $userId, 'forced');
$hVeto = RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA);
check(
    'H1 un device VETADO igual puede subir lo que ya emitió (§53)',
    RegisterLeaseService::isAdminRevoked($hVeto) && !$drainBlocks($hVeto),
    'la venta impresa quedaría trabada: ' . json_encode($hVeto, JSON_UNESCAPED_UNICODE),
    $failures
);

// H2 — pero NO puede tomar la caja, o sea que no puede emitir NADA nuevo.
$outH2 = RegisterLeaseService::claim($registerId, $companyId, $outletId, $deviceA, RegisterLeaseService::ACQUIRE_AUTO);
check(
    'H2 y aun así NO puede tomar la caja (subir sí, vender no)',
    $outH2['registerLeaseId'] === null && $activeLeaseCount($registerId) === 0,
    json_encode($outH2, JSON_UNESCAPED_UNICODE),
    $failures
);

// H3 — con OTRO device emitiendo contra la misma rama de numeración, sí frena.
$leaseH = takeLease($registerId, $companyId, $outletId, $deviceB);
check(
    'H3 con la caja tomada por OTRO, la venta encolada SÍ se frena',
    $drainBlocks(RegisterLeaseService::holderConflict($registerId, $companyId, $deviceA)),
    'se aceptaría un correlativo contra una caja que otro está usando',
    $failures
);
RegisterLeaseService::close($leaseH, 'released', 'device:close', 'released');

// ── Caso F — el rastro de tenencia impide borrar el dispositivo ─────────────
// La otra cara de `register_lease`: además de garantizar un solo tenedor, es
// la cadena de auditoría de qué aparato tenía qué caja al emitir cada
// comprobante. Por eso su FK a `device` no lleva ON DELETE, y por eso
// "Eliminar dispositivo" reventaba con un 23503 crudo (reporte del owner,
// 2026-09-01).
//
// Se prueba ACÁ y no en un arnés aparte porque los fixtures son los mismos y
// el invariante es el mismo: lo que hace irreemplazable a `register_lease`.
// Contra Postgres REAL, que es lo único que puede atrapar un error de casing
// de identificadores — el modo de falla histórico de este schema (mig 150).
echo "\n-- F. El historial de caja protege al dispositivo del borrado --\n";

require_once dirname(__DIR__) . '/lib/services/DeviceHistoryService.php';

$kindsA = \Punto\Api\Services\DeviceHistoryService::kindsFor($deviceA, $companyId);
check(
    'F1 un device que tuvo la caja figura con historial de tenencia',
    in_array('register_lease', $kindsA, true),
    'kinds=' . json_encode($kindsA),
    $failures
);

// Device sin ningún rastro: nunca tomó caja, nunca abrió sesión, nunca tocó
// una orden ni registró una impresora. Ese SÍ se puede borrar.
$deviceC = 'cccccccc-0000-4000-8000-000000000003';
ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$deviceC]);
ncmExecute(
    'INSERT INTO device (deviceid, companyid, userid, outletid, devicename, status)
     VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, 0)',
    [$deviceC, $companyId, $userId, $outletId, 'KDS Cocina']
);
$kindsC = \Punto\Api\Services\DeviceHistoryService::kindsFor($deviceC, $companyId);
check(
    'F2 un device sin rastro operativo NO tiene historial (se puede borrar)',
    $kindsC === [],
    'kinds=' . json_encode($kindsC),
    $failures
);
check(
    'F3 el DELETE de ese device pasa (la barrera es el historial, no el tipo)',
    ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid', [$deviceC, $companyId]) !== false,
    'no se pudo borrar un device sin historial',
    $failures
);

// F5 — las expresiones de `selectSql()` no viven solas: se EMBEBEN en el
// listado de `GET /v1/devices`, que ya tiene sus propios `register_lease rl` y
// `auth_session s` unidos. Este check reproduce esa forma —el mismo LEFT JOIN
// que el endpoint— para que un alias sombreado o un identificador mal citado
// falle acá y no en producción, que es donde falla siempre esta clase de bug
// (mig 150).
$rsList = ncmExecute(
    'SELECT d.deviceid, '
    . \Punto\Api\Services\DeviceHistoryService::selectSql('d') . '
       FROM device d
       LEFT JOIN register_lease rl ON rl.registerid = d.registerid
                                  AND rl.companyid  = d.companyid
                                  AND rl.status     = \'active\'
      WHERE d.companyid = ?::uuid AND d.deviceid = ?::uuid',
    [$companyId, $deviceA],
    false,
    true
);
$listRow = ($rsList !== false && $rsList !== 0 && !$rsList->EOF) ? $rsList->fields : null;
check(
    'F5 selectSql() funciona embebido en el listado (mismo JOIN que el endpoint)',
    $listRow !== null
        && in_array('register_lease', \Punto\Api\Services\DeviceHistoryService::kindsFromRow($listRow), true),
    $listRow === null ? 'la query del listado no devolvió la fila' : json_encode($listRow),
    $failures
);

check(
    'F4 el mensaje del 409 nombra en castellano qué historial tiene',
    str_contains(
        \Punto\Api\Services\DeviceHistoryService::describe(['register_lease', 'auth_session']),
        'tenencia de cajas'
    ),
    \Punto\Api\Services\DeviceHistoryService::describe(['register_lease', 'auth_session']),
    $failures
);

// ── Limpieza ─────────────────────────────────────────────────────────────────
ncmExecute('DELETE FROM "register_lease" WHERE registerid = ?', [$registerId]);
ncmExecute('DELETE FROM device WHERE deviceid IN (?::uuid, ?::uuid)', [$deviceA, $deviceB]);

echo "\n";
harnessFinish($failures, $checks);

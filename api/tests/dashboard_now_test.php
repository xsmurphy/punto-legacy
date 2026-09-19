<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (Postgres real) de los widgets nuevos del dashboard:
 * `now` ("Ahora", `NowService`), `salesByOutlet` y la comparativa `previous`
 * de `incomeOutcomeStats`.
 *
 * La regla del owner que protege: una fila aparece SOLO si el comercio usa esa
 * capacidad y hay algo que mostrar — nunca un cero —, cada una con el módulo
 * y/o el permiso de la pantalla a la que linkea, acotada por sucursal y por
 * empresa.
 *
 *   (A) Comercio sin nada → cero filas.
 *   (B) Órdenes: módulo apagado = sin fila; activas vs cerradas; demoradas en
 *       cocina a partir de 20 min desde el envío; agendadas para otro día no
 *       cuentan; agendadas para hoy cuentan la demora desde la hora pactada;
 *       por sucursal.
 *   (C) Espacios: libres/ocupados/pidieron la cuenta; deshabilitados y
 *       decorativos afuera; por sucursal.
 *   (D) Cajas abiertas: la cerrada no; operador, caja y hora; por sucursal.
 *   (E) Personal presente: última marcación de HOY = entrada; salida marcada
 *       o entrada de ayer no; marcación sin sucursal en cualquier alcance.
 *   (F) Agenda: citas que quedan hoy; terminadas, canceladas y de mañana no;
 *       módulo + permiso.
 *   (G) Vencimientos: cheques emitidos y compras a pagar de hoy a 7 días,
 *       vencidos aparte; recibidos/cobrados/lejanos no; permiso por parte.
 *   (H) Permisos, fail-closed y aislamiento de fallas.
 *   (I) Tenant: nada de la empresa vecina aparece.
 *   (J) Período anterior: `Date::previousRange()` y `previous` de los KPIs.
 *   (K) Ventas por sucursal: filas, porcentaje y período anterior.
 *
 * Uso: bash api/tests/run_dashboard_now_test.sh
 */

$companyId = 'da70e000-0000-4000-8000-000000000001';
$companyB  = 'da70e000-0000-4000-8000-000000000002';
$outlet1   = 'da70e000-0000-4000-8000-000000000011';
$outlet2   = 'da70e000-0000-4000-8000-000000000012';
$outletB   = 'da70e000-0000-4000-8000-000000000013';
$register1 = 'da70e000-0000-4000-8000-000000000021';
$register2 = 'da70e000-0000-4000-8000-000000000022';
$registerB = 'da70e000-0000-4000-8000-000000000023';
$userId    = 'da70e000-0000-4000-8000-000000000031';
$userB     = 'da70e000-0000-4000-8000-000000000032';
$people    = [];
for ($i = 1; $i <= 6; $i++) {
    $people[$i] = sprintf('da70e000-0000-4000-8000-0000000001%02d', $i);
}
$supplier  = 'da70e000-0000-4000-8000-000000000099';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outlet1);
define('USER_ID',    $userId);

require_once dirname(__DIR__) . '/bootstrap.php';
defined('TODAY') || define('TODAY', date('Y-m-d H:i:s'));
defined('TODAY_DATE') || define('TODAY_DATE', date('Y-m-d'));

use Punto\Api\Reports\DashboardService;
use Punto\Api\Reports\NowService;
use Punto\Api\Reports\Roc;
use Punto\Api\Support\TenantClock;
use Punto\App\Helpers\Date;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function uuid(): string
{
    $b    = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** @var array<string,bool> Módulos prendidos, por empresa. */
$modules = [];

/** Filas del widget indexadas por clave. */
function tilesOf(string $companyId, array $outletIds, ?callable $can = null): array
{
    global $modules;
    $can ??= static fn (string $p): bool => true;
    $out = [];
    $w = (new NowService())->tiles(
        $companyId,
        $outletIds,
        $can,
        static fn (string $m): bool => !empty($modules[$companyId][$m])
    );
    foreach ($w['tiles'] as $t) {
        $out[$t['key']] = $t;
    }
    return $out;
}

// ── Fixture base ────────────────────────────────────────────────────────────
foreach ([[$companyId, 'Ahora A'], [$companyB, 'Ahora B']] as [$cid, $name]) {
    ncmExecute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)",
        [$cid, json_encode(['settingName' => $name])]
    );
}
foreach ([[$outlet1, 'Centro', $companyId], [$outlet2, 'Norte', $companyId], [$outletB, 'B Uno', $companyB]] as [$oid, $name, $cid]) {
    ncmExecute('INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)', [$oid, $name, $cid]);
}
foreach ([[$register1, 'Caja 1', $outlet1, $companyId], [$register2, 'Caja 2', $outlet2, $companyId],
          [$registerB, 'Caja B', $outletB, $companyB]] as [$rid, $rname, $oid, $cid]) {
    ncmExecute(
        'INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId) VALUES (?, ?, TRUE, ?, ?)',
        [$rid, $rname, $oid, $cid]
    );
}
$contacts = [[$userId, 'Ana Dueña', $companyId, $outlet1], [$userB, 'Vecino', $companyB, $outletB],
             [$supplier, 'Proveedor', $companyId, $outlet1]];
foreach ($people as $i => $pid) {
    $contacts[] = [$pid, "Persona $i", $companyId, $outlet1];
}
foreach ($contacts as [$cid, $name, $comp, $oid]) {
    ncmExecute(
        'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus) VALUES (?, ?, ?, ?, 0, 1)',
        [$cid, $name, $comp, $oid]
    );
}

TenantClock::apply($companyId);
$today = substr(TenantClock::now($companyId), 0, 10);
$day   = static fn (int $off): string => (new DateTimeImmutable($today))->modify(($off >= 0 ? '+' : '-') . abs($off) . ' days')->format('Y-m-d');

// ═══ (A) Comercio sin nada ══════════════════════════════════════════════════
echo "\n=== (A) comercio sin nada: ninguna fila ===\n";
$modules[$companyId] = ['ordersPanel' => true, 'tables' => true, 'calendar' => true];
check('(A1) con módulos prendidos pero sin datos → sin filas', tilesOf($companyId, []) === [], json_encode(tilesOf($companyId, [])), $failures, $checks);

// ═══ (B) Órdenes ════════════════════════════════════════════════════════════
echo "\n=== (B) órdenes ===\n";
$order = static function (string $outlet, string $status, string $sentAgo, ?string $scheduled = null) use ($companyId): void {
    ncmExecute(
        "INSERT INTO pos_order (companyid, outletid, status, sent_at, scheduled_for)
         VALUES (?, ?, ?, now() - ?::interval, " . ($scheduled === null ? 'NULL' : $scheduled) . ")",
        [$companyId, $outlet, $status, $sentAgo]
    );
};
$order($outlet1, 'sent', '30 minutes');                  // demorada
$order($outlet1, 'in_progress', '5 minutes');            // en cocina, a tiempo
$order($outlet1, 'ready', '60 minutes');                 // lista: no es demora de cocina
$order($outlet1, 'closed', '90 minutes');                // cerrada: no es activa
$order($outlet1, 'sent', '2 hours', "date_trunc('day', now()) + interval '1 day' + interval '12 hours'"); // otro día
$order($outlet1, 'sent', '2 hours', 'now()');            // agendada para ahora: cuenta, no demorada
$order($outlet2, 'sent', '45 minutes');                  // demorada en la otra sucursal
$r = tilesOf($companyId, []);
check('(B1) activas: 5 (la cerrada y la de otro día no)', ($r['orders']['active'] ?? null) === 5, json_encode($r['orders'] ?? null), $failures, $checks);
check('(B2) demoradas: 2 (la de 30 min y la de 45 min)', ($r['orders']['late'] ?? null) === 2, json_encode($r['orders'] ?? null), $failures, $checks);
check('(B3) umbral y destino', ($r['orders']['lateMinutes'] ?? null) === 20 && ($r['orders']['href'] ?? '') === '/pos/ordenes', '', $failures, $checks);
$r1 = tilesOf($companyId, [$outlet1]);
check('(B4) sucursal 1: 4 activas, 1 demorada', ($r1['orders']['active'] ?? null) === 4 && ($r1['orders']['late'] ?? null) === 1, json_encode($r1['orders'] ?? null), $failures, $checks);
$modules[$companyId]['ordersPanel'] = false;
check('(B5) módulo apagado → sin fila', !isset(tilesOf($companyId, [])['orders']), '', $failures, $checks);
$modules[$companyId]['ordersPanel'] = true;

// ═══ (C) Espacios ═══════════════════════════════════════════════════════════
echo "\n=== (C) espacios ===\n";
$sector = static function (string $outlet) use ($companyId): string {
    $id = uuid();
    ncmExecute('INSERT INTO space_sector (sectorid, companyid, outletid, name) VALUES (?, ?, ?, ?)', [$id, $companyId, $outlet, 'Salón']);
    return $id;
};
$s1 = $sector($outlet1);
$s2 = $sector($outlet2);
$space = static function (string $outlet, string $sectorId, string $name, string $shape = 'square', int $status = 1, ?string $session = null) use ($companyId): void {
    $id = uuid();
    ncmExecute(
        'INSERT INTO space (tableid, companyid, outletid, sectorid, name, shape, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$id, $companyId, $outlet, $sectorId, $name, $shape, $status]
    );
    if ($session !== null) {
        ncmExecute(
            'INSERT INTO space_session (companyid, outletid, tableid, status) VALUES (?, ?, ?, ?)',
            [$companyId, $outlet, $id, $session]
        );
    }
};
$space($outlet1, $s1, 'M1');
$space($outlet1, $s1, 'M2', 'square', 1, 'open');
$space($outlet1, $s1, 'M3', 'round', 1, 'bill_requested');
$space($outlet1, $s1, 'M4', 'square', 0);            // deshabilitada
$space($outlet1, $s1, 'Pared', 'decor_wall');        // decorativo
$space($outlet1, $s1, 'M5', 'square', 1, 'closed');  // sesión cerrada = libre
$space($outlet2, $s2, 'N1');
$r = tilesOf($companyId, []);
check('(C1) 5 espacios: 3 libres, 1 ocupado, 1 pidió la cuenta', ($r['spaces']['total'] ?? null) === 5
    && $r['spaces']['free'] === 3 && $r['spaces']['occupied'] === 1 && $r['spaces']['billRequested'] === 1,
    json_encode($r['spaces'] ?? null), $failures, $checks);
$r2 = tilesOf($companyId, [$outlet2]);
check('(C2) sucursal 2: 1 libre', ($r2['spaces']['total'] ?? null) === 1 && $r2['spaces']['free'] === 1, json_encode($r2['spaces'] ?? null), $failures, $checks);
$modules[$companyId]['tables'] = false;
check('(C3) módulo apagado → sin fila', !isset(tilesOf($companyId, [])['spaces']), '', $failures, $checks);
$modules[$companyId]['tables'] = true;

// ═══ (D) Cajas abiertas ═════════════════════════════════════════════════════
echo "\n=== (D) cajas abiertas ===\n";
$drawer = static function (string $register, string $outlet, int $uid, bool $closed, string $cid) use ($userId): void {
    ncmExecute(
        'INSERT INTO drawer (drawerOpenDate, drawerCloseDate, drawerOpenAmount, drawerUID, drawerUserOpen, registerId, outletId, companyId)
         VALUES (now() - interval \'3 hours\', ' . ($closed ? 'now()' : 'NULL') . ', 0, ?, ?, ?, ?, ?)',
        [$uid, $userId, $register, $outlet, $cid]
    );
};
$drawer($register1, $outlet1, 1, false, $companyId);
$drawer($register1, $outlet1, 2, true, $companyId);
$drawer($register2, $outlet2, 3, false, $companyId);
$r = tilesOf($companyId, []);
check('(D1) 2 abiertas (la cerrada no)', ($r['drawers']['count'] ?? null) === 2 && count($r['drawers']['rows']) === 2, json_encode($r['drawers'] ?? null), $failures, $checks);
$row = $r['drawers']['rows'][0] ?? [];
check('(D2) fila con caja, sucursal, operador y hora', ($row['registerName'] ?? '') !== '' && ($row['operator'] ?? '') === 'Ana Dueña'
    && ($row['outletName'] ?? '') !== '' && ($row['openedAt'] ?? '') !== '', json_encode($row), $failures, $checks);
check('(D3) sucursal 2 → solo la suya', (tilesOf($companyId, [$outlet2])['drawers']['rows'][0]['registerName'] ?? '') === 'Caja 2', '', $failures, $checks);
check('(D4) sin reports.drawers.view → sin fila', !isset(tilesOf($companyId, [], static fn (string $p): bool => $p !== 'reports.drawers.view')['drawers']), '', $failures, $checks);

// ═══ (E) Personal presente ══════════════════════════════════════════════════
echo "\n=== (E) personal presente ===\n";
$emp = new \Punto\Api\Hr\EmployeeService();
foreach ([1, 2, 3, 4, 5] as $i) {
    $emp->create($companyId, ['contactId' => $people[$i], 'hireDate' => '2026-01-01', 'outletId' => $outlet1], $userId);
}
$mark = static function (string $who, string $kind, string $when, ?string $outlet) use ($companyId): void {
    ncmExecute(
        "INSERT INTO attendance_mark (companyid, contactid, outletid, kind, markedat, method, needsreview, opid)
         VALUES (?, ?, ?, ?, $when, 'pin', FALSE, ?)",
        [$companyId, $who, $outlet, $kind, 'dn-' . bin2hex(random_bytes(6))]
    );
};
$todayStart = "date_trunc('day', now())";
$mark($people[1], 'in',  "GREATEST(now() - interval '2 hours', $todayStart)", $outlet1);              // presente
$mark($people[2], 'in',  "GREATEST(now() - interval '2 hours', $todayStart)", $outlet1);
$mark($people[2], 'out', "GREATEST(now() - interval '1 hour', $todayStart + interval '1 second')", $outlet1); // se fue
$mark($people[3], 'in',  "$todayStart - interval '3 hours'", $outlet1);                              // ayer, sin salida
$mark($people[4], 'in',  "GREATEST(now() - interval '1 hour', $todayStart)", $outlet2);               // presente en la 2
$mark($people[5], 'in',  "GREATEST(now() - interval '1 hour', $todayStart)", null);                   // sin sucursal
$r = tilesOf($companyId, []);
check('(E1) presentes: 3 (el que salió y el de ayer no)', ($r['staff']['count'] ?? null) === 3, json_encode($r['staff'] ?? null), $failures, $checks);
$names = array_column($r['staff']['people'] ?? [], 'name');
check('(E2) con nombre y hora', in_array('Persona 1', $names, true) && !in_array('Persona 2', $names, true)
    && ($r['staff']['people'][0]['since'] ?? '') !== '', json_encode($names), $failures, $checks);
check('(E3) sucursal 1 → la suya + la sin sucursal = 2', (tilesOf($companyId, [$outlet1])['staff']['count'] ?? null) === 2, '', $failures, $checks);
check('(E4) sin hr.attendance.view → sin fila', !isset(tilesOf($companyId, [], static fn (string $p): bool => $p !== 'hr.attendance.view')['staff']), '', $failures, $checks);

// ═══ (F) Agenda ═════════════════════════════════════════════════════════════
echo "\n=== (F) agenda de hoy ===\n";
$apt = static function (string $outlet, int $status, string $from, string $to, ?string $customer) use ($companyId, $register1, $userId): void {
    ncmExecute(
        "INSERT INTO transaction (transactionId, companyId, outletId, registerId, userId, customerId, transactionType,
                                  transactionStatus, transactionComplete, transactionTotal, transactionDiscount,
                                  transactionDate, fromDate, toDate)
         VALUES (?, ?, ?, ?, ?, ?, 13, ?, FALSE, 0, 0, now() - interval '10 days', $from, $to)",
        [uuid(), $companyId, $outlet, $register1, $userId, $customer, $status]
    );
};
$apt($outlet1, 1, "now() - interval '5 minutes'", "now() + interval '5 minutes'", $people[6]);       // en curso
$apt($outlet1, 0, "$todayStart", "now() + interval '1 hour'", null);                                   // hoy
$apt($outlet2, 3, "now() - interval '1 minute'", "now() + interval '30 minutes'", null);              // hoy, otra sucursal
$apt($outlet1, 1, "$todayStart", "now() - interval '1 second'", null);                                 // ya terminó
$apt($outlet1, 4, "now() - interval '5 minutes'", "now() + interval '5 minutes'", null);              // cancelada
$apt($outlet1, 7, "now() - interval '5 minutes'", "now() + interval '5 minutes'", null);              // bloqueo
$apt($outlet1, 0, "$todayStart + interval '1 day' + interval '1 hour'", "$todayStart + interval '1 day' + interval '2 hours'", null); // mañana
$r = tilesOf($companyId, []);
check('(F1) quedan 3 citas hoy', ($r['agenda']['count'] ?? null) === 3 && count($r['agenda']['next']) === 3, json_encode($r['agenda'] ?? null), $failures, $checks);
check('(F2) la primera es la que arrancó a las 00:00, con cliente en la de ahora',
    in_array('Persona 6', array_column($r['agenda']['next'] ?? [], 'customer'), true), json_encode($r['agenda']['next'] ?? null), $failures, $checks);
check('(F3) sucursal 1 → 2', (tilesOf($companyId, [$outlet1])['agenda']['count'] ?? null) === 2, '', $failures, $checks);
check('(F4) sin reports.schedule.view → sin fila', !isset(tilesOf($companyId, [], static fn (string $p): bool => $p !== 'reports.schedule.view')['agenda']), '', $failures, $checks);
$modules[$companyId]['calendar'] = false;
check('(F5) módulo apagado → sin fila', !isset(tilesOf($companyId, [])['agenda']), '', $failures, $checks);
$modules[$companyId]['calendar'] = true;

// ═══ (G) Vencimientos ═══════════════════════════════════════════════════════
echo "\n=== (G) vencimientos de la semana ===\n";
check('(G0) sin cheques ni compras → sin fila', !isset(tilesOf($companyId, [])['dues']), '', $failures, $checks);
$check = static function (string $direction, string $status, string $due, float $amount) use ($companyId): void {
    ncmExecute(
        'INSERT INTO fin_check (companyid, direction, amount, duedate, status) VALUES (?, ?, ?, ?::date, ?)',
        [$companyId, $direction, $amount, $due, $status]
    );
};
$check('issued', 'pending', $day(3), 100);
$check('issued', 'deposited', $day(0), 50);
$check('issued', 'pending', $day(-2), 70);     // vencido sin cambiar de estado
$check('issued', 'pending', $day(10), 999);    // fuera de la semana
$check('issued', 'cleared', $day(1), 999);     // ya debitado
$check('received', 'pending', $day(2), 999);   // es un ingreso
$purchase = static function (string $outlet, string $due, float $total, bool $complete = false) use ($companyId, $register1, $userId, $supplier): void {
    ncmExecute(
        "INSERT INTO transaction (transactionId, companyId, outletId, registerId, userId, supplierId, transactionType,
                                  transactionStatus, transactionComplete, transactionTotal, transactionDiscount,
                                  transactionDate, transactionDueDate)
         VALUES (?, ?, ?, ?, ?, ?, 4, 1, ?, ?, 0, now() - interval '40 days', ?::timestamp)",
        [uuid(), $companyId, $outlet, $register1, $userId, $supplier, $complete, $total, $due . ' 00:00:00']
    );
};
$purchase($outlet1, $day(5), 300);
$purchase($outlet1, $day(20), 999);            // fuera de la semana
$purchase($outlet1, $day(-3), 400);            // vencida
$purchase($outlet1, $day(2), 999, true);       // saldada
$purchase($outlet2, $day(1), 200);
$r = tilesOf($companyId, []);
$d = $r['dues'] ?? [];
check('(G1) cheques: 2 en la semana por 150, 1 vencido, próximo hoy',
    ($d['checks']['count'] ?? null) === 2 && abs(($d['checks']['amount'] ?? 0) - 150) < 0.01
    && ($d['checks']['overdue'] ?? null) === 1 && ($d['checks']['next'] ?? '') === $today
    && ($d['checks']['href'] ?? '') === '/finanzas/cheques', json_encode($d['checks'] ?? null), $failures, $checks);
check('(G2) compras: 2 en la semana por 500, 1 vencida',
    ($d['payables']['count'] ?? null) === 2 && abs(($d['payables']['amount'] ?? 0) - 500) < 0.01
    && ($d['payables']['overdue'] ?? null) === 1 && ($d['payables']['next'] ?? '') === $day(1),
    json_encode($d['payables'] ?? null), $failures, $checks);
$d1 = tilesOf($companyId, [$outlet1])['dues'] ?? [];
check('(G3) sucursal 1: compras 1 (cheques son de la empresa: iguales)', ($d1['payables']['count'] ?? null) === 1
    && ($d1['checks']['count'] ?? null) === 2, json_encode($d1), $failures, $checks);
$d = tilesOf($companyId, [], static fn (string $p): bool => $p !== 'finance.manage')['dues'] ?? [];
check('(G4) sin finance.manage → sin cheques, compras sí', array_key_exists('checks', $d) && $d['checks'] === null && ($d['payables']['count'] ?? null) === 2, json_encode($d), $failures, $checks);
check('(G5) sin ninguna de las dos claves → sin fila',
    !isset(tilesOf($companyId, [], static fn (string $p): bool => !in_array($p, ['finance.manage', 'reports.purchases.view'], true))['dues']), '', $failures, $checks);

// ═══ (H) Permisos, fail-closed y fallas ═════════════════════════════════════
echo "\n=== (H) permisos y fallas ===\n";
$r = tilesOf($companyId, []);
check('(H1) orden de pantalla', array_keys($r) === ['orders', 'spaces', 'drawers', 'staff', 'agenda', 'dues'], json_encode(array_keys($r)), $failures, $checks);
$r = tilesOf($companyId, [], static fn (): bool => false);
check('(H2) sin ningún permiso → solo las del POS sin clave (órdenes y espacios)', array_keys($r) === ['orders', 'spaces'], json_encode(array_keys($r)), $failures, $checks);
$svc = new NowService([
    'orders' => static function (): array { throw new \RuntimeException('simulada'); },
    'spaces' => static fn (): array => ['total' => 2, 'free' => 2, 'occupied' => 0, 'billRequested' => 0],
]);
$w = $svc->tiles($companyId, [], static fn (): bool => true, static fn (): bool => true)['tiles'];
check('(H3) la fila que falla se omite y la otra sale', count($w) === 1 && $w[0]['key'] === 'spaces', json_encode($w), $failures, $checks);
$w = (new DashboardService())->widget('now', [], '', $companyId, [], $userId);
check('(H4) widget sin $can → sin filas de clave (fail-closed)', array_diff(array_column($w['tiles'], 'key'), ['orders', 'spaces']) === [], json_encode($w), $failures, $checks);

// ═══ (I) Tenant ═════════════════════════════════════════════════════════════
echo "\n=== (I) aislamiento de tenant ===\n";
$modules[$companyB] = ['ordersPanel' => true, 'tables' => true, 'calendar' => true];
check('(I1) B no ve nada de A', tilesOf($companyB, []) === [], json_encode(tilesOf($companyB, [])), $failures, $checks);
ncmExecute("INSERT INTO pos_order (companyid, outletid, status, sent_at) VALUES (?, ?, 'sent', now() - interval '50 minutes')", [$companyB, $outletB]);
check('(I2) lo de B no suma en A', (tilesOf($companyId, [])['orders']['active'] ?? null) === 5, '', $failures, $checks);

// ═══ (J) Período anterior ═══════════════════════════════════════════════════
echo "\n=== (J) período anterior ===\n";
check('(J1) días enteros: 1 al 19 → 13 al 31 del mes anterior',
    Date::previousRange('2026-09-01 00:00:00', '2026-09-19 23:59:59.999999') === ['2026-08-13 00:00:00', '2026-08-31 ' . Date::END_OF_DAY],
    json_encode(Date::previousRange('2026-09-01 00:00:00', '2026-09-19 23:59:59.999999')), $failures, $checks);
check('(J2) un día → el día anterior entero',
    Date::previousRange('2026-03-01 00:00:00', '2026-03-01 23:59:59') === ['2026-02-28 00:00:00', '2026-02-28 ' . Date::END_OF_DAY], '', $failures, $checks);
check('(J3) franja con horas → mismo largo pegado antes',
    Date::previousRange('2026-09-19 10:00:00', '2026-09-19 14:00:00') === ['2026-09-19 05:59:59', '2026-09-19 09:59:59'],
    json_encode(Date::previousRange('2026-09-19 10:00:00', '2026-09-19 14:00:00')), $failures, $checks);

$sale = static function (string $outlet, float $total, string $date) use ($companyId, $register1, $userId): void {
    ncmExecute(
        "INSERT INTO transaction (transactionId, companyId, outletId, registerId, userId, transactionType,
                                  transactionStatus, transactionComplete, transactionTotal, transactionDiscount, transactionDate)
         VALUES (?, ?, ?, ?, ?, 0, 1, TRUE, ?, 0, ?::timestamp)",
        [uuid(), $companyId, $outlet, $register1, $userId, $total, $date]
    );
};
$roc  = Roc::build($companyId);
$opts = ['from' => "$today 00:00:00", 'to' => "$today " . Date::END_OF_DAY];
$st = (new DashboardService())->widget('incomeOutcomeStats', $opts, $roc, $companyId, [], $userId);
check('(J4) sin ventas en el anterior (ni egresos) → previous null', array_key_exists('previous', $st) && $st['previous'] === null, json_encode($st), $failures, $checks);
$sale($outlet1, 1000, "$today 00:00:01");
$sale($outlet2, 500, "$today 00:00:02");
$sale($outlet1, 800, $day(-1) . ' 12:00:00');
$sale($outlet1, 700, $day(-2) . ' 12:00:00');   // fuera del período anterior
$st = (new DashboardService())->widget('incomeOutcomeStats', $opts, $roc, $companyId, [], $userId);
check('(J5) actual 1500 en 2 ventas; anterior 800 en 1', abs($st['total'] - 1500) < 0.01 && $st['count'] === 2
    && abs(($st['previous']['total'] ?? 0) - 800) < 0.01 && ($st['previous']['count'] ?? null) === 1, json_encode($st), $failures, $checks);

// ═══ (K) Ventas por sucursal ════════════════════════════════════════════════
echo "\n=== (K) ventas por sucursal ===\n";
$so = (new DashboardService())->widget('salesByOutlet', $opts, $roc, $companyId, [], $userId)['rows'];
check('(K1) dos sucursales, ordenadas por total', count($so) === 2 && $so[0]['name'] === 'Centro' && abs($so[0]['total'] - 1000) < 0.01
    && $so[1]['name'] === 'Norte', json_encode($so), $failures, $checks);
check('(K2) porcentaje del total', abs($so[0]['share'] - 66.7) < 0.05 && abs($so[1]['share'] - 33.3) < 0.05, json_encode($so), $failures, $checks);
check('(K3) anterior: Centro 800, Norte sin base (null)', abs(($so[0]['previous'] ?? 0) - 800) < 0.01 && $so[1]['previous'] === null, json_encode($so), $failures, $checks);
$so1 = (new DashboardService())->widget('salesByOutlet', $opts, Roc::build($companyId, $outlet1), $companyId, [$outlet1], $userId)['rows'];
check('(K4) alcance de una sucursal → una fila', count($so1) === 1 && $so1[0]['outletId'] === $outlet1, json_encode($so1), $failures, $checks);
$empty = (new DashboardService())->widget('salesByOutlet', ['from' => $day(-40) . ' 00:00:00', 'to' => $day(-39) . ' 23:59:59'], $roc, $companyId, [], $userId);
check('(K5) sin ventas → sin filas', $empty === ['rows' => []], json_encode($empty), $failures, $checks);

harnessFinish($failures, $checks);

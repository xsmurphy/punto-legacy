<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (Postgres real) del widget `goal` del dashboard
 * ("Objetivo semanal", `WeeklyGoalService`).
 *
 * "Ahora" fijo: jueves 2026-09-17 15:00 (semana en curso desde el lunes 14).
 * La ventana son las 12 semanas completas anteriores (lunes 2026-06-22 al
 * domingo 2026-09-13).
 *
 *   (A) Menos de 4 semanas completas con ventas → null (una venta vieja,
 *       fuera de la ventana, no cuenta).
 *   (B) Mejor semana: total neto de descuento, sin anuladas ni egresos;
 *       rango lunes–domingo; semanas con ventas.
 *   (C) Misma altura: lo que llevaba la mejor semana al jueves 15:00 (la venta
 *       del jueves 16:00 queda afuera).
 *   (D) Semana en curso: hasta ahora (una venta posterior a "ahora" no cuenta).
 *   (E) Alcance por sucursal: cada sucursal tiene su propia mejor semana y su
 *       propio mínimo de semanas.
 *   (F) Tenant: las ventas de la empresa vecina no suman.
 *   (G) El widget del dashboard devuelve `{goal}`.
 *
 * Uso: bash api/tests/run_dashboard_goal_test.sh
 */

$companyId = 'da70e000-0000-4000-8000-0000000a0001';
$companyB  = 'da70e000-0000-4000-8000-0000000a0002';
$outlet1   = 'da70e000-0000-4000-8000-0000000a0011';
$outlet2   = 'da70e000-0000-4000-8000-0000000a0012';
$outletB   = 'da70e000-0000-4000-8000-0000000a0013';
$register1 = 'da70e000-0000-4000-8000-0000000a0021';
$registerB = 'da70e000-0000-4000-8000-0000000a0023';
$userId    = 'da70e000-0000-4000-8000-0000000a0031';
$userB     = 'da70e000-0000-4000-8000-0000000a0032';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outlet1);
define('USER_ID',    $userId);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Reports\DashboardService;
use Punto\Api\Reports\Roc;
use Punto\Api\Reports\WeeklyGoalService;
use Punto\Api\Support\TenantClock;

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

function near(float $a, float $b): bool
{
    return abs($a - $b) < 0.01;
}

// ── Fixture base ────────────────────────────────────────────────────────────
foreach ([[$companyId, 'Objetivo A'], [$companyB, 'Objetivo B']] as [$cid, $name]) {
    ncmExecute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)",
        [$cid, json_encode(['settingName' => $name])]
    );
}
foreach ([[$outlet1, 'Centro', $companyId], [$outlet2, 'Norte', $companyId], [$outletB, 'B Uno', $companyB]] as [$oid, $name, $cid]) {
    ncmExecute('INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)', [$oid, $name, $cid]);
}
foreach ([[$register1, 'Caja 1', $outlet1, $companyId], [$registerB, 'Caja B', $outletB, $companyB]] as [$rid, $rname, $oid, $cid]) {
    ncmExecute(
        'INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId) VALUES (?, ?, TRUE, ?, ?)',
        [$rid, $rname, $oid, $cid]
    );
}
foreach ([[$userId, 'Ana', $companyId, $outlet1], [$userB, 'Vecino', $companyB, $outletB]] as [$cid, $name, $comp, $oid]) {
    ncmExecute(
        'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus) VALUES (?, ?, ?, ?, 0, 1)',
        [$cid, $name, $comp, $oid]
    );
}

TenantClock::apply($companyId);

/** Una transacción. `$type` 0 = venta, 1 = egreso. */
$tx = static function (
    string $company, string $outlet, float $total, string $date,
    float $discount = 0, int $type = 0, bool $voided = false
) use ($register1, $registerB, $userId, $userB, $companyB): void {
    $isB = $company === $companyB;
    ncmExecute(
        "INSERT INTO transaction (transactionId, companyId, outletId, registerId, userId, transactionType,
                                  transactionStatus, transactionComplete, transactionTotal, transactionDiscount,
                                  transactionDate, voidedat)
         VALUES (?, ?, ?, ?, ?, ?, 1, TRUE, ?, ?, ?::timestamp, " . ($voided ? "now()" : "NULL") . ")",
        [uuid(), $company, $outlet, $isB ? $registerB : $register1, $isB ? $userB : $userId, $type, $total, $discount, $date]
    );
};
$sale = static fn (string $outlet, float $total, string $date, float $discount = 0) => $tx($companyId, $outlet, $total, $date, $discount);

$NOW  = '2026-09-17 15:00:00';   // jueves
$svc  = new WeeklyGoalService();
$rocA = Roc::build($companyId);
$goal = static fn (string $roc): ?array => $svc->goal($roc, $NOW);

// ═══ (A) Historia insuficiente ══════════════════════════════════════════════
echo "\n=== (A) menos de 4 semanas con ventas → null ===\n";
check('(A1) sin ventas → null', $goal($rocA) === null, json_encode($goal($rocA)), $failures, $checks);
$sale($outlet1, 99999, '2026-06-16 12:00:00');   // semana del 15/06: fuera de la ventana
$sale($outlet1, 1000, '2026-07-21 12:00:00');
$sale($outlet1, 1000, '2026-07-28 12:00:00');
$sale($outlet1, 1000, '2026-08-04 12:00:00');
check('(A2) 3 semanas en la ventana (+1 vieja afuera) → null', $goal($rocA) === null, json_encode($goal($rocA)), $failures, $checks);

// ═══ (B) Mejor semana ═══════════════════════════════════════════════════════
echo "\n=== (B) mejor semana ===\n";
// Semana del lunes 10/08: 1000 (1100 - 100 de descuento) + 500 + 700 + 2000 = 4200.
$sale($outlet1, 1100, '2026-08-10 10:00:00', 100);
$sale($outlet1, 500,  '2026-08-13 14:00:00');    // jueves antes de las 15
$sale($outlet1, 700,  '2026-08-13 16:00:00');    // jueves después de las 15
$sale($outlet1, 2000, '2026-08-15 12:00:00');    // sábado
$tx($companyId, $outlet1, 50000, '2026-08-12 12:00:00', 0, 0, true);   // anulada
$tx($companyId, $outlet1, 300,   '2026-08-11 12:00:00', 0, 1);         // egreso
$g = $goal($rocA);
check('(B1) 4 semanas → hay objetivo', $g !== null, json_encode($g), $failures, $checks);
check('(B2) mejor semana = lunes 10/08 a domingo 16/08', ($g['best']['weekStart'] ?? null) === '2026-08-10'
    && ($g['best']['weekEnd'] ?? null) === '2026-08-16', json_encode($g), $failures, $checks);
check('(B3) total neto de descuento, sin anulada ni egreso = 4200', near((float) ($g['best']['total'] ?? 0), 4200), json_encode($g), $failures, $checks);
check('(B4) semanas con ventas = 4', ($g['weeksWithSales'] ?? null) === 4, json_encode($g), $failures, $checks);
check('(B5) semana en curso = lunes 14/09', ($g['weekStart'] ?? null) === '2026-09-14', json_encode($g), $failures, $checks);

// ═══ (C) Misma altura ═══════════════════════════════════════════════════════
echo "\n=== (C) la mejor semana a la misma altura ===\n";
check('(C1) al jueves 15:00 llevaba 1500 (lunes + jueves 14:00)', near((float) ($g['best']['atSamePoint'] ?? -1), 1500), json_encode($g), $failures, $checks);
$g2 = $svc->goal($rocA, '2026-09-14 09:00:00');   // lunes temprano
check('(C2) lunes 09:00 → la mejor semana todavía no había vendido', near((float) ($g2['best']['atSamePoint'] ?? -1), 0), json_encode($g2), $failures, $checks);
$g3 = $svc->goal($rocA, '2026-09-20 23:59:59');   // domingo al cierre
check('(C3) domingo 23:59:59 → misma altura = semana entera', near((float) ($g3['best']['atSamePoint'] ?? -1), 4200), json_encode($g3), $failures, $checks);

// ═══ (D) Semana en curso ════════════════════════════════════════════════════
echo "\n=== (D) semana en curso ===\n";
check('(D1) sin ventas esta semana → current 0', near((float) ($g['current'] ?? -1), 0), json_encode($g), $failures, $checks);
$sale($outlet1, 800,  '2026-09-14 10:00:00');
$sale($outlet1, 900,  '2026-09-16 12:00:00');
$sale($outlet1, 5000, '2026-09-17 18:00:00');   // después de "ahora"
$g = $goal($rocA);
check('(D2) current = 1700 (la de las 18:00 no)', near((float) ($g['current'] ?? -1), 1700), json_encode($g), $failures, $checks);
check('(D3) la semana en curso no compite por la mejor', ($g['best']['weekStart'] ?? null) === '2026-08-10' && ($g['weeksWithSales'] ?? null) === 4, json_encode($g), $failures, $checks);

// ═══ (E) Alcance por sucursal ═══════════════════════════════════════════════
echo "\n=== (E) alcance por sucursal ===\n";
$sale($outlet2, 3000, '2026-08-25 12:00:00');
$sale($outlet2, 300,  '2026-09-01 12:00:00');
$sale($outlet2, 300,  '2026-09-08 12:00:00');
$g = $goal($rocA);
check('(E1) empresa: la mejor sigue siendo 10/08 (4200 > 3000), 7 semanas', ($g['best']['weekStart'] ?? null) === '2026-08-10'
    && ($g['weeksWithSales'] ?? null) === 7, json_encode($g), $failures, $checks);
$roc2 = Roc::build($companyId, $outlet2);
check('(E2) sucursal Norte con 3 semanas → null', $goal($roc2) === null, json_encode($goal($roc2)), $failures, $checks);
$sale($outlet2, 300, '2026-07-14 12:00:00');
$g = $goal($roc2);
check('(E3) Norte con 4 semanas → su mejor semana es la del 24/08 con 3000', ($g['best']['weekStart'] ?? null) === '2026-08-24'
    && near((float) ($g['best']['total'] ?? 0), 3000) && ($g['weeksWithSales'] ?? null) === 4 && near((float) ($g['current'] ?? -1), 0),
    json_encode($g), $failures, $checks);
$g = $goal(Roc::scoped($companyId, [$outlet1]));
check('(E4) alcance [Centro] → 10/08, 4 semanas, current 1700', ($g['best']['weekStart'] ?? null) === '2026-08-10'
    && ($g['weeksWithSales'] ?? null) === 4 && near((float) ($g['current'] ?? -1), 1700), json_encode($g), $failures, $checks);
$g = $goal(Roc::scoped($companyId, [$outlet1, $outlet2]));
check('(E5) alcance [Centro, Norte] = empresa (8 semanas)', ($g['weeksWithSales'] ?? null) === 8, json_encode($g), $failures, $checks);

// ═══ (F) Tenant ═════════════════════════════════════════════════════════════
echo "\n=== (F) aislamiento de tenant ===\n";
$tx($companyB, $outletB, 900000, '2026-09-02 12:00:00');
$g = $goal($rocA);
check('(F1) la venta de B no cambia la mejor semana de A', ($g['best']['weekStart'] ?? null) === '2026-08-10'
    && near((float) ($g['best']['total'] ?? 0), 4200), json_encode($g), $failures, $checks);
check('(F2) B con una sola semana → null', $goal(Roc::build($companyB)) === null, '', $failures, $checks);

// ═══ (G) Widget ═════════════════════════════════════════════════════════════
echo "\n=== (G) widget del dashboard ===\n";
$w = (new DashboardService())->widget('goal', [], $rocA, $companyId, [], $userId);
check('(G1) el widget devuelve {goal}', array_keys($w) === ['goal'], json_encode($w), $failures, $checks);

harnessFinish($failures, $checks);

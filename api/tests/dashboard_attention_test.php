<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (Postgres real) del widget `attention` del dashboard
 * ("Requiere atención", `AttentionService`).
 *
 * La regla del owner que protege: una fila aparece SOLO si hay algo que
 * resolver, y un comercio que no usa una capacidad (FE, stock, margen, crédito,
 * asistencia) no ve esa fila — ni en cero.
 *
 *   (A) Comercio SIN nada (sin FE, sin stock, sin objetivo de margen, sin
 *       crédito, sin marcaciones) → cero filas.
 *   (B) Stock: agotado + bajo mínimo cuentan; en rango no; sin movimientos no
 *       (nunca se trabajó ahí); por sucursal.
 *   (C) Margen: sin objetivo no hay fila; con objetivo cuentan los de stock
 *       propio Y los de receta; un servicio sin receta (costo 0) no; por sucursal.
 *   (D) FE: sin cuenta no hay fila aunque haya documentos; rechazado activo y
 *       error con reintentos agotados cuentan; error que todavía reintenta,
 *       rechazado ya reemplazado y aprobado no; por sucursal (vía la venta).
 *   (E) Deuda vencida: clientes + monto, lo por vencer no cuenta; por sucursal.
 *   (F) Marcaciones: solo las sin revisar; las sin sucursal entran en cualquier
 *       alcance.
 *   (G) Permiso por fila: sin la clave, la fila no existe.
 *   (H) Tenant: nada de la empresa vecina aparece.
 *   (I) Una fila que falla se omite y las demás salen; el widget sin `$can`
 *       no devuelve nada (fail-closed).
 *
 * Uso: bash api/tests/run_dashboard_attention_test.sh
 */

$companyId = 'da7e0000-0000-4000-8000-000000000001';
$companyB  = 'da7e0000-0000-4000-8000-000000000002';
$outlet1   = 'da7e0000-0000-4000-8000-000000000011';
$outlet2   = 'da7e0000-0000-4000-8000-000000000012';
$outletB   = 'da7e0000-0000-4000-8000-000000000013';
$register1 = 'da7e0000-0000-4000-8000-000000000021';
$registerB = 'da7e0000-0000-4000-8000-000000000023';
$userId    = 'da7e0000-0000-4000-8000-000000000031';
$userB     = 'da7e0000-0000-4000-8000-000000000032';
$cli1      = 'da7e0000-0000-4000-8000-000000000041';
$cli2      = 'da7e0000-0000-4000-8000-000000000042';
$cliB      = 'da7e0000-0000-4000-8000-000000000043';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outlet1);
define('USER_ID',    $userId);

require_once dirname(__DIR__) . '/bootstrap.php';
// `data.php` define estas constantes leyendo la empresa, que acá todavía no
// existe; el arnés crea sus propias empresas, así que define solo las que usa
// el camino del ledger (`manageStock` → `updateRowLastUpdate`).
defined('TODAY') || define('TODAY', date('Y-m-d H:i:s'));
defined('TODAY_DATE') || define('TODAY_DATE', date('Y-m-d'));

use Punto\Api\Reports\AttentionService;
use Punto\Api\Reports\DashboardService;
use Punto\Api\Support\TenantClock;
use Punto\App\Domain\Inventory;

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

/** Filas del widget indexadas por clave. */
function rowsOf(string $companyId, array $outletIds, ?callable $can = null): array
{
    $can ??= static fn (string $p): bool => true;
    $out = [];
    foreach ((new AttentionService())->rows($companyId, $outletIds, $can)['rows'] as $r) {
        $out[$r['key']] = $r;
    }
    return $out;
}

function item(string $companyId, string $id, string $name, float $price, bool $tracked, ?float $min, array $outlets, string $type = 'product'): void
{
    ncmExecute(
        "INSERT INTO item (itemid, itemname, itemsku, itemprice, itemcost, itemtype, itemstatus,
                           itemcansale, itemtrackinventory, itemproduction, itemminstock, data, companyid)
         VALUES (?, ?, ?, ?, 0, ?, 1, TRUE, ?, FALSE, ?, '{}'::jsonb, ?)",
        [$id, $name, 'DA-' . substr($id, 0, 8), $price, $type, $tracked, $min, $companyId]
    );
    foreach ($outlets as $o) {
        ncmExecute(
            'INSERT INTO item_outlet (itemid, outletid, companyid) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
            [$id, $o, $companyId]
        );
    }
}

function move(string $companyId, string $itemId, string $outletId, float $count, float $cogs, string $type = '+'): void
{
    global $userId;
    Inventory::manageStock([
        'itemId' => $itemId, 'outletId' => $outletId, 'cogs' => $cogs, 'count' => $count,
        'type' => $type, 'source' => 'adjustment', 'userId' => $userId, 'companyId' => $companyId,
        'date' => date('Y-m-d H:i:s'),
    ]);
}

function sale(string $companyId, string $outletId, string $registerId, string $userId, int $type, ?string $customer,
              float $total, ?string $dueDate): string
{
    $id = uuid();
    ncmExecute(
        "INSERT INTO transaction
           (transactionId, companyId, outletId, registerId, userId, customerId, transactionType,
            transactionStatus, transactionComplete, transactionTotal, transactionDiscount,
            transactionDate, transactionDueDate)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 0, NOW(), ?::timestamp)",
        [$id, $companyId, $outletId, $registerId, $userId, $customer, $type, $type !== 3, $total, $dueDate]
    );
    return $id;
}

function fedoc(string $companyId, string $txId, string $status, ?string $sifen, int $attempts, ?string $supersededBy = null, string $doctype = 'FC'): string
{
    $id = uuid();
    ncmExecute(
        'INSERT INTO einvoice_document (einvoicedocid, companyid, transactionid, doctype, status, sifen_status, attempts, superseded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $companyId, $txId, $doctype, $status, $sifen, $attempts, $supersededBy]
    );
    return $id;
}

function setTarget(string $companyId, ?string $target): void
{
    $obj = $target === null ? [] : ['marginTarget' => $target];
    ncmExecute(
        "UPDATE company SET config = jsonb_set(COALESCE(config, '{}'::jsonb), '{settingObj}', to_jsonb(?::text)) WHERE companyId = ?",
        [json_encode($obj), $companyId]
    );
}

// ── Fixture base: dos empresas, A con dos sucursales ────────────────────────
foreach ([[$companyId, 'Atencion A'], [$companyB, 'Atencion B']] as [$cid, $name]) {
    ncmExecute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)",
        [$cid, json_encode(['settingName' => $name])]
    );
}
foreach ([[$outlet1, 'A Uno', $companyId], [$outlet2, 'A Dos', $companyId], [$outletB, 'B Uno', $companyB]] as [$oid, $name, $cid]) {
    ncmExecute('INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)', [$oid, $name, $cid]);
}
foreach ([[$register1, $outlet1, $companyId], [$registerB, $outletB, $companyB]] as [$rid, $oid, $cid]) {
    ncmExecute(
        'INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId) VALUES (?, ?, TRUE, ?, ?)',
        [$rid, 'Caja', $oid, $cid]
    );
}
foreach ([[$userId, $companyId, $outlet1], [$cli1, $companyId, $outlet1], [$cli2, $companyId, $outlet2],
          [$userB, $companyB, $outletB], [$cliB, $companyB, $outletB]] as [$cid, $comp, $oid]) {
    ncmExecute(
        'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus) VALUES (?, ?, ?, ?, 0, 1)',
        [$cid, 'Contacto ' . substr($cid, -2), $comp, $oid]
    );
}

$today = new DateTimeImmutable(substr(TenantClock::now($companyId), 0, 10), new DateTimeZone('UTC'));
$day   = static fn (int $off): string => $today->modify(($off >= 0 ? '+' : '-') . abs($off) . ' days')->format('Y-m-d') . ' 00:00:00';

// ═══ (A) Comercio sin nada ══════════════════════════════════════════════════
echo "\n=== (A) comercio sin capacidades: ninguna fila ===\n";
// Un artículo con control de stock que nunca se movió (catálogo de ejemplo).
item($companyId, uuid(), 'Nunca movido', 0, true, null, [$outlet1]);
$r = rowsOf($companyId, []);
check('(A1) sin FE/stock/margen/crédito/marcaciones → sin filas', $r === [], json_encode($r), $failures, $checks);

// ═══ (B) Stock ══════════════════════════════════════════════════════════════
echo "\n=== (B) stock ===\n";
$SA = uuid(); item($companyId, $SA, 'Bajo minimo', 0, true, 5, [$outlet1]);
$SB = uuid(); item($companyId, $SB, 'Agotado', 0, true, null, [$outlet1]);
$SC = uuid(); item($companyId, $SC, 'En rango', 0, true, 1, [$outlet2]);
$SN = uuid(); item($companyId, $SN, 'Sin control', 0, false, 3, [$outlet1]);
move($companyId, $SA, $outlet1, 3, 100);
move($companyId, $SB, $outlet1, 2, 100);
move($companyId, $SB, $outlet1, 2, 100, '-');
move($companyId, $SC, $outlet2, 10, 100);

$r = rowsOf($companyId, []);
check('(B1) agotado + bajo mínimo = 2 (en rango, sin movimientos y sin control no)',
    ($r['stock']['count'] ?? null) === 2, json_encode($r['stock'] ?? null), $failures, $checks);
check('(B2) la fila linkea a Artículos', ($r['stock']['href'] ?? '') === '/items', json_encode($r['stock'] ?? null), $failures, $checks);
check('(B3) alcance sucursal 1 → 2', (rowsOf($companyId, [$outlet1])['stock']['count'] ?? null) === 2, '', $failures, $checks);
check('(B4) alcance sucursal 2 (todo en rango) → sin fila', !isset(rowsOf($companyId, [$outlet2])['stock']), '', $failures, $checks);

// ═══ (C) Margen ═════════════════════════════════════════════════════════════
echo "\n=== (C) margen objetivo ===\n";
$ME = uuid(); item($companyId, $ME, 'Margen bajo (stock)', 10000, true, null, [$outlet1]);
$MG = uuid(); item($companyId, $MG, 'Margen sano', 20000, true, null, [$outlet1]);
$MF = uuid(); item($companyId, $MF, 'Plato por receta', 10000, false, null, [$outlet1]);
$MS = uuid(); item($companyId, $MS, 'Servicio sin receta', 5000, false, null, [$outlet1]);
move($companyId, $ME, $outlet1, 100, 8000);
move($companyId, $MG, $outlet1, 10, 8000);
ncmExecute('INSERT INTO item_compound (parentItemId, childItemId, quantity, sort, companyId) VALUES (?, ?, 1, 0, ?)', [$MF, $ME, $companyId]);

check('(C1) sin objetivo → sin fila de margen', !isset(rowsOf($companyId, [])['margin']), '', $failures, $checks);
setTarget($companyId, '30');
$r = rowsOf($companyId, []);
check('(C2) objetivo 30%: stock propio (20%) + receta (20%) = 2; sano y servicio no',
    ($r['margin']['count'] ?? null) === 2, json_encode($r['margin'] ?? null), $failures, $checks);
check('(C3) alcance sucursal 2 (no están dados de alta ahí) → sin fila', !isset(rowsOf($companyId, [$outlet2])['margin']), '', $failures, $checks);
check('(C4) stock sigue en 2 (el artículo con margen bajo está en rango)', ($r['stock']['count'] ?? null) === 2, '', $failures, $checks);

// ═══ (D) FE ═════════════════════════════════════════════════════════════════
echo "\n=== (D) facturas electrónicas ===\n";
$t1 = sale($companyId, $outlet1, $register1, $userId, 0, null, 1000, null);
$t2 = sale($companyId, $outlet1, $register1, $userId, 0, null, 1000, null);
$t3 = sale($companyId, $outlet1, $register1, $userId, 0, null, 1000, null);
$t4 = sale($companyId, $outlet1, $register1, $userId, 0, null, 1000, null);
$t5 = sale($companyId, $outlet2, $register1, $userId, 0, null, 1000, null);
fedoc($companyId, $t1, 'issued', 'Rechazado', 1);                  // cuenta
fedoc($companyId, $t2, 'error', null, 8);                           // cuenta: reintentos agotados
fedoc($companyId, $t3, 'error', null, 2);                           // no: se reintenta solo
$nuevo = fedoc($companyId, $t4, 'issued', 'Aprobado', 1);           // no: aprobado
fedoc($companyId, $t4, 'issued', 'Rechazado', 1, $nuevo, 'FCR');    // no: ya reemplazado
fedoc($companyId, $t5, 'issued', 'FinalizadoERROR', 1);             // cuenta (sucursal 2)

check('(D1) sin cuenta de FE → sin fila aunque haya documentos', !isset(rowsOf($companyId, [])['einvoice']), '', $failures, $checks);
ncmExecute(
    "INSERT INTO einvoice_account (companyid, provider, username, password_enc, status, environment, config)
     VALUES (?, 'fepy', 'arnes', 'no-usada', 'ok', 'test', '{}'::jsonb)",
    [$companyId]
);
$r = rowsOf($companyId, []);
check('(D2) rechazado + error agotado + rechazado sucursal 2 = 3', ($r['einvoice']['count'] ?? null) === 3, json_encode($r['einvoice'] ?? null), $failures, $checks);
check('(D3) alcance sucursal 1 → 2', (rowsOf($companyId, [$outlet1])['einvoice']['count'] ?? null) === 2, '', $failures, $checks);
check('(D4) alcance sucursal 2 → 1', (rowsOf($companyId, [$outlet2])['einvoice']['count'] ?? null) === 1, '', $failures, $checks);
check('(D5) linkea a Ajustes › FE', ($r['einvoice']['href'] ?? '') === '/settings/facturacion-electronica', '', $failures, $checks);

// ═══ (E) Deuda vencida ══════════════════════════════════════════════════════
echo "\n=== (E) deuda de clientes vencida ===\n";
sale($companyId, $outlet1, $register1, $userId, 3, $cli1, 100000, $day(-10));
sale($companyId, $outlet2, $register1, $userId, 3, $cli2, 50000, $day(-1));
sale($companyId, $outlet1, $register1, $userId, 3, $cli1, 70000, $day(5));   // por vencer
$r = rowsOf($companyId, []);
check('(E1) 2 clientes, 150.000 vencido (lo por vencer no suma)',
    ($r['receivables']['count'] ?? null) === 2 && abs(($r['receivables']['amount'] ?? 0) - 150000) < 0.01,
    json_encode($r['receivables'] ?? null), $failures, $checks);
$r1 = rowsOf($companyId, [$outlet1]);
check('(E2) alcance sucursal 1 → 1 cliente, 100.000',
    ($r1['receivables']['count'] ?? null) === 1 && abs(($r1['receivables']['amount'] ?? 0) - 100000) < 0.01,
    json_encode($r1['receivables'] ?? null), $failures, $checks);

// ═══ (F) Marcaciones ════════════════════════════════════════════════════════
echo "\n=== (F) marcaciones para revisar ===\n";
check('(F0) sin marcaciones → sin fila', !isset(rowsOf($companyId, [])['attendance']), '', $failures, $checks);
$emp = (new \Punto\Api\Hr\EmployeeService())->create($companyId, [
    'contactId' => $cli1, 'hireDate' => '2026-01-01', 'outletId' => $outlet1,
], $userId);
$mark = static function (?string $outlet, bool $review) use ($companyId, $emp): void {
    ncmExecute(
        "INSERT INTO attendance_mark (companyid, contactid, outletid, kind, markedat, method, needsreview, opid)
         VALUES (?, ?, ?, 'in', now() - interval '20 days', 'pin', ?, ?)",
        [$companyId, $emp['id'], $outlet, $review, 'da-' . bin2hex(random_bytes(6))]
    );
};
$mark($outlet1, true);
$mark($outlet2, true);
$mark(null, true);
$mark($outlet1, false);
$r = rowsOf($companyId, []);
check('(F1) 3 sin revisar (la revisada no), aunque sean viejas', ($r['attendance']['count'] ?? null) === 3, json_encode($r['attendance'] ?? null), $failures, $checks);
check('(F2) alcance sucursal 1 → la suya + la sin sucursal = 2', (rowsOf($companyId, [$outlet1])['attendance']['count'] ?? null) === 2, '', $failures, $checks);
check('(F3) linkea a Asistencia filtrada', ($r['attendance']['href'] ?? '') === '/reports/attendance?review=1', '', $failures, $checks);
check('(F4) orden de pantalla', array_keys($r) === ['einvoice', 'stock', 'margin', 'receivables', 'attendance'], json_encode(array_keys($r)), $failures, $checks);

// ═══ (G) Permiso por fila ═══════════════════════════════════════════════════
echo "\n=== (G) permiso por fila ===\n";
$asked = [];
$r = rowsOf($companyId, [], static function (string $p) use (&$asked): bool {
    $asked[] = $p;
    return $p !== 'reports.sales.view';
});
check('(G1) sin reports.sales.view no hay fila de deudas', !isset($r['receivables']) && count($r) === 4, json_encode(array_keys($r)), $failures, $checks);
check('(G2) sin ningún permiso → sin filas', rowsOf($companyId, [], static fn (): bool => false) === [], '', $failures, $checks);

// ═══ (H) Tenant ═════════════════════════════════════════════════════════════
echo "\n=== (H) aislamiento de tenant ===\n";
$SB2 = uuid(); item($companyB, $SB2, 'Agotado vecino', 0, true, null, [$outletB]);
move($companyB, $SB2, $outletB, 1, 10);
move($companyB, $SB2, $outletB, 1, 10, '-');
sale($companyB, $outletB, $registerB, $userB, 3, $cliB, 999000, $day(-30));
$r = rowsOf($companyId, []);
check('(H1) lo de la vecina no suma en A', ($r['stock']['count'] ?? null) === 2
    && abs(($r['receivables']['amount'] ?? 0) - 150000) < 0.01, json_encode($r), $failures, $checks);
$rb = rowsOf($companyB, []);
check('(H2) B ve solo lo suyo: stock 1, deuda 999.000, sin FE/margen/marcaciones',
    array_keys($rb) === ['stock', 'receivables'] && $rb['stock']['count'] === 1
    && abs($rb['receivables']['amount'] - 999000) < 0.01, json_encode($rb), $failures, $checks);

// ═══ (I) Fallas ═════════════════════════════════════════════════════════════
echo "\n=== (I) aislamiento de fallas ===\n";
$svc = new AttentionService([
    'einvoice' => static function (): array { throw new \RuntimeException('simulada'); },
    'stock'    => static fn (): array => ['count' => 4],
]);
$r = $svc->rows($companyId, [], static fn (): bool => true)['rows'];
check('(I1) la fila que falla se omite y la otra sale', count($r) === 1 && $r[0]['key'] === 'stock' && $r[0]['count'] === 4, json_encode($r), $failures, $checks);
$w = (new DashboardService())->widget('attention', [], '', $companyId, [], $userId);
check('(I2) widget sin $can → sin filas (fail-closed)', $w === ['rows' => []], json_encode($w), $failures, $checks);
$w = (new DashboardService())->widget('attention', [], '', $companyId, [], $userId, static fn (): bool => true);
check('(I3) widget con $can → las 5 filas', count($w['rows']) === 5, json_encode($w), $failures, $checks);

harnessFinish($failures, $checks);

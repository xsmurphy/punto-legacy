<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) del semáforo de cuadre del arqueo
 * (mig 164 + `Reports\CashCountStatus` + `Reports\DrawersService`).
 *
 * Qué protege:
 *
 *   (a) EL ESPERADO SE CONGELA. `Services\DrawerService::close()` escribe
 *       `drawerExpectedAmount` con el MISMO número que la caja le mostró al
 *       cajero (`composeSummary()['subtotal']`), y ese número no se mueve
 *       cuando después entran más ventas al mismo register.
 *   (b) SOLO EFECTIVO. Un turno cobrado con tarjeta no genera un faltante: el
 *       esperado cuenta billetes, no el total del turno. Es el bug concreto
 *       que el reporte tenía (sumaba `sold` de todos los medios de pago).
 *   (c) LOS TRES ESTADOS. Cuadra exacto → ok, contado de menos → short,
 *       contado de más → over.
 *   (d) TOLERANCIA. Una diferencia dentro del margen del comercio cuadra; una
 *       de un guaraní cuadra siempre (piso de redondeo) aunque la tolerancia
 *       configurada sea 0.
 *   (e) CIERRES HISTÓRICOS. Una caja cerrada ANTES de la migración
 *       (`drawerExpectedAmount IS NULL`) no rompe el reporte y sale marcada
 *       `expectedSource='estimated'` — nunca como un veredicto que hubiera
 *       quedado registrado.
 *   (f) CORRECCIÓN. `correct()` re-congela el esperado con el monto de
 *       apertura corregido.
 *
 * Fixture propio (no depende de verify_chain/seed.sql): tenant, sucursal, caja
 * y usuario con UUIDs fijos de este arnés. Las transacciones se insertan en un
 * día FIJO Y VIEJO (2019-05-14) para no cruzarse con lo que otros arneses
 * escriben hoy.
 *
 * Uso (necesita Postgres migrado — ver `run_drawer_cash_count_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/drawer_cash_count_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

$companyIdConst  = 'd1b7c3e4-5a26-4f18-9c30-2b8a6f7e1101';
$outletIdConst   = 'd1b7c3e4-5a26-4f18-9c30-2b8a6f7e1102';
$registerIdConst = 'd1b7c3e4-5a26-4f18-9c30-2b8a6f7e1103';
$userIdConst     = 'd1b7c3e4-5a26-4f18-9c30-2b8a6f7e1104';

// Las constantes del contexto van ANTES del bootstrap — es lo que hace
// `api/data.php` en un request real, y los helpers globales del arqueo
// (`getSalesByPayment()`, `getPaymentMethodName()`) las leen al cargarse.
define('COMPANY_ID',  $companyIdConst);
define('OUTLET_ID',   $outletIdConst);
define('REGISTER_ID', $registerIdConst);
define('USER_ID',     $userIdConst);
define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Reports\CashCountStatus;
use Punto\Api\Reports\DrawersService;
use Punto\Api\Reports\Roc;
use Punto\Api\Services\DrawerService;

$companyId  = $companyIdConst;
$outletId   = $outletIdConst;
$registerId = $registerIdConst;
$userId     = $userIdConst;
$day        = '2019-05-14';

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

/** Comparación de montos: los importes viajan por DECIMAL y vuelven como string. */
function near(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.005;
}

// ── Fixture ──────────────────────────────────────────────────────────────────
function seedTenant(string $companyId, string $outletId, string $registerId, string $userId, float $tolerance): void
{
    global $db;
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
         ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config",
        [$companyId, json_encode([
            'settingName'              => 'Arqueo Test',
            // Guaraní: sin decimales ⇒ el piso de redondeo de CashCountStatus es 1.
            'settingDecimal'           => 'no',
            'settingThousandSeparator' => 'dot',
            'settingCountry'           => 'PY',
            'settingCurrency'          => 'PYG',
            'settingTimeZone'          => 'America/Asuncion',
            'settingTaxName'           => 'IVA',
            'settingLanguage'          => 'es',
            'settingSocialMedia'       => '{}',
            'settingObj'               => '{}',
            CashCountStatus::SETTING_KEY => $tolerance,
        ])]
    );
    $db->Execute(
        "INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, 'Arqueo Test - Sucursal', 1, ?)
         ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName",
        [$outletId, $companyId]
    );
    $db->Execute(
        "INSERT INTO register (registerid, registername, registerstatus,
            registerinvoicenumber, registerticketnumber, registerreturnnumber,
            registerschedulenumber, registerpedidonumber, registerquotenumber, outletid, companyid)
         VALUES (?, 'Arqueo Test - Caja', TRUE, 1, 1, 1, 1, 1, 1, ?, ?)
         ON CONFLICT (registerid) DO UPDATE SET registername = EXCLUDED.registername",
        [$registerId, $outletId, $companyId]
    );
    $db->Execute(
        "INSERT INTO contact (contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, outletId, companyId)
         VALUES (?, 'Arqueo Test Admin', '595991000914', 'arqueo-test@local.test', 1, 0, 'admin', 1, ?, ?)
         ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName",
        [$userId, $outletId, $companyId]
    );
}

/** Estado limpio: este arnés es la única fuente de cajas y ventas de su tenant. */
function resetShift(string $companyId, string $registerId, string $day): void
{
    global $db;
    $db->Execute(
        "DELETE FROM transaction WHERE companyid = ? AND transactiondate >= ?::date AND transactiondate < (?::date + 7)",
        [$companyId, $day, $day]
    );
    $db->Execute("DELETE FROM expenses WHERE companyid = ?", [$companyId]);
    $db->Execute("DELETE FROM drawer WHERE companyid = ? AND registerid = ?", [$companyId, $registerId]);
}

/** Abre la caja del turno y devuelve su drawerId. */
function openShift(DrawerService $svc, string $companyId, string $registerId, string $openDate, float $amount, string $userId): string
{
    global $db;
    $r = $svc->open($amount, $openDate, $userId);
    if ($r !== true) {
        throw new RuntimeException("open() devolvió: " . var_export($r, true));
    }
    $row = ncmExecute(
        'SELECT drawerId AS "drawerId" FROM drawer WHERE companyId = ? AND registerId = ? AND drawerCloseDate IS NULL LIMIT 1',
        [$companyId, $registerId]
    );
    return (string) $row['drawerId'];
}

/**
 * Venta con un medio de pago declarado. `$type` es el slug del medio:
 * 'efectivo' entra al cajón, cualquier otro (ej. 'tcredito') no.
 */
function insertSale(
    string $companyId, string $outletId, string $registerId, string $userId,
    string $drawerId, string $date, float $total, string $type, string $name,
): void {
    global $db;
    $db->Execute(
        "INSERT INTO transaction
            (transactionId, transactionDate, transactionTotal, transactionDiscount,
             transactionType, transactionPaymentType, transactionComplete,
             drawerId, registerId, outletId, companyId, userId, meta)
         VALUES (gen_random_uuid(), ?, ?, 0, 0, ?, TRUE, ?, ?, ?, ?, ?, '{}'::jsonb)",
        [
            $date, $total,
            json_encode([['type' => $type, 'name' => $name, 'price' => $total, 'total' => $total]]),
            $drawerId, $registerId, $outletId, $companyId, $userId,
        ]
    );
}

/** Fila del reporte para una caja concreta. */
function reportRow(DrawersService $reports, string $companyId, string $outletId, string $drawerId, string $from, string $to): ?array
{
    $roc  = Roc::build($companyId, $outletId);
    $rows = $reports->listMovements($from, $to, $roc, $companyId);
    foreach ($rows as $r) {
        if ($r['drawerId'] === $drawerId) {
            return $r;
        }
    }
    return null;
}

seedTenant($companyId, $outletId, $registerId, $userId, 0.0);

$ctx     = new TenantContext(
    companyId: $companyId,
    outletId: $outletId,
    userId: $userId,
    registerId: $registerId,
    roleId: '',
    deviceId: '',
);
$svc     = new DrawerService($ctx);
$reports = new DrawersService();

$from = $day . ' 00:00:00';
$to   = date('Y-m-d 23:59:59', strtotime($day . ' +6 days'));

// ── (a) + (b) El esperado se congela, y cuenta SOLO el efectivo ──────────────
echo "\n=== (a+b) El cierre congela el efectivo esperado (tarjeta no cuenta) ===\n";

resetShift($companyId, $registerId, $day);
$openDate = $day . ' 08:00:00';
$drawerId = openShift($svc, $companyId, $registerId, $openDate, 100000.0, $userId);

insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . ' 09:00:00', 50000.0, 'efectivo', 'Efectivo');
insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . ' 10:00:00', 70000.0, 'tcredito', 'Tarjeta de crédito');

// Esperado en billetes = 100.000 (apertura) + 50.000 (venta en efectivo) = 150.000.
// El total del turno es 220.000: si el esperado usara `sold`, cerrar con
// 150.000 marcaría un faltante de 70.000 que no existe.
$expectedCash = 150000.0;

$summary = $svc->getSummary($registerId, $outletId, $companyId);
check(
    'el resumen que ve el cajero informa 150.000 de efectivo (no los 220.000 del turno)',
    near((float) $summary['subtotal'], $expectedCash),
    'subtotal=' . var_export($summary['subtotal'] ?? null, true) . ' total=' . var_export($summary['total'] ?? null, true),
    $failures, $checks
);

$closeDate = $day . ' 20:00:00';
$r = $svc->close($expectedCash, $closeDate, $userId);
check('close() devuelve true', $r === true, 'close() devolvió: ' . var_export($r, true), $failures, $checks);

$stored = ncmExecute(
    'SELECT drawerExpectedAmount AS "exp", drawerCloseAmount AS "close" FROM drawer WHERE drawerId = ?',
    [$drawerId]
);
check(
    'drawerExpectedAmount quedó congelado en 150.000',
    near((float) $stored['exp'], $expectedCash),
    'drawerExpectedAmount=' . var_export($stored['exp'] ?? null, true),
    $failures, $checks
);

$row = reportRow($reports, $companyId, $outletId, $drawerId, $from, $to);
check('el reporte devuelve la caja', $row !== null, 'no apareció en listMovements', $failures, $checks);
check(
    'cuadra exacto ⇒ cashStatus=ok, esperado congelado',
    $row !== null && $row['cashStatus'] === CashCountStatus::OK && $row['expectedSource'] === 'frozen',
    'cashStatus=' . var_export($row['cashStatus'] ?? null, true) . ' source=' . var_export($row['expectedSource'] ?? null, true),
    $failures, $checks
);
check(
    'el reporte separa el efectivo del total (cashSold=50.000, sold=120.000)',
    $row !== null && near((float) $row['cashSold'], 50000.0) && near((float) $row['sold'], 120000.0),
    'cashSold=' . var_export($row['cashSold'] ?? null, true) . ' sold=' . var_export($row['sold'] ?? null, true),
    $failures, $checks
);

// El congelado no se mueve: una venta que sincroniza DESPUÉS del cierre no
// puede reescribir contra qué se arqueó ese turno.
insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . ' 19:00:00', 30000.0, 'efectivo', 'Efectivo');
$row = reportRow($reports, $companyId, $outletId, $drawerId, $from, $to);
check(
    'una venta que llega tarde NO cambia el esperado congelado ni el veredicto',
    $row !== null && near((float) $row['expectedAmount'], $expectedCash) && $row['cashStatus'] === CashCountStatus::OK,
    'expectedAmount=' . var_export($row['expectedAmount'] ?? null, true) . ' cashStatus=' . var_export($row['cashStatus'] ?? null, true),
    $failures, $checks
);

// ── (c) Faltante y sobrante ─────────────────────────────────────────────────
echo "\n=== (c) Faltante (rojo) y sobrante (amarillo) ===\n";

/** Corre un turno de un solo pago en efectivo y devuelve la fila del reporte. */
$runShift = function (float $open, float $cashSale, float $counted, string $slot) use (
    $svc, $reports, $companyId, $outletId, $registerId, $userId, $day, $from, $to
): array {
    resetShift($companyId, $registerId, $day);
    $openDate = $day . " {$slot}:00:00";
    $drawerId = openShift($svc, $companyId, $registerId, $openDate, $open, $userId);
    insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . " {$slot}:30:00", $cashSale, 'efectivo', 'Efectivo');
    $svc->close($counted, $day . ' 23:00:00', $userId);
    $row = reportRow($reports, $companyId, $outletId, $drawerId, $from, $to);
    if ($row === null) {
        throw new RuntimeException('la caja no apareció en el reporte');
    }
    return $row;
};

// Esperado 150.000, contado 145.000 ⇒ faltan 5.000.
$row = $runShift(100000.0, 50000.0, 145000.0, '08');
check(
    'contado de menos ⇒ short con diferencia −5.000',
    $row['cashStatus'] === CashCountStatus::SHORT && near((float) $row['difference'], -5000.0),
    'cashStatus=' . var_export($row['cashStatus'], true) . ' difference=' . var_export($row['difference'], true),
    $failures, $checks
);

// Esperado 150.000, contado 153.000 ⇒ sobran 3.000.
$row = $runShift(100000.0, 50000.0, 153000.0, '08');
check(
    'contado de más ⇒ over con diferencia +3.000',
    $row['cashStatus'] === CashCountStatus::OVER && near((float) $row['difference'], 3000.0),
    'cashStatus=' . var_export($row['cashStatus'], true) . ' difference=' . var_export($row['difference'], true),
    $failures, $checks
);

// ── (d) Tolerancia ──────────────────────────────────────────────────────────
echo "\n=== (d) Tolerancia: piso de redondeo y margen del comercio ===\n";

// Tolerancia configurada 0: 1 guaraní sigue cuadrando (piso de redondeo).
$row = $runShift(100000.0, 50000.0, 149999.0, '08');
check(
    'con tolerancia 0, una diferencia de 1 Gs igual cuadra (piso de redondeo)',
    $row['cashStatus'] === CashCountStatus::OK,
    'cashStatus=' . var_export($row['cashStatus'], true) . ' difference=' . var_export($row['difference'], true),
    $failures, $checks
);

// …pero 100 Gs no: con tolerancia 0 el arqueo es exacto.
$row = $runShift(100000.0, 50000.0, 149900.0, '08');
check(
    'con tolerancia 0, una diferencia de 100 Gs SÍ es faltante',
    $row['cashStatus'] === CashCountStatus::SHORT,
    'cashStatus=' . var_export($row['cashStatus'], true),
    $failures, $checks
);

// El comercio sube la tolerancia a 500: la misma diferencia pasa a cuadrar.
seedTenant($companyId, $outletId, $registerId, $userId, 500.0);
$reports = new DrawersService(); // instancia nueva: la tolerancia se cachea por request
$row = reportRow(
    $reports, $companyId, $outletId,
    (string) ncmExecute('SELECT drawerId AS "drawerId" FROM drawer WHERE companyId = ? ORDER BY drawerOpenDate DESC LIMIT 1', [$companyId])['drawerId'],
    $from, $to
);
check(
    'subiendo la tolerancia a 500, esa misma diferencia de 100 cuadra',
    $row !== null && $row['cashStatus'] === CashCountStatus::OK,
    'cashStatus=' . var_export($row['cashStatus'] ?? null, true),
    $failures, $checks
);
check(
    'tolerance() refleja el valor del comercio',
    near($reports->tolerance($companyId), 500.0),
    'tolerance=' . var_export($reports->tolerance($companyId), true),
    $failures, $checks
);

// Unitarios del clasificador — el piso no depende de la BD.
check(
    'effectiveTolerance nunca baja del piso de la moneda',
    near(CashCountStatus::effectiveTolerance(0.0, false), 1.0)
        && near(CashCountStatus::effectiveTolerance(0.0, true), 0.01)
        && near(CashCountStatus::effectiveTolerance(500.0, false), 500.0),
    'piso mal resuelto',
    $failures, $checks
);
check(
    'sin esperado no hay veredicto (UNKNOWN, no un sobrante inventado)',
    CashCountStatus::classify(150000.0, null, 1.0) === CashCountStatus::UNKNOWN,
    'classify(counted, null) debería ser UNKNOWN',
    $failures, $checks
);

seedTenant($companyId, $outletId, $registerId, $userId, 0.0);

// ── (e) Cierres históricos (anteriores a la mig 164) ────────────────────────
echo "\n=== (e) Un cierre viejo, sin esperado guardado, no rompe el reporte ===\n";

resetShift($companyId, $registerId, $day);
$openDate = $day . ' 08:00:00';
$drawerId = openShift($svc, $companyId, $registerId, $openDate, 100000.0, $userId);
insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . ' 09:00:00', 50000.0, 'efectivo', 'Efectivo');
// Con tarjeta EN EL MISMO turno a propósito: el estimado de un cierre viejo
// tiene que contar billetes igual que el congelado. Si el reporte volviera a
// sumar todos los medios de pago, este turno cerraría con un faltante de
// 70.000 que nunca existió — que es exactamente el bug que había.
insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . ' 10:00:00', 70000.0, 'tcredito', 'Tarjeta de crédito');
$svc->close(150000.0, $day . ' 20:00:00', $userId);

// Simula el estado en el que quedaron TODOS los cierres previos a la migración.
$db->Execute('UPDATE drawer SET drawerExpectedAmount = NULL WHERE drawerId = ?', [$drawerId]);

$reports = new DrawersService();
$row = reportRow($reports, $companyId, $outletId, $drawerId, $from, $to);
check(
    'el cierre histórico sigue apareciendo en el reporte',
    $row !== null,
    'listMovements se rompió o lo omitió',
    $failures, $checks
);
check(
    'se marca como estimado, no como un veredicto registrado',
    $row !== null && $row['expectedSource'] === 'estimated',
    'expectedSource=' . var_export($row['expectedSource'] ?? null, true),
    $failures, $checks
);
check(
    'el esperado estimado cuenta solo efectivo (150.000, no los 220.000 del turno)',
    $row !== null && near((float) $row['expectedAmount'], 150000.0),
    'expectedAmount=' . var_export($row['expectedAmount'] ?? null, true),
    $failures, $checks
);
check(
    'y por lo tanto el cierre histórico cuadra, no marca un faltante fantasma',
    $row !== null && $row['cashStatus'] === CashCountStatus::OK,
    'cashStatus=' . var_export($row['cashStatus'] ?? null, true)
        . ' difference=' . var_export($row['difference'] ?? null, true),
    $failures, $checks
);

$detail = $reports->detail($drawerId, $companyId, Roc::build($companyId, $outletId));
check(
    'el detalle del cierre histórico tampoco se rompe y coincide con el listado',
    $detail !== null
        && $detail['expectedSource'] === 'estimated'
        && $detail['cashStatus'] === $row['cashStatus'],
    'detail=' . var_export($detail['expectedSource'] ?? null, true) . '/' . var_export($detail['cashStatus'] ?? null, true),
    $failures, $checks
);

// ── (f) Corrección del arqueo ───────────────────────────────────────────────
echo "\n=== (f) Corregir el arqueo re-congela el esperado ===\n";

// La apertura real eran 120.000, no 100.000 ⇒ el esperado pasa a 170.000 y el
// cierre de 150.000 se convierte en un faltante de 20.000.
$ok = $reports->correct($drawerId, $companyId, $openDate, $day . ' 20:00:00', 120000.0, 150000.0);
check('correct() devuelve true', $ok, 'correct() devolvió false', $failures, $checks);

$stored = ncmExecute('SELECT drawerExpectedAmount AS "exp" FROM drawer WHERE drawerId = ?', [$drawerId]);
check(
    'el esperado se re-congela con la apertura corregida (170.000)',
    $stored && near((float) $stored['exp'], 170000.0),
    'drawerExpectedAmount=' . var_export($stored['exp'] ?? null, true),
    $failures, $checks
);

$reports = new DrawersService();
$row = reportRow($reports, $companyId, $outletId, $drawerId, $from, $to);
check(
    'tras corregir, el veredicto pasa a faltante de 20.000 y ya no es estimado',
    $row !== null
        && $row['cashStatus'] === CashCountStatus::SHORT
        && near((float) $row['difference'], -20000.0)
        && $row['expectedSource'] === 'frozen',
    'cashStatus=' . var_export($row['cashStatus'] ?? null, true)
        . ' difference=' . var_export($row['difference'] ?? null, true)
        . ' source=' . var_export($row['expectedSource'] ?? null, true),
    $failures, $checks
);

// ── Limpieza ────────────────────────────────────────────────────────────────
resetShift($companyId, $registerId, $day);

echo "\n";
harnessFinish($failures, $checks);

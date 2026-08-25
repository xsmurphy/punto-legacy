<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) del arqueo POR MEDIO DE PAGO del cierre
 * de caja (mig 169 + `DrawerService::composeArqueo()` + `drawer_count` +
 * `Reports\DrawersService::detail()['countByMethod']`).
 *
 * Qué protege:
 *
 *   (a) EL EFECTIVO SIGUE SIENDO EL EFECTIVO. `amount` del cierre es el
 *       efectivo contado y nada más: `drawerCloseAmount` y
 *       `drawerExpectedAmount` (mig 164) conservan su significado y el
 *       semáforo de cuadre del panel sigue leyendo lo mismo. Es el invariante
 *       más fácil de romper con este cambio —mandar ahí la suma de todos los
 *       medios convertiría cada turno con tarjeta en un sobrante gigante— y el
 *       más caro, porque el faltante fantasma es justo lo que la mig 164 vino
 *       a arreglar.
 *   (b) UNA FILA POR MEDIO, CON SU ESPERADO. El efectivo espera
 *       `subtotal` (inicial + ventas en efectivo + ingresos − extracciones);
 *       los demás medios esperan sus ventas. Las diferencias se calculan por
 *       fila, no contra el total del turno.
 *   (c) EL EFECTIVO ESTÁ AUNQUE NO HAYA HABIDO VENTAS EN EFECTIVO. El fondo
 *       inicial está en el cajón desde que el turno abrió: sin esta fila el
 *       cajero cierra sin contar la plata.
 *   (d) COMPATIBILIDAD. Un cierre SIN desglose (cliente sin actualizar, o una
 *       operación encolada antes del deploy) no rompe: escribe la fila del
 *       efectivo con el mismo par contado/esperado de siempre.
 *   (e) IDEMPOTENCIA. Reenviar el mismo cierre no duplica el arqueo
 *       (`ON CONFLICT (drawerid, methodkey)`), que es exactamente lo que hace
 *       la cola offline cuando reintenta.
 *   (f) CIERRES HISTÓRICOS. Una caja cerrada ANTES de la mig 169 no tiene
 *       filas y el reporte la muestra con la única fila reconstruible —la del
 *       cajón— marcada `source='estimated'`. Los demás medios NO aparecen en
 *       cero: un cero acá diría "se contó y no había nada".
 *
 * Fixture propio, con UUIDs de este arnés y un día FIJO Y VIEJO (2019-05-16)
 * para no cruzarse con lo que otros arneses escriben.
 *
 * Uso (necesita Postgres migrado — ver `run_drawer_count_by_method_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/drawer_count_by_method_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

$companyIdConst  = 'e2c8d4f5-6b37-4a29-8d41-3c9b7a8f2201';
$outletIdConst   = 'e2c8d4f5-6b37-4a29-8d41-3c9b7a8f2202';
$registerIdConst = 'e2c8d4f5-6b37-4a29-8d41-3c9b7a8f2203';
$userIdConst     = 'e2c8d4f5-6b37-4a29-8d41-3c9b7a8f2204';

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
$day        = '2019-05-16';

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

function near(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.005;
}

function seedTenant(string $companyId, string $outletId, string $registerId, string $userId): void
{
    global $db;
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
         ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config",
        [$companyId, json_encode([
            'settingName'              => 'Arqueo x Medio Test',
            'settingDecimal'           => 'no',
            'settingThousandSeparator' => 'dot',
            'settingCountry'           => 'PY',
            'settingCurrency'          => 'PYG',
            'settingTimeZone'          => 'America/Asuncion',
            'settingTaxName'           => 'IVA',
            'settingLanguage'          => 'es',
            'settingSocialMedia'       => '{}',
            'settingObj'               => '{}',
            CashCountStatus::SETTING_KEY => 0.0,
        ])]
    );
    $db->Execute(
        "INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, 'Arqueo x Medio - Sucursal', 1, ?)
         ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName",
        [$outletId, $companyId]
    );
    $db->Execute(
        "INSERT INTO register (registerid, registername, registerstatus,
            registerinvoicenumber, registerticketnumber, registerreturnnumber,
            registerschedulenumber, registerpedidonumber, registerquotenumber, outletid, companyid)
         VALUES (?, 'Arqueo x Medio - Caja', TRUE, 1, 1, 1, 1, 1, 1, ?, ?)
         ON CONFLICT (registerid) DO UPDATE SET registername = EXCLUDED.registername",
        [$registerId, $outletId, $companyId]
    );
    $db->Execute(
        "INSERT INTO contact (contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, outletId, companyId)
         VALUES (?, 'Arqueo x Medio Admin', '595991000916', 'arqueo-medio-test@local.test', 1, 0, 'admin', 1, ?, ?)
         ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName",
        [$userId, $outletId, $companyId]
    );
}

function resetShift(string $companyId, string $registerId, string $day): void
{
    global $db;
    // drawer_count cae por CASCADE al borrar el drawer, pero se limpia primero
    // igual: si mañana la FK cambia, el arnés no arranca con basura del corrido
    // anterior y dando un falso verde de idempotencia.
    $db->Execute(
        "DELETE FROM drawer_count WHERE companyid = ? AND drawerid IN
            (SELECT drawerid FROM drawer WHERE companyid = ? AND registerid = ?)",
        [$companyId, $companyId, $registerId]
    );
    $db->Execute(
        "DELETE FROM transaction WHERE companyid = ? AND transactiondate >= ?::date AND transactiondate < (?::date + 7)",
        [$companyId, $day, $day]
    );
    $db->Execute("DELETE FROM expenses WHERE companyid = ?", [$companyId]);
    $db->Execute("DELETE FROM drawer WHERE companyid = ? AND registerid = ?", [$companyId, $registerId]);
}

function openShift(DrawerService $svc, string $companyId, string $registerId, string $openDate, float $amount, string $userId): string
{
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

/** Las filas congeladas del arqueo, indexadas por medio. */
function countRows(string $drawerId): array
{
    $rs = ncmExecute(
        'SELECT methodkey, methodname, iscash, expectedamount, countedamount
           FROM drawer_count WHERE drawerid = ? ORDER BY iscash DESC',
        [$drawerId],
        false,
        true
    );
    $out = [];
    if ($rs) {
        while (!$rs->EOF) {
            $f = $rs->fields;
            $out[(string) $f['methodkey']] = [
                'name'     => (string) $f['methodname'],
                'isCash'   => (bool) $f['iscash'],
                'expected' => $f['expectedamount'] !== null ? (float) $f['expectedamount'] : null,
                'counted'  => (float) $f['countedamount'],
            ];
            $rs->MoveNext();
        }
        $rs->Close();
    }
    return $out;
}

seedTenant($companyId, $outletId, $registerId, $userId);

$ctx = new TenantContext(
    companyId: $companyId,
    outletId: $outletId,
    userId: $userId,
    registerId: $registerId,
    roleId: '',
    deviceId: '',
);
$svc     = new DrawerService($ctx);
$reports = new DrawersService();
$roc     = Roc::build($companyId, $outletId);

// ── composeArqueo: la fórmula, sin base de datos ─────────────────────────────
echo "\n=== composeArqueo — emparejamiento y diferencias ===\n";

$expectedByMethod = [
    ['key' => 'efectivo',  'name' => 'Efectivo',  'code' => 'cash', 'isCash' => true,  'expected' => 150000.0],
    ['key' => 'tcrédito',  'name' => 'T. Crédito', 'code' => 'tcredito', 'isCash' => false, 'expected' => 70000.0],
];

// REGRESIÓN: el servidor agrupa con el nombre RESUELTO por taxonomía y cae al
// slug crudo cuando no resuelve; la caja solo conoce el nombre que ella anotó
// al vender. Emparejar únicamente por clave dejaba el esperado sin contar y lo
// contado como un sobrante por el monto entero — el turno entero mal arqueado.
// Lo detectó este arnés antes de llegar a una caja; `methodIdentities()` lo
// resuelve matcheando también por nombre normalizado y por slug.
$arqueo = DrawerService::composeArqueo($expectedByMethod, [
    ['key' => 'efectivo',           'name' => 'Efectivo',           'code' => 'cash',     'isCash' => true,  'counted' => 148000.0],
    ['key' => 'tarjeta de crédito', 'name' => 'Tarjeta de crédito', 'code' => 'tcredito', 'isCash' => false, 'counted' => 70000.0],
]);
check(
    'empareja por SLUG cuando el nombre del servidor y el de la caja difieren',
    count($arqueo) === 2
        && near((float) $arqueo[1]['expected'], 70000.0)
        && near($arqueo[1]['difference'], 0.0)
        && $arqueo[1]['key'] === 'tcrédito',
    var_export($arqueo, true),
    $failures, $checks
);

$arqueo = DrawerService::composeArqueo($expectedByMethod, [
    ['key' => 'efectivo', 'name' => 'Efectivo',   'isCash' => true,  'counted' => 145000.0],
    ['key' => 'tcrédito', 'name' => 'T. Crédito', 'isCash' => false, 'counted' => 70000.0],
]);
check(
    'la diferencia se calcula POR MEDIO (efectivo −5.000, crédito 0)',
    near($arqueo[0]['difference'], -5000.0) && near($arqueo[1]['difference'], 0.0),
    var_export(array_column($arqueo, 'difference'), true),
    $failures, $checks
);

$arqueo = DrawerService::composeArqueo($expectedByMethod, [
    ['name' => 'Caja chica', 'isCash' => true, 'counted' => 150000.0],
]);
check(
    'el efectivo matchea por bandera aunque el comercio lo llame distinto',
    near($arqueo[0]['counted'], 150000.0) && near($arqueo[0]['difference'], 0.0),
    var_export($arqueo[0], true),
    $failures, $checks
);
check(
    'un medio esperado que nadie contó queda en null, no en cero',
    $arqueo[1]['counted'] === null && $arqueo[1]['difference'] === null,
    var_export($arqueo[1], true),
    $failures, $checks
);

$arqueo = DrawerService::composeArqueo($expectedByMethod, [
    ['key' => 'efectivo', 'name' => 'Efectivo', 'isCash' => true, 'counted' => 150000.0],
    ['key' => 'qr',       'name' => 'QR',       'isCash' => false, 'counted' => 9000.0],
]);
$qr = array_values(array_filter($arqueo, static fn($r) => $r['key'] === 'qr'));
check(
    'un medio contado que el servidor no vio es un sobrante, no una fila que se tira',
    count($qr) === 1 && near($qr[0]['expected'], 0.0) && near($qr[0]['difference'], 9000.0),
    var_export($qr, true),
    $failures, $checks
);

// ── (a)+(b)+(c) Cierre completo con desglose ────────────────────────────────
echo "\n=== (a+b+c) El cierre congela una fila por medio, y el efectivo sigue siendo el efectivo ===\n";

resetShift($companyId, $registerId, $day);
$openDate = $day . ' 08:00:00';
$drawerId = openShift($svc, $companyId, $registerId, $openDate, 100000.0, $userId);

insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . ' 09:00:00', 50000.0, 'efectivo', 'Efectivo');
insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . ' 10:00:00', 70000.0, 'tcredito', 'Tarjeta de crédito');

$totals = $svc->getClosingTotals($registerId, $outletId, $companyId);
check(
    'getClosingTotals informa el esperado por medio (efectivo=150.000, crédito=70.000)',
    $totals !== null
        && count($totals['expectedByMethod']) === 2
        && $totals['expectedByMethod'][0]['isCash'] === true
        && near((float) $totals['expectedByMethod'][0]['expected'], 150000.0)
        && near((float) $totals['expectedByMethod'][1]['expected'], 70000.0),
    var_export($totals['expectedByMethod'] ?? null, true),
    $failures, $checks
);

$cashCounted = 148000.0; // faltan 2.000 en billetes
$closeDate   = $day . ' 20:00:00';
// El conteo va como lo manda la caja SIN CONEXIÓN: con el nombre que ella
// anotó al vender ("Tarjeta de crédito") y el slug del medio, que puede no
// coincidir con el nombre que el servidor resuelve por taxonomía. Es el caso
// que rompía el emparejamiento antes de `methodIdentities()`.
$r = $svc->close($cashCounted, $closeDate, $userId, [
    ['key' => 'efectivo', 'name' => 'Efectivo', 'code' => 'cash', 'isCash' => true, 'counted' => $cashCounted],
    ['key' => 'tarjeta de crédito', 'name' => 'Tarjeta de crédito', 'code' => 'tcredito', 'isCash' => false, 'counted' => 70000.0],
], $totals);
check('close() con desglose devuelve true', $r === true, var_export($r, true), $failures, $checks);

$stored = ncmExecute(
    'SELECT drawerExpectedAmount AS "exp", drawerCloseAmount AS "close" FROM drawer WHERE drawerId = ?',
    [$drawerId]
);
check(
    '(a) drawerCloseAmount siguió siendo SOLO el efectivo (148.000, no los 218.000 contados)',
    near((float) $stored['close'], $cashCounted) && near((float) $stored['exp'], 150000.0),
    'close=' . var_export($stored['close'] ?? null, true) . ' exp=' . var_export($stored['exp'] ?? null, true),
    $failures, $checks
);

$rows = countRows($drawerId);
// La fila NO se busca por la clave que mandó la caja: se congela con la
// identidad del SERVIDOR (la que agrupó el turno), que es justamente la que
// puede no coincidir con la del cliente. Buscarla por `isCash` es lo que
// prueba que el emparejamiento ocurrió en vez de asumirlo.
$cardRows = array_values(array_filter($rows, static fn($r) => !$r['isCash']));
check(
    '(b) hay una fila por medio, con su esperado y su contado',
    count($rows) === 2
        && near($rows['efectivo']['expected'] ?? null, 150000.0)
        && near($rows['efectivo']['counted'] ?? null, 148000.0)
        && count($cardRows) === 1
        && near($cardRows[0]['expected'], 70000.0)
        && near($cardRows[0]['counted'], 70000.0),
    var_export($rows, true),
    $failures, $checks
);

$detail = $reports->detail($drawerId, $companyId, $roc);
$byMethod = $detail['countByMethod'] ?? [];
check(
    'el reporte del panel clasifica CADA medio (efectivo faltante, crédito ok)',
    count($byMethod) === 2
        && $byMethod[0]['isCash'] === true
        && $byMethod[0]['status'] === CashCountStatus::SHORT
        && near($byMethod[0]['difference'], -2000.0)
        && $byMethod[1]['status'] === CashCountStatus::OK
        && $byMethod[0]['source'] === 'frozen',
    var_export($byMethod, true),
    $failures, $checks
);
check(
    'el semáforo del efectivo del listado NO cambió (sigue leyendo la mig 164)',
    ($detail['cashStatus'] ?? null) === CashCountStatus::SHORT
        && near((float) ($detail['difference'] ?? 0), -2000.0),
    'cashStatus=' . var_export($detail['cashStatus'] ?? null, true)
        . ' difference=' . var_export($detail['difference'] ?? null, true),
    $failures, $checks
);

// ── (c) El efectivo está aunque el turno no lo haya tocado ──────────────────
echo "\n=== (c) Turno sin una sola venta en efectivo: el cajón se cuenta igual ===\n";

resetShift($companyId, $registerId, $day);
$drawerId = openShift($svc, $companyId, $registerId, $day . ' 08:00:00', 80000.0, $userId);
insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . ' 09:00:00', 30000.0, 'tcredito', 'Tarjeta de crédito');

$totals = $svc->getClosingTotals($registerId, $outletId, $companyId);
$cashRow = $totals['expectedByMethod'][0] ?? null;
check(
    'el esperado incluye la fila del efectivo con el fondo inicial (80.000)',
    $cashRow !== null && $cashRow['isCash'] === true && near((float) $cashRow['expected'], 80000.0),
    var_export($totals['expectedByMethod'] ?? null, true),
    $failures, $checks
);

// ── (d) Compatibilidad: cierre sin desglose ─────────────────────────────────
echo "\n=== (d) Un cierre SIN desglose (cliente viejo) escribe la fila del efectivo ===\n";

$r = $svc->close(80000.0, $day . ' 20:00:00', $userId);
check('close() sin desglose devuelve true', $r === true, var_export($r, true), $failures, $checks);

$rows = countRows($drawerId);
check(
    'quedó UNA fila, la del cajón, con el mismo par contado/esperado de la mig 164',
    count($rows) === 1
        && ($rows['efectivo']['isCash'] ?? false) === true
        && near($rows['efectivo']['expected'] ?? null, 80000.0)
        && near($rows['efectivo']['counted'] ?? null, 80000.0),
    var_export($rows, true),
    $failures, $checks
);

// ── (e) Idempotencia del reenvío ────────────────────────────────────────────
echo "\n=== (e) Reenviar el cierre no duplica el arqueo ===\n";

resetShift($companyId, $registerId, $day);
$drawerId = openShift($svc, $companyId, $registerId, $day . ' 08:00:00', 10000.0, $userId);
insertSale($companyId, $outletId, $registerId, $userId, $drawerId, $day . ' 09:00:00', 5000.0, 'efectivo', 'Efectivo');

$counted = [
    ['key' => 'efectivo', 'name' => 'Efectivo', 'isCash' => true, 'counted' => 15000.0],
    ['key' => 'qr',       'name' => 'QR',       'isCash' => false, 'counted' => 3000.0],
];
$svc->close(15000.0, $day . ' 20:00:00', $userId, $counted);
$firstRows = countRows($drawerId);

// Segundo envío del MISMO cierre: la caja ya está cerrada ⇒ 'Already Closed'.
// Lo que importa es que no aparezca un segundo juego de filas.
$svc->close(15000.0, $day . ' 20:00:00', $userId, $counted);
$secondRows = countRows($drawerId);
check(
    'el reenvío deja las mismas filas, no un segundo arqueo',
    count($firstRows) === 2 && count($secondRows) === 2,
    'primera=' . count($firstRows) . ' segunda=' . count($secondRows),
    $failures, $checks
);

// ── (f) Cierres anteriores a la migración ───────────────────────────────────
echo "\n=== (f) Un cierre sin filas se informa con la del cajón, marcada estimada ===\n";

global $db;
$db->Execute('DELETE FROM drawer_count WHERE drawerid = ?', [$drawerId]);
$detail   = $reports->detail($drawerId, $companyId, $roc);
$byMethod = $detail['countByMethod'] ?? [];
check(
    'una sola fila, la del efectivo, con source=estimated',
    count($byMethod) === 1
        && $byMethod[0]['isCash'] === true
        && $byMethod[0]['source'] === 'estimated',
    var_export($byMethod, true),
    $failures, $checks
);

// ── Limpieza ────────────────────────────────────────────────────────────────
resetShift($companyId, $registerId, $day);

echo "\n";
harnessFinish($failures, $checks);

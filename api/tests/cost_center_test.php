<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) de los centros de costo y del código
 * contable externo de las categorías (mig 167 + `CostCenterService` +
 * `MovementService`).
 *
 * Qué protege:
 *
 *   (a) LA MIGRACIÓN APLICA. `fin_category.code`, la tabla `fin_cost_center` y
 *       `fin_movement.costcenterid` existen tras correr `migrate.php`. Es lo
 *       único que no se puede verificar leyendo el código: la migración se
 *       corre en producción a mano al mergear, y un error de sintaxis ahí
 *       tumba el boot del contenedor (ya pasó con las migs 74/77).
 *   (b) EL CÓDIGO ES ÚNICO POR COMERCIO, y case-insensitive: "A100" y "a100"
 *       son el mismo código para el contador. Si esto no se cumple, el
 *       matcheo contra su listado —que es el ÚNICO motivo por el que existe
 *       el campo— no sirve para nada.
 *   (c) EL CÓDIGO NO SE PIERDE AL RENOMBRAR. Un PUT sin la clave `code` deja
 *       el código como estaba; uno con `code: ''` lo borra. La distinción
 *       "clave ausente" vs "null" es la que permite que el diálogo de
 *       reclasificar mande solo lo que el operador tocó.
 *   (d) EL CENTRO DE COSTO ES OPCIONAL (decisión del owner 2026-08-24): un
 *       movimiento sin centro se registra igual, y el filtro `none` lo
 *       encuentra para clasificarlo después.
 *   (e) SE PUEDE RECLASIFICAR EL HISTÓRICO, incluidos los movimientos
 *       DERIVADOS (compras, gastos de caja del POS) que `void()` rechaza.
 *       Sin esto, la decisión (d) no cierra: los 696 movimientos de
 *       producción se quedarían sin centro para siempre.
 *   (f) RECLASIFICAR NO MUEVE EL SALDO. Es metadata pura — si tocara
 *       `fin_account.currentbalance`, clasificar el histórico descuadraría
 *       todas las cuentas del comercio.
 *   (g) UN CENTRO ARCHIVADO NO RECIBE IMPUTACIONES NUEVAS pero conserva las
 *       viejas (el histórico no se reescribe).
 *   (h) EL REPORTE POR CENTRO CUADRA, con su fila "Sin centro de costo" para
 *       lo no clasificado.
 *
 * Fixture propio con UUIDs fijos de este arnés. Los movimientos van a un día
 * FIJO Y VIEJO (2019-03-11) para no cruzarse con lo que otros arneses
 * escriben hoy.
 *
 * Uso (necesita Postgres migrado — ver `run_cost_center_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/cost_center_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

$companyIdConst = 'c057ce47-0000-4000-8000-000000000101';
$outletIdConst  = 'c057ce47-0000-4000-8000-000000000102';
$userIdConst    = 'c057ce47-0000-4000-8000-000000000103';
$accountIdConst = 'c057ce47-0000-4000-8000-000000000104';

define('COMPANY_ID', $companyIdConst);
define('OUTLET_ID',  $outletIdConst);
define('USER_ID',    $userIdConst);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Finance\CategoryService;
use Punto\Api\Finance\CostCenterService;
use Punto\Api\Finance\MovementService;

$companyId = $companyIdConst;
$outletId  = $outletIdConst;
$accountId = $accountIdConst;
$day       = '2019-03-11';

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

/** Los importes viajan por DECIMAL y vuelven como string. */
function near(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.005;
}

/** Corre $fn y devuelve el mensaje de la excepción, o null si no lanzó. */
function expectThrow(callable $fn): ?string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
    return null;
}

/** true si la columna existe — es el chequeo de que la migración corrió. */
function hasColumn(string $table, string $column): bool
{
    $row = ncmExecute(
        'SELECT 1 AS ok FROM information_schema.columns
          WHERE table_name = ? AND column_name = ? LIMIT 1',
        [$table, $column]
    );
    return (bool) $row;
}

// ── Fixture ──────────────────────────────────────────────────────────────────

global $db;

$db->Execute(
    "INSERT INTO company (companyId, status, plan, balance, isParent, config)
     VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
     ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config",
    [$companyId, json_encode([
        'settingName'              => 'Centros de Costo Test',
        'settingDecimal'           => 'no',
        'settingThousandSeparator' => 'dot',
        'settingCountry'           => 'PY',
        'settingCurrency'          => 'PYG',
        'settingTimeZone'          => 'America/Asuncion',
        'settingTaxName'           => 'IVA',
        'settingLanguage'          => 'es',
        'settingSocialMedia'       => '{}',
        'settingObj'               => '{}',
    ])]
);
$db->Execute(
    "INSERT INTO outlet (outletId, outletName, outletStatus, companyId)
     VALUES (?, 'Centros de Costo Test - Sucursal', 1, ?)
     ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName",
    [$outletId, $companyId]
);
$db->Execute(
    "INSERT INTO fin_account (accountid, companyid, name, type, openingbalance, currentbalance, outletid, issystem, status)
     VALUES (?, ?, 'Efectivo (test centros)', 'cash', 0, 0, ?, false, 1)
     ON CONFLICT (accountid) DO UPDATE SET currentbalance = 0, openingbalance = 0",
    [$accountId, $companyId, $outletId]
);

/** Estado limpio: este arnés es la única fuente de datos de su tenant. */
function resetFixture(string $companyId, string $accountId): void
{
    ncmExecute('DELETE FROM fin_movement WHERE companyid = ?', [$companyId]);
    ncmExecute('DELETE FROM fin_cost_center WHERE companyid = ?', [$companyId]);
    ncmExecute('DELETE FROM fin_category WHERE companyid = ?', [$companyId]);
    ncmExecute('UPDATE fin_account SET currentbalance = 0 WHERE accountid = ?', [$accountId]);
}

resetFixture($companyId, $accountId);

$costCenters = new CostCenterService();
$categories  = new CategoryService();
$movements   = new MovementService();

// ── (a) La migración aplicó ──────────────────────────────────────────────────

check(
    'mig 167: fin_category.code existe',
    hasColumn('fin_category', 'code'),
    'la columna no está — ¿corrió migrate.php?',
    $failures, $checks
);
check(
    'mig 167: fin_movement.costcenterid existe',
    hasColumn('fin_movement', 'costcenterid'),
    'la columna no está — ¿corrió migrate.php?',
    $failures, $checks
);
check(
    'mig 167: la tabla fin_cost_center existe',
    hasColumn('fin_cost_center', 'costcenterid'),
    'la tabla no está — ¿corrió migrate.php?',
    $failures, $checks
);

// ── (b) El código es único por comercio, case-insensitive ────────────────────

$produccion = $costCenters->create($companyId, ['name' => 'Producción', 'code' => 'CC-10']);
$admin      = $costCenters->create($companyId, ['name' => 'Administración', 'code' => 'CC-20']);

check(
    'crear centro de costo devuelve id, nombre y código',
    $produccion['name'] === 'Producción' && $produccion['code'] === 'CC-10' && $produccion['status'] === 1,
    var_export($produccion, true),
    $failures, $checks
);

$err = expectThrow(fn() => $costCenters->create($companyId, ['name' => 'Otro', 'code' => 'cc-10']));
check(
    'el código de centro de costo es único e ignora mayúsculas ("cc-10" choca con "CC-10")',
    $err !== null && stripos($err, 'código') !== false,
    'error=' . var_export($err, true),
    $failures, $checks
);

$err = expectThrow(fn() => $costCenters->create($companyId, ['name' => 'producción']));
check(
    'el nombre de centro de costo también es único e ignora mayúsculas',
    $err !== null && stripos($err, 'nombre') !== false,
    'error=' . var_export($err, true),
    $failures, $checks
);

$sinCodigo1 = $costCenters->create($companyId, ['name' => 'Depósito', 'code' => '']);
$sinCodigo2 = $costCenters->create($companyId, ['name' => 'Reparto']);
check(
    'el código es OPCIONAL y varios centros pueden no tenerlo (el UNIQUE es parcial)',
    $sinCodigo1['code'] === null && $sinCodigo2['code'] === null,
    'code1=' . var_export($sinCodigo1['code'], true) . ' code2=' . var_export($sinCodigo2['code'], true),
    $failures, $checks
);

// Categorías: el MISMO invariante, en la otra taxonomía.
$categories->ensureSeed($companyId);
$alquiler = null;
foreach ($categories->list($companyId) as $c) {
    if ($c['kind'] === 'expense' && $c['name'] === 'Alquiler') {
        $alquiler = $c;
    }
}
check(
    'las categorías del seed nacen sin código (el campo es opcional y nuevo)',
    $alquiler !== null && $alquiler['code'] === null,
    var_export($alquiler, true),
    $failures, $checks
);

$alquiler = $categories->update((string) $alquiler['id'], $companyId, [
    'name' => 'Alquiler',
    'code' => '5.1.02',
]);
check(
    'una categoría del sistema SÍ acepta código (es la que el contador necesita mapear)',
    $alquiler['code'] === '5.1.02',
    var_export($alquiler, true),
    $failures, $checks
);

$otraCat = $categories->create($companyId, ['name' => 'Limpieza', 'kind' => 'expense']);
$err = expectThrow(fn() => $categories->update((string) $otraCat['id'], $companyId, [
    'name' => 'Limpieza',
    'code' => '5.1.02',
]));
check(
    'el código de categoría es único por comercio',
    $err !== null && stripos($err, 'código') !== false,
    'error=' . var_export($err, true),
    $failures, $checks
);

// El UNIQUE es por COMERCIO, no por kind: el plan de cuentas del contador es
// una sola lista, un código no puede designar un ingreso y un egreso a la vez.
$err = expectThrow(fn() => $categories->create($companyId, [
    'name' => 'Ingreso raro', 'kind' => 'income', 'code' => '5.1.02',
]));
check(
    'el código de categoría es único CRUZANDO income/expense, no por kind',
    $err !== null && stripos($err, 'código') !== false,
    'error=' . var_export($err, true),
    $failures, $checks
);

// ── (c) El código no se pierde al renombrar ──────────────────────────────────

$renombrada = $categories->update((string) $alquiler['id'], $companyId, ['name' => 'Alquiler local']);
check(
    'un PUT sin la clave `code` NO borra el código ya cargado',
    $renombrada['code'] === '5.1.02' && $renombrada['name'] === 'Alquiler local',
    var_export($renombrada, true),
    $failures, $checks
);

$borrada = $categories->update((string) $alquiler['id'], $companyId, [
    'name' => 'Alquiler local',
    'code' => '   ',
]);
check(
    'un PUT con `code` en blanco SÍ lo borra (normaliza a null, no a "")',
    $borrada['code'] === null,
    var_export($borrada, true),
    $failures, $checks
);
// Se restaura para los checks del reporte de más abajo.
$categories->update((string) $alquiler['id'], $companyId, ['name' => 'Alquiler local', 'code' => '5.1.02']);

// ── (d) El centro de costo es OPCIONAL ───────────────────────────────────────

$conCentro = $movements->create($companyId, [
    'accountId'    => $accountId,
    'categoryId'   => (string) $alquiler['id'],
    'costCenterId' => (string) $produccion['id'],
    'kind'         => 'expense',
    'amount'       => 300000,
    'date'         => $day,
    'description'  => 'Gasto imputado a Producción',
]);
check(
    'un movimiento con centro de costo lo guarda y resuelve nombre y código',
    $conCentro['costCenterId'] === $produccion['id']
        && $conCentro['costCenterName'] === 'Producción'
        && $conCentro['costCenterCode'] === 'CC-10'
        && $conCentro['categoryCode'] === '5.1.02',
    var_export($conCentro, true),
    $failures, $checks
);

$sinCentro = $movements->create($companyId, [
    'accountId'   => $accountId,
    'categoryId'  => (string) $alquiler['id'],
    'kind'        => 'expense',
    'amount'      => 100000,
    'date'        => $day,
    'description' => 'Gasto sin clasificar',
]);
check(
    'el centro de costo es OPCIONAL: el movimiento se registra igual, sin centro',
    $sinCentro['costCenterId'] === null && $sinCentro['costCenterName'] === null,
    var_export($sinCentro, true),
    $failures, $checks
);

$noClasificados = $movements->list($companyId, ['costCenterId' => 'none']);
check(
    'el filtro `none` encuentra exactamente lo que falta clasificar',
    $noClasificados['total'] === 1
        && ($noClasificados['rows'][0]['id'] ?? null) === $sinCentro['id'],
    'total=' . $noClasificados['total'],
    $failures, $checks
);

$delCentro = $movements->list($companyId, ['costCenterId' => (string) $produccion['id']]);
check(
    'el filtro por centro trae solo los imputados a ese centro',
    $delCentro['total'] === 1 && ($delCentro['rows'][0]['id'] ?? null) === $conCentro['id'],
    'total=' . $delCentro['total'],
    $failures, $checks
);

// ── (e)/(f) Reclasificar el histórico, incluido lo derivado ──────────────────

$saldoAntes = (float) (ncmExecute(
    'SELECT currentbalance FROM fin_account WHERE accountid = ?',
    [$accountId]
)['currentbalance'] ?? 0);

$reclasificado = $movements->reclassify((string) $sinCentro['id'], $companyId, [
    'costCenterId' => (string) $admin['id'],
]);
check(
    'reclasificar asigna el centro de costo a un movimiento que no lo tenía',
    $reclasificado['costCenterId'] === $admin['id']
        && $reclasificado['costCenterName'] === 'Administración',
    var_export($reclasificado, true),
    $failures, $checks
);
check(
    'reclasificar NO pisa la categoría cuando la clave no viene en el payload',
    $reclasificado['categoryId'] === $sinCentro['categoryId'],
    'antes=' . var_export($sinCentro['categoryId'], true)
        . ' después=' . var_export($reclasificado['categoryId'], true),
    $failures, $checks
);

$saldoDespues = (float) (ncmExecute(
    'SELECT currentbalance FROM fin_account WHERE accountid = ?',
    [$accountId]
)['currentbalance'] ?? 0);
check(
    'reclasificar NO mueve el saldo de la cuenta (es metadata pura)',
    near($saldoAntes, $saldoDespues),
    "antes=$saldoAntes después=$saldoDespues",
    $failures, $checks
);

// El caso principal: una COMPRA. `void()` la rechaza ("anulala desde su
// origen") pero reclasificarla tiene que funcionar — es el grueso del gasto de
// un comercio y nace sin centro.
$derived = $movements->recordDerivedMovement($companyId, 'purchase', 'c057ce47-0000-4000-8000-0000000001aa', [
    'accountId'   => $accountId,
    'categoryId'  => (string) $alquiler['id'],
    'kind'        => 'expense',
    'amount'      => 500000,
    'date'        => $day,
    'description' => 'Compra de prueba',
]);
check(
    'un movimiento derivado se registra sin centro de costo',
    $derived['inserted'] === true && $derived['movementId'] !== null,
    var_export($derived, true),
    $failures, $checks
);

$err = expectThrow(fn() => $movements->void((string) $derived['movementId'], $companyId));
check(
    'un derivado NO se puede anular desde Finanzas (contrato previo, sin cambios)',
    $err !== null && stripos($err, 'origen') !== false,
    'error=' . var_export($err, true),
    $failures, $checks
);

$derivedReclass = $movements->reclassify((string) $derived['movementId'], $companyId, [
    'costCenterId' => (string) $produccion['id'],
]);
check(
    'un derivado SÍ se puede reclasificar (es el caso principal: compras y gastos de caja)',
    $derivedReclass['costCenterId'] === $produccion['id'],
    var_export($derivedReclass, true),
    $failures, $checks
);

// ── (g) Centro archivado: no recibe nada nuevo, conserva lo viejo ────────────

$costCenters->archive((string) $admin['id'], $companyId);

$err = expectThrow(fn() => $movements->create($companyId, [
    'accountId'    => $accountId,
    'costCenterId' => (string) $admin['id'],
    'kind'         => 'expense',
    'amount'       => 1000,
    'date'         => $day,
]));
check(
    'un centro ARCHIVADO no recibe imputaciones nuevas',
    $err !== null && stripos($err, 'archivado') !== false,
    'error=' . var_export($err, true),
    $failures, $checks
);

$conservado = $movements->find((string) $sinCentro['id'], $companyId);
check(
    'archivar un centro NO reescribe el histórico ya imputado a él',
    $conservado !== null
        && $conservado['costCenterId'] === $admin['id']
        && $conservado['costCenterName'] === 'Administración',
    var_export($conservado, true),
    $failures, $checks
);

check(
    'un centro archivado desaparece del listado que alimenta los selectores',
    !in_array($admin['id'], array_column($costCenters->list($companyId), 'id'), true),
    'sigue en la lista',
    $failures, $checks
);

// ── (h) El reporte por centro cuadra ─────────────────────────────────────────

// Un movimiento más, sin centro, para que la fila "Sin centro de costo" tenga
// contenido después de que todo lo demás quedó clasificado.
$movements->create($companyId, [
    'accountId'   => $accountId,
    'kind'        => 'expense',
    'amount'      => 70000,
    'date'        => $day,
    'description' => 'Gasto suelto',
]);

$reporte = $movements->totalsByCostCenter($companyId, "$day 00:00:00", "$day 23:59:59");
$porNombre = [];
foreach ($reporte as $fila) {
    $porNombre[$fila['name']] = $fila;
}

check(
    'el reporte agrupa por centro: Producción suma el gasto propio + la compra reclasificada',
    isset($porNombre['Producción']) && near((float) $porNombre['Producción']['expense'], 800000.0),
    var_export($porNombre['Producción'] ?? null, true),
    $failures, $checks
);
check(
    'el reporte incluye los centros ARCHIVADOS que tienen histórico',
    isset($porNombre['Administración']) && near((float) $porNombre['Administración']['expense'], 100000.0),
    var_export($porNombre['Administración'] ?? null, true),
    $failures, $checks
);
check(
    'el reporte trae una fila "Sin centro de costo" con lo no clasificado, y va al final',
    isset($porNombre['Sin centro de costo'])
        && $porNombre['Sin centro de costo']['id'] === null
        && near((float) $porNombre['Sin centro de costo']['expense'], 70000.0)
        && end($reporte)['name'] === 'Sin centro de costo',
    var_export($reporte, true),
    $failures, $checks
);

$totalReporte = 0.0;
foreach ($reporte as $fila) {
    $totalReporte += (float) $fila['expense'];
}
$totalLedger = (float) (ncmExecute(
    "SELECT COALESCE(SUM(amount), 0) AS total FROM fin_movement
      WHERE companyid = ? AND status = 1 AND kind = 'expense'",
    [$companyId]
)['total'] ?? 0);
check(
    'el reporte por centro no pierde ni duplica plata: suma exactamente el ledger',
    near($totalReporte, $totalLedger),
    "reporte=$totalReporte ledger=$totalLedger",
    $failures, $checks
);

// Un movimiento ANULADO no se reclasifica: ya no cuenta en ningún reporte, así
// que tocarlo solo puede ser un error del operador.
$anulable = $movements->create($companyId, [
    'accountId'   => $accountId,
    'kind'        => 'expense',
    'amount'      => 5000,
    'date'        => $day,
    'description' => 'Para anular',
]);
$movements->void((string) $anulable['id'], $companyId);
$err = expectThrow(fn() => $movements->reclassify((string) $anulable['id'], $companyId, [
    'costCenterId' => (string) $produccion['id'],
]));
check(
    'un movimiento ANULADO no se puede reclasificar',
    $err !== null && stripos($err, 'anulado') !== false,
    'error=' . var_export($err, true),
    $failures, $checks
);

// ── (i) Colisión de la UNIQUE de la mig 153 al reclasificar ─────────────────
//
// Va DESPUÉS de los checks del reporte a propósito: agrega movimientos y
// movería los totales que (h) verifica contra números exactos.
//
// Una compra dividida por categoría deja VARIAS filas con el mismo
// (companyid, source, sourceid, accountid) que difieren SOLO en `categoryid` —
// que es justo la clave de `uidx_fin_movement_source` (mig 153). Mover una
// porción a la categoría de otra del mismo comprobante levanta un 23505.
//
// El 23505 tiene que llegar como \RuntimeException, para que el endpoint lo
// convierta en 422 con mensaje útil. Sin el guard sale como DbQueryException,
// que extiende \Exception y NO \RuntimeException: ATRAVIESA el
// `catch (\RuntimeException)` de `api/v1/finance/movements.php` y el operador
// ve un 500 genérico ante algo perfectamente explicable. No hay corrupción
// posible —el índice es el que frena— pero el mensaje importa.

$splitSourceId = 'c057ce47-0000-4000-8000-0000000001bb';
$porcionA = $movements->recordDerivedMovement($companyId, 'purchase', $splitSourceId, [
    'accountId'   => $accountId,
    'categoryId'  => (string) $alquiler['id'],
    'kind'        => 'expense',
    'amount'      => 60000,
    'date'        => $day,
    'description' => 'Compra dividida — porción Alquiler',
]);
$porcionB = $movements->recordDerivedMovement($companyId, 'purchase', $splitSourceId, [
    'accountId'   => $accountId,
    'categoryId'  => (string) $otraCat['id'],
    'kind'        => 'expense',
    'amount'      => 40000,
    'date'        => $day,
    'description' => 'Compra dividida — porción Limpieza',
]);
check(
    'una compra dividida por categoría genera DOS filas del mismo origen',
    $porcionA['inserted'] === true && $porcionB['inserted'] === true
        && $porcionA['movementId'] !== $porcionB['movementId'],
    'A=' . var_export($porcionA['movementId'] ?? null, true)
        . ' B=' . var_export($porcionB['movementId'] ?? null, true),
    $failures, $checks
);

$err = expectThrow(fn() => $movements->reclassify((string) $porcionB['movementId'], $companyId, [
    'categoryId' => (string) $alquiler['id'],
]));
check(
    'reclasificar una porción sobre la categoría de otra da un error legible, no un 500',
    $err !== null && stripos($err, 'porción') !== false,
    'error=' . var_export($err, true),
    $failures, $checks
);

// Reclasificar el CENTRO de una porción NO colisiona: `costcenterid` quedó
// FUERA de la clave del índice justamente para que esto funcione.
$sinChoque = $movements->reclassify((string) $porcionB['movementId'], $companyId, [
    'costCenterId' => (string) $produccion['id'],
]);
check(
    'cambiar el CENTRO de una porción no colisiona (costcenterid no está en la clave)',
    $sinChoque['costCenterId'] === $produccion['id'],
    var_export($sinChoque['costCenterId'] ?? null, true),
    $failures, $checks
);

// ── Limpieza ────────────────────────────────────────────────────────────────
resetFixture($companyId, $accountId);

echo "\n";
harnessFinish($failures, $checks);

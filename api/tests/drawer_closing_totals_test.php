<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arqueo devuelto al cerrar la caja — la mitad SERVIDOR del total offline del
 * turno (context/51 §4).
 *
 * Qué se está probando y por qué necesita Postgres real
 * ────────────────────────────────────────────────────
 * Sin conexión, el POS muestra el total de lo que ESE dispositivo registró
 * (`local-shift-total.ts`) y el cajero cierra con el efectivo que contó. Horas
 * después la cola drena y el servidor calcula el arqueo REAL. Si esos dos
 * números difieren, el cajero tiene que enterarse — y para eso el cierre ahora
 * devuelve `closing`, los totales del turno tal como quedaron.
 *
 * Nada de eso se puede afirmar con un mock: lo que se verifica es que el
 * camino liviano (`getClosingTotals`, cuatro queries) dé EXACTAMENTE el mismo
 * dinero que el composite completo (`getSummary`, que además trae productos y
 * estadísticas), y que un movimiento de caja hecho por fuera del dispositivo
 * mueva ese número. Eso son sumas del motor sobre `transaction`, `expenses` y
 * `drawer`, con el filtro de sesión de caja (drawerId + fallback por fecha,
 * mig 70) en el medio.
 *
 * Casos:
 *   A. Turno con ventas: `getClosingTotals()` devuelve total, efectivo y
 *      ventas con la fórmula de `composeSummary` (inicial + ventas, y el
 *      efectivo sin la tarjeta).
 *   B. Coincide con `getSummary()` peso por peso. Es la razón de existir del
 *      método liviano: ahorra dos queries, no cambia la plata.
 *   C. Una EXTRACCIÓN hecha desde el panel —el hueco que el dispositivo offline
 *      no puede ver— baja el total del servidor. Es exactamente la diferencia
 *      que el POS tiene que mostrarle al cajero al sincronizar el cierre.
 *   D. Con la caja YA cerrada devuelve null. Es lo que obliga a leerlo ANTES
 *      del `close()` en `drawer.php`: después no hay turno abierto que sumar,
 *      y un reenvío idempotente del cierre no informa totales en vez de
 *      informar ceros.
 *
 * Uso (necesita Postgres migrado + seed.sql de verify_chain cargado — ver
 * `run_drawer_closing_totals_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/drawer_closing_totals_test.php
 */

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

// Las constantes del contexto se definen ANTES del bootstrap: es lo que hace
// `api/data.php` en un request real, y `getSalesByPayment()` (legacy) las lee
// para el fence multi-tenant.
define('COMPANY_ID', $companyId);
define('OUTLET_ID', $outletId);
define('USER_ID', $userId);
define('REGISTER_ID', $registerId);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/services/DrawerService.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Services\DrawerService;

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

/** Comparación de plata: dos decimales, sin ruido de punto flotante. */
function money(float $a, float $b): bool
{
    return abs($a - $b) < 0.005;
}

// El turno de prueba arranca en el pasado cercano para no chocar con datos del
// seed ni con un turno real. La fecha es naive tenant-local, igual que en
// producción (TenantClock).
$openDate = date('Y-m-d H:i:s', strtotime('-6 hours'));
$saleDate = date('Y-m-d H:i:s', strtotime('-5 hours'));
$moveDate = date('Y-m-d H:i:s', strtotime('-4 hours'));
$closeDate = date('Y-m-d H:i:s', strtotime('-1 hour'));

$uidPrefix = 'harness-closing-' . getmypid();

function cleanup(string $registerId, string $uidPrefix): void
{
    ncmExecute('DELETE FROM "transaction" WHERE registerid = ? AND transactionuid LIKE ?', [$registerId, $uidPrefix . '%']);
    ncmExecute('DELETE FROM expenses WHERE registerid = ? AND expensesdescription LIKE ?', [$registerId, 'harness-closing%']);
    ncmExecute('DELETE FROM drawer WHERE registerid = ?', [$registerId]);
}

/** Inserta una venta contado del turno con su desglose de medios de pago. */
function insertSale(
    string $uid,
    string $date,
    array $payments,
    string $drawerId,
    string $registerId,
    string $outletId,
    string $companyId,
    string $userId,
): void {
    $total = 0.0;
    foreach ($payments as $p) {
        $total += (float) $p['total'];
    }
    ncmExecute(
        'INSERT INTO "transaction"
            (transactiondate, transactiondiscount, transactiontax, transactiontotal,
             transactionpaymenttype, transactiontype, transactionuid,
             drawerid, registerid, userid, outletid, companyid)
         VALUES (?, 0, 0, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
        [
            $date,
            $total,
            json_encode($payments),
            $uid,
            $drawerId,
            $registerId,
            $userId,
            $outletId,
            $companyId,
        ]
    );
}

cleanup($registerId, $uidPrefix);

$ctx = new TenantContext(
    companyId: $companyId,
    outletId: $outletId,
    userId: $userId,
    registerId: $registerId,
    roleId: '',
);
$svc = new DrawerService($ctx);

// ── Turno: apertura de 100.000 ───────────────────────────────────────────────
$opened = $svc->open(100000.0, $openDate, $userId);
check(
    'S1 el turno de prueba abrió',
    $opened === true,
    'open() devolvió ' . var_export($opened, true),
    $failures
);

$open = $svc->getOpen($registerId, $outletId, $companyId);
$drawerId = (string) ($open['drawerId'] ?? '');
check('S2 hay un drawer abierto con id', $drawerId !== '', 'drawerId=' . $drawerId, $failures);

// Dos ventas: una en efectivo, otra mixta. Total vendido 100.000, de los cuales
// 80.000 en efectivo.
insertSale(
    $uidPrefix . '-1',
    $saleDate,
    [['name' => 'Efectivo', 'type' => 'efectivo', 'total' => 50000, 'price' => 50000]],
    $drawerId, $registerId, $outletId, $companyId, $userId
);
insertSale(
    $uidPrefix . '-2',
    $saleDate,
    [
        ['name' => 'Efectivo', 'type' => 'efectivo', 'total' => 30000, 'price' => 30000],
        ['name' => 'T. Débito', 'type' => 'tdebito', 'total' => 20000, 'price' => 20000],
    ],
    $drawerId, $registerId, $outletId, $companyId, $userId
);

// ── A. Los totales del cierre ────────────────────────────────────────────────
$closing = $svc->getClosingTotals($registerId, $outletId, $companyId);
check('A1 devuelve totales con la caja abierta', is_array($closing), 'closing=' . var_export($closing, true), $failures);
check(
    'A2 total = inicial + ventas',
    is_array($closing) && money((float) $closing['total'], 200000.0),
    'total=' . ($closing['total'] ?? 'null') . ' esperado=200000',
    $failures
);
check(
    'A3 efectivo = inicial + ventas en efectivo (sin la tarjeta)',
    is_array($closing) && money((float) $closing['subtotal'], 180000.0),
    'subtotal=' . ($closing['subtotal'] ?? 'null') . ' esperado=180000',
    $failures
);
check(
    'A4 salesTotal = solo lo vendido',
    is_array($closing) && money((float) $closing['salesTotal'], 100000.0),
    'salesTotal=' . ($closing['salesTotal'] ?? 'null') . ' esperado=100000',
    $failures
);

// ── B. Misma plata que el composite completo ────────────────────────────────
$summary = $svc->getSummary($registerId, $outletId, $companyId);
check(
    'B1 el camino liviano da el mismo total que getSummary()',
    is_array($summary) && is_array($closing) && money((float) $summary['total'], (float) $closing['total']),
    'summary=' . ($summary['total'] ?? 'null') . ' closing=' . ($closing['total'] ?? 'null'),
    $failures
);
check(
    'B2 y el mismo efectivo',
    is_array($summary) && is_array($closing) && money((float) $summary['subtotal'], (float) $closing['subtotal']),
    'summary=' . ($summary['subtotal'] ?? 'null') . ' closing=' . ($closing['subtotal'] ?? 'null'),
    $failures
);
check(
    'B3 y la misma fecha de apertura',
    is_array($summary) && is_array($closing) && (string) $summary['date'] === (string) $closing['date'],
    'summary=' . ($summary['date'] ?? 'null') . ' closing=' . ($closing['date'] ?? 'null'),
    $failures
);

// ── C. El hueco que el dispositivo offline no puede ver ─────────────────────
// Una extracción hecha desde el panel mientras la caja estaba sin red. El POS
// nunca la vio, así que su total local sigue diciendo 200.000 — y el servidor,
// 175.000. Esa diferencia es justamente lo que el cierre tiene que informar.
$svc->addExpense(25000.0, 'harness-closing extraccion desde el panel', $moveDate);
$closingAfterExpense = $svc->getClosingTotals($registerId, $outletId, $companyId);
check(
    'C1 la extracción del panel baja el total del servidor',
    is_array($closingAfterExpense) && money((float) $closingAfterExpense['total'], 175000.0),
    'total=' . ($closingAfterExpense['total'] ?? 'null') . ' esperado=175000',
    $failures
);
check(
    'C2 y baja también el efectivo esperado en el cajón',
    is_array($closingAfterExpense) && money((float) $closingAfterExpense['subtotal'], 155000.0),
    'subtotal=' . ($closingAfterExpense['subtotal'] ?? 'null') . ' esperado=155000',
    $failures
);
check(
    'C3 la diferencia contra el total local es exactamente la extracción',
    is_array($closing) && is_array($closingAfterExpense)
        && money((float) $closing['total'] - (float) $closingAfterExpense['total'], 25000.0),
    'diferencia=' . ((float) ($closing['total'] ?? 0) - (float) ($closingAfterExpense['total'] ?? 0)),
    $failures
);

// ── D. Después del cierre no hay nada que informar ──────────────────────────
$closed = $svc->close(155000.0, $closeDate, $userId);
check('D1 la caja cerró', $closed === true, 'close() devolvió ' . var_export($closed, true), $failures);
check(
    'D2 con la caja cerrada getClosingTotals() devuelve null',
    $svc->getClosingTotals($registerId, $outletId, $companyId) === null,
    'devolvió algo distinto de null — leerlo DESPUÉS del cierre daría el arqueo equivocado',
    $failures
);

// ── Limpieza ─────────────────────────────────────────────────────────────────
cleanup($registerId, $uidPrefix);

echo "\n";
harnessFinish($failures, $checks);

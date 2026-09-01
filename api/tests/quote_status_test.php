<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (DB real) del ESTADO DE UNA COTIZACIÓN.
 *
 * ── El bug (reporte del tester, 2026-08-28) ─────────────────────────────────
 * En Ventas > Transacciones > Cotizaciones la columna "Estado" mostraba un «1»
 * pelado dentro de un Badge, y el pedido era ver si el presupuesto sigue
 * pendiente o ya se aprobó. Eran dos cosas:
 *
 *  1. El reporte devolvía `transactionStatus` crudo — el entero del motor de
 *     transacciones (1=activa, 6=anulada) — y el front esperaba los strings
 *     "activa"/"vencida", que nunca llegaban.
 *  2. No había CÓMO saber si una cotización se facturó. La mig 115 backfilleó
 *     los `transaction_link` kind='quote_to_sale' desde `transactionParentId` y
 *     después dropeó la columna, pero el writer que la reemplazara nunca se
 *     construyó (comentario textual en SaleService: "sub-slices futuros lo
 *     agregarán"). El front venía mandando `parentTransactionId` en el payload
 *     de la venta desde entonces y el backend lo descartaba.
 *
 * Este arnés cubre las dos mitades: que `SaleInput` ahora LEE ese campo, y que
 * `TransactionsService::quotes()` deriva los cuatro estados del ciclo de vida.
 *
 * Uso (necesita Postgres migrado — Docker, ver run_quote_status_test.sh):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/quote_status_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

$companyId = '90a7e5c0-0000-4000-8000-000000000101';
$outletId  = '90a7e5c0-0000-4000-8000-000000000102';
$customer  = '90a7e5c0-0000-4000-8000-000000000103';
$userId    = '90a7e5c0-0000-4000-8000-000000000104';
$register  = '90a7e5c0-0000-4000-8000-000000000105';

$qPend     = '90a7e5c0-0000-4000-8000-000000000106'; // pendiente
$qBilled   = '90a7e5c0-0000-4000-8000-000000000107'; // facturada
$qVoid     = '90a7e5c0-0000-4000-8000-000000000108'; // anulada
$qExpired  = '90a7e5c0-0000-4000-8000-000000000109'; // vencida
$sale      = '90a7e5c0-0000-4000-8000-00000000010a'; // la venta que nace de $qBilled

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletId);
define('USER_ID',    $userId);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Reports\Roc;
use Punto\Api\Reports\TransactionsService;
use Punto\Api\Sales\SaleInput;

/** @var \Punto\Api\Database\Query $db */
global $db;

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

$created = ['transaction_link' => [], 'transaction' => [], 'contact' => [], 'register' => [], 'outlet' => [], 'company' => []];

try {
    // ── Mitad 1: el DTO lee `parentTransactionId` ────────────────────────────
    // Sin esto no hay nada que enlazar: es el campo que el carrito manda desde
    // que la mig 115 dropeó `transactionParentId`, y que se descartaba.
    $payload = [
        'uid' => 'u-1',
        'transaction' => [
            'type' => 0, 'date' => date('Y-m-d H:i:s'), 'timestamp' => time(),
            'subtotal' => 1000, 'tax' => 0, 'discount' => 0,
            'sale' => [['itemId' => $customer, 'count' => 1, 'price' => 1000]],
            'payment' => [['name' => 'Efectivo', 'total' => 1000, 'type' => 'cash']],
            'parentTransactionId' => $qBilled,
        ],
    ];
    $in = SaleInput::fromPayload($payload, $companyId);
    check(
        'SaleInput lee parentTransactionId como quoteParentId',
        $in->quoteParentId === $qBilled,
        'quoteParentId = ' . var_export($in->quoteParentId, true),
        $failures, $checks
    );

    $payloadSinParent = $payload;
    unset($payloadSinParent['transaction']['parentTransactionId']);
    check(
        'una venta sin cotización de origen deja quoteParentId en null',
        SaleInput::fromPayload($payloadSinParent, $companyId)->quoteParentId === null,
        'esperaba null',
        $failures, $checks
    );

    $payloadBasura = $payload;
    $payloadBasura['transaction']['parentTransactionId'] = 'no-es-uuid';
    check(
        'un parentTransactionId con basura se descarta, no tira 422',
        SaleInput::fromPayload($payloadBasura, $companyId)->quoteParentId === null,
        'esperaba null (la venta nunca debe caerse por trazabilidad)',
        $failures, $checks
    );

    // ── Fixtures para la mitad 2 ─────────────────────────────────────────────
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Quote Status Test\"}'::jsonb)",
        [$companyId]
    );
    $created['company'][] = $companyId;

    $db->Execute(
        'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
        [$outletId, 'QS Sucursal', $companyId]
    );
    $created['outlet'][] = $outletId;

    $db->Execute(
        'INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId)
         VALUES (?, ?, TRUE, ?, ?)',
        [$register, 'QS Caja', $outletId, $companyId]
    );
    $created['register'][] = $register;

    // `type = 1` es el rol "cliente" que exige el filtro por nombre de quotes().
    foreach ([[$customer, 'QS Cliente', 1], [$userId, 'QS Usuario', 0]] as [$cid, $cname, $ctype]) {
        $db->Execute(
            'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
             VALUES (?, ?, ?, ?, ?, 1)',
            [$cid, $cname, $companyId, $outletId, $ctype]
        );
        $created['contact'][] = $cid;
    }

    // 4 cotizaciones (type 9), una por estado esperado.
    $quotes = [
        [$qPend,    1, null],
        [$qBilled,  1, null],
        [$qVoid,    6, null],
        [$qExpired, 1, date('Y-m-d H:i:s', strtotime('-10 days'))],
    ];
    foreach ($quotes as [$qid, $status, $due]) {
        $db->Execute(
            "INSERT INTO transaction
               (transactionId, companyId, outletId, registerId, userId, customerId, transactionType,
                transactionStatus, transactionComplete, transactionTotal, transactionDiscount,
                transactionDate, transactionDueDate)
             VALUES (?, ?, ?, ?, ?, ?, 9, ?, FALSE, 500000, 0, NOW(), ?)",
            [$qid, $companyId, $outletId, $register, $userId, $customer, $status, $due]
        );
        $created['transaction'][] = $qid;
    }

    // La venta que nace de $qBilled, y su vínculo — lo que ahora escribe
    // SaleService::save() cuando el payload trae parentTransactionId.
    $db->Execute(
        "INSERT INTO transaction
           (transactionId, companyId, outletId, registerId, userId, customerId, transactionType,
            transactionStatus, transactionComplete, transactionTotal, transactionDiscount, transactionDate)
         VALUES (?, ?, ?, ?, ?, ?, 0, 1, TRUE, 500000, 0, NOW())",
        [$sale, $companyId, $outletId, $register, $userId, $customer]
    );
    $created['transaction'][] = $sale;

    $db->Execute(
        "INSERT INTO transaction_link (companyId, originId, derivedId, kind)
         VALUES (?, ?, ?, 'quote_to_sale')",
        [$companyId, $qBilled, $sale]
    );
    $created['transaction_link'][] = $sale;

    // ── Mitad 2: el reporte deriva los 4 estados ─────────────────────────────
    $svc  = new TransactionsService();
    $roc  = Roc::build($companyId, $outletId);
    $res  = $svc->quotes(
        ['cusId' => '', 'singleRow' => '', 'src' => ''],
        date('Y-m-d 00:00:00', strtotime('-1 day')),
        date('Y-m-d 23:59:59'),
        $roc,
        $companyId
    );

    $byId = [];
    foreach ($res['rows'] ?? [] as $r) {
        $byId[(string) $r['transactionId']] = (string) ($r['quoteStatus'] ?? '');
    }

    check('las 4 cotizaciones salen en el reporte', count($byId) === 4, 'salieron ' . count($byId), $failures, $checks);

    foreach ([[$qPend, 'pendiente'], [$qBilled, 'facturada'], [$qVoid, 'anulada'], [$qExpired, 'vencida']] as [$qid, $esperado]) {
        check(
            "estado '$esperado'",
            ($byId[$qid] ?? '') === $esperado,
            'devolvió: ' . var_export($byId[$qid] ?? null, true),
            $failures, $checks
        );
    }

    // La venta NO es una cotización: no debe aparecer en esta vista.
    check(
        'la venta derivada no se cuela en el listado de cotizaciones',
        !isset($byId[$sale]),
        'la venta apareció como cotización',
        $failures, $checks
    );
} finally {
    foreach ($created['transaction_link'] as $id) {
        $db->Execute('DELETE FROM transaction_link WHERE derivedId = ?', [$id]);
    }
    foreach ($created['transaction'] as $id) {
        $db->Execute('DELETE FROM transaction WHERE transactionId = ?', [$id]);
    }
    foreach ($created['contact'] as $id) {
        $db->Execute('DELETE FROM contact WHERE contactId = ?', [$id]);
    }
    foreach ($created['register'] as $id) {
        $db->Execute('DELETE FROM register WHERE registerId = ?', [$id]);
    }
    foreach ($created['outlet'] as $id) {
        $db->Execute('DELETE FROM outlet WHERE outletId = ?', [$id]);
    }
    foreach ($created['company'] as $id) {
        $db->Execute('DELETE FROM company WHERE companyId = ?', [$id]);
    }
}

harnessFinish($failures, $checks);

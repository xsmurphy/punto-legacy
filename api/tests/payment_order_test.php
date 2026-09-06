<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la ORDEN DE PAGO A PROVEEDOR (migs 196/197).
 *
 * Corre contra Postgres real, con sesiones de panel reales y el endpoint real
 * en subproceso. Lo que verifica no se puede verificar leyendo el código:
 *
 *   (A) Aprobar sin `purchases.paymentorder.approve` da 403 — y ese rol SÍ
 *       puede crear. Es la separación de tareas, no un gate genérico.
 *   (B) Con `settingPaymentOrderRequireSecondApprover` PRENDIDO, el creador no
 *       puede aprobar su propia orden aunque tenga la clave.
 *   (C) Con el flag APAGADO (el default), el mismo usuario crea y aprueba. La
 *       feature nace sin fricción — ningún comercio se rompe el día del deploy.
 *   (D) Una factura ya incluida en otra orden VIVA se rechaza.
 *   (E) Imputar más que el saldo pendiente se rechaza — al crear Y al aprobar,
 *       porque el saldo cambia entre una cosa y la otra.
 *   (F) EL PUNTO DE LA FEATURE: ejecutar produce el recibo por
 *       `CreditPaymentService` (type=5, kind purchase_payment, con sus
 *       `transaction_link`) y deja la factura saldada. Si esto pasa por otro
 *       camino, la orden de pago es un duplicado del módulo de pagos.
 *   (G) Una orden pagada no se puede editar ni cancelar.
 *   (H) Cancelar exige motivo, lo registra con su autor, y LIBERA las facturas
 *       para otra orden.
 *
 * El caso que más importa es (F). Es el único que no se ve en la respuesta
 * HTTP: el endpoint devolvería 200 igual si la orden insertara su propio
 * recibo a mano, y la divergencia recién aparecería meses después, cuando el
 * reporte de cuentas por pagar y Finanzas no cuadren. Por eso se verifica
 * contra `transaction`/`transaction_link` directamente, no contra el JSON.
 *
 * Reusa el tenant fixture "Verify PY" (`api/lib/Sales/verify_chain/seed.sql`).
 * El PROVEEDOR y las FACTURAS de compra son propios de este arnés.
 *
 * Uso (ver `run_payment_order_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/payment_order_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
// RoleService NO tiene namespace (clase global, ver su encabezado) — por eso
// se requiere pero no se importa.
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/services/TransactionLinkService.php';

use Punto\Api\Services\TransactionLinkService;

// ── Tenant fixture "Verify PY" ─────────────────────────────────────────────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

const AGENTE_DEL_ARNES = 'payment-order-test';
/** Proveedor propio del arnés — no toca los contactos del seed. */
const SUPPLIER_ID = 'aa11bb22-cc33-4d44-8e55-ff6677889900';

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

// ═══════════════════════════════════════════════════════════════════════════
// Fixtures
// ═══════════════════════════════════════════════════════════════════════════

// Proveedor (contact type=2). Idempotente — el arnés se puede recorrer varias
// veces contra la misma base.
//
// `contactSecondName` va en el JSONB `data`, no como columna: la mig 25 la
// degradó ahí (junto con dirección/nota/ciudad/CI). Se puebla a propósito para
// que el listado ejercite la regla de `ContactDisplayName` —nombre de la
// persona sobre razón social— y no solo el fallback.
ncmExecute(
    "INSERT INTO contact (contactId, contactName, data, contactPhone, contactStatus, type, main, role, outletId, companyId)
     VALUES (?::uuid, 'Proveedor Arnes OP SA', '{\"contactSecondName\":\"Proveedor Arnes OP\"}'::jsonb,
             '+595991000777', 1, 2, '', 0, ?::uuid, ?::uuid)
     ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName, data = EXCLUDED.data",
    [SUPPLIER_ID, $outletId, $companyId],
    true
);

/**
 * Inserta una COMPRA a crédito (type=4) mínima, propia de este arnés.
 * `transactionComplete=false` + `transactionType=4` es el contrato completo de
 * "cuenta por pagar abierta" (ver `context/modules/08-compras.md` regla 1).
 * `registerId` va NULL a propósito: `PurchasesService` nunca lo setea — las
 * compras se cargan desde backoffice, sin caja.
 */
function makePurchase(string $companyId, string $outletId, string $userId, float $total): string
{
    global $db;
    $db->AutoExecute('transaction', [
        'transactionTotal'    => $total,
        'transactionDiscount' => 0,
        'transactionType'     => 4,
        'transactionComplete' => false,
        'transactionStatus'   => 1,
        'transactionDate'     => date('Y-m-d H:i:s'),
        'transactionDueDate'  => date('Y-m-d H:i:s', strtotime('+15 days')),
        'invoiceNo'           => random_int(1000000, 9999999),
        'timestamp'           => time(),
        'supplierId'          => SUPPLIER_ID,
        'userId'              => $userId,
        'responsibleId'       => $userId,
        'outletId'            => $outletId,
        'companyId'           => $companyId,
    ], 'INSERT');
    return (string) $db->Insert_ID();
}

// ── Roles ──────────────────────────────────────────────────────────────────
RoleService::seedCompanyRoles($companyId);

/** Borra y recrea un rol custom con exactamente $perms. */
function makeRole(string $name, array $perms, string $companyId, string $creator): string
{
    $row = ncmExecute(
        "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND LOWER(taxonomyname)=LOWER(?)",
        [$companyId, $name]
    );
    if ($row && !empty($row['taxonomyid'])) {
        $id = (string) $row['taxonomyid'];
        RoleService::updateRole($id, null, $perms, $companyId);
        return $id;
    }
    return RoleService::createRole($name, $perms, $companyId, $creator);
}

// El rol que ARMA la orden pero no la autoriza. Es exactamente el rol para el
// que existe esta feature: puede proponer el pago, no decidirlo.
$rolePreparador = makeRole('op-test-preparador', [
    'purchases.paymentorder.view',
    'purchases.paymentorder.create',
    'finance.manage',
], $companyId, $adminId);

// El rol que autoriza Y ejecuta (execute pide approve + finance.manage).
$roleAprobador = makeRole('op-test-aprobador', [
    'purchases.paymentorder.view',
    'purchases.paymentorder.create',
    'purchases.paymentorder.approve',
    'finance.manage',
], $companyId, $adminId);

function panelSession(string $roleId, string $companyId, string $outletId, string $userId): string
{
    return authSessionCreate('panel', [
        'companyId' => $companyId,
        'userId'    => $userId,
        'outletId'  => $outletId,
        'roleId'    => $roleId,
        'expiresAt' => date('Y-m-d H:i:s', time() + 3600),
        'userAgent' => AGENTE_DEL_ARNES,
    ]);
}

$tokPreparador = panelSession($rolePreparador, $companyId, $outletId, $adminId);
$tokAprobador  = panelSession($roleAprobador,  $companyId, $outletId, $adminId);

/**
 * Llama al endpoint real en subproceso. Subproceso porque `apiError()` hace
 * `exit`: un 403 dentro del proceso del arnés lo mataría entero.
 *
 * @return array{status:int, data:mixed, message:string}
 */
function hit(string $method, string $query, array $body, string $panelToken): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        'v1/payment-orders.php', $method, $query,
        json_encode($body), $panelToken, '', '', '',
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'data' => null, 'message' => 'no se pudo abrir el subproceso'];
    }
    fwrite($pipes[0], json_encode($body));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // El status sale del ENVELOPE canónico: bajo SAPI cli http_response_code()
    // no devuelve de forma confiable lo que seteó apiError().
    $status = 0; $data = null; $message = '';
    if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            $status  = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
            $data    = $env['data'] ?? null;
            $message = (string) ($env['error']['message'] ?? '');
        }
    }
    return ['status' => $status, 'data' => $data, 'message' => $message];
}

/** Prende o apaga el flag del segundo aprobador en `company.config`. */
function setSecondApproverFlag(string $companyId, bool $on): void
{
    ncmExecute(
        "UPDATE company
            SET config = COALESCE(config, '{}'::jsonb)
                      || jsonb_build_object('settingPaymentOrderRequireSecondApprover', ?::text)
          WHERE companyId = ?::uuid",
        [$on ? '1' : '0', $companyId],
        true
    );
}

/**
 * Estado persistido de una orden.
 *
 * `iterator_to_array` y no `is_array($r) ? $r : []`: `ncmExecute()` devuelve un
 * `CaseInsensitiveArray` (wrapper de DB del proyecto), que implementa
 * ArrayAccess e IteratorAggregate pero NO pasa `is_array()`. Con el chequeo
 * ingenuo este helper devolvía `[]` SIEMPRE y las aserciones de estado daban
 * rojo por el motivo equivocado.
 */
function orderRow(string $id): array
{
    $r = ncmExecute('SELECT * FROM payment_order WHERE paymentorderid = ?::uuid', [$id]);
    if ($r instanceof Traversable) {
        return iterator_to_array($r);
    }
    return is_array($r) ? $r : [];
}

setSecondApproverFlag($companyId, false);

// ═══════════════════════════════════════════════════════════════════════════
// (A) Aprobar sin el permiso → 403 (pero crear sí puede)
// ═══════════════════════════════════════════════════════════════════════════
$inv1 = makePurchase($companyId, $outletId, $adminId, 100000.0);

$r = hit('POST', '', [
    'action'     => 'create',
    'supplierId' => SUPPLIER_ID,
    'outletId'   => $outletId,
    'lines'      => [['transactionId' => $inv1, 'amount' => 100000.0]],
], $tokPreparador);
check('(A) el preparador SÍ puede crear la orden', $r['status'] === 200,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);
$orderA = (string) ($r['data']['paymentOrderId'] ?? '');

check('(A) la orden nace con correlativo propio (context/37)',
    (int) ($r['data']['docNumber'] ?? 0) >= 1,
    'docNumber=' . json_encode($r['data']['docNumber'] ?? null), $failures, $checks);

$r = hit('POST', '', ['action' => 'approve', 'id' => $orderA], $tokPreparador);
check('(A) aprobar SIN purchases.paymentorder.approve → 403', $r['status'] === 403,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);
check('(A) el 403 nombra la clave que falta',
    str_contains($r['message'], 'purchases.paymentorder.approve'),
    'msg=' . $r['message'], $failures, $checks);
check('(A) la orden sigue en borrador tras el 403',
    (string) (orderRow($orderA)['status'] ?? '') === 'draft',
    'status=' . json_encode(orderRow($orderA)['status'] ?? null), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (B) Flag PRENDIDO: el creador no aprueba su propia orden
// ═══════════════════════════════════════════════════════════════════════════
setSecondApproverFlag($companyId, true);

$inv2 = makePurchase($companyId, $outletId, $adminId, 50000.0);
$r = hit('POST', '', [
    'action'     => 'create',
    'supplierId' => SUPPLIER_ID,
    'outletId'   => $outletId,
    'lines'      => [['transactionId' => $inv2, 'amount' => 50000.0]],
], $tokAprobador);
$orderB = (string) ($r['data']['paymentOrderId'] ?? '');
check('(B) setup: el aprobador crea una orden', $r['status'] === 200,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

// Mismo usuario, y CON la clave `approve`: lo único que lo frena es el flag.
$r = hit('POST', '', ['action' => 'approve', 'id' => $orderB], $tokAprobador);
check('(B) con el flag prendido, el creador NO aprueba su orden (403)', $r['status'] === 403,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);
check('(B) el 403 explica que hace falta otra persona',
    str_contains(mb_strtolower($r['message']), 'distinto de quien la creó'),
    'msg=' . $r['message'], $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (C) Flag APAGADO (default): el mismo usuario crea y aprueba
// ═══════════════════════════════════════════════════════════════════════════
setSecondApproverFlag($companyId, false);

$r = hit('POST', '', ['action' => 'approve', 'id' => $orderB], $tokAprobador);
check('(C) con el flag apagado, el creador SÍ aprueba su propia orden', $r['status'] === 200,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

$rowB = orderRow($orderB);
check('(C) queda registrado quién aprobó y cuándo',
    (string) ($rowB['status'] ?? '') === 'approved'
        && (string) ($rowB['approvedby'] ?? '') === $adminId
        && !empty($rowB['approved_at']),
    'row=' . json_encode([$rowB['status'] ?? null, $rowB['approvedby'] ?? null, $rowB['approved_at'] ?? null]),
    $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (D) Una factura ya incluida en otra orden VIVA se rechaza
// ═══════════════════════════════════════════════════════════════════════════
// $inv1 está en $orderA (draft) y $inv2 en $orderB (approved) — los dos
// estados "vivos". Se prueban los dos, porque son las dos ramas del predicado
// del índice único parcial.
$r = hit('POST', '', [
    'action'     => 'create',
    'supplierId' => SUPPLIER_ID,
    'outletId'   => $outletId,
    'lines'      => [['transactionId' => $inv1, 'amount' => 1000.0]],
], $tokAprobador);
check('(D) factura tomada por una orden en BORRADOR → rechazada', $r['status'] === 422,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);
check('(D) el error dice que ya está en otra orden',
    str_contains(mb_strtolower($r['message']), 'ya está incluida en otra orden'),
    'msg=' . $r['message'], $failures, $checks);

$r = hit('POST', '', [
    'action'     => 'create',
    'supplierId' => SUPPLIER_ID,
    'outletId'   => $outletId,
    'lines'      => [['transactionId' => $inv2, 'amount' => 1000.0]],
], $tokAprobador);
check('(D) factura tomada por una orden APROBADA → rechazada', $r['status'] === 422,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (E) Imputar más que el saldo se rechaza
// ═══════════════════════════════════════════════════════════════════════════
$inv3 = makePurchase($companyId, $outletId, $adminId, 30000.0);
$r = hit('POST', '', [
    'action'     => 'create',
    'supplierId' => SUPPLIER_ID,
    'outletId'   => $outletId,
    'lines'      => [['transactionId' => $inv3, 'amount' => 30000.01]],
], $tokAprobador);
check('(E) imputar más que el saldo pendiente → 422', $r['status'] === 422,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);
check('(E) el error nombra el saldo real',
    str_contains(mb_strtolower($r['message']), 'supera su saldo pendiente'),
    'msg=' . $r['message'], $failures, $checks);

// El mismo monto EXACTO sí entra (el límite es el saldo, no algo más chico).
$r = hit('POST', '', [
    'action'     => 'create',
    'supplierId' => SUPPLIER_ID,
    'outletId'   => $outletId,
    'lines'      => [['transactionId' => $inv3, 'amount' => 30000.0]],
], $tokAprobador);
check('(E) imputar exactamente el saldo SÍ entra', $r['status'] === 200,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);
$orderE = (string) ($r['data']['paymentOrderId'] ?? '');

// ── (E2) La revalidación AL APROBAR, que es la que justifica el diseño ─────
// Se paga la factura POR OTRO LADO (el camino que ya existe: un recibo directo
// al proveedor) DESPUÉS de armar la orden. Aprobar tiene que rechazar: si solo
// se validara al crear, se aprobaría un pago por plata que ya no se debe.
require_once dirname(__DIR__) . '/lib/services/CreditPaymentService.php';
(new \Punto\Api\Services\CreditPaymentService())->create(
    $companyId, $adminId,
    [['parentTransactionId' => $inv3, 'amount' => 30000.0]],
    'efectivo', null, null, false
);
$r = hit('POST', '', ['action' => 'approve', 'id' => $orderE], $tokAprobador);
check('(E2) aprobar revalida el saldo: la factura ya se pagó por otro lado → rechazo',
    $r['status'] === 422,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);
check('(E2) la orden NO quedó aprobada',
    (string) (orderRow($orderE)['status'] ?? '') === 'draft',
    'status=' . json_encode(orderRow($orderE)['status'] ?? null), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (F) EJECUTAR pasa por CreditPaymentService y salda la factura
// ═══════════════════════════════════════════════════════════════════════════
$links = new TransactionLinkService();

$r = hit('POST', '', [
    'action'           => 'execute',
    'id'               => $orderB,
    'paymentMethodKey' => 'efectivo',
], $tokAprobador);
check('(F) ejecutar una orden aprobada → 200', $r['status'] === 200,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

$paymentId = (string) ($r['data']['paymentTransactionId'] ?? '');
check('(F) devuelve el id del recibo que ejecutó la orden', $paymentId !== '',
    'data=' . json_encode($r['data']), $failures, $checks);

// El recibo REAL, leído de la tabla — no del JSON de respuesta. Type 5 y
// supplierId poblado es la firma de `CreditPaymentService` en modo proveedor.
$rec = $paymentId !== ''
    ? ncmExecute('SELECT * FROM transaction WHERE transactionId = ?::uuid AND companyId = ?::uuid', [$paymentId, $companyId])
    : null;
check('(F) el recibo es un type=5 de PROVEEDOR (lo produjo CreditPaymentService)',
    is_array($rec)
        && (string) ($rec['transactionType'] ?? '') === '5'
        && (string) ($rec['supplierId'] ?? '') === SUPPLIER_ID
        && empty($rec['customerId']),
    'rec=' . json_encode(is_array($rec) ? [$rec['transactionType'] ?? null, $rec['supplierId'] ?? null] : null),
    $failures, $checks);

check('(F) la imputación quedó en transaction_link con el monto de la línea',
    abs($links->sumDerivedAmounts($companyId, $inv2, 'purchase_payment') - 50000.0) < 0.01,
    'sumDerivedAmounts=' . $links->sumDerivedAmounts($companyId, $inv2, 'purchase_payment'),
    $failures, $checks);

$invRow = ncmExecute('SELECT transactionComplete FROM transaction WHERE transactionId = ?::uuid', [$inv2]);
check('(F) la factura quedó SALDADA',
    (bool) ($invRow['transactioncomplete'] ?? $invRow['transactionComplete'] ?? false),
    'complete=' . json_encode($invRow), $failures, $checks);

$rowB = orderRow($orderB);
check('(F) la orden quedó pagada, con autor y con el recibo enlazado',
    (string) ($rowB['status'] ?? '') === 'paid'
        && (string) ($rowB['paidby'] ?? '') === $adminId
        && (string) ($rowB['paymenttransactionid'] ?? '') === $paymentId
        && !empty($rowB['paid_at']),
    'row=' . json_encode([$rowB['status'] ?? null, $rowB['paidby'] ?? null, $rowB['paymenttransactionid'] ?? null]),
    $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (G) Una orden pagada es inmutable
// ═══════════════════════════════════════════════════════════════════════════
$r = hit('POST', '', [
    'action' => 'update',
    'id'     => $orderB,
    'lines'  => [['transactionId' => $inv2, 'amount' => 1.0]],
], $tokAprobador);
check('(G) editar una orden PAGADA se rechaza', $r['status'] === 422,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

$r = hit('POST', '', ['action' => 'cancel', 'id' => $orderB, 'reason' => 'no debería poder'], $tokAprobador);
check('(G) cancelar una orden PAGADA se rechaza', $r['status'] === 422,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

check('(G) la orden pagada quedó intacta después de los dos intentos',
    (string) (orderRow($orderB)['status'] ?? '') === 'paid'
        && (string) (orderRow($orderB)['cancelreason'] ?? '') === '',
    'row=' . json_encode(orderRow($orderB)['status'] ?? null), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (H) Cancelar: exige motivo, lo atribuye, y libera las facturas
// ═══════════════════════════════════════════════════════════════════════════
$r = hit('POST', '', ['action' => 'cancel', 'id' => $orderA, 'reason' => '   '], $tokAprobador);
check('(H) cancelar sin motivo se rechaza', $r['status'] === 422,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

$r = hit('POST', '', ['action' => 'cancel', 'id' => $orderA, 'reason' => 'El proveedor emitió nota de crédito'], $tokAprobador);
check('(H) cancelar con motivo → 200', $r['status'] === 200,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

$rowA = orderRow($orderA);
check('(H) queda el motivo y quién canceló',
    (string) ($rowA['status'] ?? '') === 'cancelled'
        && (string) ($rowA['cancelreason'] ?? '') === 'El proveedor emitió nota de crédito'
        && (string) ($rowA['cancelledby'] ?? '') === $adminId
        && !empty($rowA['cancelled_at']),
    'row=' . json_encode([$rowA['status'] ?? null, $rowA['cancelreason'] ?? null, $rowA['cancelledby'] ?? null]),
    $failures, $checks);

// Liberación: el trigger de propagación puso `orderstatus='cancelled'` en la
// línea, así que la factura salió del índice único y se puede volver a tomar.
$r = hit('POST', '', [
    'action'     => 'create',
    'supplierId' => SUPPLIER_ID,
    'outletId'   => $outletId,
    'lines'      => [['transactionId' => $inv1, 'amount' => 100000.0]],
], $tokAprobador);
check('(H) cancelar LIBERA la factura para otra orden', $r['status'] === 200,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);
$orderH = (string) ($r['data']['paymentOrderId'] ?? '');

// ═══════════════════════════════════════════════════════════════════════════
// (I) `pendingInvoices` marca lo comprometido en vez de esconderlo
// ═══════════════════════════════════════════════════════════════════════════
$r = hit('GET', 'action=pendingInvoices&supplierId=' . SUPPLIER_ID, [], $tokAprobador);
check('(I) pendingInvoices responde 200', $r['status'] === 200,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

$rows = is_array($r['data']['rows'] ?? null) ? $r['data']['rows'] : [];
$byId = [];
foreach ($rows as $row) {
    $byId[(string) $row['transactionId']] = $row;
}
check('(I) la factura tomada por la orden nueva sale marcada como comprometida',
    isset($byId[$inv1]) && ($byId[$inv1]['committed'] ?? false) === true
        && (string) ($byId[$inv1]['committedOrderId'] ?? '') === $orderH,
    'row=' . json_encode($byId[$inv1] ?? null), $failures, $checks);

check('(I) la factura ya SALDADA no se ofrece',
    !isset($byId[$inv2]),
    'inv2 apareció en pendingInvoices pese a estar saldada', $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (J) Lectura sin el permiso de vista
// ═══════════════════════════════════════════════════════════════════════════
$roleCiego = makeRole('op-test-sin-vista', ['finance.manage'], $companyId, $adminId);
$tokCiego  = panelSession($roleCiego, $companyId, $outletId, $adminId);
$r = hit('GET', 'action=list', [], $tokCiego);
check('(J) listar sin purchases.paymentorder.view → 403', $r['status'] === 403,
    'status=' . $r['status'] . ' msg=' . $r['message'], $failures, $checks);

// ── Limpieza de sesiones del arnés ─────────────────────────────────────────
ncmExecute('DELETE FROM auth_session WHERE companyid = ?::uuid AND useragent = ?', [$companyId, AGENTE_DEL_ARNES], true);
setSecondApproverFlag($companyId, false);

harnessFinish($failures, $checks);

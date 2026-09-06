<?php
/**
 * ÓRDENES DE PAGO A PROVEEDOR — el documento que autoriza el pago (migs 196/197).
 *
 *   GET    ?action=list[&status=&supplierId=&dateFrom=&dateTo=]
 *   GET    ?action=get&id=<uuid>
 *   GET    ?action=pendingInvoices&supplierId=<uuid>[&excludeOrderId=<uuid>]
 *   POST   { action: "create",  supplierId, outletId, lines: [{transactionId, amount}], paymentDate?, notes? }
 *   POST   { action: "update",  id, lines: [...], paymentDate?, notes? }     // solo en borrador
 *   POST   { action: "approve", id }
 *   POST   { action: "execute", id, paymentMethodKey, note?, identifier?, supplierDoc? }
 *   POST   { action: "cancel",  id, reason }
 *
 * ── Realm: PANEL y solo panel ──────────────────────────────────────────────
 *
 * `apiAuthTenant(['panel'])`, sin `pos-app`. No es una omisión: el POS no le
 * compra a proveedores ni autoriza desembolsos, y `credit-payments.php` ya
 * cierra el pago a proveedor al realm `panel` por el mismo motivo. Abrirlo a
 * `pos-app` pondría la autorización de pagos en una tablet del mostrador cuyo
 * rol `device` es el mismo para todas las personas que la agarran.
 *
 * ── Permisos ───────────────────────────────────────────────────────────────
 *
 *   lectura           → `purchases.paymentorder.view`
 *   create/update     → `purchases.paymentorder.create`
 *   approve           → `purchases.paymentorder.approve`   ← SEPARADA, ver abajo
 *   execute           → `purchases.paymentorder.approve` + `finance.manage`
 *   cancel            → `purchases.paymentorder.create`  (borrador propio o ajeno)
 *                       `purchases.paymentorder.approve` si ya está APROBADA
 *                       — el gate lo elige el SERVICIO contra la fila lockeada,
 *                         no este archivo con una lectura previa; ver el bloque
 *                         de `cancel` abajo
 *
 * `approve` está separada de `create` porque ES el punto de la feature:
 * segregación de tareas entre quien arma el pago y quien lo autoriza. Las tres
 * claves nacen en `PermissionCatalog` con `since` = 9 y se backfillean en la
 * mig 197.
 *
 * EJECUTAR exige LAS DOS claves. `approve` porque ejecutar es el acto que
 * consuma la autorización; `finance.manage` porque es la clave que HOY gatea
 * pagarle a un proveedor en `credit-payments.php`, y este endpoint termina
 * llamando exactamente a ese servicio. Si pidiera menos, sería un camino
 * lateral para desembolsar sin la clave que el codebase ya exige para
 * desembolsar — la orden de pago se convertiría en un bypass del control que
 * vino a reforzar.
 *
 * CANCELAR escala con el estado: descartar un borrador es deshacer trabajo de
 * carga (alcanza `create`), pero anular una orden YA APROBADA revierte una
 * decisión de autoridad, así que pide la clave de esa autoridad.
 *
 * ── El segundo aprobador ───────────────────────────────────────────────────
 *
 * Además del permiso —que es el gate duro y siempre aplica— hay un ajuste por
 * comercio, `settingPaymentOrderRequireSecondApprover` (`company.config`),
 * APAGADO por default: prendido, impide que quien creó la orden sea quien la
 * aprueba. Se lee acá una sola vez y se pasa al servicio, que es quien lo hace
 * cumplir dentro de la transacción con la orden lockeada.
 *
 * ── Finanzas ───────────────────────────────────────────────────────────────
 *
 * `execute` dispara `FinanceLedger::recordPurchasePayment()` post-commit,
 * best-effort, DESDE ACÁ — mismo patrón que `credit-payments.php` y
 * `purchases.php`. El servicio no lo hace: en este codebase el asiento lo
 * dispara el endpoint, y tener dos formas de poblar el ledger es exactamente
 * cómo se desincroniza.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Purchases/PaymentOrderService.php';

use Punto\Api\Purchases\PaymentOrderService;

$ctx       = apiAuthTenant(['panel']);
$companyId = (string) $ctx['companyId'];
$userId    = (string) ($ctx['userId'] ?? '');

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Los gates se escriben con la clave LITERAL dentro de `hasPermission(...)`, y
 * no a través de un helper que reciba la clave por variable, aunque eso repita
 * el `if` cinco veces.
 *
 * El motivo es un chequeo real del repo: `permission_enforcement_test.php`
 * recorre `api/v1/**` y cuenta una clave del catálogo como gateada SOLO si
 * matchea `hasPermission('<clave>')` con el literal adentro. Una indirección
 * —`poRequire($perm)`— deja las tres claves nuevas figurando como "existen en
 * PermissionCatalog y no las chequea ningún endpoint", que ese arnés reporta
 * como bug de seguridad. La verbosidad es el precio de que la cobertura de
 * permisos sea verificable por grep en vez de por confianza.
 */
$svc = new PaymentOrderService();

// ── Lectura ────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!hasPermission('purchases.paymentorder.view')) {
        apiError('No tenés permiso para esta acción (requiere: purchases.paymentorder.view)', 403);
    }
    $action = (string) ($_GET['action'] ?? 'list');

    if ($action === 'get') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if (!preg_match($uuidRe, $id)) {
            apiError('id inválido', 422);
        }
        $detail = $svc->find($id, $companyId);
        if ($detail === null) {
            apiError('Orden de pago no encontrada', 404);
        }
        apiOk($detail);
    }

    if ($action === 'pendingInvoices') {
        $supplierId = trim((string) ($_GET['supplierId'] ?? ''));
        if (!preg_match($uuidRe, $supplierId)) {
            apiError('supplierId inválido', 422);
        }
        $exclude = trim((string) ($_GET['excludeOrderId'] ?? ''));
        $exclude = preg_match($uuidRe, $exclude) ? $exclude : null;
        apiOk(['rows' => $svc->pendingInvoices($companyId, $supplierId, $exclude)]);
    }

    if ($action !== 'list') {
        apiError('Acción no soportada', 422);
    }

    // Alcance por sucursal consolidado (context/25): la suma de las sucursales
    // asignadas al usuario, cero filas = global. Se resuelve acá y se pasa al
    // servicio — nunca sale del query string.
    $rows = $svc->list($companyId, [
        'status'     => (string) ($_GET['status'] ?? ''),
        'supplierId' => (string) ($_GET['supplierId'] ?? ''),
        'outletIds'  => \Punto\Api\Outlets\OutletScope::effectiveIds(),
        'dateFrom'   => (string) ($_GET['dateFrom'] ?? ''),
        'dateTo'     => (string) ($_GET['dateTo'] ?? ''),
    ]);
    apiOk(['rows' => $rows, 'total' => count($rows)]);
}

if ($method !== 'POST') {
    apiError('Método no permitido', 405);
}

// ── Escritura ──────────────────────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string) ($body['action'] ?? '');

/** Extrae y valida el `id` del body para las acciones que operan sobre una orden. */
$requireId = static function () use ($body, $uuidRe): string {
    $id = trim((string) ($body['id'] ?? ''));
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    return $id;
};

if ($action === 'create') {
    if (!hasPermission('purchases.paymentorder.create')) {
        apiError('No tenés permiso para esta acción (requiere: purchases.paymentorder.create)', 403);
    }

    $supplierId = trim((string) ($body['supplierId'] ?? ''));
    if (!preg_match($uuidRe, $supplierId)) {
        apiError('supplierId inválido', 422);
    }
    $outletId = trim((string) ($body['outletId'] ?? ''));
    if (!preg_match($uuidRe, $outletId)) {
        apiError('outletId inválido', 422);
    }
    // El alcance por sucursal también manda en la ESCRITURA: un usuario
    // scopeado a la sucursal A no emite un documento de la B. `allows()` con
    // scope global devuelve true, así que el comercio sin scopes no cambia.
    if (!\Punto\Api\Outlets\OutletScope::allows(\Punto\Api\Outlets\OutletScope::current(), $outletId, $companyId)) {
        apiError('No tenés acceso a esa sucursal', 403);
    }
    if (!is_array($body['lines'] ?? null) || $body['lines'] === []) {
        apiError('lines debe ser un array no vacío', 422);
    }

    apiOk($svc->create(
        $companyId,
        $userId,
        $supplierId,
        $outletId,
        $body['lines'],
        isset($body['paymentDate']) ? (string) $body['paymentDate'] : null,
        isset($body['notes']) ? (string) $body['notes'] : null
    ));
}

if ($action === 'update') {
    if (!hasPermission('purchases.paymentorder.create')) {
        apiError('No tenés permiso para esta acción (requiere: purchases.paymentorder.create)', 403);
    }
    $id = $requireId();
    if (!is_array($body['lines'] ?? null) || $body['lines'] === []) {
        apiError('lines debe ser un array no vacío', 422);
    }

    apiOk($svc->update(
        $id,
        $companyId,
        $body['lines'],
        isset($body['paymentDate']) ? (string) $body['paymentDate'] : null,
        isset($body['notes']) ? (string) $body['notes'] : null
    ));
}

if ($action === 'approve') {
    if (!hasPermission('purchases.paymentorder.approve')) {
        apiError('No tenés permiso para esta acción (requiere: purchases.paymentorder.approve)', 403);
    }
    $id = $requireId();

    // El ajuste se lee UNA vez acá y se pasa al servicio, que lo hace cumplir
    // dentro de la TX con la orden ya lockeada. Leerlo adentro del servicio
    // habría escondido en una capa de dominio una regla que el endpoint
    // también necesita poder explicar.
    apiOk($svc->approve($id, $companyId, $userId, $svc->requiresSecondApprover($companyId)));
}

if ($action === 'execute') {
    // Las DOS claves — ver el docblock del archivo. `finance.manage` es la que
    // ya gatea el pago a proveedor en credit-payments.php, y este camino
    // termina llamando a ese mismo servicio.
    if (!hasPermission('purchases.paymentorder.approve')) {
        apiError('No tenés permiso para esta acción (requiere: purchases.paymentorder.approve)', 403);
    }
    if (!hasPermission('finance.manage')) {
        apiError('No tenés permiso para esta acción (requiere: finance.manage)', 403);
    }
    $id = $requireId();

    $pmKey = trim((string) ($body['paymentMethodKey'] ?? ''));
    if ($pmKey === '') {
        apiError('paymentMethodKey requerido', 422);
    }
    $note        = isset($body['note']) ? trim((string) $body['note']) : null;
    $identifier  = isset($body['identifier']) ? trim((string) $body['identifier']) : null;
    // Comprobante + timbrado del PROVEEDOR (mig 144) — mismo shape que ya
    // acepta credit-payments.php; se pasa tal cual al mismo servicio.
    $supplierDoc = is_array($body['supplierDoc'] ?? null) ? $body['supplierDoc'] : null;

    $result = $svc->execute($id, $companyId, $userId, $pmKey, $note ?: null, $identifier ?: null, $supplierDoc);

    // Finanzas: auto-poblado del ledger, best-effort post-commit — nunca rompe
    // un pago ya confirmado. Idéntico al bloque de credit-payments.php, porque
    // el recibo que produjo esta orden ES el mismo tipo de recibo.
    try {
        (new \Punto\Api\Finance\FinanceLedger())
            ->recordPurchasePayment($companyId, (string) ($result['paymentTransactionId'] ?? ''));
    } catch (\Throwable $e) {
        error_log('[FinanceLedger] recordPurchasePayment falló para orden de pago id=' . $id . ': ' . $e->getMessage());
    }

    apiOk($result);
}

if ($action === 'cancel') {
    $id     = $requireId();
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') {
        apiError('Cancelar una orden de pago exige un motivo', 422);
    }

    // El permiso escala con el estado: descartar un borrador es deshacer carga;
    // anular una orden aprobada revierte una decisión de autoridad.
    //
    // El estado NO se lee acá para elegir el gate. Antes se hacía con un SELECT
    // sin lock y era un agujero real: entre esa lectura y el `FOR UPDATE` del
    // servicio, otra sesión podía aprobar la orden, y alguien con solo `create`
    // terminaba cancelando una orden APROBADA (el servicio acepta los dos
    // estados, así que nada más lo frenaba). Se mandan las dos capacidades y la
    // decisión la toma el servicio contra la fila ya lockeada — el estado que
    // elige el permiso es exactamente el que se va a cancelar.
    //
    // Cortar antes por falta de LAS DOS sigue valiendo: quien no tiene ninguna
    // no puede cancelar nada, y eso no depende del estado.
    $canCancelDraft    = hasPermission('purchases.paymentorder.create');
    $canCancelApproved = hasPermission('purchases.paymentorder.approve');
    if (!$canCancelDraft && !$canCancelApproved) {
        apiError('No tenés permiso para esta acción (requiere: purchases.paymentorder.create)', 403);
    }

    apiOk($svc->cancel($id, $companyId, $userId, $reason, $canCancelDraft, $canCancelApproved));
}

apiError('Acción no soportada', 422);

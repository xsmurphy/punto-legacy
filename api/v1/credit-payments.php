<?php
/**
 * Pagos de facturas a crédito (type=5 hijo de type=3/4) — cobro a CLIENTE
 * (`contactType=1`, default) o pago a PROVEEDOR (`contactType=2`, generalizado
 * 2026-08 — antes este endpoint solo cobraba crédito de clientes).
 *
 *   POST   { action: "create", parentTransactionId, amount, paymentMethodKey, contactType?, note? }
 *   POST   { action: "create", allocations: [{parentTransactionId, amount}, ...], paymentMethodKey, contactType?, note? }
 *   POST   { action: "createDistributed", contactId, contactType, amount, paymentMethodKey, note? }
 *   DELETE ?id=<uuid>                     → anula un recibo (soft-void, transactionStatus=6)
 *
 * La forma `allocations` (mig 123) permite UN recibo repartido en VARIAS
 * facturas del mismo contacto — antes cada factura necesitaba su propio
 * recibo, quemando un número correlativo por factura para un solo documento
 * fiscal real. La forma vieja (`parentTransactionId` + `amount` sueltos) se
 * traduce acá a un `allocations` de un solo elemento — se mantiene por
 * compatibilidad con consumidores existentes (POS, panel legacy).
 *
 * `createDistributed` ("monto libre"): el caller manda un monto total, EL
 * BACKEND decide cómo se reparte entre las facturas abiertas del contacto
 * (más vieja primero) — ver `CreditPaymentService::createDistributed()`.
 * Nunca se acepta un array de allocations pre-calculado por el cliente para
 * este modo.
 *
 * Auth: multi-realm (panel + pos-app), mismo patrón que outlets.php/register.php
 * (allowlist por endpoint vía apiAuthTenant). userId del JWT, nunca del body.
 * registerId y paymentMethodName se resuelven server-side (desde la primera
 * factura y el catálogo) — CreditPaymentService nunca lee registerId/drawerId
 * del ctx, así que es realm-agnostic: el pago desde panel (sin caja/drawer
 * activos) resuelve registerId de la primera factura y drawerId queda null
 * si no hay caja abierta (igual que el backfill manual — ver
 * DrawerService::resolveOpenDrawerId).
 *
 * El guard de module==='pos' solo aplica al realm pos-app (bloquea devices
 * 'screen'/'kds' de cobrar crédito) — no aplica a panel, donde apiAuthTenant
 * no resuelve device (module siempre '' para ese realm).
 *
 * Permiso: `pos.sale.creditPayment` para cobro a cliente (mismo que ya
 * gateaba este endpoint — cajeros lo tienen, es operación de mostrador).
 * `finance.manage` para pago a proveedor — un pago a proveedor mueve caja
 * hacia AFUERA, mismo permiso que gatea `finance/movements.php`; no lo tiene
 * el rol cajero por default (solo manager/owner), a propósito.
 *
 * Anulación (DELETE): NO reusa `pos.sale.creditPayment` — anular plata movida
 * es más sensible que cobrarla, y el cajero que cobra no tiene por qué poder
 * revertirlo. Cliente → `pos.sale.void` ("Anular ventas", ya sembrado en
 * manager/owner, NO en cashier — mismo criterio de riesgo que anular una
 * venta). Proveedor → `finance.manage` (mismo que ya gatea crear el pago).
 * Ninguna clave nueva: las dos ya existen en `PermissionCatalog` y ya están
 * sembradas en `RoleService::SEED_PERMISSIONS` (owner 2026-08-16, ver
 * `context/40-anulacion-y-nota-credito.md`).
 */
require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'pos-app']);
if ($ctx['realm'] === 'pos-app' && ($ctx['module'] ?? 'pos') !== 'pos') {
    apiError('Endpoint solo accesible desde POS', 403);
}
$companyId = (string) $ctx['companyId'];
$userId    = (string) ($ctx['userId'] ?? '');

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'DELETE') {
    $paymentId = trim((string) ($_GET['id'] ?? ''));
    if (!preg_match($uuidRe, $paymentId)) {
        apiError('id inválido', 422);
    }

    // El kind (y por lo tanto el permiso requerido) lo decide EL SERVIDOR
    // leyendo la fila — nunca un parámetro del caller. Lectura liviana (sin
    // lock) solo para elegir el permiso; CreditPaymentService::void() vuelve
    // a leer con FOR UPDATE dentro de su propia TX antes de anular.
    $row = ncmExecute(
        'SELECT customerId, supplierId FROM transaction WHERE transactionId = ? AND companyId = ? AND transactionType = 5 LIMIT 1',
        [$paymentId, $companyId]
    );
    if (!$row) {
        apiError('Recibo no encontrado', 404);
    }
    $isCustomerPayment = !empty($row['customerId'] ?? null);
    $voidPerm = $isCustomerPayment ? 'pos.sale.void' : 'finance.manage';
    if (!hasPermission($voidPerm)) {
        apiError("No tenés permiso para esta acción (requiere: $voidPerm)", 403);
    }

    require_once __DIR__ . '/../lib/services/CreditPaymentService.php';
    $result = (new \Punto\Api\Services\CreditPaymentService())->void($paymentId, $companyId, $userId);

    // Finanzas Fase 3: revierte el movimiento derivado de este recibo —
    // mismo patrón que purchases.php con PurchasesService::void().
    try {
        (new \Punto\Api\Finance\FinanceLedger())->voidBySource($companyId, (string) $result['kind'], $paymentId);
    } catch (\Throwable $e) {
        error_log('[FinanceLedger] voidBySource(' . $result['kind'] . ') falló para id=' . $paymentId . ': ' . $e->getMessage());
    }

    apiOk($result);
}

if ($method !== 'POST') {
    apiError('Método no permitido', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string) ($body['action'] ?? '');

// contactType: 1=cliente (default, compat con callers viejos que no lo mandan) | 2=proveedor.
$contactTypeRaw = (int) ($body['contactType'] ?? 1);
$isCustomer = $contactTypeRaw !== 2;

// Gate de autorización — antes de abrir a panel el único control de acceso
// era el device pairing (Bearer del realm pos-app); un panel session
// cualquiera podía cobrar crédito sin chequeo de rol. Mismo patrón que otros
// endpoints panel de escritura (finance/movements.php, outlets.php).
// Seed default: manager/cashier tienen pos.sale.creditPayment; owner siempre.
// Solo manager/owner tienen finance.manage (pago a proveedor es más sensible
// — mueve caja hacia afuera sin la fricción de "hay un cliente en el mostrador").
$requiredPerm = $isCustomer ? 'pos.sale.creditPayment' : 'finance.manage';
if (!hasPermission($requiredPerm)) {
    apiError("No tenés permiso para esta acción (requiere: $requiredPerm)", 403);
}
if (!$isCustomer && $ctx['realm'] === 'pos-app') {
    // El POS no compra a proveedores — el pago a proveedor solo tiene
    // sentido desde el panel (reporte de cuentas por pagar / ficha del
    // proveedor). Evita un 500 confuso más adelante si algún día un device
    // manda contactType=2 por error.
    apiError('El pago a proveedor solo está disponible desde el panel', 403);
}

require_once __DIR__ . '/../lib/services/CreditPaymentService.php';
$svc = new \Punto\Api\Services\CreditPaymentService();

if ($action === 'createDistributed') {
    $contactId = (string) ($body['contactId'] ?? '');
    if (!preg_match($uuidRe, $contactId)) {
        apiError('contactId inválido', 422);
    }
    if (!is_numeric($body['amount'] ?? null) || (float) $body['amount'] <= 0) {
        apiError('amount numérico > 0 requerido', 422);
    }
    $pmKey = trim((string) ($body['paymentMethodKey'] ?? ''));
    if ($pmKey === '') {
        apiError('paymentMethodKey requerido', 422);
    }
    $note       = isset($body['note']) ? trim((string) $body['note']) : null;
    $identifier = isset($body['identifier']) ? trim((string) $body['identifier']) : null;
    // Comprobante+timbrado del proveedor (mig 144) — solo aplica si !$isCustomer,
    // CreditPaymentService::insertReceipt lo ignora en cobro a cliente.
    $supplierDoc = is_array($body['supplierDoc'] ?? null) ? $body['supplierDoc'] : null;

    $result = $svc->createDistributed(
        $companyId, $userId, $contactId, $isCustomer,
        (float) $body['amount'], $pmKey, $note ?: null, $identifier ?: null, $supplierDoc
    );
} elseif ($action === 'create') {
    // Forma nueva: allocations[]. Forma vieja: parentTransactionId + amount
    // sueltos, traducida acá a un allocations de 1 elemento — mismo shape para
    // el service de acá en más.
    if (isset($body['allocations'])) {
        $rawAllocations = $body['allocations'];
        if (!is_array($rawAllocations) || $rawAllocations === []) {
            apiError('allocations debe ser un array no vacío', 422);
        }
    } else {
        $rawAllocations = [[
            'parentTransactionId' => $body['parentTransactionId'] ?? '',
            'amount'              => $body['amount'] ?? 0,
        ]];
    }

    // Validación de FORMA acá (array bien armado, montos numéricos > 0); la de
    // NEGOCIO (mismo contacto, no supera deuda, etc.) vive en el service, dentro
    // de la TX con el lock ya tomado.
    $allocations = [];
    foreach ($rawAllocations as $alloc) {
        if (!is_array($alloc)) {
            apiError('Cada allocation debe ser un objeto {parentTransactionId, amount}', 422);
        }
        $pid = (string) ($alloc['parentTransactionId'] ?? '');
        if (!preg_match($uuidRe, $pid)) {
            apiError('parentTransactionId inválido en allocations', 422);
        }
        if (!is_numeric($alloc['amount'] ?? null) || (float) $alloc['amount'] <= 0) {
            apiError('Cada allocation necesita un amount numérico > 0', 422);
        }
        $allocations[] = ['parentTransactionId' => $pid, 'amount' => (float) $alloc['amount']];
    }

    $pmKey = trim((string) ($body['paymentMethodKey'] ?? ''));
    if ($pmKey === '') {
        apiError('paymentMethodKey requerido', 422);
    }

    $note       = isset($body['note']) ? trim((string) $body['note']) : null;
    $identifier = isset($body['identifier']) ? trim((string) $body['identifier']) : null;
    $supplierDoc = is_array($body['supplierDoc'] ?? null) ? $body['supplierDoc'] : null;

    $result = $svc->create($companyId, $userId, $allocations, $pmKey, $note ?: null, $identifier ?: null, $isCustomer, $supplierDoc);
} else {
    apiError('Acción no soportada', 422);
}

// Finanzas Fase 3: auto-poblado del ledger, best-effort — nunca rompe el pago.
try {
    $ledger = new \Punto\Api\Finance\FinanceLedger();
    if ($isCustomer) {
        $ledger->recordCreditPayment($companyId, (string) ($result['id'] ?? ''));
    } else {
        $ledger->recordPurchasePayment($companyId, (string) ($result['id'] ?? ''));
    }
} catch (\Throwable $e) {
    error_log('[FinanceLedger] record' . ($isCustomer ? 'CreditPayment' : 'PurchasePayment') . ' falló para id=' . ($result['id'] ?? '') . ': ' . $e->getMessage());
}

apiOk($result);

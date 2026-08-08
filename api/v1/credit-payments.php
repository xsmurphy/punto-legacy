<?php
/**
 * Pagos de facturas a crédito (type=5 hijo de type=3).
 *
 *   POST { action: "create", parentTransactionId, amount, paymentMethodKey, note? }
 *   POST { action: "create", allocations: [{parentTransactionId, amount}, ...], paymentMethodKey, note? }
 *
 * La segunda forma (mig 123) permite UN recibo repartido en VARIAS facturas
 * del mismo cliente — antes cada factura necesitaba su propio recibo,
 * quemando un número correlativo por factura para un solo documento fiscal
 * real. La forma vieja (`parentTransactionId` + `amount` sueltos) se traduce
 * acá a un `allocations` de un solo elemento — se mantiene por compatibilidad
 * con consumidores existentes (POS, panel legacy).
 *
 * Auth: multi-realm (panel + pos-app), mismo patrón que outlets.php/register.php
 * (allowlist por endpoint vía apiAuthTenant). userId del JWT, nunca del body.
 * registerId y paymentMethodName se resuelven server-side (desde la primera
 * factura y el catálogo) — CreditPaymentService::create() nunca lee
 * registerId/drawerId del ctx, así que es realm-agnostic: el pago desde panel
 * (sin caja/drawer activos) resuelve registerId de la primera factura y
 * drawerId queda null si no hay caja abierta (igual que el backfill manual —
 * ver DrawerService::resolveOpenDrawerId).
 *
 * El guard de module==='pos' solo aplica al realm pos-app (bloquea devices
 * 'screen'/'kds' de cobrar crédito) — no aplica a panel, donde apiAuthTenant
 * no resuelve device (module siempre '' para ese realm).
 */
require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'pos-app']);
if ($ctx['realm'] === 'pos-app' && ($ctx['module'] ?? 'pos') !== 'pos') {
    apiError('Endpoint solo accesible desde POS', 403);
}
// Gate de autorización — antes de abrir a panel el único control de acceso
// era el device pairing (Bearer del realm pos-app); un panel session
// cualquiera podía cobrar crédito sin chequeo de rol. Mismo patrón que otros
// endpoints panel de escritura (finance/movements.php, outlets.php).
// Seed default: manager/cashier lo tienen (RoleService::SEED_PERMISSIONS);
// owner lo tiene siempre (auto-sync). Tenants existentes con roles custom
// necesitan el backfill de la migración 74.
if (!hasPermission('pos.sale.creditPayment')) {
    apiError('No tenés permiso para esta acción (requiere: pos.sale.creditPayment)', 403);
}
$companyId = (string) $ctx['companyId'];
$userId    = (string) ($ctx['userId'] ?? '');

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    apiError('Método no permitido', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string) ($body['action'] ?? '');

if ($action !== 'create') {
    apiError('Acción no soportada', 422);
}

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
// NEGOCIO (mismo cliente, no supera deuda, etc.) vive en el service, dentro
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

$note = isset($body['note']) ? trim((string) $body['note']) : null;
$identifier = isset($body['identifier']) ? trim((string) $body['identifier']) : null;

require_once __DIR__ . '/../lib/services/CreditPaymentService.php';
$svc    = new \Punto\Api\Services\CreditPaymentService();
$result = $svc->create($companyId, $userId, $allocations, $pmKey, $note ?: null, $identifier ?: null);

// Finanzas Fase 3: auto-poblado del ledger, best-effort — nunca rompe el pago.
try {
    (new \Punto\Api\Finance\FinanceLedger())->recordCreditPayment($companyId, (string) ($result['id'] ?? ''));
} catch (\Throwable $e) {
    error_log('[FinanceLedger] recordCreditPayment falló para id=' . ($result['id'] ?? '') . ': ' . $e->getMessage());
}

apiOk($result);

<?php
/**
 * Pagos de facturas a crédito (type=5 hijo de type=3).
 *
 *   POST { action: "create", parentTransactionId, amount, paymentMethodKey, note? }
 *
 * Auth: realm panel + pos-app. userId del JWT, nunca del body.
 * registerId y paymentMethodName se resuelven server-side (desde el parent y el catálogo).
 */
require_once __DIR__ . '/../../bootstrap.php';

require_once dirname(__DIR__) . '/lib/Auth/apiAuthPosContext.php';
$ctx       = apiAuthPosContext();
$companyId = (string) COMPANY_ID;
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

$parentId = (string) ($body['parentTransactionId'] ?? '');
if (!preg_match($uuidRe, $parentId)) {
    apiError('parentTransactionId inválido', 422);
}

$amount = (float) ($body['amount'] ?? 0);
if ($amount <= 0) {
    apiError('Monto inválido', 422);
}

$pmKey = trim((string) ($body['paymentMethodKey'] ?? ''));
if ($pmKey === '') {
    apiError('paymentMethodKey requerido', 422);
}

$note = isset($body['note']) ? trim((string) $body['note']) : null;

require_once __DIR__ . '/../lib/services/CreditPaymentService.php';
$svc    = new \Punto\Api\Services\CreditPaymentService();
$result = $svc->create($companyId, $userId, $parentId, $amount, $pmKey, $note ?: null);

apiOk($result);

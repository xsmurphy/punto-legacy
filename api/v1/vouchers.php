<?php
/**
 * /api/v1/vouchers.php — módulo de Vouchers F1 (context/36-vouchers-plan.md).
 *
 *   POST ?resource=issue    {items:[{itemId, qty}], expiresAt?, beneficiaryContactId?, transactionId}
 *                            → crea el voucher (atómico, precio congelado por ítem)
 *   POST ?resource=validate {code}
 *                            → cabecera + ítems del voucher, o error tipado
 *                              (404 no existe / 410 ya consumido / 410 vencido / 410 anulado)
 *   POST ?resource=consume  {code, transactionId}
 *                            → marca consumido (lock optimista, idempotente por transactionId)
 *
 * companyId del JWT. Mismo realm/estructura que giftcards.php (dominio
 * hermano, NO lo reemplaza — el voucher es por productos, no por importe).
 * F1 es solo backend: la integración con el carrito del POS es F2.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/VoucherService.php';
use Punto\Api\Services\VoucherService;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$outletId  = $ctx['outletId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource  = (string) ($_GET['resource'] ?? '');

if ($method !== 'POST') {
    apiError('Método no permitido', 405);
}

$body = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
$svc  = new VoucherService();

if ($resource === 'issue') {
    $items                = $body['items'] ?? null;
    $transactionId        = trim((string) ($body['transactionId'] ?? ''));
    $expiresAt            = isset($body['expiresAt']) && $body['expiresAt'] !== '' ? (string) $body['expiresAt'] : null;
    $beneficiaryContactId = isset($body['beneficiaryContactId']) && $body['beneficiaryContactId'] !== ''
        ? (string) $body['beneficiaryContactId']
        : null;

    if (!is_array($items) || empty($items)) {
        apiError('items requerido (al menos un ítem)', 422);
    }
    if ($transactionId === '') {
        apiError('transactionId requerido', 422);
    }

    try {
        $result = $svc->issue($companyId, $outletId, $items, $transactionId, $expiresAt, $beneficiaryContactId);
    } catch (\InvalidArgumentException $e) {
        apiError($e->getMessage(), 422);
    } catch (\Throwable $e) {
        apiError('No se pudo emitir el voucher', 500);
    }

    apiOk([
        'ok'        => true,
        'voucherId' => $result['voucherId'],
        'code'      => $result['code'],
        'items'     => $result['items'],
    ]);
}

if ($resource === 'validate') {
    $code = trim((string) ($body['code'] ?? ''));
    if ($code === '') {
        apiError('code requerido', 400);
    }

    $result = $svc->validate($companyId, $code);
    if (!$result['ok']) {
        $map = [
            'not_found' => ['Voucher no encontrado', 404],
            'used'      => ['El voucher ya fue consumido', 410],
            'expired'   => ['El voucher está vencido', 410],
            'voided'    => ['El voucher fue anulado', 410],
        ];
        [$msg, $httpStatus] = $map[$result['reason']] ?? ['No se pudo validar el voucher', 400];
        apiError($msg, $httpStatus);
    }

    apiOk([
        'ok'        => true,
        'voucherId' => $result['voucherId'],
        'code'      => $result['code'],
        'expiresAt' => $result['expiresAt'],
        'items'     => $result['items'],
    ]);
}

if ($resource === 'consume') {
    $code          = trim((string) ($body['code'] ?? ''));
    $transactionId = trim((string) ($body['transactionId'] ?? ''));
    if ($code === '' || $transactionId === '') {
        apiError('code y transactionId requeridos', 400);
    }

    $result = $svc->consume($companyId, $code, $transactionId);
    if (!$result['ok']) {
        $map = [
            'not_found'     => ['Voucher no encontrado', 404],
            'used_by_other' => ['El voucher ya fue consumido por otra transacción', 409],
            'expired'       => ['El voucher está vencido', 410],
            'voided'        => ['El voucher fue anulado', 410],
            'conflict'      => ['Conflicto: el voucher fue consumido por otro proceso', 409],
        ];
        [$msg, $httpStatus] = $map[$result['reason']] ?? ['No se pudo consumir el voucher', 400];
        apiError($msg, $httpStatus);
    }

    apiOk(['ok' => true]);
}

apiError('Recurso no encontrado', 404);

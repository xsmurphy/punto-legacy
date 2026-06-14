<?php
/**
 * REST — Webhook PÚBLICO de dLocal Go (notificación de pagos).
 *
 *   POST /v1/billing-webhook.php   (notification_url configurado en dLocal)
 *
 * NO usa apiAuthTenant — es un callback server-to-server de dLocal. La
 * autenticidad se valida por firma HMAC-SHA256 sobre el body CRUDO
 * (PaymentsService::handleWebhook → DlocalGoProvider::verifyWebhookSignature).
 *
 * Importante: leemos el body crudo ANTES de incluir bootstrap.php, porque
 * bootstrap consume php://input para poblar $_POST (y JSON.stringify del parse
 * reordena keys → rompería el HMAC). php://input es re-legible para JSON, así
 * que el re-read de bootstrap no molesta; igual usamos $rawBody acá.
 *
 * Siempre responde 200 salvo firma inválida (401) → así dLocal no entra en
 * loop de reintentos por un 500 transitorio; los errores se loguean.
 */

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

require_once __DIR__ . '/../bootstrap.php'; // autoloader + db + ncm helpers (NO auth)

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$pay = new \Punto\Api\Billing\PaymentsService();

try {
    $result = $pay->handleWebhook($rawBody, $_SERVER);
    apiOk(['received' => true] + $result);
} catch (\RuntimeException $e) {
    if ($e->getMessage() === 'invalid_signature') {
        apiError('Firma inválida', 401);
    }
    // Error procesando → logueamos y devolvemos 200 para evitar el loop de
    // reintentos de dLocal (el pago se puede reconciliar manualmente / re-query).
    error_log('[billing-webhook] ' . $e->getMessage());
    apiOk(['received' => true, 'handled' => false, 'reason' => 'error']);
} catch (\Throwable $e) {
    error_log('[billing-webhook] fatal: ' . $e->getMessage());
    apiOk(['received' => true, 'handled' => false, 'reason' => 'error']);
}

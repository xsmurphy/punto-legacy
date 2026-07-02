<?php
/**
 * REST — Config de Finanzas (mapa método de pago → cuenta).
 *
 *   GET  /v1/finance/config → array de métodos de pago reales del tenant
 *                             (taxonomía paymentMethod), cada uno:
 *                             { methodId, methodName, accountId, isCash }.
 *                             El método "Efectivo" trae isCash=true y
 *                             accountId fijo a la cuenta Efectivo del sistema.
 *   POST /v1/finance/config { [methodId]: accountId|null, ... } → MERGE
 *                             no-destructivo. methodId es el taxonomyId real
 *                             (UUID) del método; "Efectivo" se ignora si viene.
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$svc = new \Punto\Api\Finance\ConfigService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    apiOk($svc->read((string) COMPANY_ID));
}

if ($method === 'POST') {
    $body = is_array($_POST) ? $_POST : [];
    try {
        $result = $svc->update((string) COMPANY_ID, $body);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($result);
}

apiError('Método no permitido', 405);

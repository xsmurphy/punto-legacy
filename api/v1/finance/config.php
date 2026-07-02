<?php
/**
 * REST — Config de Finanzas (mapa método de pago → cuenta).
 *
 *   GET  /v1/finance/config → mapa resuelto (efectivo fijo a la cuenta Efectivo,
 *                             demás métodos con el accountId asignado o null)
 *   POST /v1/finance/config { tarjeta_debito?, tarjeta_credito?, transferencia?,
 *                             billetera?, cheque?, otro? } → MERGE no-destructivo
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

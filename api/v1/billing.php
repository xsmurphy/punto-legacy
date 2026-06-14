<?php
/**
 * REST — Facturación del tenant (API compartida /api).
 *
 *   GET  /v1/billing                   → resumen: plan, uso, créditos, pagos
 *   GET  /v1/billing?resource=plans    → lista de planes para comparación
 *   GET  /v1/billing?resource=ai-ledger → últimos 20 movimientos del ledger IA
 *   POST /v1/billing (action=requestPlanChange, planCode, note?) → solicita cambio de plan
 *
 * Auth: apiAuthTenant(['panel']) — JWT realm "panel" (panel-next → BFF → aquí).
 * Scoping: tenant por COMPANY_ID del JWT. NO captura ni almacena datos de pago.
 *
 * Sigue el patrón de api/v1/settings.php:
 *   bootstrap require, apiAuthTenant, instantiate service, dispatch.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Billing\BillingService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $resource = (string) (validateHttp('resource') ?: '');

    if ($resource === 'plans') {
        apiOk(['plans' => $svc->plans()]);
    }

    if ($resource === 'ai-ledger') {
        apiOk(['rows' => $svc->aiLedger(COMPANY_ID)]);
    }

    // default: resumen de facturación
    apiOk($svc->summary(COMPANY_ID));
}

if ($method === 'POST') {
    $action = (string) (validateHttp('action', 'post') ?: '');

    if ($action === 'requestPlanChange') {
        $planCode = (int) (validateHttp('planCode', 'post') ?: 0);
        $note     = (string) (validateHttp('note', 'post') ?: '');

        if ($planCode <= 0) {
            apiError('planCode inválido', 422);
        }

        try {
            $result = $svc->requestPlanChange(COMPANY_ID, $planCode, $note !== '' ? $note : null);
            apiOk($result);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            apiError('No se pudo registrar la solicitud', 500);
        }
    }

    apiError('Acción no soportada', 422);
}

apiError('Método no permitido', 405);

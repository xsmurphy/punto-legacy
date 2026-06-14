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
$pay    = new \Punto\Api\Billing\PaymentsService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $resource = (string) (validateHttp('resource') ?: '');

    if ($resource === 'plans') {
        apiOk(['plans' => $svc->plans()]);
    }

    if ($resource === 'ai-ledger') {
        apiOk(['rows' => $svc->aiLedger(COMPANY_ID)]);
    }

    // Packs de créditos comprables (catálogo) + si los pagos están habilitados.
    if ($resource === 'packs') {
        apiOk(['packs' => $pay->listPacks(), 'paymentsEnabled' => $pay->isEnabled()]);
    }

    // Facturas del tenant (billing_invoice — pagos a Punto vía dLocal).
    if ($resource === 'invoices') {
        apiOk(['invoices' => $pay->listInvoices(COMPANY_ID)]);
    }

    // default: resumen de facturación
    apiOk($svc->summary(COMPANY_ID));
}

if ($method === 'POST') {
    $action = (string) (validateHttp('action', 'post') ?: '');

    // Compra de un pack de créditos → inicia checkout hosteado en dLocal Go.
    if ($action === 'checkout') {
        $packId = (string) (validateHttp('packId', 'post') ?: '');
        if ($packId === '') {
            apiError('packId requerido', 422);
        }
        try {
            apiOk($pay->createPackCheckout(COMPANY_ID, $packId));
        } catch (\InvalidArgumentException $e) {
            apiError('Pack no encontrado', 404);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'PAYMENTS_NOT_CONFIGURED') {
                apiError('Pagos no disponibles por el momento', 503);
            }
            apiError('No se pudo iniciar el pago', 502);
        }
    }

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

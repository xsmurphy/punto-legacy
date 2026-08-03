<?php
/**
 * /api/v1/space-settlements.php — split de cuenta de una sesión de espacio
 * (F3a+F3b+F3c, context/15-espacios-module-plan.md §F3).
 *
 *   GET  /v1/space-settlements?sessionId=<uuid>                → saldo (total, paid, balance, items)
 *   POST /v1/space-settlements?sessionId=<uuid>&action=pay      → registra un pago parcial y liquida
 *                                                                  la sesión si el saldo llega a 0
 *   POST /v1/space-settlements?sessionId=<uuid>&action=validate → preflight de solo lectura: corre
 *                                                                  las mismas validaciones que 'pay'
 *                                                                  sin escribir nada (ver docblock de
 *                                                                  SpaceSettlementService::preflightPayment)
 *        body (ambas acciones): { transactionId, kind: 'items'|'amount'|'share',
 *                amount?, orderItemIds?: string[], shareCount?, shareIndex? }
 *        (transactionId no se valida en 'validate' — ver preflightPayment)
 *
 * Auth: panel + pos-app (mismo realm que orders-core.php / table-sessions.php).
 * pos-app: la operación queda scopeada al outlet del device — un POS/KDS de
 * la sucursal A no opera el split de una mesa de la sucursal B del mismo
 * tenant (mismo patrón que orders-core.php).
 *
 * Alcance: SOLO backend. La UI del split (diálogo de modos, selección de
 * ítems) va en un commit siguiente — este endpoint ya es consumible por
 * curl/Postman/tests mientras tanto.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$outletId  = $ctx['outletId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessionId = (string) ($_GET['sessionId'] ?? '');
$action    = $_GET['action'] ?? null;

if ($sessionId === '') {
    apiError('sessionId requerido', 422);
}

global $db;
$svc = new \Punto\Api\Spaces\SpaceSettlementService($db);

$isPosApp    = ($ctx['realm'] ?? '') === 'pos-app';
$outletScope = $isPosApp ? $outletId : null;

switch ($method) {
    case 'GET':
        try {
            apiOk($svc->getBalance($companyId, $sessionId, $outletScope));
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 404);
        }
        break;

    case 'POST':
        if (!in_array($action, ['pay', 'validate'], true)) {
            apiError("action inválida (esperado: 'pay'|'validate')", 422);
        }
        $data = [
            'transactionId' => (string) ($_POST['transactionId'] ?? ''),
            'kind'          => (string) ($_POST['kind'] ?? ''),
            'amount'        => $_POST['amount'] ?? null,
            'orderItemIds'  => $_POST['orderItemIds'] ?? [],
            'shareCount'    => $_POST['shareCount'] ?? null,
            'shareIndex'    => $_POST['shareIndex'] ?? null,
        ];
        try {
            if ($action === 'validate') {
                // Preflight de solo lectura — mismo payload que 'pay', mismo
                // auth/outletScope, pero no escribe nada (ver docblock de
                // SpaceSettlementService::preflightPayment). El caller lo usa
                // ANTES de crear la venta, para no comprometer el cobro si el
                // pago parcial va a ser rechazado.
                apiOk($svc->preflightPayment($companyId, $sessionId, $data, $outletScope));
            } else {
                apiOk($svc->registerPayment($companyId, $sessionId, $data, $outletScope));
            }
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}

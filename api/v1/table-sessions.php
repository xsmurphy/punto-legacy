<?php
/**
 * /api/v1/table-sessions.php — ciclo de vida de la ocupación de una mesa
 * (table_session, mig 80, context/15-mesas-module-plan.md F0+F1).
 *
 *   GET  /v1/table-sessions?outletId=<uuid>&status?=          → lista del outlet
 *   GET  /v1/table-sessions?id=<uuid>                          → detalle
 *   POST /v1/table-sessions                                    → abre sesión (body: tableId, guests?, waiterId?)
 *   POST /v1/table-sessions?id=<uuid>&action=request-bill       → open → bill_requested
 *   POST /v1/table-sessions?id=<uuid>&action=cancel              → cancela (solo sin órdenes activas)
 *   POST /v1/table-sessions?id=<uuid>&action=close {transactionId?} → cierra (F0+F1: sin cobro; F2 lo llamará con transactionId)
 *
 * Auth: panel + pos-app. pos-app queda scopeado al outlet del device (mismo
 * patrón outletScope de orders-core.php).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$outletId  = $ctx['outletId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;
$action    = $_GET['action'] ?? null;

$isPosApp    = ($ctx['realm'] ?? '') === 'pos-app';
$outletScope = $isPosApp ? $outletId : null;

global $db;
$svc = new \Punto\Api\Tables\TableSessionService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $session = $svc->find($companyId, (string) $id);
            if ($session === null) apiError('Sesión no encontrada', 404);
            if ($outletScope !== null && $session['outletId'] !== $outletScope) {
                apiError('Sesión no encontrada', 404);
            }
            apiOk($session);
            break;
        }
        $reqOutletId = $outletScope ?? (string) ($_GET['outletId'] ?? '');
        if ($reqOutletId === '') apiError('outletId requerido', 422);
        $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
        apiOk(['sessions' => $svc->listByOutlet($companyId, $reqOutletId, $status)]);
        break;

    case 'POST':
        if ($id !== null && $action === 'request-bill') {
            try {
                apiOk($svc->requestBill($companyId, (string) $id, $outletScope));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'cancel') {
            try {
                apiOk($svc->cancel($companyId, (string) $id, $outletScope));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'close') {
            $transactionId = !empty($_POST['transactionId']) ? (string) $_POST['transactionId'] : null;
            try {
                apiOk($svc->close($companyId, (string) $id, $transactionId, $outletScope));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null) {
            apiError('action inválida (esperado: request-bill|cancel|close)', 422);
        }

        try {
            $tableId  = (string) ($_POST['tableId'] ?? '');
            $guests   = isset($_POST['guests']) && $_POST['guests'] !== '' ? (int) $_POST['guests'] : null;
            $waiterId = !empty($_POST['waiterId']) ? (string) $_POST['waiterId'] : null;
            if ($tableId === '') apiError('tableId requerido', 422);
            apiOk($svc->open($companyId, $tableId, $guests, $waiterId, $outletScope), 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}

<?php
/**
 * /api/v1/space-sessions.php — ciclo de vida de la ocupación de un espacio
 * (space_session, mig 80, context/15-espacios-module-plan.md F0+F1).
 *
 *   GET  /v1/space-sessions?outletId=<uuid>&status?=          → lista del outlet
 *   GET  /v1/space-sessions?id=<uuid>                          → detalle
 *   POST /v1/space-sessions                                    → abre sesión (body: tableId, guests?, waiterId?)
 *   POST /v1/space-sessions?id=<uuid>&action=request-bill       → open → bill_requested
 *   POST /v1/space-sessions?id=<uuid>&action=cancel              → cancela (solo sin órdenes activas)
 *   POST /v1/space-sessions?id=<uuid>&action=close {transactionId?} → cierra (F0+F1: sin cobro; F2 lo llamará con transactionId)
 *   POST /v1/space-sessions?id=<uuid>&action=update {alias?, guests?, waiterId?} → edita la ocupación
 *   POST /v1/space-sessions?id=<uuid>&action=move   {targetSpaceId}  → mueve la mesa a otro espacio libre
 *   POST /v1/space-sessions?id=<uuid>&action=merge  {targetSessionId} → une esta cuenta a otra (esta es el ORIGEN)
 *
 * Auth: panel + pos-app. pos-app queda scopeado al outlet del device (mismo
 * patrón outletScope de orders-core.php).
 *
 * Exclusividad de mesa (owner 2026-08-23): una mesa con mozo asignado solo la
 * opera ese mozo, o quien tenga `pos.space.override`. El enforcement NO está
 * acá sino en `SpaceSessionService` (vía `SpaceOwnershipGuard`), para que valga
 * también para los otros callers del service; este archivo solo resuelve QUIÉN
 * está operando y traduce el rechazo a 403. Ver `Punto\Api\Auth\OperatorContext`
 * para por qué la persona no se puede leer del token bajo realm `pos-app`.
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

require_once __DIR__ . '/../lib/Auth/OperatorContext.php';
$operator = \Punto\Api\Auth\OperatorContext::resolve($ctx);
$svc      = new \Punto\Api\Spaces\SpaceSessionService($db, $operator);

/**
 * Traduce el rechazo por exclusividad a 403 y todo lo demás a 422. Un solo
 * lugar: si cada action lo hiciera por su cuenta, la primera que se olvidara
 * devolvería 422 y el front lo mostraría como "datos inválidos" en vez de
 * "esta mesa no es tuya".
 */
function spaceSessionFail(\Throwable $e): void
{
    if ($e instanceof \Punto\Api\Spaces\SpaceOwnershipException) {
        apiError($e->getMessage(), 403);
    }
    apiError($e->getMessage(), 422);
}

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
                spaceSessionFail($e);
            }
            break;
        }

        if ($id !== null && $action === 'cancel') {
            try {
                apiOk($svc->cancel($companyId, (string) $id, $outletScope));
            } catch (\Throwable $e) {
                spaceSessionFail($e);
            }
            break;
        }

        if ($id !== null && $action === 'update') {
            // Contrato por PRESENCIA de la clave, no por valor: mandar
            // `alias: ""` significa "borrale el alias" y tiene que poder
            // distinguirse de no mandarlo. Por eso se arma el array mirando
            // array_key_exists sobre el body, no `!empty()`.
            $fields = [];
            if (array_key_exists('alias', $_POST))    $fields['alias']    = $_POST['alias'];
            if (array_key_exists('guests', $_POST))   $fields['guests']   = ($_POST['guests'] === '' || $_POST['guests'] === null) ? null : (int) $_POST['guests'];
            if (array_key_exists('waiterId', $_POST)) $fields['waiterId'] = $_POST['waiterId'];
            if ($fields === []) apiError('Nada para actualizar (alias, guests o waiterId)', 422);
            try {
                apiOk($svc->update($companyId, (string) $id, $fields, $outletScope));
            } catch (\Throwable $e) {
                spaceSessionFail($e);
            }
            break;
        }

        if ($id !== null && $action === 'move') {
            $targetSpaceId = trim((string) ($_POST['targetSpaceId'] ?? ''));
            if ($targetSpaceId === '') apiError('targetSpaceId requerido', 422);
            try {
                apiOk($svc->move($companyId, (string) $id, $targetSpaceId, $outletScope));
            } catch (\Throwable $e) {
                spaceSessionFail($e);
            }
            break;
        }

        if ($id !== null && $action === 'merge') {
            // `id` es la sesión ORIGEN (la que se absorbe y deja su espacio
            // libre); `targetSessionId` es la cuenta que sobrevive.
            $targetSessionId = trim((string) ($_POST['targetSessionId'] ?? ''));
            if ($targetSessionId === '') apiError('targetSessionId requerido', 422);
            try {
                apiOk($svc->merge($companyId, (string) $id, $targetSessionId, $outletScope));
            } catch (\Throwable $e) {
                spaceSessionFail($e);
            }
            break;
        }

        if ($id !== null && $action === 'close') {
            $transactionId = !empty($_POST['transactionId']) ? (string) $_POST['transactionId'] : null;
            // Cierre con saldo pendiente = perdonar lo que falta cobrar. Es
            // explícito y solo desde el panel: la caja no puede cerrar una
            // mesa a medio pagar por accidente.
            $forgiveBalance = ($ctx['realm'] ?? '') === 'panel'
                && filter_var($_POST['forgivePendingBalance'] ?? false, FILTER_VALIDATE_BOOLEAN);
            try {
                apiOk($svc->close($companyId, (string) $id, $transactionId, $outletScope, $forgiveBalance));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null) {
            apiError('action inválida (esperado: request-bill|cancel|close|update|move|merge)', 422);
        }

        try {
            $tableId  = (string) ($_POST['tableId'] ?? '');
            $guests   = isset($_POST['guests']) && $_POST['guests'] !== '' ? (int) $_POST['guests'] : null;
            $waiterId = !empty($_POST['waiterId']) ? (string) $_POST['waiterId'] : null;
            $alias    = !empty($_POST['alias']) ? (string) $_POST['alias'] : null;
            if ($tableId === '') apiError('tableId requerido', 422);
            apiOk($svc->open($companyId, $tableId, $guests, $waiterId, $outletScope, $alias), 201);
        } catch (\Throwable $e) {
            spaceSessionFail($e);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}

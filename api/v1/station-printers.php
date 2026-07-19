<?php
/**
 * /v1/station-printers — impresoras físicas de una Estación de Impresión
 * (P0, context/26-print-station-plan.md). La estación es un device pareado
 * (module='print', ver DISPLAY_MODULES en api/v1/screens.php); no hay tabla
 * `print_station` — la identidad de la estación ES el device, y las
 * impresoras físicas cuelgan de él.
 *
 *   GET  /v1/station-printers[?outletId=<uuid>]        → lista (panel, pos-app, estación)
 *   POST /v1/station-printers                          → registra/re-anuncia una impresora (SOLO estación)
 *   POST /v1/station-printers?id=<uuid>                → actualiza metadata propia (SOLO estación, debe ser su device)
 *   PUT  /v1/station-printers?id=<uuid>                 → rename (SOLO panel)
 *   DELETE /v1/station-printers?id=<uuid>               → soft-delete (SOLO panel)
 *
 * El registro nace DESDE la estación: ella descubre el hardware físico
 * (USB/BT/red) y se auto-registra. El panel solo puede renombrar/borrar, NUNCA
 * crear impresoras a mano (no tiene forma de saber qué hardware existe).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Printing\PrintPoolService;

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;

global $db;
$svc = new PrintPoolService($db);

switch ($method) {
    case 'GET': {
        $ctx       = apiAuthTenant(['panel', 'pos-app']);
        $companyId = $ctx['companyId'];
        $isPosApp  = ($ctx['realm'] ?? '') === 'pos-app';
        $outletId  = $isPosApp ? $ctx['outletId'] : ($_GET['outletId'] ?? null);
        apiOk(['printers' => $svc->listPrinters($companyId, $outletId !== '' ? $outletId : null)]);
        break;
    }

    case 'POST': {
        // Registro y auto-actualización: SOLO la propia estación (realm
        // pos-app, module='print'). El panel no puede crear/tocar impresoras
        // por POST — solo PUT (rename) / DELETE.
        $ctx = apiAuthTenant(['pos-app']);
        if (($ctx['module'] ?? '') !== 'print') {
            apiError("El dispositivo ({$ctx['module']}) no puede registrar impresoras de estación", 403);
        }
        $companyId = $ctx['companyId'];
        $outletId  = $ctx['outletId'];
        $deviceId  = defined('AUTHED_DEVICE_ID') ? AUTHED_DEVICE_ID : '';
        if ($deviceId === '') {
            apiError('Device no identificado', 401);
        }

        try {
            if ($id !== null) {
                // Actualiza SU PROPIA impresora (ej. status tras un fallo de conexión).
                $printer = $svc->findPrinter($companyId, (string) $id);
                if ($printer === null || $printer['deviceId'] !== $deviceId) {
                    apiError('Impresora no encontrada', 404);
                }
                apiOk($svc->updatePrinter($companyId, (string) $id, $_POST));
                break;
            }
            apiOk($svc->registerPrinter($companyId, $outletId, $deviceId, $_POST), 201);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;
    }

    case 'PUT': {
        // Rename desde panel — único campo editable por esta vía.
        $ctx       = apiAuthTenant(['panel']);
        $companyId = $ctx['companyId'];
        if ($id === null) apiError('id requerido', 422);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') apiError('name requerido', 422);
        try {
            apiOk($svc->updatePrinter($companyId, (string) $id, ['name' => $name]));
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 404);
        }
        break;
    }

    case 'DELETE': {
        $ctx       = apiAuthTenant(['panel']);
        $companyId = $ctx['companyId'];
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->deletePrinter($companyId, (string) $id);
            apiOk(['ok' => true]);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 404);
        }
        break;
    }

    default:
        apiError('Method not allowed', 405);
}

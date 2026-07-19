<?php
/**
 * /v1/print-jobs — cola durable del pool de impresión (P0,
 * context/26-print-station-plan.md). `print_job` es la fuente de verdad: un
 * comando de cocina no se pierde porque la estación estaba offline. El WS
 * (`{companyId}:print:{outletId}`) solo notifica — la estación re-sincroniza
 * pendientes con GET ?resource=pending al conectar y en cada reconexión.
 *
 *   POST /v1/print-jobs?action=enqueue   (panel, pos-app)      → encola (el caller ya renderizó el payload)
 *   GET  /v1/print-jobs?resource=pending (SOLO estación)        → jobs queued de las impresoras de esta estación
 *   POST /v1/print-jobs?id=<uuid>&action=claim  (SOLO estación) → CAS queued → printing
 *   POST /v1/print-jobs?id=<uuid>&action=done   (SOLO estación) → CAS printing → done
 *   POST /v1/print-jobs?id=<uuid>&action=failed {error} (SOLO estación) → CAS printing → failed|queued (retry)
 *   POST /v1/print-jobs?id=<uuid>&action=cancel (SOLO panel)   → CAS queued → cancelled
 *   GET  /v1/print-jobs (SOLO panel, filtros outletId/stationPrinterId/status/from/to) → auditoría/reimpresión
 *
 * Auth por module (mismo patrón que assertModuleCanSetStatus en
 * orders-core.php): dentro del realm pos-app, SOLO el module 'print' puede
 * operar este endpoint como estación (pending/claim/done/failed) — un POS o
 * KDS del mismo tenant no puede tocar la cola de impresión. Whitelist
 * explícita, no un "cualquier device pos-app puede".
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Printing\PrintPoolService;

/** Whitelist explícita: solo el module 'print' opera la cola como estación. */
function assertPrintStationModule(?string $module): void
{
    if ($module !== 'print') {
        apiError("El dispositivo ({$module}) no puede operar la cola de impresión de estación", 403);
    }
}

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;
$action   = $_GET['action'] ?? null;
$resource = $_GET['resource'] ?? null;

global $db;
$svc = new PrintPoolService($db);

switch (true) {

    // ── GET ?resource=pending — SOLO estación ────────────────────────────
    case $method === 'GET' && $resource === 'pending': {
        $ctx = apiAuthTenant(['pos-app']);
        assertPrintStationModule($ctx['module'] ?? null);
        $deviceId = defined('AUTHED_DEVICE_ID') ? AUTHED_DEVICE_ID : '';
        if ($deviceId === '') apiError('Device no identificado', 401);
        apiOk(['jobs' => $svc->listPending($ctx['companyId'], $deviceId)]);
        break;
    }

    // ── GET (list) — SOLO panel, auditoría ───────────────────────────────
    case $method === 'GET': {
        $ctx       = apiAuthTenant(['panel']);
        $companyId = $ctx['companyId'];
        $filters = [
            'outletId'         => $_GET['outletId'] ?? null,
            'stationPrinterId' => $_GET['stationPrinterId'] ?? null,
            'status'           => $_GET['status'] ?? null,
            'from'             => $_GET['from'] ?? null,
            'to'               => $_GET['to'] ?? null,
        ];
        apiOk(['jobs' => $svc->listJobs($companyId, array_filter($filters, static fn ($v) => $v !== null && $v !== ''))]);
        break;
    }

    // ── POST ?action=enqueue — panel + pos-app (el caller ya renderizó) ──
    case $method === 'POST' && $action === 'enqueue': {
        $ctx       = apiAuthTenant(['panel', 'pos-app']);
        $companyId = $ctx['companyId'];
        $deviceId  = defined('AUTHED_DEVICE_ID') ? AUTHED_DEVICE_ID : '';
        // pos-app queda scopeado a su propia sucursal; panel (admin) puede
        // encolar cross-outlet (reimpresión desde auditoría).
        $requireOutletId = ($ctx['realm'] ?? '') === 'pos-app' ? (string) ($ctx['outletId'] ?? '') : null;
        try {
            apiOk($svc->enqueue($companyId, $deviceId, $_POST, $requireOutletId), 201);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;
    }

    // ── POST ?id=&action=claim|done|failed — SOLO estación ───────────────
    case $method === 'POST' && in_array($action, ['claim', 'done', 'failed'], true): {
        $ctx = apiAuthTenant(['pos-app']);
        assertPrintStationModule($ctx['module'] ?? null);
        $companyId = $ctx['companyId'];
        $deviceId  = defined('AUTHED_DEVICE_ID') ? AUTHED_DEVICE_ID : '';
        if ($deviceId === '') apiError('Device no identificado', 401);
        if ($id === null) apiError('id requerido', 422);

        try {
            switch ($action) {
                case 'claim':
                    apiOk($svc->claim($companyId, (string) $id, $deviceId));
                    break;
                case 'done':
                    apiOk($svc->markDone($companyId, (string) $id, $deviceId));
                    break;
                case 'failed':
                    $error = (string) ($_POST['error'] ?? 'Error desconocido');
                    apiOk($svc->markFailed($companyId, (string) $id, $deviceId, $error));
                    break;
            }
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;
    }

    // ── POST ?id=&action=cancel — SOLO panel ─────────────────────────────
    case $method === 'POST' && $action === 'cancel': {
        $ctx       = apiAuthTenant(['panel']);
        $companyId = $ctx['companyId'];
        if ($id === null) apiError('id requerido', 422);
        try {
            apiOk($svc->cancel($companyId, (string) $id));
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;
    }

    case $method === 'POST':
        apiError('action inválida (esperado: enqueue|claim|done|failed|cancel)', 422);
        break;

    default:
        apiError('Method not allowed', 405);
}

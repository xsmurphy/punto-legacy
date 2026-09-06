<?php
/**
 * REST — Lotes de producción multi-plato (context/70-viandas.md, etapa B / F2).
 *
 *   GET  /v1/production-batches                       → lista (filtros: status, outletId, from, to)
 *   GET  /v1/production-batches?id=<uuid>             → detalle (lote + líneas + necesidad si está en draft)
 *   POST /v1/production-batches?resource=estimate     → necesidad consolidada (LECTURA PURA, no escribe)
 *                                                       body: outletId, locationId?, lines:[{itemId, qty}]
 *   POST /v1/production-batches                       → crea el lote en draft + sus N órdenes hijas
 *                                                       body: outletId, locationId?, outputLocationId?,
 *                                                             note?, lines:[{itemId, qty}]
 *   POST /v1/production-batches?id=<uuid>&action=confirm → completa TODAS las líneas (atómico)
 *   POST /v1/production-batches?id=<uuid>&action=cancel  → cancela el lote y sus líneas (solo draft)
 *
 * Auth: panel. Escritura gateada por `production.manage` — la MISMA clave que
 * ya gobierna `production.php`, `waste.php` y `waste-reasons.php`. No se crea
 * una clave nueva: el lote no es una capacidad distinta de "producir", es la
 * misma operación para varios platos a la vez, y una clave más obligaría a
 * cada comercio a re-tildar un permiso para algo que su encargado de
 * producción ya podía hacer de a uno. Sin clave nueva no hace falta migración
 * de backfill.
 *
 * POR QUÉ `estimate` ES POST SIENDO UNA LECTURA
 * ----------------------------------------------
 * Su entrada es una lista de N pares {plato, cantidad}. Serializarla en la
 * query string la vuelve frágil (largo de URL, orden, escaping) y la deja
 * cacheada en logs y en el historial del browser. El verbo es POST por el
 * BODY, no por el efecto: el servicio no escribe una fila. Como POST, cae
 * bajo la misma gate `production.manage` que el resto del módulo, lo cual es
 * deseable: la necesidad consolidada expone saldos de stock del comercio.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;
$resource  = $_GET['resource'] ?? null;
$action    = $_GET['action'] ?? null;

global $db;
$svc = new \Punto\Api\Production\ProductionBatchService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $batch = $svc->find($companyId, (string) $id);
            if ($batch === null) apiError('Lote de producción no encontrado', 404);
            apiOk($batch);
            break;
        }
        $filters = [
            'status'   => $_GET['status'] ?? null,
            'outletId' => $_GET['outletId'] ?? null,
            'from'     => $_GET['from'] ?? null,
            'to'       => $_GET['to'] ?? null,
        ];
        apiOk(['batches' => $svc->list($companyId, array_filter($filters, static fn ($v) => $v !== null && $v !== ''))]);
        break;

    case 'POST':
        if (!hasPermission('production.manage')) {
            apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
        }

        if ($resource === 'estimate') {
            $outletId   = (string) ($_POST['outletId'] ?? ($ctx['outletId'] ?? ''));
            $locationId = !empty($_POST['locationId']) ? (string) $_POST['locationId'] : null;
            if ($outletId === '') {
                apiError('outletId es requerido', 422);
            }
            try {
                apiOk($svc->estimate($companyId, $outletId, (array) ($_POST['lines'] ?? []), $locationId));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'confirm') {
            try {
                apiOk($svc->confirm($companyId, $userId, (string) $id));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'cancel') {
            try {
                $svc->cancel($companyId, (string) $id);
                apiOk($svc->find($companyId, (string) $id));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null) {
            apiError('action inválida (esperado: confirm|cancel)', 422);
        }

        try {
            $newId = $svc->create($companyId, $userId, $_POST);
            apiOk($svc->find($companyId, $newId), 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}

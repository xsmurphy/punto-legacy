<?php
/**
 * /api/v1/printer_binding.php — bindings de impresoras por caja (registerId).
 *
 *   GET  ?registerId=<uuid>                  → lista bindings de esa caja
 *   POST {action:"create", registerId, ...}  → crea binding
 *   POST {action:"update", id, ...}          → actualiza binding
 *   POST {action:"delete", id}               → elimina binding
 *
 * companyId/outletId SIEMPRE del JWT. Envelope canónico { ok, data }.
 * Realms: panel + pos-app.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/PrinterBindingService.php';
use Punto\Api\Services\PrinterBindingService;

$ctx        = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];

$svc        = new PrinterBindingService($companyId, $outletId);
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $registerId = trim((string)($_GET['registerId'] ?? ''));
    if ($registerId === '') apiError('registerId requerido', 422);
    apiOk(['bindings' => $svc->list($registerId)]);
}

if ($method === 'POST') {
    $body   = $_POST;
    $action = (string)($body['action'] ?? '');

    if ($action === 'create') {
        $registerId = trim((string)($body['registerId'] ?? ''));
        if ($registerId === '') apiError('registerId requerido', 422);
        try {
            $binding = $svc->create($registerId, $body);
            apiOk(['binding' => $binding]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    if ($action === 'update') {
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') apiError('id requerido', 422);
        try {
            $binding = $svc->update($id, $body);
            apiOk(['binding' => $binding]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    if ($action === 'delete') {
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') apiError('id requerido', 422);
        $svc->delete($id);
        apiOk(['deleted' => true]);
    }

    apiError('Acción inválida', 422);
}

apiError('Método no permitido', 405);

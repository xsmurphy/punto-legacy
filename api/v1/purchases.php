<?php
/**
 * REST canónico — Compras del panel.
 *
 *   GET  /v1/purchases?from=&to=&supplierId=&limit=&offset= → lista
 *   GET  /v1/purchases?id=<uuid>                            → detalle
 *   POST /v1/purchases  { supplierId, outletId, items, ... }→ crea
 *
 * Auth realm `panel`. Respeta VIEW_OUTLET_ID si el browser mandó X-Outlet-Id.
 *
 * Esta primera vuelta soporta SOLO compras (transactionType=1). Ordenes,
 * devoluciones y reposiciones del legacy quedan para iteración posterior.
 */
require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Purchases\PurchasesService();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $row = $svc->find($id, (string) COMPANY_ID);
        if (!$row) {
            apiError('Compra no encontrada', 404);
        }
        apiOk($row);
    }

    try {
        $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID, 't');
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 500);
    }

    $filters = [
        'from'       => $_GET['from'] ?? null,
        'to'         => $_GET['to'] ?? null,
        'supplierId' => $_GET['supplierId'] ?? null,
        'limit'      => $_GET['limit'] ?? null,
        'offset'     => $_GET['offset'] ?? null,
    ];

    apiOk($svc->list((string) COMPANY_ID, $roc, $filters));
}

if ($method === 'POST') {
    // Body JSON.
    $raw = file_get_contents('php://input');
    $body = [];
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $body = $json;
        }
    }

    // outletId: si no viene, usar OUTLET_ID del JWT.
    if (empty($body['outletId'])) {
        $body['outletId'] = (string) OUTLET_ID;
    }
    $body['userId'] = (string) ($ctx['userId'] ?? '');

    try {
        $id = $svc->create((string) COMPANY_ID, $body);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }

    apiOk(['id' => $id]);
}

apiError('Método no permitido', 405);

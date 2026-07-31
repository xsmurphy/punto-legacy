<?php
/**
 * REST — Borradores de compra generados por OCR/IA.
 * Ver context/32-ocr-facturas-compra.md (plan cerrado 2026-07-31).
 *
 *   GET    /v1/purchase-drafts?status=&limit=&offset=        → lista (resumen)
 *   GET    /v1/purchase-drafts?id=<uuid>                      → detalle completo
 *   POST   /v1/purchase-drafts   multipart(image, extracted, outletId, error?)
 *                                                              → crea (llamado por
 *          el BFF de Next, frontend/app/api/ocr-invoice/route.ts, tras la
 *          extracción IA — NUNCA se llama directo desde el browser)
 *   PUT    /v1/purchase-drafts?id=<uuid>  { edited?, contactId? } → guarda correcciones
 *   POST   /v1/purchase-drafts?id=<uuid>&resource=approve { edited? } → aprueba
 *   POST   /v1/purchase-drafts?id=<uuid>&resource=reject             → rechaza
 *
 * Auth: realm panel. El registro de compras (/v1/purchases) hoy no tiene una
 * permission key dedicada — solo exige sesión de panel autenticada
 * (apiAuthTenant(['panel'])) — este endpoint replica exactamente ese mismo
 * alcance, sin inventar un permiso nuevo que no existe para el módulo hoy.
 *
 * La IA NUNCA escribe stock/finanzas: `approve` es el ÚNICO camino a crear la
 * compra real, y reusa `PurchasesService::create` con el payload editado por
 * el humano — el mismo código que la creación manual de /v1/purchases.
 */
require_once __DIR__ . '/../bootstrap.php';

use Punto\Api\Purchases\PurchaseDraftService;
use Punto\Api\Finance\FinanceLedger;

$ctx = apiAuthTenant(['panel']);
$svc = new PurchaseDraftService();

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = trim((string) ($_GET['id'] ?? ''));
$resource = trim((string) ($_GET['resource'] ?? ''));

if ($method === 'GET') {
    if ($id !== '') {
        $row = $svc->find($id, (string) COMPANY_ID);
        if (!$row) {
            apiError('Borrador no encontrado', 404);
        }
        apiOk($row);
    }

    $filters = [
        'status' => $_GET['status'] ?? null,
        'limit'  => $_GET['limit'] ?? null,
        'offset' => $_GET['offset'] ?? null,
    ];
    apiOk($svc->list((string) COMPANY_ID, $filters));
}

if ($method === 'POST' && $id === '') {
    if (empty($_FILES['image']['tmp_name'])) {
        apiError('Falta archivo (campo "image")', 422);
    }

    $outletId = trim((string) ($_POST['outletId'] ?? ''));
    if ($outletId === '') {
        $outletId = (string) OUTLET_ID;
    }

    $extracted = null;
    $rawExtracted = (string) ($_POST['extracted'] ?? '');
    if ($rawExtracted !== '') {
        $decoded = json_decode($rawExtracted, true);
        if (is_array($decoded)) {
            $extracted = $decoded;
        }
    }
    $extractError = trim((string) ($_POST['error'] ?? '')) ?: null;

    try {
        $row = $svc->create(
            (string) COMPANY_ID,
            $outletId,
            (string) ($ctx['userId'] ?? ''),
            $_FILES['image'],
            $extracted,
            $extractError
        );
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($row, 201);
}

if ($method === 'PUT' && $id !== '') {
    $raw  = file_get_contents('php://input');
    $body = [];
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $body = $json;
        }
    }
    try {
        $row = $svc->update($id, (string) COMPANY_ID, $body);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($row);
}

if ($method === 'POST' && $id !== '' && $resource === 'approve') {
    $raw  = file_get_contents('php://input');
    $body = [];
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $body = $json;
        }
    }
    $editedOverride = isset($body['edited']) && is_array($body['edited']) ? $body['edited'] : null;

    try {
        $result = $svc->approve($id, (string) COMPANY_ID, (string) ($ctx['userId'] ?? ''), $editedOverride);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }

    // Finanzas Fase 3: auto-poblado del ledger, best-effort — mismo patrón
    // que POST /v1/purchases. Nunca rompe la aprobación del borrador ya
    // confirmada (la compra ya existe si esto falla).
    if (!empty($result['transactionId']) && empty($result['alreadyApproved'])) {
        try {
            (new FinanceLedger())->recordPurchase((string) COMPANY_ID, $result['transactionId']);
        } catch (\Throwable $e) {
            error_log('[FinanceLedger] recordPurchase falló para draft=' . $id . ': ' . $e->getMessage());
        }
    }

    apiOk($result);
}

if ($method === 'POST' && $id !== '' && $resource === 'reject') {
    try {
        $result = $svc->reject($id, (string) COMPANY_ID);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($result);
}

apiError('Método no permitido', 405);

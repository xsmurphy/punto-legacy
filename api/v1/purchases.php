<?php
/**
 * REST canónico — Compras del panel.
 *
 *   GET    /v1/purchases?from=&to=&supplierId=&limit=&offset=     → lista
 *   GET    /v1/purchases?id=<uuid>                                → detalle (incluye creditNotes + creditedQty)
 *   POST   /v1/purchases  { supplierId, outletId, items, ... }    → crea
 *   POST   /v1/purchases?resource=creditNote { parentTransactionId, items, refundMode, affectsStock, note? } → nota de crédito de compra
 *   DELETE /v1/purchases?id=<uuid>                                → anula la compra
 *   DELETE /v1/purchases?resource=creditNote&id=<uuid>            → anula una nota de crédito de compra
 *
 * Auth realm `panel`. Respeta VIEW_OUTLET_ID si el browser mandó X-Outlet-Id.
 *
 * Soporta compras al contado (transactionType=1) y a crédito
 * (transactionType=4, `condition:'credit'` en el POST → queda pendiente y
 * aparece en Cuentas por pagar y Previsiones). Devoluciones y reposiciones
 * del legacy quedan para iteración posterior.
 */
require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc   = new \Punto\Api\Purchases\PurchasesService();
$ncSvc = new \Punto\Api\Purchases\PurchaseCreditNoteService();

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = trim((string) ($_GET['resource'] ?? ''));

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $row = $svc->find($id, (string) COMPANY_ID);
        if (!$row) {
            apiError('Compra no encontrada', 404);
        }
        // Notas de crédito ya emitidas + cupo ya consumido por línea — el
        // detalle de compra las lista y el form de emisión usa `creditedQty`
        // para calcular el máximo disponible por ítem (comprado - acreditado).
        $row['creditNotes'] = $ncSvc->listForParent($id, (string) COMPANY_ID);
        $row['creditedQty'] = $ncSvc->creditedQtyByItem($id, (string) COMPANY_ID);
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

if ($method === 'POST' && $resource === 'creditNote') {
    $raw  = file_get_contents('php://input');
    $body = [];
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $body = $json;
        }
    }

    $parentTransactionId = trim((string) ($body['parentTransactionId'] ?? ''));
    $items                = is_array($body['items'] ?? null) ? $body['items'] : [];
    $refundMode           = trim((string) ($body['refundMode'] ?? ''));
    $affectsStock         = (bool) ($body['affectsStock'] ?? true);
    $note                 = isset($body['note']) && trim((string) $body['note']) !== '' ? trim((string) $body['note']) : null;
    // Comprobante+timbrado del proveedor (mig 144) — opcional, pasa tal cual al service.
    $supplierDoc          = is_array($body['supplierDoc'] ?? null) ? $body['supplierDoc'] : null;

    if ($parentTransactionId === '') {
        apiError('parentTransactionId requerido', 422);
    }
    if ($items === []) {
        apiError('items debe ser un array no vacío', 422);
    }

    // companyId/userId/outletId SIEMPRE del JWT — nunca del body.
    try {
        $result = $ncSvc->create(
            (string) COMPANY_ID,
            (string) ($ctx['userId'] ?? ''),
            (string) OUTLET_ID,
            $parentTransactionId,
            $items,
            $refundMode,
            $affectsStock,
            $note,
            $supplierDoc,
        );
    } catch (\InvalidArgumentException $e) {
        apiError($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        error_log('[purchases.creditNote] ' . $e->getMessage());
        apiError('No se pudo emitir la nota de crédito', 500);
    }

    apiOk($result);
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

    // Finanzas Fase 3: auto-poblado del ledger, best-effort — nunca rompe la compra.
    try {
        (new \Punto\Api\Finance\FinanceLedger())->recordPurchase((string) COMPANY_ID, $id);
    } catch (\Throwable $e) {
        error_log('[FinanceLedger] recordPurchase falló para id=' . $id . ': ' . $e->getMessage());
    }

    apiOk(['id' => $id]);
}

if ($method === 'DELETE' && $resource === 'creditNote') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        apiError('id requerido', 400);
    }
    try {
        $res = $ncSvc->void($id, (string) COMPANY_ID, (string) ($ctx['userId'] ?? ''));
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }

    // Revierte el movimiento derivado de ESTA nota de crédito (source =
    // 'purchase_credit_note', sourceId = el id de la NC — no el de la
    // compra padre). Modo 'credit' nunca generó movimiento, así que acá es
    // un no-op best-effort; modo 'cash' sí tiene uno que revertir.
    try {
        (new \Punto\Api\Finance\FinanceLedger())->voidBySource((string) COMPANY_ID, 'purchase_credit_note', $id);
    } catch (\Throwable $e) {
        error_log('[FinanceLedger] voidBySource(purchase_credit_note) falló para id=' . $id . ': ' . $e->getMessage());
    }

    apiOk($res);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        apiError('id requerido', 400);
    }
    try {
        $res = $svc->void($id, (string) COMPANY_ID, (string) ($ctx['userId'] ?? ''));
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }

    // Finanzas Fase 3: revierte el/los movimiento(s) derivados de esta compra.
    try {
        (new \Punto\Api\Finance\FinanceLedger())->voidBySource((string) COMPANY_ID, 'purchase', $id);
    } catch (\Throwable $e) {
        error_log('[FinanceLedger] voidBySource(purchase) falló para id=' . $id . ': ' . $e->getMessage());
    }

    apiOk($res);
}

apiError('Método no permitido', 405);

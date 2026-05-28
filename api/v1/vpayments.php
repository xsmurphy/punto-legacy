<?php
/**
 * /api/v1/vpayments.php — pagos electrónicos del POS (Slice 15, cluster ENCOM→Punto).
 *
 *   POST { source, authCode, amount, saleAmount, UID, operationNo[, data, status] }
 *        → registra un vPayment (port fiel de panel/API/add_vpayment.php)
 *   GET  ?code=  → verifica un authCode APPROVED (lectura)
 *
 * companyId/outletId/userId SIEMPRE del JWT. PATH DE DINERO — ver VPaymentService para
 * las salvedades de eposData y el branch 4456 omitido. Respuesta canónica { ok, data };
 * el BFF desenvuelve data (el front lee result.success / result.error al top level).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/VPaymentService.php';

$ctx        = apiAuthTenant();
$companyId   = $ctx['companyId'];
$outletId    = $ctx['outletId'];
$userId      = $ctx['userId'];

$svc    = new VPaymentService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET ?code= → verifica un authCode APPROVED (lectura pura). Verbos REST (§22.7).
if ($method === 'GET') {
    $code     = (string) ($_GET['code'] ?? '');
    $authCode = $svc->verify($companyId, $code);
    if ($authCode !== null) {
        apiOk(['success' => $authCode]);
    }
    apiError('Código no encontrado', 401);
}

// POST → registra un vPayment (crea recurso).
if ($method === 'POST') {
    // nroOperacion (orderNo) se computa server-side, igual que los handlers legacy.
    $order = substr((string) strtotime(date('Y-m-d H:i:s')), -6);

    $in = [
        'UID'         => $_POST['UID']         ?? null,
        'saleAmount'  => $_POST['saleAmount']  ?? 0,
        'amount'      => $_POST['amount']      ?? 0,   // recibido por paridad; el service usa saleAmount
        'source'      => $_POST['source']      ?? null,
        'status'      => !empty($_POST['status']) ? $_POST['status'] : 'REVIEW',
        'authCode'    => $_POST['authCode']    ?? '',
        'operationNo' => $_POST['operationNo'] ?? null,
        'order'       => $order,
        'data'        => $_POST['data']        ?? null,
    ];

    apiOk($svc->add($companyId, $outletId, $userId, $in));
}

apiError('Método no permitido', 405);

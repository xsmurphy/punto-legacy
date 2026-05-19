<?php
/**
 * API pública para el CDS (Customer Display System).
 * Auth: slug opaco ?s= que codifica companyId,outletId en base64.
 *
 * Acciones GET:
 *   ?s=<slug>&action=time   → hora del servidor
 *   ?s=<slug>&action=list   → órdenes en proceso y listas para retirar
 */

require_once __DIR__ . '/lib/api_middleware.php';

// Slug viene del query string. validateHttp() vive en functions.php, que se
// carga DENTRO de apiMiddlewarePublic(), así que acá solo leemos el raw $_GET.
$slug = (string)($_GET['s'] ?? '');
apiMiddlewarePublic($slug);

$parts       = explode(',', base64_decode($slug));
$ECOMPANY_ID = trim($parts[0] ?? '');
$EOUTLET_ID  = trim($parts[1] ?? '');
$companyId   = dec($ECOMPANY_ID);
$outletId    = dec($EOUTLET_ID);

if (!$companyId || !$outletId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Slug inválido']);
    exit;
}

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletId);

$serverDate = date('Y-m-d H');
$action     = validateHttp('action');

function cdsJson(mixed $data): never {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if ($action === 'time') {
    cdsJson(['date' => $serverDate]);
}

if ($action === 'list') {
    $API_KEY = getAPICreds(COMPANY_ID);
    $data = [
        'api_key'      => $API_KEY,
        'company_id'   => $ECOMPANY_ID,
        'children'     => 'all',
        'customerdata' => 1,
        'type'         => 12,
        'limit'        => 100,
        'order'        => 'DESC',
        'outlet'       => $EOUTLET_ID,
        'status'       => '3,5',
        'from'         => date('Y-m-d H:i:s', strtotime('-1 day')),
        'to'           => date('Y-m-d 23:59:59'),
    ];

    if (validateHttp('reverse')) {
        $data['reverse'] = 'true';
    }

    $result           = json_decode(curlContents(API_URL . '/get_orders.php', 'POST', $data), true);
    cdsJson(['orders' => $result]);
}

http_response_code(404);
cdsJson(['error' => 'Acción no encontrada']);

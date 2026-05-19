<?php
/**
 * API pública para el KDS (Kitchen Display System).
 * Auth lectura: slug opaco ?s= que codifica companyId,outletId en base64.
 * Auth escritura: HMAC write token ?w=<expiry>.<sig> emitido por screens/kds.php
 * (válido 24h, firmado con JWT_SECRET, bindea companyId+outletId+expiry).
 *
 * Acciones GET (lectura, solo slug):
 *   ?s=<slug>&action=time          → hora del servidor (para sincronía cliente)
 *   ?s=<slug>&action=lists         → órdenes activas (con delta opcional via ?time=)
 *   ?s=<slug>&action=items         → catálogo de ítems
 *   ?s=<slug>&action=tags          → etiquetas
 *   ?s=<slug>&action=categories    → categorías
 *
 * Acción de escritura (requiere &w=<expiry>.<sig>):
 *   ?s=<slug>&action=update&i=&t=&d=&w= → actualiza estado de orden + push WS
 */

require_once __DIR__ . '/lib/api_middleware.php';

// Slug viene del query string. validateHttp() vive en functions.php, que se
// carga DENTRO de apiMiddlewarePublic(), así que acá solo leemos el raw $_GET.
$slug = (string)($_GET['s'] ?? '');
apiMiddlewarePublic($slug);

// Decodificar slug: base64(companyId,outletId)
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

// Respuesta JSON sin envelope canónico para compatibilidad con el JS del KDS
function kdsJson(mixed $data): never {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// --------------------------------------------------------------------------
// Acciones que no necesitan API_KEY
// --------------------------------------------------------------------------

if ($action === 'time') {
    kdsJson(['date' => $serverDate]);
}

// --------------------------------------------------------------------------
// Acciones que llaman internamente al panel/API (proxy)
// --------------------------------------------------------------------------

$API_KEY = getAPICreds(COMPANY_ID);
$data = [
    'api_key'      => $API_KEY,
    'company_id'   => $ECOMPANY_ID,
    'children'     => 'all',
    'customerdata' => 1,
];

if ($action === 'lists') {
    $compTime = validateHttp('compTime');
    if ($compTime && $compTime !== $serverDate) {
        kdsJson(['error' => 'time', 'servers' => $serverDate, 'sent' => $compTime]);
    }

    $timestamp = validateHttp('time');
    $getList   = true;

    if ($timestamp) {
        $updated = json_decode(curlContents(API_URL . '/get_last_update.php', 'POST', $data), true);
        if (strtotime($updated['orders'] ?? 0) < strtotime($timestamp)) {
            $getList = false;
        }
    }

    $result = [];
    if ($getList) {
        $data['type']   = 12;
        $data['limit']  = 100;
        $data['order']  = 'DESC';
        $data['outlet'] = $EOUTLET_ID;
        $data['status'] = '0,1,2,3';
        if (validateHttp('reverse')) {
            $data['reverse'] = 'true';
        }
        $data['from']     = date('Y-m-d H:i:s', strtotime('-1 day'));
        $data['to']       = date('Y-m-d H:i:s');
        $orders           = json_decode(curlContents(API_URL . '/get_orders.php', 'POST', $data), true);
        $result['orders'] = $orders;
    }
    kdsJson($result);
}

if ($action === 'items') {
    $data['nolimit'] = true;
    $items = json_decode(curlContents(API_URL . '/get_items', 'POST', $data), true);
    kdsJson($items ?? []);
}

if ($action === 'tags') {
    $tags = json_decode(curlContents(API_URL . '/get_tags.php', 'POST', $data), true);
    kdsJson($tags ?? []);
}

if ($action === 'categories') {
    $cats = json_decode(curlContents(API_URL . '/get_categories.php', 'POST', $data), true);
    kdsJson($cats ?? []);
}

if ($action === 'update') {
    // Validar HMAC write token (?w=<expiry>.<sig>)
    $writeParam = validateHttp('w');
    $jwtSecret  = $_ENV['JWT_SECRET'] ?? '';

    if (!$writeParam || !$jwtSecret) {
        http_response_code(403);
        kdsJson(['error' => 'Token de escritura requerido']);
    }

    [$expiryStr, $sig] = array_pad(explode('.', $writeParam, 2), 2, '');
    $expiry = (int)$expiryStr;

    if ($expiry < time()) {
        http_response_code(403);
        kdsJson(['error' => 'Token de escritura expirado']);
    }

    $expected = hash_hmac('sha256', COMPANY_ID . '|' . OUTLET_ID . '|' . $expiry, $jwtSecret);

    if (!hash_equals($expected, $sig)) {
        http_response_code(403);
        kdsJson(['error' => 'Token de escritura inválido']);
    }

    $id     = validateHttp('i');
    $type   = validateHttp('t');
    $date   = validateHttp('d');
    $status = ($type === 'start') ? 3 : 5;

    $data['id']     = $id;
    $data['status'] = $status;
    $data['date']   = $date;
    $update = json_decode(curlContents(API_URL . '/edit_order_status.php', 'POST', $data), true);

    if ($update['success'] ?? false) {
        require_once __DIR__ . '/../includes/ws_publish.php';
        wsPublish(enc(OUTLET_ID) . '-register', 'order', ['ID' => $id, 'registerID' => false]);
        wsPublish(enc(OUTLET_ID) . '-KDS',      'order', $id);
    }
    kdsJson($update ?? []);
}

http_response_code(404);
kdsJson(['error' => 'Acción no encontrada']);

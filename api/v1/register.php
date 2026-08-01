<?php
/**
 * /api/v1/register.php — sesión de caja (register) del POS (Slice 10).
 *
 *   GET                → numeración de documentos de la caja (docsNum)
 *   PUT { sessionId }  → fija el sessionId de la caja (registerId del JWT) + broadcast WS
 *
 * registerId/companyId SIEMPRE del JWT (nunca del request). Envelope canónico { ok, data }.
 * El broadcast WS es best-effort: su falla no revierte la persistencia.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/RegisterService.php';
require_once __DIR__ . '/../lib/services/RegisterAdminService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\RegisterService;
use Punto\Api\Services\RegisterAdminService;

// MULTI-REALM (A7): la caja vive dentro del panel; acepta el realm panel
// además de pos-app.
$ctx        = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];
$registerId = $ctx['registerId'];
$outletId   = $ctx['outletId'];

$svc        = new RegisterService(TenantContext::fromAuth($ctx));
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource   = (string) ($_GET['resource'] ?? '');

// GET ?resource=listAll — lista todas las cajas del tenant.
// Multi-realm: el panel admin lo usa para CRUD; el POS lo usa para que el
// cajero pueda elegir otra caja/sucursal desde Ajustes (sin pasar por admin).
if ($method === 'GET' && $resource === 'listAll') {
    $adminSvc = new RegisterAdminService($companyId);
    apiOk(['registers' => $adminSvc->listAll()]);
}

// POST — operaciones admin de cajas (realm panel)
if ($method === 'POST') {
    $body   = $_POST;
    $action = (string)($body['action'] ?? '');

    if (!in_array($action, ['create', 'update', 'delete'], true)) {
        apiError('Acción inválida', 422);
    }

    // Solo realm panel para operaciones admin de cajas
    if (!in_array($ctx['realm'] ?? '', ['panel'], true)) {
        apiError('No autorizado', 403);
    }

    $adminSvc = new RegisterAdminService($companyId);

    if ($action === 'create') {
        $reqOutletId = trim((string)($body['outletId'] ?? ''));
        $name        = trim((string)($body['name'] ?? ''));
        apiOk($adminSvc->create($reqOutletId, $name));
    }

    if ($action === 'update') {
        $id     = trim((string)($body['id'] ?? ''));
        $fields = [];
        if (isset($body['name']))   { $fields['name']   = $body['name']; }
        if (isset($body['status'])) { $fields['status'] = filter_var($body['status'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$body['status']; }
        // Timbrado de la caja (número, EEE-PPP, vigencia) — la caja es el
        // punto de expedición; ver RegisterAdminService::update.
        if (isset($body['fiscal']) && is_array($body['fiscal'])) { $fields['fiscal'] = $body['fiscal']; }
        if ($id === '') { apiError('id requerido', 422); }
        apiOk($adminSvc->update($id, $fields));
    }

    if ($action === 'delete') {
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') { apiError('id requerido', 422); }
        apiOk($adminSvc->delete($id));
    }
}

// GET ?resource=list = cajas activas de la sucursal activa (selector de caja
// del POS, A7). companyId + outletId SIEMPRE del JWT (no del request).
if ($method === 'GET' && $resource === 'list') {
    // P1 code-review: sin sucursal activa no listamos (evita query con
    // outletId='' que rompería el invariante de scope-por-outlet).
    if ($outletId === '') {
        apiError('Sin sucursal activa', 422);
    }
    $rs = ncmExecute(
        'SELECT registerId, registerName, data
           FROM register
          WHERE companyId = ? AND outletId = ? AND registerStatus = TRUE
          ORDER BY registerName ASC',
        [$companyId, $outletId],
        false,
        true
    );
    $registers = [];
    if ($rs && is_object($rs)) {
        while (!$rs->EOF) {
            $f = $rs->fields;
            // Timbrado (mig 26 lo demoteó a `data` JSONB). El POS lo necesita
            // para el ticket impreso (flujo NO-FE, ver context/10-roadmap.md
            // §2026-07-30): TIMBRADO/INICIO/VENCIMIENTO salían en blanco
            // porque este endpoint nunca los exponía.
            //
            // OJO: ncmExecute APLANA `data` al nivel de la fila y borra la
            // columna (Query::flattenJsonb) — `$f['data']` acá es null. Los
            // campos del JSONB se leen DIRECTO de la fila aplanada; hacer
            // json_decode($f['data']) es el mismo bug silencioso de
            // parked-sales/pinhash (2026-07-30).
            $registers[] = [
                'id'                    => (string) ($f['registerId'] ?? ''),
                'name'                  => (string) ($f['registerName'] ?? ''),
                'invoiceAuth'           => (string) ($f['registerInvoiceAuth'] ?? ''),
                'invoicePrefix'         => (string) ($f['registerInvoicePrefix'] ?? ''),
                'invoiceAuthStart'      => (string) ($f['registerInvoiceAuthStart'] ?? ''),
                'invoiceAuthExpiration' => (string) ($f['registerInvoiceAuthExpiration'] ?? ''),
            ];
            $rs->MoveNext();
        }
        $rs->Close();
    }
    apiOk(['registers' => $registers]);
}

// GET ?resource=hotkeys = grilla de accesos directos (hotkeys) de la caja activa.
// registerId del JWT. La usa el POS para hidratar la grilla configurable (chunk 2).
if ($method === 'GET' && $resource === 'hotkeys') {
    if ($registerId === '') {
        apiError('Sin caja activa', 422);
    }
    apiOk(['hotkeys' => $svc->getHotkeys($registerId, $companyId)]);
}

// PUT ?resource=hotkeys = persistir la grilla de accesos directos en register.data.hotkeys.
// registerId/companyId SIEMPRE del JWT. Valida + normaliza el shape server-side antes de guardar.
if ($method === 'PUT' && $resource === 'hotkeys') {
    if ($registerId === '') {
        apiError('Sin caja activa', 422);
    }
    $raw = $_POST['hotkeys'] ?? null;
    if (!is_array($raw)) {
        apiError('Falta hotkeys (array)', 422);
    }
    // Shape canónico: {itemId, position, color, isCategory}. Descartamos entradas
    // sin itemId. Defensivo: el front es la fuente, pero no confiamos en el payload.
    $clean = [];
    foreach ($raw as $h) {
        if (!is_array($h)) {
            continue;
        }
        $itemId = (string) ($h['itemId'] ?? '');
        if ($itemId === '') {
            continue;
        }
        $clean[] = [
            'itemId'     => $itemId,
            'position'   => (int) ($h['position'] ?? 0),
            'color'      => (string) ($h['color'] ?? ''),
            'isCategory' => (bool) ($h['isCategory'] ?? false),
        ];
    }
    $ok = $svc->saveHotkeys($registerId, $companyId, $clean);
    if (!$ok) {
        apiError('No se pudo guardar la configuración de accesos', 500);
    }
    apiOk(['hotkeys' => $clean]);
}

// Config general del POS por caja (toggles AjustesPanel). registerId del JWT.
// Defaults canónicos en backend = single source of truth.
const POS_CONFIG_DEFAULTS = [
    'controlCaja'             => true,
    'tecladoVirtual'          => false,
    'ordenEnVenta'            => false,
    'ordenAImpresion'         => false,
    'servidorImpresion'       => false,
    'sonidosAlertas'          => false,
    'inhabilitarAnimaciones'  => false,
    'permitirGuardarVentas'   => false,
    'ocultarDetalleCombos'    => false,
    'modoSoloOrdenes'         => false,
    'mergeRepeated'           => true,
    'showSoftKeyboard'        => false,
];

if ($method === 'GET' && $resource === 'config') {
    if ($registerId === '') {
        apiError('Sin caja activa', 422);
    }
    $stored = $svc->getConfig($registerId, $companyId);
    $merged = array_merge(POS_CONFIG_DEFAULTS, $stored);
    // Descartar keys fuera del whitelist (config vieja con keys obsoletas).
    $clean = array_intersect_key($merged, POS_CONFIG_DEFAULTS);
    apiOk(['config' => $clean]);
}

if ($method === 'PUT' && $resource === 'config') {
    if ($registerId === '') {
        apiError('Sin caja activa', 422);
    }
    $raw = $_POST['config'] ?? null;
    if (!is_array($raw)) {
        apiError('Falta config (objeto)', 422);
    }
    // Whitelist + validación de tipo. Aceptamos parcial — mergeamos con lo guardado.
    $patch = [];
    foreach ($raw as $k => $v) {
        if (!array_key_exists($k, POS_CONFIG_DEFAULTS)) {
            continue; // key fuera del whitelist
        }
        if (!is_bool($v)) {
            apiError("Valor inválido para '$k' (se esperaba boolean)", 422);
        }
        $patch[$k] = $v;
    }
    $current = $svc->getConfig($registerId, $companyId);
    $merged  = array_merge(POS_CONFIG_DEFAULTS, $current, $patch);
    $clean   = array_intersect_key($merged, POS_CONFIG_DEFAULTS);
    $ok = $svc->saveConfig($registerId, $companyId, $clean);
    if (!$ok) {
        apiError('No se pudo guardar la configuración del POS', 500);
    }
    apiOk(['config' => $clean]);
}

// GET = numeración de documentos de la caja (docsNum). registerId del JWT.
if ($method === 'GET') {
    // P1 code-review: sin caja activa no hay numeración que devolver.
    if ($registerId === '') {
        apiError('Sin caja activa', 422);
    }
    apiOk($svc->docNumbers($registerId, $companyId));
}

// PUT = actualizar el estado de la caja (sessionId). registerId del JWT. Verbos REST (§22.7).
if ($method !== 'PUT') {
    apiError('Método no permitido', 405);
}

$sessionId = (int) ($_POST['sessionId'] ?? 0);
if ($sessionId <= 0) {
    apiError('Falta sessionId', 422);
}

$ok = $svc->setSession($registerId, $companyId, $sessionId);
if (!$ok) {
    apiError('No se pudo fijar la sesión de caja', 500);
}

// Broadcast WS best-effort: avisa a otras pestañas de la MISMA caja que su sesión
// quedó obsoleta. Canal: <companyId>-<registerId>-registerSession (igual que el front).
try {
    sendWS([
        'channel' => $companyId . '-' . $registerId . '-registerSession',
        'event'   => 'checkSession',
        'message' => $sessionId,
    ]);
} catch (\Throwable $e) {
    error_log('[register.setSession] WS broadcast falló (ignorado): ' . $e->getMessage());
}

apiOk(['sessionId' => $sessionId]);

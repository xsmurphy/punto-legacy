<?php
/**
 * REST canónico (API compartida /api) — Sucursales (motor ERP, raw).
 *
 *   GET  /v1/outlets                 → lista de sucursales de la company (CRUDA).
 *   GET  /v1/outlets?id=<uuid>        → una sucursal (campos completos para el form).
 *   POST /v1/outlets (action=update&id=<uuid> + campos) → actualiza.
 *
 * Lectura sin formatear/HTML (el front formatea + arma). Escritura scopeada por COMPANY_ID del
 * JWT. Auth: MULTI-REALM — GET acepta `panel` y `pos-app`; las escrituras son
 * solo `panel` (ver la línea de `apiAuthTenant` abajo, que es la fuente de
 * verdad). Este docblock decía "realm panel" a secas y contradecía al código:
 * en una auditoría de credenciales cruzadas hace perder tiempo o, peor, lleva
 * a "arreglar" algo que ya estaba bien.
 *
 * Port FIEL de panel/API/v1/outlets.php (Fase 2 del desacople de /panel). Cambios respecto al
 * original: `apiMiddleware()` → `apiAuthTenant(['panel'])`; `PANEL_AUTHED_ROLE` → `$ctx['roleId']`;
 * service en namespace `Punto\Api\Outlets`. El contrato (acciones, shape, status codes) es
 * idéntico — el JS de a_outlets.js depende de él (createOutlet/removeOutlet usan action=create
 * y action=delete contra este endpoint vía /bff/outlets.php).
 *
 * MULTI-REALM (GET únicamente): el POS (realm `pos-app`) necesita listar sucursales para el
 * selector de Ajustes → Sucursal (device sin sesión de panel, mismo patrón que /v1/price_list y
 * /v1/register?resource=listAll). Los writes (POST create/update/delete) siguen panel-only —
 * gateados abajo por método, no por el auth inicial.
 */

require_once __DIR__ . '/../bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctx    = apiAuthTenant($method === 'GET' ? ['panel', 'pos-app', 'api'] : ['panel']);
$svc    = new \Punto\Api\Outlets\OutletsService();
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// ── JSON body → $_POST (el front manda JSON, PHP no lo parsea automáticamente) ──
$_raw = file_get_contents('php://input');
if (is_string($_raw) && $_raw !== '') {
    $_json = json_decode($_raw, true);
    if (is_array($_json)) {
        $_POST = array_merge($_POST, $_json);
    }
}

if ($method === 'POST') {
    if (!hasPermission('settings.outlet.manage')) {
        apiError('No tenés permiso para esta acción (requiere: settings.outlet.manage)', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    if (!in_array($action, ['update', 'create', 'delete'], true)) {
        apiError('Acción no soportada', 422);
    }

    if ($action === 'delete') {
        $id = (string) (validateHttp('id', 'post') ?: '');
        if (!preg_match($uuidRe, $id)) {
            apiError('id inválido', 422);
        }
        if ($id === OUTLET_ID) {
            apiError('No se puede eliminar la sucursal activa', 422);
        }
        if (!$svc->delete($id, COMPANY_ID)) {
            apiError('No se pudo eliminar la sucursal', 500);
        }
        apiOk(['id' => $id]);
    }

    // Parseo de fields (create con datos + update lo usan).
    // Distinción crítica: `array_key_exists` vs `!empty`.
    //   - Field PRESENT but empty → cliente explicitly mandó '' → REJECT (422).
    //     El form de frontend con zod no submitea con name vacío; este caso
    //     es defensa server-side ante clientes maliciosos / requests crudas.
    //   - Field ABSENT del todo → cliente legacy (panel/a_outlets.js) que solo
    //     manda {action:create} → fallback al flujo blank ("Nueva Sucursal"
    //     placeholder). Backwards compat hasta que el legacy desaparezca.
    $nameProvided = isset($_POST['name']);
    $name = trim((string) (validateHttp('name', 'post') ?: ''));

    $poRaw = (string) (validateHttp('purchaseOrderNo', 'post') ?: '');
    $po    = ($poRaw !== '' && is_numeric($poRaw)) ? (int) $poRaw : null;
    // `taxId` es el nombre que manda el frontend (y el que espera
    // OutletsService::update). El port leía `tax`, la clave del legacy: el
    // impuesto por defecto de la sucursal NUNCA se guardaba y, peor, cada save
    // lo mandaba vacío → la columna quedaba en NULL. Se aceptan las dos por el
    // legacy que todavía postea `tax`.
    $taxId = (string) (validateHttp('taxId', 'post') ?: '');
    if ($taxId === '') {
        $taxId = (string) (validateHttp('tax', 'post') ?: '');
    }
    if ($taxId !== '' && !preg_match($uuidRe, $taxId)) {
        apiError('impuesto inválido', 422);
    }
    // lat/lng: columnas numéricas (post-migración 14). Vacías → null en BD,
    // no 0 (0,0 es el Golfo de Guinea — placeholder incorrecto si el user no
    // las completó). El front manda "" cuando están vacías.
    $latRaw = (string) (validateHttp('lat', 'post') ?? '');
    $lngRaw = (string) (validateHttp('lng', 'post') ?? '');
    $lat = ($latRaw !== '' && is_numeric($latRaw)) ? (float) $latRaw : null;
    $lng = ($lngRaw !== '' && is_numeric($lngRaw)) ? (float) $lngRaw : null;

    $fields = [
        'name'            => $name,
        'address'         => (string) (validateHttp('address', 'post') ?: ''),
        'phone'           => (string) (validateHttp('phone', 'post') ?: ''),
        'email'           => (string) (validateHttp('email', 'post') ?: ''),
        'description'     => (string) (validateHttp('description', 'post') ?: ''),
        'status'          => validateHttp('status', 'post') ? 1 : 0,
        'billingName'     => (string) (validateHttp('billingName', 'post') ?: ''),
        'ruc'             => (string) (validateHttp('ruc', 'post') ?: ''),
        'whatsApp'        => (string) (validateHttp('whatsApp', 'post') ?: ''),
        'purchaseOrderNo' => $po,
        'lat'             => $lat,
        'lng'             => $lng,
        'taxId'           => $taxId,
        'ecom'            => (bool) validateHttp('ecom', 'post'),
        'taxIncluded'     => (bool) validateHttp('taxIncluded', 'post'),
        'businessHours'   => (string) (validateHttp('businessHours', 'post') ?: ''),
        'priceListId'     => (string) (validateHttp('priceListId', 'post') ?: '') ?: null,
    ];

    if ($action === 'create') {
        if ($nameProvided) {
            // Cliente nuevo (frontend form): validamos requireds.
            if ($name === '') {
                apiError('El nombre es requerido', 422);
            }
            $newFields = $fields;
        } else {
            // Cliente legacy (panel/a_outlets.js): crea blank y edita después.
            $newFields = null;
        }
        try {
            $newId = $svc->create(COMPANY_ID, $newFields);
        } catch (\RuntimeException $e) {
            // El service ahora lanza RuntimeException con el ErrorMsg() del
            // driver — mucho más útil que el 500 silente de antes. El BFF lo
            // loguea server-side y el front lo muestra en el toast.
            apiError($e->getMessage(), 500);
        }
        if ($newId === null) {
            apiError('No se pudo crear la sucursal', 500);
        }
        apiOk(['id' => $newId]);
    }

    // action === 'update'
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    // Required field check — en update siempre exigimos name (no hay path
    // legacy que update sin name; cualquier cliente que llegue acá tiene
    // que mandar el form completo).
    if ($name === '') {
        apiError('El nombre es requerido', 422);
    }
    if (!$svc->update($id, COMPANY_ID, $fields)) {
        apiError('No se pudo actualizar', 500);
    }
    apiOk(['id' => $id, 'action' => 'update']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$id = (string) (validateHttp('id') ?: '');
if ($id !== '') {
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    $row = $svc->get($id, COMPANY_ID);
    if ($row === null) {
        apiError('Sucursal no encontrada', 404);
    }
    $row['availableTaxes'] = $svc->taxes(COMPANY_ID);
    apiOk($row);
}

apiOk(['rows' => $svc->listAll(COMPANY_ID)]);

<?php
/**
 * REST canónico (API compartida /api) — Add-ons por producto (context/41, F1).
 *
 *   GET  /v1/item_addons?itemId=<uuid>                          → grupos + opciones del ítem
 *   POST /v1/item_addons {action:'replace', itemId, groups}     → reemplazo completo
 *   POST /v1/item_addons {action:'copy', itemId, sourceItemId}  → copia grupos de otro ítem
 *
 * Auth: MULTI-REALM — `apiAuthTenant(['panel','pos-app'])`. Mismo patrón que
 * /v1/items.php: el POS (`pos-app`, token del dispositivo) es GET-only.
 * La administración de add-ons es del panel — una caja comprometida no debe
 * poder reemplazar ni copiar grupos del catálogo.
 *
 * NO se gatea con `hasPermission()`: `/v1/items.php` (CRUD del catálogo, la
 * referencia que pidió el brief) tampoco usa una clave de permiso propia —
 * se apoya solo en el guard de realm de arriba. No existe una clave
 * `items.edit` en el catálogo de permisos (`PermissionCatalog.php`), así que
 * inventar una acá sería una convención nueva sin precedente en el propio
 * endpoint que se tomó como referencia.
 */

require_once __DIR__ . '/../bootstrap.php';

use Punto\Api\Items\AddonService;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe    = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// El realm `pos-app` es el token del DISPOSITIVO (una caja), no el de un
// operador del panel: se agregó solo para que el POS pueda CONSULTAR el
// catálogo de add-ons al armar el carrito. La administración (replace/copy)
// es del panel. Guard único acá arriba — mismo patrón que items.php.
if (($ctx['realm'] ?? '') === 'pos-app' && $method !== 'GET') {
    apiError('El dispositivo POS solo puede leer los add-ons', 403);
}

$svc = new AddonService();

if ($method === 'GET') {
    $itemId = (string) ($_GET['itemId'] ?? '');
    if (!preg_match($uuidRe, $itemId)) {
        apiError('itemId inválido', 422);
    }
    apiOk(['groups' => $svc->listForItem($itemId, $companyId)]);
}

if ($method === 'POST') {
    // El front manda JSON; PHP no lo parsea automáticamente a $_POST.
    $raw = file_get_contents('php://input');
    $body = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    $action = (string) ($body['action'] ?? '');
    $itemId = (string) ($body['itemId'] ?? '');
    if (!preg_match($uuidRe, $itemId)) {
        apiError('itemId inválido', 422);
    }

    if ($action === 'replace') {
        $groups = $body['groups'] ?? null;
        if (!is_array($groups)) {
            apiError('groups es requerido', 422);
        }
        apiOk(['groups' => $svc->replaceForItem($itemId, $companyId, $groups)]);
    }

    if ($action === 'copy') {
        $sourceItemId = (string) ($body['sourceItemId'] ?? '');
        if (!preg_match($uuidRe, $sourceItemId)) {
            apiError('sourceItemId inválido', 422);
        }
        apiOk(['groups' => $svc->copyFromItem($itemId, $sourceItemId, $companyId)]);
    }

    apiError('Acción no soportada', 422);
}

apiError('Método no soportado', 405);

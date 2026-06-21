<?php
/**
 * Bootstrap de la API compartida (/api) — backend único del sistema.
 *
 * TRANSITORIO: por ahora reusa los includes de /app (db con Insert_ID, functions,
 * jwt_middleware, head.php, data.php) vía rutas absolutas. La consolidación de un
 * `/api/includes` canónico (independiente de /panel y /app) es la migración gradual
 * pendiente — ver context/10-roadmap.md § API compartida.
 *
 * Auth: JWT de tenant (cookie _jwt | Bearer | POST _jwt), JWT_SECRET, claim `cid`.
 * Mismo secret/claims que ya validan /panel (_jwt_panel) y /app (_jwt).
 */

session_start();

define('API_APP_DIR', dirname(__DIR__) . '/app');
chdir(API_APP_DIR); // los includes de head.php/data.php son relativos al cwd

require_once API_APP_DIR . '/includes/cors.php';
require_once API_APP_DIR . '/includes/jwt_middleware.php';
require_once API_APP_DIR . '/includes/realtime.php';
require_once API_APP_DIR . '/includes/rollup.php';
require_once __DIR__ . '/lib/response.php';

// Autoloader mínimo PSR-4 para código nuevo en `api/lib/` con namespace `Punto\Api\…`.
// Mapea `Punto\Api\Sales\SaleService` → `api/lib/Sales/SaleService.php`.
// El código legacy sin namespace (api/lib/services/*) sigue cargándose con
// `require_once` manual desde los endpoints — coexisten. Ver convención §22.9.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Punto\\Api\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$rateLimiterId = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
require_once API_APP_DIR . '/head.php'; // db, functions (ncm*, sendPush, checkCompanyStatus), config, enc/dec

// Normaliza el body → $_POST para todos los verbos (POST/PUT/DELETE/PATCH).
// PHP sólo puebla $_POST en POST form-encoded; en JSON o los demás verbos el body
// queda en php://input. Así los endpoints REST leen $_POST uniformemente sin
// importar el verbo ni el content-type. (Mismo enfoque que panel api_middleware.)
// El BFF legacy de /app manda form-encoded (http_build_query) → $_POST ya
// poblado, salteamos. El cliente nuevo de panel-next manda JSON en POST →
// $_POST vacío, parseamos.
if (empty($_POST) && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
    $rawBody = file_get_contents('php://input');
    if ($rawBody !== '' && $rawBody !== false) {
        $jsonBody = json_decode($rawBody, true);
        if (is_array($jsonBody)) {
            $_POST = $jsonBody;
        } else {
            parse_str($rawBody, $parsedBody);
            if (!empty($parsedBody)) {
                $_POST = $parsedBody;
            }
        }
    }
}

/**
 * Autentica el JWT de tenant y prepara el contexto (COMPANY_ID, OUTLET_ID, TODAY,
 * settings, COMPANY_NAME…). Corta 401/403 si falla. Devuelve los ids del token.
 *
 * Multi-realm con allowlist por endpoint: cada endpoint declara qué realms acepta —
 * `apiAuthTenant(['panel'])` para endpoints del panel, `apiAuthTenant(['panel','pos-app'])`
 * para recursos compartidos. Default `['pos-app']` (los endpoints POS existentes no
 * cambian). Los tokens POS son eternos (device pairing): NO deben autenticar en
 * endpoints administrativos del panel. Ver docs/PLAN_panel_desacople.md § Fase 0.
 */
function apiAuthTenant(array $realms = ['pos-app']): array
{
    if (!jwtAuthenticate($realms)) {
        apiError('Autenticación requerida', 401);
    }

    $companyId  = AUTHED_COMPANY_ID;
    $outletId   = AUTHED_OUTLET_ID;
    $userId     = AUTHED_USER_ID;
    $registerId = AUTHED_REGISTER_ID;
    $roleId     = AUTHED_ROLE_ID;
    $realm      = AUTHED_REALM;

    if (!checkCompanyStatus($companyId)) {
        apiError('Company Blocked', 403);
    }

    // El JWT del panel puede traer `oid` vacío (empresa sin outlet al loguear) y trae
    // `rid` siempre vacío. data.php llama getAllOutletData(OUTLET_ID) — resolvemos el
    // primer outlet activo (misma resolución que issueJwtPanel) ANTES de cargarlo.
    // `rid=''` es tolerable: los endpoints del panel scopean por parámetros.
    if ($outletId === '') {
        $row = ncmExecute(
            'SELECT outletId FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletId ASC LIMIT 1',
            [$companyId]
        );
        $outletId = (string)($row['outletId'] ?? '');
    }

    // data.php define COMPANY_ID/OUTLET_ID/TODAY/COMPANY_NAME/etc. desde estas locales.
    require API_APP_DIR . '/data.php';

    // View-scope override (panel-next 2026-06-13). Si el browser eligió una sucursal
    // o "Todas" desde el dropdown del logo, manda header X-Outlet-Id:
    //   - 'all' → VIEW_OUTLET_ID = '' → Roc::build NO filtra por outlet (consolidado)
    //   - UUID  → VIEW_OUTLET_ID = ese UUID, previa validación de pertenencia al tenant
    //
    // OUTLET_ID (constante del JWT, ya definida por data.php) NO se toca — las
    // escrituras (sale, drawer, etc.) siguen scopeadas a la sucursal activa.
    // VIEW_OUTLET_ID solo afecta lecturas que pasan por Roc::build.
    //
    // Restringido a realm 'panel': el POS no debería poder enviar este header.
    if ($realm === 'panel' && isset($_SERVER['HTTP_X_OUTLET_ID'])) {
        $override = trim((string) $_SERVER['HTTP_X_OUTLET_ID']);
        if ($override === 'all' || $override === '') {
            define('VIEW_OUTLET_ID', '');
        } else {
            $uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
            if (preg_match($uuidRe, $override)) {
                $check = ncmExecute(
                    'SELECT 1 FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',
                    [$override, $companyId]
                );
                if ($check) {
                    define('VIEW_OUTLET_ID', $override);
                }
                // Si no pertenece al tenant, ignoramos el override silenciosamente
                // (defense-in-depth: no rompemos la sesión por header malformado).
            }
        }
    }

    // Auditoría: registrar mutaciones (POST/PUT/PATCH/DELETE) del tenant.
    // GET no se audita. Best-effort: tenantAudit() absorbe cualquier error.
    $__auditMethod = $_SERVER['REQUEST_METHOD'] ?? '';
    if (in_array($__auditMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $__auditEndpoint = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
        $__auditTargetId = isset($_GET['id']) ? (string) $_GET['id'] : null;
        tenantAudit(
            compact('companyId', 'outletId', 'userId', 'realm'),
            $__auditMethod,
            $__auditEndpoint,
            $__auditTargetId
        );
        realtimeAfterMutation($__auditMethod, $__auditEndpoint, $__auditTargetId);
    }

    return compact('companyId', 'outletId', 'userId', 'registerId', 'roleId', 'realm');
}

/**
 * Registra una acción mutante del tenant en tenant_audit (best-effort, nunca lanza).
 *
 * Solo se llama para métodos POST/PUT/PATCH/DELETE desde apiAuthTenant().
 * Si la DB no está lista o el INSERT falla, se loguea y se ignora.
 *
 * @param array       $ctx      Resultado de apiAuthTenant (companyId, userId, outletId, realm).
 * @param string      $method   HTTP method.
 * @param string      $endpoint Path de REQUEST_URI sin querystring.
 * @param string|null $targetId ID del recurso si viene en $_GET['id'].
 * @param array       $meta     Datos extra (vacío por defecto en la instrumentación base).
 */
function tenantAudit(array $ctx, string $method, string $endpoint, ?string $targetId = null, array $meta = []): void
{
    global $db;

    if (!isset($db) || !is_object($db)) {
        return;
    }

    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $db->Execute(
            'INSERT INTO tenant_audit
               ("companyId", "userId", "outletId", realm, method, endpoint, "targetId", meta, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)',
            [
                $ctx['companyId'] ?: null,
                $ctx['userId']    ?: null,
                $ctx['outletId']  ?: null,
                isset($ctx['realm'])    ? substr((string)$ctx['realm'],    0, 20)  : null,
                $method                 ? substr($method,                  0, 10)  : null,
                $endpoint               ? substr($endpoint,                0, 160) : null,
                $targetId               ? substr($targetId,                0, 64)  : null,
                json_encode($meta, JSON_UNESCAPED_UNICODE),
                $ip                     ? substr($ip,                      0, 64)  : null,
            ]
        );
    } catch (\Throwable $e) {
        // Best-effort: nunca interrumpir la request.
        error_log('[tenantAudit] Error insertando en tenant_audit: ' . $e->getMessage());
    }
}

/**
 * Wire de invalidación realtime — mismo patrón que tenantAudit: se llama
 * después de cada POST/PUT/PATCH/DELETE para que todos los browsers del
 * tenant invaliden las queries afectadas.
 *
 * Mapeo cerrado endpoint→entity: si agregás un endpoint nuevo que muta,
 * agregá la línea acá. No hay auto-detección.
 */
function realtimeAfterMutation(string $method, string $endpoint, ?string $targetId): void
{
    static $map = [
        '/v1/items'                 => ['entity' => 'item',        'scope' => 'all'],
        '/v1/contacts'              => ['entity' => 'contact',     'scope' => 'all'],
        '/v1/customers'             => ['entity' => 'contact',     'scope' => 'all'],
        '/v1/outlets'               => ['entity' => 'outlet',      'scope' => 'all'],
        '/v1/categories'            => ['entity' => 'category',    'scope' => 'all'],
        '/v1/brands'                => ['entity' => 'brand',       'scope' => 'all'],
        '/v1/tags'                  => ['entity' => 'tag',         'scope' => 'all'],
        '/v1/taxes'                 => ['entity' => 'tax',         'scope' => 'all'],
        '/v1/sales'                 => ['entity' => 'transaction', 'scope' => 'dashboard'],
        '/v1/transactions'          => ['entity' => 'transaction', 'scope' => 'dashboard'],
        '/v1/orders'                => ['entity' => 'transaction', 'scope' => 'dashboard'],
        '/v1/drawer'                => ['entity' => 'drawer',      'scope' => 'dashboard'],
        '/v1/reports/drawers'       => ['entity' => 'drawer',      'scope' => 'dashboard'],
        '/v1/settings'              => ['entity' => 'setting',     'scope' => 'all'],
        '/v1/modules'               => ['entity' => 'setting',     'scope' => 'all'],
        '/v1/price_list'            => ['entity' => 'item',        'scope' => 'all'],
        '/v1/price_list_item'       => ['entity' => 'item',        'scope' => 'all'],
        '/v1/screens'               => ['entity' => 'screen',      'scope' => 'all'],
    ];

    foreach ($map as $prefix => $cfg) {
        if (str_starts_with($endpoint, $prefix)) {
            $op = match ($method) {
                'POST'         => 'create',
                'PUT', 'PATCH' => 'update',
                'DELETE'       => 'delete',
                default        => 'update',
            };
            realtimePublish($cfg['entity'], $op, $targetId, $cfg['scope']);
            return;
        }
    }
}

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

// Normaliza el body de PUT/DELETE/PATCH → $_POST. PHP sólo puebla $_POST en POST
// form-encoded; en los demás verbos el body queda en php://input. Así los endpoints REST
// leen $_POST uniformemente sin importar el verbo. (Mismo enfoque que panel api_middleware.)
// El BFF de /app manda form-encoded (http_build_query); soportamos también JSON por las dudas.
if (empty($_POST) && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['PUT', 'DELETE', 'PATCH'], true)) {
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

    return compact('companyId', 'outletId', 'userId', 'registerId', 'roleId', 'realm');
}

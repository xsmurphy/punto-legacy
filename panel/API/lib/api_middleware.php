<?php
/**
 * Middleware centralizado para la API del panel.
 *
 * Encapsula todo lo que hoy hace api_head.php + extiende con soporte JWT.
 *
 * Autenticación (en orden de prioridad):
 *   1. JWT via  Authorization: Bearer <token>  |  Cookie _jwt_panel  |  POST _jwt
 *      → define PANEL_AUTHED_* constants, salta verificación de api_key
 *   2. Legacy api_key + company_id  (igual que siempre)
 *      → si JWT_SECRET no está configurado, este es el único camino
 *
 * Uso en un endpoint nuevo:
 *   require_once __DIR__ . '/lib/api_middleware.php';
 *   apiMiddleware();
 *   // A partir de aquí COMPANY_ID, ECOMPANY_ID, TODAY, etc. están definidos
 *
 * Para migrar un endpoint existente basta reemplazar:
 *   include_once('api_head.php');
 * por:
 *   require_once __DIR__ . '/../lib/api_middleware.php';
 *   apiMiddleware();
 */

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/../../includes/jwt.php';

// enc()/dec() son helpers globales que todos los endpoints usan.
// En api_head.php se definen inline; aquí las proveemos para endpoints migrados.
if (!function_exists('enc')) {
    function enc($str): string
    {
        global $HASHIDS_INSTANCE;
        if (!isset($HASHIDS_INSTANCE)) {
            // SALT se define en simple.config.php — si aún no está cargado, cargarlo
            if (!defined('SALT')) {
                include_once __DIR__ . '/../../includes/simple.config.php';
            }
            $HASHIDS_INSTANCE = new Hashids\Hashids(SALT);
        }
        return $HASHIDS_INSTANCE->encode($str);
    }
}

if (!function_exists('dec')) {
    function dec($str): int
    {
        global $HASHIDS_INSTANCE;
        if (!isset($HASHIDS_INSTANCE)) {
            if (!defined('SALT')) {
                include_once __DIR__ . '/../../includes/simple.config.php';
            }
            $HASHIDS_INSTANCE = new Hashids\Hashids(SALT);
        }
        $decoded = $HASHIDS_INSTANCE->decode($str);
        return (int)($decoded[0] ?? 0);
    }
}

/**
 * Ejecuta el middleware completo.
 * Muere con error JSON si la autenticación falla.
 *
 * @param bool $rateLimitEnabled  Desactivar solo en tests / endpoints internos
 */
function apiMiddleware(bool $rateLimitEnabled = true): void
{
    // 1. Normalizar body JSON → $_POST
    if (empty($_POST)) {
        $body = file_get_contents('php://input');
        if ($body) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $_POST = $decoded;
            }
        }
    }

    // 2. Rate limiter (igual que api_head.php)
    if ($rateLimitEnabled) {
        _apiRateLimit();
    }

    // 3. Bootstrap de dependencias
    // $db debe ser global para que ncmExecute() lo encuentre con `global $db`
    global $db, $ADODB_CACHE_DIR, $plansValues, $countries;

    require_once __DIR__ . '/../../libraries/whoops/autoload.php';
    include_once __DIR__ . '/../../includes/db.php';
    include_once __DIR__ . '/../../includes/simple.config.php';
    include_once __DIR__ . '/../../libraries/hashid.php';
    include_once __DIR__ . '/../../includes/functions.php';

    // 4. Autenticación: JWT primero, legacy api_key como fallback
    $jwtSecret = $_ENV['JWT_SECRET'] ?? '';
    $jwtToken  = _apiExtractJwtToken();

    if ($jwtSecret && $jwtToken !== null) {
        // Ruta JWT
        $payload = jwtDecode($jwtToken, $jwtSecret);
        if ($payload === null) {
            apiUnauthorized('Token inválido o expirado');
        }

        $companyIdInt  = (int)($payload['cid']  ?? 0);
        $outletIdInt   = (int)($payload['oid']  ?? 0);
        $registerIdInt = (int)($payload['rid']  ?? 0);
        $userIdInt     = (int)($payload['sub']  ?? 0);
        $roleInt       = (int)($payload['role'] ?? 0);

        if (!$companyIdInt) {
            apiUnauthorized('Token sin companyId');
        }

        // Reconstruir encoded IDs para compatibilidad con código legacy que usa ECOMPANY_ID
        $hashids       = new Hashids\Hashids(SALT);
        $eCompanyId    = $hashids->encode($companyIdInt);

        define('PANEL_JWT_AUTHED',    true);
        define('PANEL_AUTHED_USER',   $userIdInt);
        define('PANEL_AUTHED_ROLE',   $roleInt);

        // Constantes compartidas con api_head.php legacy
        _apiDefineSharedConstants($eCompanyId, $companyIdInt, $outletIdInt, $registerIdInt);

    } else {
        // Ruta legacy: company_id + api_key
        $companyId = validateHttp('company_id', 'post');
        $apiKey    = validateHttp('api_key', 'post');

        if (!validateAPIAccess($companyId, $apiKey, $_GET['debug'] ?? false)) {
            apiForbidden('Acceso denegado');
        }

        define('PANEL_JWT_AUTHED',    false);
        define('PANEL_AUTHED_USER',   0);
        define('PANEL_AUTHED_ROLE',   0);

        _apiDefineSharedConstants($companyId, dec($companyId), 0, 0);
    }
}

// ---------------------------------------------------------------------------
// Helpers internos
// ---------------------------------------------------------------------------

function _apiRateLimit(): void
{
    include_once __DIR__ . '/../../libraries/rateLimiter.php';

    $rateLimiter = new RateLimiter($_POST['company_id'] ?? $_SERVER['REMOTE_ADDR']);
    $limit   = 60;
    $minutes = 1;

    try {
        $rateLimiter->limitRequestsInMinutes($limit, $minutes);
    } catch (RateExceededException $e) {
        http_response_code(429);
        header('Content-Type: application/json');
        header(sprintf('Retry-After: %d', $minutes * 60));
        echo json_encode(apiErrorEnvelope('Rate Limit Exceeded', 429));
        exit;
    }
}

function _apiExtractJwtToken(): ?string
{
    // Authorization: Bearer <token>
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return $m[1];
    }

    // Cookie _jwt_panel (nombre distinto al del módulo /app para no colisionar)
    if (!empty($_COOKIE['_jwt_panel'])) {
        return $_COOKIE['_jwt_panel'];
    }

    // POST field _jwt
    if (!empty($_POST['_jwt'])) {
        return $_POST['_jwt'];
    }

    return null;
}

function _apiDefineSharedConstants(string $eCompanyId, int $companyId, int $outletId, int $registerId): void
{
    define('ECOMPANY_ID',  $eCompanyId);
    define('COMPANY_ID',   $companyId);
    define('OUTLET_ID',    $outletId > 0
        ? (new Hashids\Hashids(SALT))->encode($outletId)
        : (validateHttp('outletId', 'post') ?? ''));
    define('REGISTER_ID',  $registerId > 0
        ? (new Hashids\Hashids(SALT))->encode($registerId)
        : (validateHttp('registerId', 'post') ?? ''));

    setTimeZone($companyId);

    define('TODAY',       date('Y-m-d H:i:s'));
    define('TODAY_START', date('Y-m-d 00:00:00'));
    define('TODAY_END',   date('Y-m-d 23:59:59'));
    define('ASSETS_URL',  'https://assets.encom.app/');

    // outlets count (igual que api_head.php)
    $outlets = ncmExecute(
        "SELECT COUNT(outletId) as count FROM outlet WHERE outletStatus = 1 AND companyId = ? LIMIT 30",
        [COMPANY_ID],
        true
    );
    define('OUTLETS_COUNT', (int)($outlets['count'] ?? 0));
}

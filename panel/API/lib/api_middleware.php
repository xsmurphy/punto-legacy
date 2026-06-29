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

// enc()/dec() son helpers globales que todos los endpoints usan.
// En api_head.php se definen inline; aquí las proveemos para endpoints migrados.
if (!function_exists('enc')) {
    function enc($str): string { return (string)$str; }
}

if (!function_exists('dec')) {
    function dec($str): string { return (string)$str; }
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
    global $db, $plansValues, $countries;

    include_once __DIR__ . '/../../includes/db.php';
    include_once __DIR__ . '/../../includes/simple.config.php';
    include_once __DIR__ . '/../../includes/functions.php';

    // 4. Autenticación: sesión opaca, realm panel. Sin fallback legacy (no hay cuentas legacy).
    require_once __DIR__ . '/../../includes/auth_session.php';

    if (!authResolve(['panel'])) {
        apiUnauthorized('Autenticación requerida');
    }

    $eCompanyId = AUTHED_COMPANY_ID;
    define('PANEL_JWT_AUTHED', true);
    define('PANEL_AUTHED_USER', AUTHED_USER_ID);
    define('PANEL_AUTHED_ROLE', (int)AUTHED_ROLE_ID);
    _apiDefineSharedConstants($eCompanyId, AUTHED_COMPANY_ID, AUTHED_OUTLET_ID, AUTHED_REGISTER_ID);
}

/**
 * Middleware para endpoints públicos de screens (KDS, CDS, recibos, etc.).
 * No requiere JWT ni api_key — autenticación basada en slug opaco.
 * Hace CORS, rate-limit por slug, y bootstrap de DB/config/functions.
 *
 * @param string $slugId  Identificador para rate-limit (normalmente el ?s= slug)
 */
function apiMiddlewarePublic(string $slugId = ''): void
{
    require_once __DIR__ . '/../../includes/cors.php';

    include_once __DIR__ . '/../../libraries/rateLimiter.php';
    $limiterId   = $slugId ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateLimiter = new RateLimiter($limiterId);
    try {
        $rateLimiter->limitRequestsInMinutes(60, 1);
    } catch (RateExceededException $e) {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: 60');
        echo json_encode(apiErrorEnvelope('Rate Limit Exceeded', 429));
        exit;
    }

    global $db, $plansValues, $countries;
    include_once __DIR__ . '/../../includes/db.php';
    include_once __DIR__ . '/../../includes/simple.config.php';
    include_once __DIR__ . '/../../includes/functions.php';

    define('PANEL_JWT_AUTHED', false);
    define('PANEL_AUTHED_USER', 0);
    define('PANEL_AUTHED_ROLE', 0);
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

function _apiDefineSharedConstants(string $eCompanyId, string $companyId, string $outletId, string $registerId): void
{
    define('ECOMPANY_ID',  $eCompanyId);
    define('COMPANY_ID',   $companyId);

    // outletId/registerId de fuente confiable (JWT/sesión) se usan tal cual. Si están
    // vacíos, se acepta el valor del POST SOLO si pertenece a COMPANY_ID (§1 — aislamiento
    // de tenant): un cliente autenticado no puede scopear queries a un outlet/register de
    // otra empresa pasando su UUID por el body. Si no pertenece, se descarta (queda '').
    if (!$outletId) {
        $outletId = _apiScopedId('outlet', 'outletId', validateHttp('outletId', 'post') ?: '', $companyId);
    }
    if (!$registerId) {
        $registerId = _apiScopedId('register', 'registerId', validateHttp('registerId', 'post') ?: '', $companyId);
    }
    define('OUTLET_ID',    $outletId);
    define('REGISTER_ID',  $registerId);

    setTimeZone($companyId);

    define('TODAY',       date('Y-m-d H:i:s'));
    define('TODAY_START', date('Y-m-d 00:00:00'));
    define('TODAY_END',   date('Y-m-d 23:59:59'));
    define('ASSETS_URL',  '/assets/');

    // outlets count (igual que api_head.php)
    $outlets = ncmExecute(
        "SELECT COUNT(outletId) as count FROM outlet WHERE outletStatus = 1 AND companyId = ? LIMIT 30",
        [COMPANY_ID],
        true
    );
    define('OUTLETS_COUNT', (int)($outlets['count'] ?? 0));
}

/**
 * Devuelve $id solo si pertenece a $companyId; si no, devuelve '' (lo descarta).
 * $table/$col son literales internos (no input del cliente) — la interpolación es segura;
 * $id y $companyId van por placeholders parametrizados.
 */
function _apiScopedId(string $table, string $col, string $id, string $companyId): string
{
    if ($id === '' || $companyId === '') {
        return '';
    }
    $row = ncmExecute(
        "SELECT $col FROM $table WHERE $col = ? AND companyId = ? LIMIT 1",
        [$id, $companyId],
        true
    );
    return (is_array($row) && !empty($row[$col])) ? (string)$row[$col] : '';
}

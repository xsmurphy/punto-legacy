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

$rateLimiterId = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
require_once API_APP_DIR . '/head.php'; // db, functions (ncm*, sendPush, checkCompanyStatus), config, enc/dec

/**
 * Autentica el JWT de tenant y prepara el contexto POS (COMPANY_ID, OUTLET_ID, TODAY,
 * settings, COMPANY_NAME…). Corta 401/403 si falla. Devuelve los ids del token.
 */
function apiAuthTenant(): array
{
    if (!jwtAuthenticate()) {
        apiError('Autenticación requerida', 401);
    }

    $companyId  = AUTHED_COMPANY_ID;
    $outletId   = AUTHED_OUTLET_ID;
    $userId     = AUTHED_USER_ID;
    $registerId = AUTHED_REGISTER_ID;
    $roleId     = AUTHED_ROLE_ID;

    if (!checkCompanyStatus($companyId)) {
        apiError('Company Blocked', 403);
    }

    // data.php define COMPANY_ID/OUTLET_ID/TODAY/COMPANY_NAME/etc. desde estas locales.
    require API_APP_DIR . '/data.php';

    return compact('companyId', 'outletId', 'userId', 'registerId', 'roleId');
}

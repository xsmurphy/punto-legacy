<?php
/**
 * Contexto de autenticacion para endpoints POS.
 *
 * Acepta cookie `_jwt` (device, 10 anyos) o fallback a `_jwt_panel`
 * del panel (24h). El fallback es temporal -- se quita en S3 cuando todo
 * el front haya completado el pairing.
 *
 * IMPORTANTE: si el path del device JWT se toma, esta funcion define
 * las constantes COMPANY_ID/OUTLET_ID/USER_ID/REGISTER_ID/ROLE_ID
 * directamente desde el ctx del device (sin pasar por data.php completo,
 * que requeriria una company activa y sus settings). Para endpoints POS
 * que solo necesitan esas constantes basicas, esto es suficiente.
 *
 * Si el endpoint POS necesita $company/$setting/$_modules (settings del
 * tenant), tendra que cargar data.php manualmente tras llamar a esta fn.
 */

use Punto\Api\Auth\DeviceAuth;

function apiAuthPosContext(): array
{
    $deviceCookie = $_COOKIE['_jwt'] ?? '';
    if ($deviceCookie !== '') {
        $ctx = DeviceAuth::validateJwt($deviceCookie);
        if ($ctx !== null) {
            // Verificar que la empresa no esta bloqueada (igual que apiAuthTenant hace via bootstrap.php:89)
            if (!checkCompanyStatus($ctx['companyId'])) {
                apiError('Company Blocked', 403);
            }
            // Definir las constantes que los endpoints esperan
            if (!defined('COMPANY_ID'))  define('COMPANY_ID',  $ctx['companyId']);
            if (!defined('OUTLET_ID'))   define('OUTLET_ID',   $ctx['outletId']);
            if (!defined('USER_ID'))     define('USER_ID',     $ctx['userId']);
            if (!defined('REGISTER_ID')) define('REGISTER_ID', $ctx['registerId']);
            if (!defined('ROLE_ID'))     define('ROLE_ID',     $ctx['roleId']);
            return $ctx;
        }
    }
    // Fallback temporal durante migracion -- se quita en S3
    return apiAuthTenant(['panel', 'pos-app']);
}

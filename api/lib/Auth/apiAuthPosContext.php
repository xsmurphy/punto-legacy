<?php
/**
 * Contexto de autenticacion para endpoints POS.
 *
 * Acepta UNICAMENTE cookie `_jwt` (device, 10 anyos). Si no hay cookie valida,
 * retorna 401 -- el front redirigira a /pos-pair para iniciar el pairing.
 *
 * IMPORTANTE: esta funcion define las constantes COMPANY_ID/OUTLET_ID/USER_ID/
 * REGISTER_ID/ROLE_ID directamente desde el ctx del device (sin pasar por
 * data.php completo, que requeriria una company activa y sus settings). Para
 * endpoints POS que solo necesitan esas constantes basicas, esto es suficiente.
 *
 * Si el endpoint POS necesita $company/$setting/$_modules (settings del
 * tenant), tendra que cargar data.php manualmente tras llamar a esta fn.
 *
 * Nota: el fallback a _jwt_panel que existia durante la migracion fue removido
 * en S3 (2026-06-24). Todo el front POS debe parear el dispositivo antes de operar.
 *
 * TODO follow-up: drop column contact.lockPass (plano) despues de validar
 * 1 semana en prod que lockPassHash (bcrypt) funciona para todos los operadores.
 * El script de backfill esta en database/migrations/postgres/49_lockpass_hash_backfill.php.
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
            // TODAY: usado por SaleService y otros endpoints POS para updated_at.
            // data.php no se carga por este path (ver doc arriba), pero TODAY es trivial.
            if (!defined('TODAY'))       define('TODAY',       date('Y-m-d H:i:s'));
            if (!defined('TODAY_DATE'))  define('TODAY_DATE',  date('Y-m-d'));
            return $ctx;
        }
    }
    apiError('Autenticacion requerida', 401);
}

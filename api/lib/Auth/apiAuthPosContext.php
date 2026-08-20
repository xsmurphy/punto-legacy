<?php
/**
 * Contexto de autenticacion para endpoints POS.
 *
 * Acepta UNICAMENTE el Bearer token del device (Authorization: Bearer <token>).
 * El token viaja en localStorage del browser y se adjunta como header en cada
 * request del front POS. Si no hay Bearer valido, retorna 401 -- el front
 * mostrara DeviceNotConnected con instrucciones para re-parear.
 *
 * IMPORTANTE: esta funcion define las constantes COMPANY_ID/OUTLET_ID/USER_ID/
 * REGISTER_ID/ROLE_ID directamente desde el ctx del device (sin pasar por
 * data.php completo, que requeriria una company activa y sus settings). Para
 * endpoints POS que solo necesitan esas constantes basicas, esto es suficiente.
 *
 * Si el endpoint POS necesita $company/$setting/$_modules (settings del
 * tenant), tendra que cargar data.php manualmente tras llamar a esta fn.
 *
 * TODO follow-up: drop column contact.lockPass (plano) despues de validar
 * 1 semana en prod que lockPassHash (bcrypt) funciona para todos los operadores.
 * El script de backfill esta en database/migrations/postgres/49_lockpass_hash_backfill.php.
 */

use Punto\Api\Auth\DeviceAuth;

function apiAuthPosContext(): array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $bearerToken = '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        $bearerToken = $m[1];
    }
    if ($bearerToken !== '') {
        $ctx = DeviceAuth::resolveDeviceToken($bearerToken);
        // Guard único de dimensiones obligatorias (companyId/outletId/registerId),
        // MISMO que apiAuthTenant() usa en bootstrap.php — un device module=pos
        // sin caja asignada (pareo a medias) o ya revocado no opera acá tampoco.
        // Antes este path solo distinguia "ctx null -> 401 generico" y dejaba
        // pasar cualquier ctx no-null aunque outletId/registerId vinieran vacios
        // (mismo agujero que bootstrap.php, en el path de ventas/transacciones
        // en vez del de solo-lectura del catalogo). El check de outlet/register
        // solo aplica a module=pos -- las pantallas (screen/kds/display/print)
        // son legitimamente outlet/register-less (ver doc de
        // requireCompleteContext() en DeviceAuth.php). requireCompleteContext()
        // termina la request con el envelope que pos-fetch.ts sabe interpretar
        // para el self-healing (limpia el token, manda a reconexion) si $ctx
        // no pasa el guard -- nunca retorna en ese caso.
        DeviceAuth::requireCompleteContext($ctx, $ctx !== null ? (string) ($ctx['module'] ?? 'pos') : 'pos');
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
            if (!defined('DEVICE_MODULE')) define('DEVICE_MODULE', $ctx['module'] ?? 'pos');
            // TODAY: usado por SaleService y otros endpoints POS para updated_at.
            // data.php no se carga por este path (ver doc arriba), pero TODAY es trivial.
            if (!defined('TODAY'))       define('TODAY',       date('Y-m-d H:i:s'));
            if (!defined('TODAY_DATE'))  define('TODAY_DATE',  date('Y-m-d'));
            return $ctx;
        }
    }
    apiError('Autenticacion requerida', 401);
}

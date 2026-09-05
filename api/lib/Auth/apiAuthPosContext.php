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
 * LA ZONA HORARIA NO ES UNA EXCEPCION A ESO. `TenantClock::apply()` se llama
 * ACA, no en el endpoint: la TZ de la sesion de PostgreSQL y del proceso PHP
 * es un invariante del EMBUDO DE AUTENTICACION, igual que COMPANY_ID.
 *
 * Por que: `data.php` (el otro embudo, `apiAuthTenant`) ya aplicaba la TZ del
 * tenant. Este path no, asi que la sesion se quedaba con el baseline de
 * plataforma de `includes/db.php` — `APP_TIMEZONE`, sin definir en prod, o sea
 * UTC. El POS manda la fecha de la venta como texto NAIVE ('Y-m-d H:i:s'), y un
 * texto sin zona no es un instante: PostgreSQL lo resuelve con la TZ de sesion.
 * El MISMO string aterrizaba entonces en dos instantes distintos segun por que
 * embudo hubiera entrado la request.
 *
 * Consecuencia real (2026-09-01): una venta emitida 12:07 en Asuncion quedo
 * guardada 09:07 (-3h). `DrawerService::resolveDrawerIdForDate()` busca el
 * turno que CONTIENE esa fecha, no encontro ninguno (el turno habia abierto
 * 12:07 — `drawer.php` va por `apiAuthTenant`, o sea con la TZ correcta),
 * `transaction.drawerid` quedo NULL, y la venta desaparecio del Control de Caja.
 *
 * No se arregla con `APP_TIMEZONE=America/Asuncion`: eso solo mueve la rotura a
 * los tenants de otro pais. Tampoco pisando la fecha del cliente con la del
 * servidor en cada endpoint (`drawer.php` hizo eso en su fallback y dejo el
 * embudo compartido roto): la fecha de la venta es la de EMISION, la del
 * dispositivo — una tablet que estuvo un dia sin red sincroniza al otro dia y
 * esas ventas pertenecen al dia anterior.
 *
 * COSTO: `TenantClock::apply()` necesita el companyId y nada mas. Resuelve la
 * TZ via `TenantLocale`, que cachea por request y hace UN `SELECT config->>...
 * FROM company`. No arrastra `data.php` ni su exigencia de company activa +
 * settings, que es la razon de ser de esta funcion.
 *
 * TODO follow-up: drop column contact.lockPass (plano) despues de validar
 * 1 semana en prod que lockPassHash (bcrypt) funciona para todos los operadores.
 * El script de backfill esta en database/migrations/postgres/49_lockpass_hash_backfill.php.
 */

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Support\TenantClock;

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
            // Verificar que la empresa no esta bloqueada (igual que apiAuthTenant
            // hace via bootstrap.php). El MOTIVO va en `error.details.reason`:
            // este es JUSTO el path por el que sincroniza la cola de ventas del
            // POS, y la D8 (context/34 §F7) exige que un rechazo por mora se
            // traduzca a espera y nunca a venta descartada.
            $denial = companyAccessDenial($ctx['companyId']);
            if ($denial !== null) {
                apiError($denial['message'], 403, ['reason' => $denial['reason']]);
            }
            // Reloj del tenant — ANTES de cualquier constante de fecha y de
            // que el endpoint toque la base. Deja la sesion de PG y el default
            // de PHP en la MISMA zona (la del comercio), que es lo que hace
            // que un timestamp naive signifique lo mismo lo escriba quien lo
            // escriba. Ver el bloque de doc de arriba.
            TenantClock::apply((string) $ctx['companyId']);

            // Definir las constantes que los endpoints esperan
            if (!defined('COMPANY_ID'))  define('COMPANY_ID',  $ctx['companyId']);
            if (!defined('OUTLET_ID'))   define('OUTLET_ID',   $ctx['outletId']);
            if (!defined('USER_ID'))     define('USER_ID',     $ctx['userId']);
            if (!defined('REGISTER_ID')) define('REGISTER_ID', $ctx['registerId']);
            if (!defined('ROLE_ID'))     define('ROLE_ID',     $ctx['roleId']);
            if (!defined('DEVICE_MODULE')) define('DEVICE_MODULE', $ctx['module'] ?? 'pos');
            // TODAY: usado por SaleService y otros endpoints POS para updated_at.
            // data.php no se carga por este path (ver doc arriba), pero TODAY es
            // trivial. Va DESPUES de TenantClock::apply(): antes `date()` corria
            // con el default del proceso (UTC en el container) y estas dos
            // constantes quedaban corridas respecto de la hora del comercio.
            if (!defined('TODAY'))       define('TODAY',       date('Y-m-d H:i:s'));
            if (!defined('TODAY_DATE'))  define('TODAY_DATE',  date('Y-m-d'));
            return $ctx;
        }
    }
    apiError('Autenticacion requerida', 401);
}

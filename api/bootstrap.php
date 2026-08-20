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

// ── Observabilidad de errores ────────────────────────────────────────────────
// display_errors sigue en 0 (no filtrar stack traces al cliente), pero los
// fatales/excepciones DEBEN ser visibles. Incidente 2026-06-30: un
// "Call to undefined method DB::GetRow()" en pagos a crédito quedó 100% silente
// (display_errors=0 + log_errors off) y costó horas de diagnóstico. Estos
// handlers logean a stderr (→ docker logs) y devuelven un JSON 500 limpio en
// vez de una respuesta vacía/HTML. error_log va a stderr por la config del
// Dockerfile (log_errors=On, error_log=/proc/self/fd/2).
// Sentry (observabilidad/alertas) se inicializa más abajo, después del autoload,
// y SOLO si SENTRY_DSN está seteado. Estos handlers lo invocan vía function_exists:
// si Sentry no se inicializó (sin DSN), captureException/captureMessage no existen
// y el reporte es no-op — el error_log + JSON 500 siguen funcionando igual.
set_exception_handler(static function (\Throwable $e): void {
    error_log('[uncaught] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (function_exists('\\Sentry\\captureException')) {
        \Sentry\captureException($e);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['ok' => false, 'error' => ['message' => 'Error interno del servidor', 'code' => 500]]);
});
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    error_log('[fatal] ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
    if (function_exists('\\Sentry\\captureMessage')) {
        \Sentry\captureMessage(
            '[fatal] ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line'],
            \Sentry\Severity::fatal()
        );
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => ['message' => 'Error interno del servidor', 'code' => 500]]);
    }
});

session_start();

// API_APP_DIR apunta a api/ (antes apuntaba a api/core; api/core fue disuelto 2026-06-29).
// La constante se mantiene para compatibilidad con los endpoints v1/ que la usan directamente.
define('API_APP_DIR', __DIR__);

require_once __DIR__ . '/vendor/autoload.php';

// ── Sentry ───────────────────────────────────────────────────────────────────
// Init temprano, SOLO si hay DSN. Sin DSN no se inicializa: \Sentry\init no se
// llama y los handlers de arriba (que chequean function_exists) quedan no-op.
// traces_sample_rate=0.0 → solo errores, sin performance (no gastar cuota).
$__sentryDsn = getenv('SENTRY_DSN') ?: ($_ENV['SENTRY_DSN'] ?? '');
if ($__sentryDsn !== '') {
    \Sentry\init([
        'dsn'                => $__sentryDsn,
        'environment'        => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production',
        'traces_sample_rate' => 0.0,
    ]);
}

require_once __DIR__ . '/includes/cors.php';
require_once __DIR__ . '/includes/jwt_middleware.php';
require_once __DIR__ . '/includes/realtime.php';
require_once __DIR__ . '/includes/rollup.php';
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
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = __DIR__ . '/lib/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
        return;
    }
    // Fallback de case: el dir físico de algunos módulos es lowercase (ej.
    // `lib/services/` con namespace `Punto\Api\Services`). En macOS (FS
    // case-insensitive) el path de arriba matchea igual, pero en Linux prod
    // (case-sensitive) falla → "Class not found". Reintentamos con el primer
    // segmento del path en minúscula para resolver ese mismatch sin renombrar
    // el directorio (que rompería los require_once existentes en lowercase).
    $lower = preg_replace_callback('#^[^/]+#', static fn ($m) => strtolower($m[0]), $relative);
    if ($lower !== $relative) {
        $pathLower = __DIR__ . '/lib/' . $lower . '.php';
        if (is_file($pathLower)) {
            require_once $pathLower;
        }
    }
});

$rateLimiterId = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
require_once __DIR__ . '/head.php'; // db, functions (ncm*, sendPush, checkCompanyStatus), config, enc/dec
require_once __DIR__ . '/lib/Auth/hasPermission.php';

// Normaliza el body → $_POST para todos los verbos (POST/PUT/DELETE/PATCH).
// PHP sólo puebla $_POST en POST form-encoded; en JSON o los demás verbos el body
// queda en php://input. Así los endpoints REST leen $_POST uniformemente sin
// importar el verbo ni el content-type. (Mismo enfoque que panel api_middleware.)
// El BFF legacy de /app manda form-encoded (http_build_query) → $_POST ya
// poblado, salteamos. El cliente nuevo de frontend manda JSON en POST →
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
    $userId     = AUTHED_USER_ID;
    $roleId     = AUTHED_ROLE_ID;
    $realm      = AUTHED_REALM;
    $deviceId   = defined('AUTHED_DEVICE_ID') ? AUTHED_DEVICE_ID : '';

    if (!checkCompanyStatus($companyId)) {
        apiError('Company Blocked', 403);
    }

    // Contexto operativo: la fila `device` es la fuente de verdad para pos-app.
    // Los claims oid/rid del JWT YA NO se usan para scope (pueden estar presentes
    // en tokens viejos, pero se ignoran — evita el drift que causaba los bugs).
    if ($realm === 'pos-app' && $deviceId !== '') {
        $dev = ncmExecute(
            'SELECT outletid, registerid, userid, module FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid AND status = 1',
            [$deviceId, $companyId]
        );
        // Fail-closed: la fila `device` es la fuente de verdad de vigencia del
        // pareo Y de sus dimensiones obligatorias (context/29). Si no existe,
        // está revocada (status=0), o existe pero sin outlet/register asignado
        // (pareo a medias), el código de abajo NO debe caer al fallback de
        // "primer outlet activo" (pensado para outletId vacío por otras
        // razones) — eso disfrazaba un device fantasma/incompleto con un
        // contexto a medias (outlet genérico del tenant, registerId vacío) en
        // vez de rechazar. Guard único compartido con apiAuthPosContext()
        // (agujero real 2026-08-19: un device eliminado desde el panel seguía
        // operando /pos con el token viejo en localStorage).
        $devModule = $dev ? (string) ($dev['module'] ?? 'pos') : 'pos';
        \Punto\Api\Auth\DeviceAuth::requireCompleteContext($dev ? [
            'outletId'   => (string) ($dev['outletid']   ?? ''),
            'registerId' => (string) ($dev['registerid'] ?? ''),
        ] : null, $devModule);
        $outletId   = (string) ($dev['outletid'] ?? '');
        $registerId = (string) ($dev['registerid'] ?? '');
        $module     = $devModule;
        // userId del realm pos-app: el token device NO tiene claim `sub`, así que
        // AUTHED_USER_ID queda vacío. La identidad operativa es el contacto que
        // pareó el device (device.userid) — misma fuente que DeviceAuth::resolveDeviceToken.
        // Sin esto, TenantContext::fromAuth lanza "userId no puede ser vacío" → 500
        // → 502 en /api/pos/bootstrap (incidente 2026-06-27).
        if ($userId === '') {
            $userId = (string) ($dev['userid'] ?? '');
        }
    } else {
        // Realm panel: la sucursal activa viene del claim `oid` del token (persistida
        // por active-outlet.php). El registerId del POS ya NO vive en el token panel
        // (rid eliminado): se resuelve desde la fila device en realm pos-app.
        $outletId   = defined('AUTHED_OUTLET_ID') ? AUTHED_OUTLET_ID : '';
        $registerId = '';
        $module     = '';   // no aplica para realm panel
    }

    // Fallback: primer outlet activo. YA NO alcanza a devices module=pos — el
    // guard de arriba (DeviceAuth::requireCompleteContext) corta antes con 401
    // si el outlet de un device pos viene vacío. Sigue alcanzando a: (1) realm
    // panel (oid ausente en el token — lo opera un humano que puede corregir
    // la sucursal desde el dropdown, no un device headless) y (2) devices
    // module=screen/kds/display/print que lleguen a un endpoint pos-app
    // genérico (ej. items.php) sin outlet asignado — caso de borde no cerrado
    // en este fix porque esos módulos son legítimamente outlet-less por
    // diseño (ver DeviceAuth::requireCompleteContext(), que por eso solo
    // aplica el check a module=pos) y hoy no hay endpoints pos-app genéricos
    // que un device screen use en la práctica. Mismo patrón de "adivinar la
    // dimensión" en PanelAuth.php:79 y CompanyAdminService.php:1550 — tampoco
    // tocado acá, reportado sin arreglar.
    if ($outletId === '') {
        $row = ncmExecute(
            'SELECT outletId FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletId ASC LIMIT 1',
            [$companyId]
        );
        $outletId = (string) ($row['outletId'] ?? ''); // CIA wrapper resuelve case-insensitive
    }

    // data.php define COMPANY_ID/OUTLET_ID/TODAY/COMPANY_NAME/etc. desde estas locales.
    require __DIR__ . '/data.php';

    // View-scope override (frontend 2026-06-13). Si el browser eligió una sucursal
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
        realtimeAfterMutation($__auditMethod, $__auditEndpoint, $__auditTargetId, $companyId);
    }

    // deviceId: solo no-vacío para realm pos-app (ver arriba). Agregado para que
    // callers que necesitan saber "qué device es este" (ej. DrawerService::close(),
    // liberar su propia tenencia de register_lease al cerrar caja — context/29 §4.4)
    // no tengan que resolver AUTHED_DEVICE_ID por su cuenta ni redefinir apiAuthTenant().
    return compact('companyId', 'outletId', 'userId', 'registerId', 'roleId', 'realm', 'module', 'deviceId');
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
               (companyid, userid, outletid, realm, method, endpoint, targetid, meta, ip)
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
 * Default INVERTIDO (2026-08-15, ver context/15-realtime-sync-plan.md): todo
 * mutante bajo /v1/ publica. `$overrides` YA NO es "la lista de lo que
 * avisa" — es (a) el nombre de entity cuando el path no alcanza para
 * derivarlo solo (plurales irregulares, alias semántico como
 * customers→contact), (b) el `scope` cuando no es 'all' por defecto, y
 * (c) `skipResources` para excluir un `?resource=` puntual que es consulta
 * disfrazada de POST. `$excluded` es la allowlist EXPLÍCITA y chica de
 * endpoints que NO publican nada (no un default: la ausencia de entrada acá
 * NUNCA silencia un endpoint, solo la presencia explícita lo hace).
 *
 * Un endpoint mutante nuevo que no aparece en ninguna de las dos listas
 * IGUAL publica: la entity sale del primer segmento del path, singularizado
 * (`deriveEntityFromPath`). Esto es lo que hace estructuralmente imposible
 * "olvidarse" de mapear un endpoint nuevo — antes era la ausencia en el mapa
 * la que dejaba un endpoint mudo por default.
 */
function realtimeAfterMutation(string $method, string $endpoint, ?string $targetId, ?string $companyId = null): void
{
    // Overrides: entity/scope/skipResources que el path NO puede dar solo.
    static $overrides = [
        '/v1/customers'             => ['entity' => 'contact'],
        // sales.php y transactions.php entran acá por completitud/simetría
        // con el resto del mapa, pero HOY son entries vestigiales: ambos
        // autentican con apiAuthPosContext(), no apiAuthTenant(), así que
        // NUNCA pasan por esta función — el publish real de esos dos vive
        // explícito en SaleService::save() y en cada resource de
        // transactions.php (void/status/reject/DELETE/itemDeletion). Si algún
        // día ese endpoint migra a apiAuthTenant(), estos overrides ya están
        // listos y quedan consistentes con esos publishes explícitos.
        '/v1/sales'                 => ['entity' => 'transaction', 'scope' => 'dashboard'],
        '/v1/transactions'          => ['entity' => 'transaction', 'scope' => 'dashboard'],
        // /v1/orders es distinto de /v1/orders-core (OrderCoreService publica
        // su propia entity 'order' con scope 'all' — ver ese Service). Este
        // override es solo para las mutaciones propias de orders.php
        // (accept/outlet/user); el matching es por segmento completo
        // (endpointMatches), así que NO colisiona con /v1/orders-core.
        '/v1/orders'                => ['entity' => 'transaction', 'scope' => 'dashboard'],
        '/v1/drawer'                => ['entity' => 'drawer',      'scope' => 'dashboard'],
        '/v1/reports/drawers'       => ['entity' => 'drawer',      'scope' => 'dashboard'],
        '/v1/modules'               => ['entity' => 'setting'],
        // Antes publicaban entity 'item' — bug: el front YA esperaba
        // 'price-list' (ENTITY_TO_QUERY_KEYS en use-realtime-sync.ts, desde
        // que existe ese mapeo), así que esa entrada era código muerto y
        // ["price-lists"]/["price-list-items"] nunca se invalidaban al
        // editar una lista — la caja seguía cobrando el precio viejo
        // (hallazgo 2026-08-16). Publicar 'item' acá tampoco tenía
        // consumidor real: ningún queryKey de catálogo (`items`, `item`,
        // `pos-bootstrap`) muestra datos derivados de price_list — el POS
        // resuelve precios server-side vía /v1/price_resolve bajo demanda,
        // nunca desde el bootstrap del catálogo. Por eso el cambio es un
        // swap completo, no un alias doble.
        '/v1/price_list'            => ['entity' => 'price-list'],
        '/v1/price_list_item'       => ['entity' => 'price-list'],
        '/v1/vpayments'             => ['entity' => 'payment-method'],
        // validate es POST de consulta pura — no muta; solo canjear/emitir dispara evento.
        '/v1/giftcards'             => ['entity' => 'giftcard', 'skipResources' => ['validate']],
        '/v1/pack_component'        => ['entity' => 'pack'],
        '/v1/sold_pack'             => ['entity' => 'pack'],
        '/v1/sold_pack_usage'       => ['entity' => 'pack'],
        '/v1/order_items'           => ['entity' => 'order'],
        '/v1/item_addons'           => ['entity' => 'item'],
        '/v1/customer_address'      => ['entity' => 'contact'],
        '/v1/customer_note'         => ['entity' => 'contact'],
        '/v1/purchases'             => ['entity' => 'purchase',    'scope' => 'dashboard'],
        // Alinea con la entity 'space' que ya publican SpaceService/
        // SpaceSessionService/SpaceSettlementService — sin este override,
        // el path derivaría 'space-sector' (string distinto) y el front no
        // lo reconocería (ver ENTITY_TO_QUERY_KEYS en use-realtime-sync.ts).
        '/v1/space-sectors'         => ['entity' => 'space'],
        // bulk-get (sync realtime quirúrgico, context/15) es una LECTURA que
        // usa POST solo por tamaño de body — sin este skip, cada fetch
        // quirúrgico del POS dispararía él mismo un evento 'item'/'contact'
        // fantasma (POST → op 'create' por default), invalidando de vuelta
        // lo que el propio fetch acaba de traer. Sin `entity` explícito acá:
        // el resto de las mutaciones de estos dos paths (create/update/
        // delete real) sigue derivando 'item'/'contact' por default, sin
        // cambios.
        '/v1/items'                 => ['skipResources' => ['bulk-get']],
        '/v1/contacts'              => ['skipResources' => ['bulk-get']],
    ];

    // Allowlist EXPLÍCITA y chica de endpoints que NO publican. Fuera de
    // alcance del plan (context/15 § "El alcance NO incluye") o consultas
    // puntuales que no ameritan un broadcast — no un default, una excepción.
    static $excluded = [
        '/v1/admin',           // mutaciones de admin realm — plan explícitamente las excluye.
        // CreditPaymentService::allocate() ya publica 'transaction'/'all'
        // explícito (patrón de referencia, ver context/15) — sin esto, el
        // default derivaría 'credit-payment' (sin queryKey en el front) Y
        // dispararía un segundo evento por la misma request.
        '/v1/credit-payments',
        // /v1/sync (context/43-sync-incremental.md) es SIEMPRE lectura — el
        // POST es solo por tamaño del body (mismo criterio que bulk-get de
        // items/contacts), nunca muta nada. Sin este excluded, el default
        // derivaría entity 'sync' (sin queryKey en el front, warning en dev)
        // y dispararía un broadcast fantasma en cada delta pedido.
        '/v1/sync',
    ];

    foreach ($excluded as $prefix) {
        if (endpointMatches($endpoint, $prefix)) {
            return;
        }
    }

    $cfg = null;
    foreach ($overrides as $prefix => $c) {
        if (endpointMatches($endpoint, $prefix)) {
            $cfg = $c;
            break;
        }
    }

    if ($cfg !== null && !empty($cfg['skipResources'])) {
        $res = (string) ($_GET['resource'] ?? '');
        if (in_array($res, $cfg['skipResources'], true)) {
            return;
        }
    }

    $entity = $cfg['entity'] ?? deriveEntityFromPath($endpoint);
    if ($entity === null) {
        return;
    }
    $scope = $cfg['scope'] ?? 'all';

    $op = match ($method) {
        'POST'         => 'create',
        'PUT', 'PATCH' => 'update',
        'DELETE'       => 'delete',
        default        => 'update',
    };
    realtimePublish($entity, $op, $targetId, $scope);
    syncSectionAfterMutation($entity, $companyId);
}

/**
 * Watermark de la sección "settings" del sync incremental del POS
 * (context/43-sync-incremental.md). Mismo choke point default-on que
 * `realtimeAfterMutation` (se llama desde ahí, con el `$entity` YA
 * derivado) — así un endpoint nuevo bajo /v1/ que mute alguna de estas
 * entidades bumpea el watermark sin que nadie tenga que acordarse de
 * llamar nada a mano.
 *
 * `item`/`contact` NO pasan por acá: su propia columna `updated_at` (con
 * índice) ES su watermark — sección "items"/"customers" del sync, delta por
 * fila. `transaction`/`drawer`/`expense`/`purchase`/etc. TAMPOCO: son ruido
 * de alta frecuencia (una venta por minuto) ajeno al bootstrap "estático"
 * del POS — bumpear acá los invalidaría constantemente sin necesidad.
 *
 * El resto del catálogo "settings" (outlet/register/tax/category/brand/tag/
 * payment-method/printer_binding/user/setting) es de cardinalidad baja
 * (decenas, no miles) — no necesita delta por fila: cuando el watermark
 * queda stale, el POS simplemente vuelve a pedir el bundle completo
 * (barato) en vez de reconciliar filas borradas/creadas una por una.
 */
function syncSectionAfterMutation(string $entity, ?string $companyId): void
{
    if (!$companyId) {
        return;
    }
    static $settingsEntities = [
        'outlet', 'register', 'tax', 'category', 'brand', 'tag',
        'payment-method', 'printer_binding', 'user', 'setting',
    ];
    // 'price-list' A PROPÓSITO no está acá TODAVÍA (hallazgo 2026-08-16,
    // verificando context/43 §Qué quedó afuera — el doc decía que
    // price_list/price_list_item bumpeaban este watermark; NO era cierto en
    // el código). Hoy `/v1/price_list` Y `/v1/price_list_item` comparten la
    // misma entity `'price-list'` acá arriba (ver `$overrides`) — sumar esa
    // entity a esta lista bumpearía "settings" tanto por editar la CABECERA
    // de una lista (barato, cardinalidad baja, correcto) como por editar UN
    // OVERRIDE de ítem (caro: el único consumidor de este watermark,
    // `runDeltaSync`/delta-sync.ts, reacciona recargando `pos-bootstrap`
    // COMPLETO — `price_list_item` puede tener tantas filas como el
    // catálogo). Diseño acordado con el owner (`context/44
    // -listas-de-precio-offline.md` §Decisión 1, revisada): la CABECERA sí
    // va en `settings` — falta separar su entity de la de
    // `price_list_item` para poder sumarla acá sin arrastrar los overrides.
    // Los overrides NO pasan por este mecanismo — viajan con el ÍTEM
    // (`item.updatedAt` bumpeado por trigger de DB sobre `price_list_item`,
    // ver el mismo doc). Planificado, no implementado.
    if (!in_array($entity, $settingsEntities, true)) {
        return;
    }
    try {
        updateLastTimeEdit($companyId, 'settings');
    } catch (\Throwable $e) {
        // Best-effort: nunca interrumpir la mutación por esto.
        error_log('[syncSectionAfterMutation] Error actualizando settingsLastUpdate: ' . $e->getMessage());
    }
}

/**
 * Match de endpoint por SEGMENTO completo, no por substring crudo.
 * `str_starts_with('/v1/orders-core', '/v1/orders')` da true y eso es un bug:
 * dos endpoints distintos ('/v1/orders' y '/v1/orders-core') colisionaban en
 * el mapa viejo (causaba doble-publish en /v1/orders-core — el mapa emitía
 * 'transaction'/dashboard Y OrderCoreService emitía 'order'/all para la misma
 * request). Acá exigimos que el próximo carácter después del prefijo sea '/'
 * o que el endpoint termine exactamente ahí.
 */
function endpointMatches(string $endpoint, string $prefix): bool
{
    if ($endpoint === $prefix) {
        return true;
    }
    return str_starts_with($endpoint, $prefix . '/');
}

/**
 * Deriva un nombre de entity del primer segmento de path bajo /v1/,
 * singularizado. Best-effort: si el resultado no matchea ningún queryKey del
 * front, `useRealtimeSync` lo ignora (con warning en dev, ver
 * use-realtime-sync.ts) — nunca rompe nada, en el peor caso es un broadcast
 * de más que nadie escucha.
 */
function deriveEntityFromPath(string $endpoint): ?string
{
    $parts = explode('/', trim($endpoint, '/'));
    // $parts[0] === 'v1', $parts[1] es el recurso (ej. 'items', 'order_items').
    if (($parts[0] ?? '') !== 'v1' || empty($parts[1])) {
        return null;
    }
    return singularizeSegment($parts[1]);
}

/**
 * Singulariza la última palabra de un segmento de path (separada por '-' o
 * '_'), preservando el resto como prefijo — "space-sectors" → "space-sector",
 * "giftcards" → "giftcard". Heurística simple (plurales regulares del
 * inglés/nombres de tabla en este codebase), no un singularizador general.
 */
function singularizeSegment(string $segment): string
{
    // OJO: `?:` trata 0 como falsy — si el separador estuviera en la
    // posición 0 (no pasa hoy con ningún endpoint real) se perdería. Chequeo
    // explícito contra `false` para no repetir ese bug.
    $dashPos   = strrpos($segment, '-');
    $underPos  = strrpos($segment, '_');
    $lastSep   = max($dashPos === false ? -1 : $dashPos, $underPos === false ? -1 : $underPos);
    $prefix  = $lastSep >= 0 ? substr($segment, 0, $lastSep + 1) : '';
    $word    = $lastSep >= 0 ? substr($segment, $lastSep + 1) : $segment;

    if (preg_match('/ies$/', $word)) {
        $word = substr($word, 0, -3) . 'y';
    } elseif (preg_match('/(s|x|z|ch|sh)es$/', $word)) {
        $word = substr($word, 0, -2);
    } elseif (str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
        $word = substr($word, 0, -1);
    }

    return $prefix . $word;
}

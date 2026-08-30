<?php
declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

/**
 * Arnés del REALM `mcp` sobre endpoints reales — M1 de `context/58`.
 *
 * Complementa a `mcp_key_test.php` (que cubre la credencial en sí) probando lo
 * que pasa cuando esa credencial LLEGA a `/v1/*`. Tres propiedades, y las tres
 * son de seguridad:
 *
 *  1. Una key lee los endpoints que optaron por el realm. Sin esto el MCP no
 *     sirve para nada.
 *  2. Una key NO puede escribir en NINGUNO, ni siquiera en los que habilitaron
 *     el realm y sirven mutaciones en el mismo archivo (items, contacts,
 *     users…). El read-only lo hace cumplir `apiAuthTenant()` en el embudo, no
 *     cada endpoint: por eso alcanza con probar que el embudo corta, y no hay
 *     que repetir el caso 18 veces esperando que nadie se olvide.
 *  3. Una key es rechazada donde el realm NO optó. Habilitar el MCP es
 *     explícito por endpoint; lo que no se agregó, no entra.
 *  4. El rate limit del realm existe, cuenta por KEY y es FAIL_OPEN. Lo
 *     último importa tanto como lo primero: si Redis se cae, las integraciones
 *     de todos los comercios tienen que seguir leyendo — es una superficie de
 *     lectura, y tirarla por un contador es peor que el abuso que evita.
 *
 * Corre cada endpoint en SUBPROCESO (`_permission_once_cli.php`) porque
 * `apiError()` hace `exit`: un 405 dentro del proceso del test lo mataría.
 *
 * Uso (necesita Postgres migrado — ver run_mcp_realm_test.sh):
 *   POSTGRES_HOST=... php -d variables_order=EGPCS api/tests/mcp_realm_test.php
 */

$companyId = 'c3a1e470-0000-4000-8000-000000000101';
$outletId  = 'c3a1e470-0000-4000-8000-000000000102';
$userId    = 'c3a1e470-0000-4000-8000-000000000103';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletId);
define('USER_ID',    $userId);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Auth/McpKeyService.php';

use Punto\Api\Auth\McpKeyService;

/** @var \Punto\Api\Database\Query $db */
global $db;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) { echo "OK   $label\n"; return; }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

/**
 * Corre un endpoint en subproceso con el Bearer dado.
 *
 * @return array{0:int,1:string} status y salida CRUDA. La cruda se incluye en
 *   el detalle de las fallas: un `status = 0` significa "no se pudo parsear la
 *   respuesta", que puede ser un fatal, una salida sin envelope o un parser
 *   desactualizado — tres causas distintas que sin el texto no se distinguen.
 */
function hitEndpoint(string $endpointRel, string $method, string $query, string $bearer): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        $endpointRel, $method, $query, '{}', '', $bearer,
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return [0, 'no se pudo abrir el subproceso'];
    }
    fwrite($pipes[0], '{}');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // El status sale del ENVELOPE, no de http_response_code(): bajo el SAPI cli
    // ese getter no refleja lo que seteó apiError(). Mismo criterio que
    // permission_enforcement_test.php.
    $raw = trim($out . $err);
    if (preg_match('/BODY:(\{.*?\})\s*\nHTTP_STATUS:/s', $raw, $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            if (($env['ok'] ?? null) === true) {
                return [200, $raw];
            }
            // Dos formas conviven y hay que contemplar las dos, o un rechazo
            // legítimo se lee como "no pude parsear":
            //   - envelope canónico de apiError():  {"ok":false,"error":{"code":403}}
            //   - `die()` crudo de authResolve():   {"error":"Token de otro realm","code":401}
            // En el segundo, `error` es un STRING: leer `error.code` da 0 y
            // enmascara un 401 real. Solo se corta si de verdad salió un código.
            $code = (int) ($env['error']['code'] ?? $env['code'] ?? 0);
            if ($code > 0) {
                return [$code, $raw];
            }
        }
    }
    if (preg_match('/"code":\s*"?(\d{3})/', $raw, $m)) return [(int) $m[1], $raw];
    if (str_contains($raw, '"ok":true')) return [200, $raw];
    return [0, mb_substr($raw, 0, 400)];
}

try {
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"MCP Realm Test\"}'::jsonb)",
        [$companyId]
    );
    $db->Execute(
        'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
        [$outletId, 'MCP Realm Sucursal', $companyId]
    );
    $db->Execute(
        'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
         VALUES (?, ?, ?, ?, 0, 1)',
        [$userId, 'MCP Realm Usuario', $companyId, $outletId]
    );

    $key = (new McpKeyService())->issue([
        'companyId' => $companyId,
        'userId'    => $userId,
        'outletId'  => $outletId,
        'roleId'    => 'admin',
    ], 'Arnés de realm');
    $bearer = $key['token'];

    // ── 1. Lee donde el realm optó ───────────────────────────────────────────
    // 200 o un error de NEGOCIO (422/404) prueban que pasó el gate de auth. Lo
    // que NO puede aparecer es 401 (no autenticado) ni 405 (verbo prohibido).
    foreach ([
        ['v1/settings.php',              ''],
        ['v1/outlets.php',               ''],
        ['v1/reports/summary_year.php',  'y=2026'],
    ] as [$ep, $q]) {
        [$st, $raw] = hitEndpoint($ep, 'GET', $q, $bearer);
        check(
            "GET $ep pasa el gate del realm",
            $st !== 401 && $st !== 405 && $st !== 0,
            "status = $st (401 = el realm no optó; 405 = lo trató como escritura) | $raw",
            $failures, $checks
        );
    }

    // ── 2. NO escribe en ningún lado ─────────────────────────────────────────
    foreach ([
        ['v1/items.php',    'POST'],
        ['v1/contacts.php', 'POST'],
        ['v1/users.php',    'POST'],
        ['v1/settings.php', 'PUT'],
    ] as [$ep, $method]) {
        [$st, $raw] = hitEndpoint($ep, $method, '', $bearer);
        check(
            "$method $ep se rechaza por read-only",
            $st === 405 || $st === 401,
            "status = $st — se esperaba 405 del embudo | $raw",
            $failures, $checks
        );
    }

    // ── 3. Rechazada donde el realm NO optó ──────────────────────────────────
    // `devices.php` y `mcp-keys.php` son realm `panel` a propósito: una key
    // filtrada no puede enumerar el parque de cajas ni fabricarse más keys.
    foreach (['v1/devices.php', 'v1/mcp-keys.php'] as $ep) {
        [$st, $raw] = hitEndpoint($ep, 'GET', '', $bearer);
        check(
            "GET $ep rechaza la key (el realm no optó)",
            $st === 401,
            "status = $st — se esperaba 401 | $raw",
            $failures, $checks
        );
    }
    // ── 4. Rate limit ────────────────────────────────────────────────────────
    // En este entorno Redis NO está (el arnés lo dice en su salida), así que lo
    // que se ejercita de verdad es la rama FAIL_OPEN: las llamadas de arriba
    // pasaron TODAS, que es exactamente el comportamiento que se quiere cuando
    // el limiter no está disponible. Si esto se rompiera, los GET del caso 1
    // habrían dado 429 o 503.
    [$st, $raw] = hitEndpoint('v1/settings.php', 'GET', '', $bearer);
    check(
        'sin Redis el limiter deja pasar (FAIL_OPEN), no corta la integración',
        $st !== 429 && $st !== 503,
        "status = $st — con FAIL_CLOSED daría 503 y tumbaría a todos los comercios | $raw",
        $failures, $checks
    );

    // Y que la regla siga en el embudo: es lo que puede regresar si alguien
    // mueve el limiter a un endpoint puntual o cambia la política.
    $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/bootstrap.php');
    check(
        'el limiter cuenta por KEY (AUTHED_SESSION_ID), no por IP ni por tenant',
        str_contains($bootstrap, '$__rlKey = (string) AUTHED_SESSION_ID'),
        'contar por IP juntaría varias keys del mismo comercio; por tenant, las de usuarios distintos',
        $failures, $checks
    );
    check(
        'son DOS ventanas: la de minuto corta el loop, la diaria acota el costo',
        str_contains($bootstrap, "'mcpmin'") && str_contains($bootstrap, "'mcpday'"),
        'quedó una sola ventana',
        $failures, $checks
    );
    check(
        'la política es FAIL_OPEN, no FAIL_CLOSED',
        str_contains($bootstrap, 'RateLimiter::FAIL_OPEN') && !preg_match('/mcp.*FAIL_CLOSED/s', substr($bootstrap, strpos($bootstrap, 'mcpmin') - 2000, 4000)),
        'con FAIL_CLOSED una caída de Redis tumba las integraciones de todos',
        $failures, $checks
    );
} finally {
    $db->Execute("DELETE FROM auth_session WHERE companyid = ?::uuid AND realm = 'mcp'", [$companyId]);
    $db->Execute('DELETE FROM tenant_audit WHERE companyid = ?::uuid', [$companyId]);
    $db->Execute('DELETE FROM contact WHERE contactId = ?', [$userId]);
    $db->Execute('DELETE FROM outlet  WHERE outletId  = ?', [$outletId]);
    $db->Execute('DELETE FROM company WHERE companyId = ?', [$companyId]);
}

harnessFinish($failures, $checks);

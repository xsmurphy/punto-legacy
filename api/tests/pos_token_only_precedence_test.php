<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (DB real) de la PRECEDENCIA DE BEARER en `authResolve()`
 * (`api/includes/auth_session.php`) y del token-only del POS.
 *
 * ── La regla ────────────────────────────────────────────────────────────────
 * Si la request trae `Authorization: Bearer`, el Bearer define el realm y las
 * cookies se IGNORAN. La cookie solo cuenta cuando NO hay Bearer.
 *
 * ── Por qué existe: tres incidentes de la misma clase en dos meses ───────────
 *   1. 2026-07-19 — Bearer automático en `api-client.ts`: el PANEL operaba con
 *      el outlet de la caja.
 *   2. 2026-08-24 — `/v1/users` con Bearer de device → 403 → lock screen sin PINs.
 *   3. 2026-08-25 — `/api/pos/bootstrap` SIN Bearer resolvía como panel por la
 *      cookie y devolvía 200 sin el roster; el cache envenenado dejó un iPhone
 *      recién pareado bloqueado.
 *
 * La causa común es el browser del operador, que por el modelo de doble sesión
 * lleva las DOS credenciales (cookie `_jwt_panel` + Bearer del device). Cada fix
 * anterior fue local; esto verifica la regla compartida.
 *
 * El caso (c) es el corazón: Bearer REVOCADO + cookie de panel VÁLIDA. Con el
 * modelo viejo ("primera credencial válida gana") la cookie rescataba la request
 * y el POS seguía operando como panel; ahora tiene que salir 401 `session_revoked`,
 * que es lo que dispara el self-healing del device en `pos-fetch.ts`.
 *
 * Los casos (b) y (f) son el regression guard del PANEL: la cookie tiene que
 * seguir funcionando cuando NO hay Bearer. La precedencia no puede dejarlo afuera.
 *
 * La contraparte de este arnés vive en el front
 * (`frontend/lib/bff/__tests__/pos-token-only.test.ts`): verifica que ninguna
 * ruta `/api/pos/*` reenvíe la cookie ni omita el Bearer.
 *
 * `authResolve()` muere con `die()` en el path de error y define constantes
 * (AUTHED_*) que solo se pueden definir una vez por proceso, así que cada caso
 * corre en su propio subproceso vía `_auth_precedence_once_cli.php`. Ese helper
 * llama a `authResolve()` y no a `apiAuthTenant()` — el motivo (una deuda de
 * `data.php` que rompería los casos de éxito por algo ajeno a la auth) está
 * explicado en su docblock.
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — Docker, ver
 * `run_credit_payment_void_test.sh` como referencia):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/pos_token_only_precedence_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Auth\DeviceAuth;

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function runAuth(string $bearer, string $cookie, string $realms): string
{
    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBin) . ' -d variables_order=EGPCS '
        . escapeshellarg(__DIR__ . '/_auth_precedence_once_cli.php') . ' '
        . escapeshellarg($bearer) . ' '
        . escapeshellarg($cookie) . ' '
        . escapeshellarg($realms) . ' 2>&1';
    return shell_exec($cmd) ?? '';
}

$deviceIdsToClean = [];
$sessionIdsToClean = [];

try {
    // ── Credenciales del fixture ──────────────────────────────────────────────
    // Device pos-app activo (el Bearer de la caja).
    $device = DeviceAuth::issueDeviceToken(
        $companyId,
        $outletId,
        $registerId,
        $userId,
        'Test device — precedencia',
        'phpunit-like/pos_token_only_precedence_test',
        'test-precedence-' . bin2hex(random_bytes(6)),
    );
    $deviceIdsToClean[] = $device['deviceId'];

    // Sesión de panel válida (la cookie `_jwt_panel` del operador).
    $panelToken = authSessionCreate('panel', [
        'companyId' => $companyId,
        'userId'    => $userId,
        'outletId'  => $outletId,
        'meta'      => ['origen' => 'pos_token_only_precedence_test'],
    ]);
    $panelRow = authSessionLookup($panelToken);
    if ($panelRow !== null) {
        $sessionIdsToClean[] = (string) $panelRow['sessionId'];
    }

    // Device con la SESIÓN revocada (el caso del incidente 3).
    //
    // Se revoca con `authSessionRevokeByDevice()` y no con `DeviceAuth::revoke()`
    // porque son dos cosas distintas: `DeviceAuth::revoke()` marca la fila
    // `device` (status=0) y a eso reacciona `apiAuthTenant()`, mientras que la
    // revocación de las filas `auth_session` es lo que ve `authResolve()`, que
    // es donde vive la precedencia que este arnés verifica. El panel dispara
    // las dos. El camino de la fila `device` ya está cubierto por
    // `pos_device_revoked_auth_test.php`.
    $revoked = DeviceAuth::issueDeviceToken(
        $companyId,
        $outletId,
        $registerId,
        $userId,
        'Test device — revocado',
        'phpunit-like/pos_token_only_precedence_test',
        'test-precedence-revoked-' . bin2hex(random_bytes(6)),
    );
    $deviceIdsToClean[] = $revoked['deviceId'];
    authSessionRevokeByDevice($revoked['deviceId'], $companyId);

    // ── (a) Bearer + cookie, endpoint multi-realm → manda el BEARER ───────────
    $outA = runAuth($device['token'], $panelToken, 'panel,pos-app');
    check(
        '(a) Bearer pos-app + cookie panel en endpoint multi-realm → resuelve pos-app',
        str_contains($outA, 'REALM:pos-app') && !str_contains($outA, 'HTTP_STATUS:401'),
        "salida: $outA",
        $failures,
        $checks
    );

    // ── (b) Solo cookie, endpoint multi-realm → resuelve PANEL ────────────────
    // REGRESSION GUARD del panel: sin Bearer la cookie sigue siendo la credencial.
    $outB = runAuth('', $panelToken, 'panel,pos-app');
    check(
        '(b) sin Bearer + cookie panel en endpoint multi-realm → resuelve panel (no rompe el panel)',
        str_contains($outB, 'REALM:panel') && !str_contains($outB, 'HTTP_STATUS:401'),
        "salida: $outB",
        $failures,
        $checks
    );

    // ── (c) Bearer REVOCADO + cookie VÁLIDA → 401, la cookie NO rescata ───────
    // El corazón del incidente 2026-08-25.
    $outC = runAuth($revoked['token'], $panelToken, 'panel,pos-app');
    check(
        '(c) Bearer revocado + cookie panel válida → 401 session_revoked, la cookie NO rescata',
        str_contains($outC, 'HTTP_STATUS:401')
            && str_contains($outC, 'session_revoked')
            && !str_contains($outC, 'REALM:panel'),
        "salida: $outC",
        $failures,
        $checks
    );

    // ── (d) Bearer de otro realm + cookie válida para ESE realm → 401 ─────────
    // Precedencia estricta: el Bearer define el realm aunque no sea el aceptado.
    $outD = runAuth($device['token'], $panelToken, 'panel');
    check(
        '(d) Bearer pos-app + cookie panel en endpoint SOLO panel → 401, no cae a la cookie',
        str_contains($outD, 'HTTP_STATUS:401') && !str_contains($outD, 'REALM:panel'),
        "salida: $outD",
        $failures,
        $checks
    );

    // ── (e) Solo cookie de panel contra endpoint SOLO pos-app → 401 ───────────
    // Es la forma pura del incidente 3: sin Bearer, un endpoint del POS no puede
    // contestarse con la sesión del panel.
    $outE = runAuth('', $panelToken, 'pos-app');
    check(
        '(e) sin Bearer + cookie panel en endpoint SOLO pos-app → 401',
        str_contains($outE, 'HTTP_STATUS:401') && !str_contains($outE, 'REALM:'),
        "salida: $outE",
        $failures,
        $checks
    );

    // ── (f) Panel puro (sin Bearer) contra endpoint solo panel → 200 ─────────
    $outF = runAuth('', $panelToken, 'panel');
    check(
        '(f) sin Bearer + cookie panel en endpoint SOLO panel → resuelve panel (panel intacto)',
        str_contains($outF, 'REALM:panel') && !str_contains($outF, 'HTTP_STATUS:401'),
        "salida: $outF",
        $failures,
        $checks
    );

    // ── (g) Superficie: los endpoints del POS y su puerta de auth ────────────
    // Chequeo estático. La precedencia de arriba protege a TODOS los endpoints
    // (vive en el resolver compartido), pero estos son los que el POS consume y
    // los que el owner pidió cubrir explícitamente. Lo que se verifica acá es
    // que ninguno cambie de puerta sin que alguien se entere: que los que hoy
    // son Bearer-only por construcción (`apiAuthPosContext()`) no pasen a
    // aceptar cookie, y que los multi-realm sigan declarando sus realms de
    // forma explícita en vez de heredar el default.
    $apiDir = dirname(__DIR__);

    // Bearer-only por construcción: `apiAuthPosContext()` solo lee el header
    // Authorization, nunca $_COOKIE (ver api/lib/Auth/apiAuthPosContext.php).
    $bearerOnlyEndpoints = [
        'v1/sales.php',
        'v1/offline-sync.php',
        'v1/transactions.php',
    ];
    foreach ($bearerOnlyEndpoints as $rel) {
        $src = @file_get_contents($apiDir . '/' . $rel);
        check(
            "(g) $rel sigue siendo Bearer-only (apiAuthPosContext)",
            is_string($src)
                && str_contains($src, 'apiAuthPosContext()')
                && !preg_match('/apiAuthTenant\s*\(\s*\[[^\]]*[\'"]panel[\'"]/', $src),
            is_string($src) ? 'el endpoint dejó de usar apiAuthPosContext o sumó panel a apiAuthTenant' : "no se pudo leer $rel",
            $failures,
            $checks
        );
    }

    // Multi-realm declarados: el POS los alcanza SOLO por el BFF `/api/pos/*`,
    // que no reenvía cookie (lo verifica el guard del front). Acá exigimos que
    // la declaración sea explícita — un `apiAuthTenant()` pelado hereda
    // `['pos-app']` y cambiaría el contrato en silencio.
    $declaredRealmEndpoints = [
        'v1/bootstrap.php',
        'v1/items.php',
        'v1/drawer.php',
        'v1/unlock-pin.php',
        'v1/orders-core.php',
    ];
    foreach ($declaredRealmEndpoints as $rel) {
        $src = @file_get_contents($apiDir . '/' . $rel);
        check(
            "(g) $rel declara sus realms explícitamente",
            is_string($src) && preg_match('/apiAuthTenant\s*\(\s*\[[^\]]*[\'"]pos-app[\'"]/', $src) === 1,
            is_string($src) ? 'no se encontró un apiAuthTenant([... "pos-app" ...]) explícito' : "no se pudo leer $rel",
            $failures,
            $checks
        );
    }
} finally {
    foreach ($deviceIdsToClean as $deviceId) {
        try {
            ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$deviceId]);
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
    foreach ($sessionIdsToClean as $sessionId) {
        try {
            ncmExecute('DELETE FROM auth_session WHERE sessionid = ?::uuid', [$sessionId]);
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}

harnessFinish($failures, $checks);

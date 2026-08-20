<?php
declare(strict_types=1);

/**
 * Test de integración (DB real) del fail-closed de `apiAuthTenant()`
 * (api/bootstrap.php) para el realm `pos-app` cuando el device pareado ya no
 * existe o está revocado — ver context/29-numeracion-y-exclusividad-de-caja.md
 * y el agujero real reportado 2026-08-19: un device eliminado desde el panel
 * seguía autenticando /pos con el token viejo en localStorage porque
 * `apiAuthTenant()`, al no encontrar la fila `device`, caía al fallback de
 * "primer outlet activo del tenant" en vez de rechazar — servía un contexto
 * a medias (outlet genérico, registerId vacío) en vez de 401.
 *
 * Reusa el tenant fixture "Verify PY" (mismo que
 * `credit_payment_void_test.php` / `api/lib/Sales/verify_chain/seed.sql`) —
 * los devices que crea este test son propios (browserLocalId único por
 * corrida) y se limpian al final; NO toca los devices/sesiones del arnés de
 * ventas.
 *
 * Casos:
 *   (a) device activo (status=1) → apiAuthTenant() resuelve el ctx normal.
 *       Regression guard: el fix NO debe romper el pareo legítimo.
 *   (b) device soft-revocado (status=0, el camino real del panel:
 *       DELETE /v1/devices?id=X) → 401 con code "session_revoked", NUNCA 200.
 *   (c) device hard-eliminado (fila ya no existe en absoluto) → mismo 401.
 *       Cubre explícitamente la hipótesis "no encontré fila → no bloqueo".
 *   (d) device module=pos ACTIVO pero con outlet/register vacío (pareo a
 *       medias) → 401 con code "device_incomplete" (distinto de
 *       session_revoked: la causa y la corrección no son las mismas).
 *       Cubre el pedido explícito del owner: companyId/outletId/registerId
 *       son 100% obligatorios en el /pos, sin importar POR QUÉ falten.
 *       Ver `DeviceAuth::requireCompleteContext()` en
 *       `api/lib/Auth/DeviceAuth.php` — guard único compartido con
 *       `apiAuthPosContext()` (ver `pos_device_screen_module_scope_test.php`
 *       para el regression guard de que este check NO alcanza a devices
 *       `screen`/`kds`/`display`, que son legítimamente outlet/register-less).
 *
 * `apiAuthTenant()` termina con `die()` en el path de error, así que (b), (c)
 * y (d) corren en subproceso vía `_pos_auth_once_cli.php` (mismo patrón que
 * `_void_once_cli.php` usa para `CreditPaymentService::void()`).
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — Docker, ver
 * `run_credit_payment_void_test.sh` como referencia de cómo levantar todo):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/pos_device_revoked_auth_test.php
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

function check(string $label, bool $ok, string $detail, int &$failures): void
{
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function runAuthSubprocess(string $bearerToken): string
{
    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBin) . ' -d variables_order=EGPCS '
        . escapeshellarg(__DIR__ . '/_pos_auth_once_cli.php') . ' '
        . escapeshellarg($bearerToken) . ' 2>&1';
    return shell_exec($cmd) ?? '';
}

$deviceIdsToClean = [];

try {
    // ── (a) device activo → resuelve normal (regression guard) ──
    $issuedA = DeviceAuth::issueDeviceToken(
        $companyId,
        $outletId,
        $registerId,
        $userId,
        'Test device — activo',
        'phpunit-like/pos_device_revoked_auth_test',
        'test-active-' . bin2hex(random_bytes(6)),
    );
    $deviceIdsToClean[] = $issuedA['deviceId'];
    $outputA = runAuthSubprocess($issuedA['token']);
    check(
        '(a) device activo resuelve ctx (no rompe el pareo legítimo)',
        str_contains($outputA, 'CTX:') && str_contains($outputA, $companyId) && !str_contains($outputA, 'HTTP_STATUS:401'),
        "salida del subproceso: $outputA",
        $failures
    );

    // ── (b) device soft-revocado (mismo camino que DELETE /v1/devices?id=X) ──
    $issuedB = DeviceAuth::issueDeviceToken(
        $companyId,
        $outletId,
        $registerId,
        $userId,
        'Test device — a revocar (soft)',
        'phpunit-like/pos_device_revoked_auth_test',
        'test-soft-revoked-' . bin2hex(random_bytes(6)),
    );
    $deviceIdsToClean[] = $issuedB['deviceId'];
    DeviceAuth::revoke($issuedB['deviceId'], $companyId);
    $outputB = runAuthSubprocess($issuedB['token']);
    check(
        '(b) device soft-revocado (status=0) → 401 session_revoked, no 200',
        str_contains($outputB, 'HTTP_STATUS:401') && str_contains($outputB, 'session_revoked') && !str_contains($outputB, 'CTX:'),
        "salida del subproceso: $outputB",
        $failures
    );

    // ── (c) device hard-eliminado (fila ya no existe) ──
    $issuedC = DeviceAuth::issueDeviceToken(
        $companyId,
        $outletId,
        $registerId,
        $userId,
        'Test device — a eliminar (hard)',
        'phpunit-like/pos_device_revoked_auth_test',
        'test-hard-deleted-' . bin2hex(random_bytes(6)),
    );
    // deliberadamente NO se agrega a $deviceIdsToClean — el DELETE de abajo
    // ya lo elimina; agregarlo duplicaría el cleanup sobre una fila inexistente
    // (no falla, ncmExecute con 0 filas afectadas no lanza, pero es ruido).
    ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$issuedC['deviceId']]);
    $outputC = runAuthSubprocess($issuedC['token']);
    check(
        '(c) device hard-eliminado (fila inexistente) → 401 session_revoked, no 200 (hipótesis "no encontré fila -> no bloqueo")',
        str_contains($outputC, 'HTTP_STATUS:401') && str_contains($outputC, 'session_revoked') && !str_contains($outputC, 'CTX:'),
        "salida del subproceso: $outputC",
        $failures
    );

    // ── (d) device activo (status=1) pero pareo a medias: module=pos SIN
    // outlet/register asignado (issueDeviceToken con '' cae a NULL en la fila,
    // ver DeviceAuth::issueDeviceToken). Caso pedido por el owner: "la
    // sucursal, caja y companyId son 100% obligatorios en el /pos" — un
    // device existente y NO revocado, pero con el pareo incompleto, tampoco
    // debe operar. Código DISTINTO de session_revoked porque la causa y la
    // corrección no son las mismas (no lo desconectó un admin, nunca terminó
    // de configurarse).
    $issuedD = DeviceAuth::issueDeviceToken(
        $companyId,
        '', // outletId vacío a propósito
        '', // registerId vacío a propósito
        $userId,
        'Test device — pareo a medias',
        'phpunit-like/pos_device_revoked_auth_test',
        'test-incomplete-' . bin2hex(random_bytes(6)),
    );
    $deviceIdsToClean[] = $issuedD['deviceId'];
    $outputD = runAuthSubprocess($issuedD['token']);
    check(
        '(d) device module=pos con outlet/register vacío (pareo a medias) → 401 device_incomplete, no 200',
        str_contains($outputD, 'HTTP_STATUS:401') && str_contains($outputD, 'device_incomplete') && !str_contains($outputD, 'CTX:'),
        "salida del subproceso: $outputD",
        $failures
    );
} finally {
    foreach ($deviceIdsToClean as $deviceId) {
        try {
            ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$deviceId]);
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}

echo $failures === 0 ? "\nTODOS LOS CASOS PASARON\n" : "\n$failures CASO(S) FALLARON\n";
exit($failures === 0 ? 0 : 1);

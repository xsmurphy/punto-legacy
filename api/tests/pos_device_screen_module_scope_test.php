<?php
declare(strict_types=1);

/**
 * Regression guard: el guard de dimensiones obligatorias
 * (`DeviceAuth::requireCompleteContext()`, ver
 * `pos_device_revoked_auth_test.php` caso (d)) NO debe alcanzar a devices
 * `screen`/`kds`/`display` — esos módulos son legítimamente
 * outlet/register-less por diseño. `api/v1/screens.php` ya trata
 * `registerId`/`outletId` vacíos como estado válido (`$ctx['registerId'] !==
 * '' ? ... : null`, líneas ~71-84) y `DeviceInvitationService::create()`
 * acepta `registerId`/`outletId` null para cualquier módulo (no solo pos).
 *
 * Sin este test, un futuro ajuste de `requireCompleteContext()` podría
 * aplicar el check de outlet/register a TODOS los módulos "por consistencia"
 * y romper silenciosamente las pantallas cliente/KDS en producción — no hay
 * devices `screen` activos hoy para notarlo en un smoke test manual (ver
 * conteo real en prod: 0 devices no-pos activos al momento de este fix).
 *
 * Reusa el tenant fixture "Verify PY" (mismo que
 * `pos_device_revoked_auth_test.php`) — el device que crea este test es
 * propio (browserLocalId único por corrida) y se limpia al final.
 *
 * Caso:
 *   device module=screen, activo (status=1), SIN outlet/register asignado →
 *   `apiAuthPosContext()` resuelve el ctx normal (200/ctx, NUNCA 401
 *   device_incomplete).
 *
 * `apiAuthPosContext()` no falla con `die()` en el path feliz, pero SÍ lo
 * hace en el de error — corre en subproceso vía `_screen_auth_once_cli.php`
 * para poder inspeccionar HTTP_STATUS de cualquier forma sin duplicar lógica
 * de captura.
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — Docker):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/pos_device_screen_module_scope_test.php
 *
 * Exit code 0 si el caso pasa, 1 si falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Auth\DeviceAuth;

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$userId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

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

$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$deviceId = null;

try {
    $issued = DeviceAuth::createDeviceAndIssueToken(
        $companyId,
        '', // outletId vacío a propósito -- legítimo para module=screen
        '', // registerId vacío a propósito -- legítimo para module=screen
        $userId,
        'Test screen device — sin caja/sucursal',
        'phpunit-like/pos_device_screen_module_scope_test',
        'test-screen-no-dims-' . bin2hex(random_bytes(6)),
        'screen',
    );
    $deviceId = $issued['deviceId'];

    $cmd = escapeshellarg($phpBin) . ' -d variables_order=EGPCS '
        . escapeshellarg(__DIR__ . '/_screen_auth_once_cli.php') . ' '
        . escapeshellarg($issued['token']) . ' 2>&1';
    $output = shell_exec($cmd) ?? '';

    check(
        'device module=screen sin outlet/register → apiAuthPosContext() resuelve ctx, NO 401 device_incomplete',
        str_contains($output, 'CTX:') && !str_contains($output, 'HTTP_STATUS:401') && !str_contains($output, 'device_incomplete'),
        "salida del subproceso: $output",
        $failures
    );
} finally {
    if ($deviceId !== null) {
        try {
            ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$deviceId]);
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}

echo $failures === 0 ? "\nTODOS LOS CASOS PASARON\n" : "\n$failures CASO(S) FALLARON\n";
exit($failures === 0 ? 0 : 1);

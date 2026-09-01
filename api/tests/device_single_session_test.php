<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (DB real) del invariante UN DISPOSITIVO = UNA SESIÓN.
 *
 * ── Qué protege ─────────────────────────────────────────────────────────────
 *
 * `DeviceAuth::buildToken()` emitía una `auth_session` nueva en cada pareo y
 * en cada reconexión sin revocar la anterior. Como la sesión del device es
 * eterna (`expiresat` NULL, mig 69), las viejas no se cerraban nunca: cada
 * dispositivo acumulaba una credencial viva por pareo. Cada una de esas
 * pestañas fantasma seguía latiendo `/v1/register/claim` cada 5 min
 * (HEARTBEAT_MS en frontend/lib/pos/register-tenancy.ts) y volvía a tomar la
 * caja apenas el tenedor legítimo la soltaba — el reporte del owner fue
 * "pareo la caja en una tablet, la revoco, la habilito en otra, y no entro en
 * ninguna".
 *
 * Casos:
 *   (a) Emisión inicial → exactamente 1 sesión activa. Regression guard: el
 *       fix no debe dejar al device sin credencial.
 *   (b) Segunda emisión para el MISMO device (`issueTokenForExistingDevice`
 *       con companyId explícito — el camino del canje en
 *       DeviceInvitationService::status()) → sigue habiendo exactamente 1
 *       activa, y es la nueva: el token viejo deja de resolver.
 *   (c) Tercera emisión con `companyId` = null. Ningún call-site de hoy pasa
 *       null, pero la firma de `issueTokenForExistingDevice()` lo acepta, y
 *       es la variante que un fix ingenuo rompe en silencio:
 *       `authSessionRevokeByDevice()` hace `return` mudo con companyId vacío,
 *       así que reenviar el parámetro crudo en vez del `companyid` de la fila
 *       `device` volvería la revocación un no-op — sin error, sin log, y sin
 *       que ningún otro caso de este arnés lo note. Debe quedar 1 activa.
 *   (d) El token vigente sigue resolviendo después de todo lo anterior: el
 *       invariante no puede lograrse revocando también la sesión nueva.
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_credit_payment_void_test.sh` como referencia de cómo levantar todo):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/device_single_session_test.php
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

/** Sesiones `auth_session` vivas de un device. Casing lowercase: mig 150. */
function activeSessionCount(string $deviceId): int
{
    $row = ncmExecute(
        'SELECT count(*) AS n FROM auth_session
          WHERE deviceid = ?::uuid AND status = 1',
        [$deviceId]
    );
    return (int) ($row['n'] ?? -1);
}

$deviceIdsToClean = [];

try {
    // ── (a) emisión inicial ────────────────────────────────────────────────
    $issuedA = DeviceAuth::issueDeviceToken(
        $companyId,
        $outletId,
        $registerId,
        $userId,
        'Test device — sesión única',
        'phpunit-like/device_single_session_test',
        'test-single-session-' . bin2hex(random_bytes(6)),
    );
    $deviceId = $issuedA['deviceId'];
    $deviceIdsToClean[] = $deviceId;

    $countA = activeSessionCount($deviceId);
    check(
        '(a) primera emisión deja exactamente 1 sesión activa',
        $countA === 1,
        "sesiones activas: $countA (esperado 1)",
        $failures
    );

    // ── (b) segunda emisión, camino canje (companyId explícito) ────────────
    $issuedB = DeviceAuth::issueTokenForExistingDevice($deviceId, $companyId);
    $countB  = activeSessionCount($deviceId);
    check(
        '(b) re-emisión con companyId deja 1 sola activa (no apila)',
        $countB === 1,
        "sesiones activas: $countB (esperado 1)",
        $failures
    );
    check(
        '(b) el token anterior deja de resolver (la sesión vieja quedó revocada)',
        DeviceAuth::resolveDeviceToken($issuedA['token']) === null,
        'el Bearer del pareo anterior sigue autenticando — es la pestaña fantasma del bug',
        $failures
    );

    // ── (c) tercera emisión, camino reconnect (companyId null) ─────────────
    $issuedC = DeviceAuth::issueTokenForExistingDevice($deviceId, null);
    $countC  = activeSessionCount($deviceId);
    check(
        '(c) re-emisión por reconnect (companyId null) deja 1 sola activa',
        $countC === 1,
        "sesiones activas: $countC (esperado 1) — revisar que la revocación use el companyid de la fila device, no el parámetro",
        $failures
    );
    check(
        '(c) el token de (b) deja de resolver',
        DeviceAuth::resolveDeviceToken($issuedB['token']) === null,
        'la reconexión no revocó la sesión anterior',
        $failures
    );

    // ── (d) la credencial vigente sigue sirviendo ──────────────────────────
    $ctx = DeviceAuth::resolveDeviceToken($issuedC['token']);
    check(
        '(d) el último token emitido SÍ resuelve (no se revoca a sí mismo)',
        $ctx !== null && (string) ($ctx['deviceId'] ?? '') === $deviceId,
        'el device quedó sin credencial usable: el fix revocó de más',
        $failures
    );
} finally {
    foreach ($deviceIdsToClean as $id) {
        try {
            // Las sesiones primero: `auth_session` no tiene FK a `device`
            // (mig 69), así que borrar el device solo dejaría filas huérfanas
            // contaminando la próxima corrida.
            ncmExecute('DELETE FROM auth_session WHERE deviceid = ?::uuid', [$id]);
            ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$id]);
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}

harnessFinish($failures);

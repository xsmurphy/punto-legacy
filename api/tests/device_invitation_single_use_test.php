<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Canje de un solo uso del Device Authorization Grant — el agujero del
 * 2026-08-25.
 *
 * El caso real que reportó el owner: creó UN link de conexión, lo pegó en dos
 * navegadores, aprobó una vez en el panel y la caja quedó habilitada en los
 * dos dispositivos. Era peor que eso:
 *
 *   - `open()` era idempotente por id a secas: el segundo navegador recibía el
 *     MISMO userCode y quedaba adherido a la misma invitación.
 *   - `status()` re-emitía un token NUEVO en CADA consulta mientras la
 *     invitación siguiera en `approved` — sin marca de consumida, sin límite,
 *     sin chequear quién preguntaba.
 *   - `expires_at` sólo se evaluaba en `pending`/`opened`, así que una vez
 *     aprobada la invitación tampoco caducaba.
 *
 * O sea: el link —que viaja por WhatsApp y queda en el chat— era un emisor de
 * credenciales permanente para esa caja. En prod se encontró un device con 3
 * sesiones `pos-app` activas creadas en 6 segundos desde 3 user-agents
 * distintos. Además de sesiones de más es un problema FISCAL: dos dispositivos
 * en la misma caja rompen la exclusividad del punto de expedición
 * (context/29-numeracion-y-exclusividad-de-caja.md) y pueden emitir facturas
 * duplicadas con el mismo timbrado.
 *
 * Casos (todos contra Postgres real, nada mockeado):
 *   A. Camino feliz completo: crear → abrir → aprobar → canjear. El
 *      dispositivo legítimo recibe su token. Regression guard: cerrar el
 *      agujero no puede romper el pareo normal.
 *   A2. Ese pareo deja EXACTAMENTE UNA sesión activa para el device. Antes
 *      `approve()` emitía una sesión extra que volvía en la respuesta del
 *      admin y no la usaba nadie.
 *   B. Segundo navegador abriendo el mismo link: 409, y NO se lleva el
 *      userCode. Antes se lo llevaba idéntico.
 *   C. El navegador legítimo recarga la página: mismo userCode, sin error.
 *      Es lo que distingue "reload" de "segundo navegador" — el secreto de
 *      pairing, no la IP ni el user-agent.
 *   D. Doble canje del MISMO navegador: la segunda consulta no trae token y
 *      la invitación quedó `consumed`.
 *   E. Canje por el segundo navegador (sin secreto y con secreto inventado):
 *      409 en ambos, cero tokens.
 *   F. El canje es un CAS de verdad: el mismo UPDATE ejecutado dos veces
 *      afecta una fila y después cero. Es lo que hace que dos requests
 *      CONCURRENTES no puedan pasar los dos — la condición vive en el WHERE,
 *      no en un `if` de PHP.
 *   G. Expiración post-aprobación: una invitación aprobada pero vencida no
 *      entrega token.
 *   H. Reconexión (`auto_approve`) también de un solo uso: la primera
 *      apertura entrega el token, la segunda 410.
 *
 * Uso (necesita Postgres migrado + seed.sql de verify_chain cargado — ver
 * `run_device_invitation_single_use_test.sh`, que levanta todo):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/device_invitation_single_use_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Auth/DeviceAuth.php';
require_once dirname(__DIR__) . '/lib/services/DeviceInvitationService.php';

use Punto\Api\Services\DeviceInvitationService;

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + 1;
    echo "FAIL $label\n";
    if ($detail !== '') {
        echo "     $detail\n";
    }
}

/**
 * Corre $fn y devuelve [resultado, códigoDeError, mensaje].
 * Un canje que "falla" es el resultado ESPERADO en la mitad de los casos, así
 * que la excepción es dato, no accidente.
 */
function attempt(callable $fn): array
{
    try {
        return [$fn(), 0, ''];
    } catch (\Throwable $e) {
        return [null, (int) $e->getCode(), $e->getMessage()];
    }
}

/** Sesiones `pos-app` vivas de un device. */
function activeSessions(string $deviceId): int
{
    $row = ncmExecute(
        "SELECT count(*) AS n FROM auth_session
          WHERE deviceid = ?::uuid AND realm = 'pos-app' AND status = 1",
        [$deviceId]
    );
    return (int) ($row['n'] ?? 0);
}

$svc = new DeviceInvitationService();

// Devices creados por el arnés, para limpiar al final.
$createdDevices = [];

echo "=== A. Camino feliz: el dispositivo legítimo se conecta ===\n";

$inv = $svc->create($companyId, $userId, 'pos', $outletId, $registerId, 'Tablet mostrador', 24);
$invId = (string) $inv['id'];

// Navegador A (la tablet real) abre el link.
$openA = $svc->open($invId, 'Mozilla/5.0 (iPad; CPU OS 18_0)', '192.168.0.10');
$secretA = (string) ($openA['pairingSecret'] ?? '');
check(
    'la primera apertura entrega userCode y secreto de pairing',
    ($openA['userCode'] ?? '') !== '' && strlen($secretA) === 64,
    'userCode=' . var_export($openA['userCode'] ?? null, true) . ' secreto=' . strlen($secretA) . ' chars'
);
check(
    'el secreto NO se guarda en claro en la BD',
    (function () use ($invId, $secretA): bool {
        $row = ncmExecute('SELECT pairing_secret FROM device_invitation WHERE id = ?::uuid', [$invId]);
        $stored = trim((string) ($row['pairing_secret'] ?? ''));
        return $stored !== '' && $stored !== $secretA && $stored === hash('sha256', $secretA);
    })(),
    'la columna tiene que ser el sha256, no el secreto'
);

echo "\n=== B. Segundo navegador con el mismo link ===\n";

[$openB, $codeB, $msgB] = attempt(
    fn () => $svc->open($invId, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)', '192.168.0.11')
);
check(
    'el segundo navegador es RECHAZADO (409), no adherido',
    $openB === null && $codeB === 409,
    'code=' . $codeB . ' msg=' . $msgB . ' resultado=' . var_export($openB, true)
);
check(
    'y no se lleva el userCode',
    $openB === null || ($openB['userCode'] ?? null) === null,
    'devolvió: ' . var_export($openB['userCode'] ?? null, true)
);

echo "\n=== C. El navegador legítimo recarga la página ===\n";

[$reload, $codeR, $msgR] = attempt(
    // Mismo secreto, pero UA e IP distintos a propósito: un reload real puede
    // llegar con otro user-agent (webview vs navegador) o desde otra IP (NAT,
    // cambio de red). La identidad es el secreto, no la telemetría.
    fn () => $svc->open($invId, 'Mozilla/5.0 (iPad; CPU OS 18_1)', '10.0.0.5', $secretA)
);
check(
    'el reload legítimo funciona pese a UA e IP distintos',
    $reload !== null && ($reload['userCode'] ?? '') === ($openA['userCode'] ?? ''),
    'code=' . $codeR . ' msg=' . $msgR
);
check(
    'y el reload NO vuelve a entregar el secreto',
    $reload !== null && ($reload['pairingSecret'] ?? null) === null,
    'devolvió: ' . var_export($reload['pairingSecret'] ?? null, true)
);

echo "\n=== A (cont). El admin aprueba y el dispositivo canjea ===\n";

$approved = $svc->approve($invId, $companyId, $userId, (string) $openA['userCode']);
$deviceId = (string) ($approved['deviceId'] ?? '');
if ($deviceId !== '') {
    $createdDevices[] = $deviceId;
}
check('aprobar crea el device', $deviceId !== '');
check(
    'aprobar NO emite ninguna sesión (el aprobador no reparte credenciales)',
    activeSessions($deviceId) === 0,
    'sesiones activas tras approve: ' . activeSessions($deviceId)
);

$redeem = $svc->status($invId, $secretA);
check(
    'el dispositivo legítimo recibe su token',
    ($redeem['status'] ?? '') === 'approved' && ($redeem['token'] ?? '') !== '',
    'status=' . ($redeem['status'] ?? '?') . ' token=' . (($redeem['token'] ?? '') !== '' ? 'sí' : 'no')
);
check(
    'el canje deja EXACTAMENTE una sesión activa para el device',
    activeSessions($deviceId) === 1,
    'sesiones activas: ' . activeSessions($deviceId)
);
check(
    'la invitación quedó marcada como consumida',
    (function () use ($invId): bool {
        $row = ncmExecute('SELECT status, consumed_at FROM device_invitation WHERE id = ?::uuid', [$invId]);
        return (string) ($row['status'] ?? '') === 'consumed' && !empty($row['consumed_at']);
    })(),
    'estado en BD distinto de consumed'
);

echo "\n=== D. Doble canje del mismo navegador ===\n";

$again = $svc->status($invId, $secretA);
check(
    'la segunda consulta NO entrega otro token',
    ($again['token'] ?? null) === null,
    'devolvió token de nuevo: ' . var_export($again, true)
);
check(
    'y reporta la invitación como consumida',
    ($again['status'] ?? '') === 'consumed',
    'status=' . ($again['status'] ?? '?')
);
check(
    'el device sigue con UNA sola sesión activa',
    activeSessions($deviceId) === 1,
    'sesiones activas: ' . activeSessions($deviceId)
);

echo "\n=== E. Dos navegadores compitiendo por el canje ===\n";

// Invitación nueva: A abre, admin aprueba, y B —que tiene el link pero no el
// secreto— intenta canjear ANTES que A.
$inv2   = $svc->create($companyId, $userId, 'pos', $outletId, $registerId, 'Tablet 2', 24);
$inv2Id = (string) $inv2['id'];
$openA2  = $svc->open($inv2Id, 'UA-A', '192.168.0.10');
$secretA2 = (string) $openA2['pairingSecret'];
$appr2   = $svc->approve($inv2Id, $companyId, $userId, (string) $openA2['userCode']);
$device2 = (string) $appr2['deviceId'];
if ($device2 !== '') {
    $createdDevices[] = $device2;
}

[$stealNoSecret, $codeS1, $msgS1] = attempt(fn () => $svc->status($inv2Id, null));
check(
    'sin secreto no se canjea (409)',
    $stealNoSecret === null && $codeS1 === 409,
    'code=' . $codeS1 . ' msg=' . $msgS1 . ' resultado=' . var_export($stealNoSecret, true)
);

[$stealWrong, $codeS2, $msgS2] = attempt(
    fn () => $svc->status($inv2Id, bin2hex(random_bytes(32)))
);
check(
    'con un secreto inventado tampoco (409)',
    $stealWrong === null && $codeS2 === 409,
    'code=' . $codeS2 . ' msg=' . $msgS2
);
check(
    'ninguno de los dos intentos creó sesión',
    activeSessions($device2) === 0,
    'sesiones activas: ' . activeSessions($device2)
);

// Y el legítimo sigue pudiendo canjear: rechazar al intruso no puede dejar
// inutilizable la invitación del dispositivo real.
$redeem2 = $svc->status($inv2Id, $secretA2);
check(
    'el dispositivo legítimo canjea igual después de los intentos fallidos',
    ($redeem2['token'] ?? '') !== '' && activeSessions($device2) === 1,
    'token=' . (($redeem2['token'] ?? '') !== '' ? 'sí' : 'no') . ' sesiones=' . activeSessions($device2)
);

echo "\n=== F. El canje es un CAS, no un if de PHP ===\n";

// Se ejecuta el MISMO UPDATE que usa status() dos veces sobre una invitación
// aprobada. Que la segunda afecte cero filas es lo que garantiza que dos
// requests concurrentes no puedan pasar los dos: la condición está en el
// WHERE, evaluada por Postgres bajo el lock de fila, no en el proceso PHP.
$inv3   = $svc->create($companyId, $userId, 'pos', $outletId, $registerId, 'Tablet 3', 24);
$inv3Id = (string) $inv3['id'];
$openA3 = $svc->open($inv3Id, 'UA-A', '192.168.0.10');
$appr3  = $svc->approve($inv3Id, $companyId, $userId, (string) $openA3['userCode']);
if (($appr3['deviceId'] ?? '') !== '') {
    $createdDevices[] = (string) $appr3['deviceId'];
}

$cas = "UPDATE device_invitation
           SET status='consumed', consumed_at=now()
         WHERE id=?::uuid AND status='approved' AND expires_at > now()
       RETURNING device_id";
$first  = ncmExecute($cas, [$inv3Id]);
$second = ncmExecute($cas, [$inv3Id]);
check('el primer CAS afecta una fila',  !empty($first));
check('el segundo CAS afecta cero filas', empty($second), 'devolvió: ' . var_export($second, true));

echo "\n=== G. Expiración después de aprobada ===\n";

$inv4   = $svc->create($companyId, $userId, 'pos', $outletId, $registerId, 'Tablet 4', 24);
$inv4Id = (string) $inv4['id'];
$openA4 = $svc->open($inv4Id, 'UA-A', '192.168.0.10');
$secretA4 = (string) $openA4['pairingSecret'];
$appr4  = $svc->approve($inv4Id, $companyId, $userId, (string) $openA4['userCode']);
$device4 = (string) $appr4['deviceId'];
if ($device4 !== '') {
    $createdDevices[] = $device4;
}
// Se vence DESPUÉS de aprobada — exactamente el estado que el código viejo
// nunca volvía a mirar.
ncmExecute(
    "UPDATE device_invitation SET expires_at = now() - interval '1 hour' WHERE id = ?::uuid",
    [$inv4Id]
);

$afterExpiry = $svc->status($inv4Id, $secretA4);
check(
    'una invitación aprobada pero vencida NO entrega token',
    ($afterExpiry['token'] ?? null) === null,
    'devolvió: ' . var_export($afterExpiry, true)
);
check(
    'y queda marcada como expirada',
    ($afterExpiry['status'] ?? '') === 'expired',
    'status=' . ($afterExpiry['status'] ?? '?')
);
check(
    'el device no llegó a tener sesión',
    activeSessions($device4) === 0,
    'sesiones activas: ' . activeSessions($device4)
);

echo "\n=== H. Reconexión (auto_approve) también de un solo uso ===\n";

// Reusa el device del caso A, que está activo.
$recon   = $svc->createReconnect($deviceId, $companyId, $userId);
$reconId = (string) $recon['id'];

$reconOpen = $svc->open($reconId, 'UA-A', '192.168.0.10');
check(
    'la reconexión entrega el token en la primera apertura',
    ($reconOpen['token'] ?? '') !== '' && ($reconOpen['autoApprove'] ?? false) === true,
    'devolvió: ' . var_export(array_keys($reconOpen), true)
);

[$reconSecond, $codeH, $msgH] = attempt(fn () => $svc->open($reconId, 'UA-B', '192.168.0.11'));
check(
    'la segunda apertura del link de reconexión es rechazada (410)',
    $reconSecond === null && $codeH === 410,
    'code=' . $codeH . ' msg=' . $msgH . ' resultado=' . var_export($reconSecond, true)
);
check(
    'la invitación de reconexión quedó consumida',
    (function () use ($reconId): bool {
        $row = ncmExecute('SELECT status FROM device_invitation WHERE id = ?::uuid', [$reconId]);
        return (string) ($row['status'] ?? '') === 'consumed';
    })(),
    'estado distinto de consumed'
);

// ── Limpieza: devices y sesiones propios del arnés ────────────────────────
foreach ($createdDevices as $d) {
    try {
        ncmExecute('DELETE FROM auth_session WHERE deviceid = ?::uuid', [$d]);
        ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$d]);
    } catch (\Throwable) {
        // best-effort — el Postgres del arnés es descartable
    }
}

harnessFinish($GLOBALS['failures'] ?? 0, $GLOBALS['checks'] ?? 0);

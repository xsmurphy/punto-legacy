<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) de la HORA DE EMISIÓN de una venta del POS.
 *
 * ── El incidente que reproduce ───────────────────────────────────────────────
 *
 * 2026-09-01. Una venta emitida 12:07 en Asunción quedó guardada como
 * 09:07-03 — tres horas atrás. `transaction.drawerid` quedó NULL y la venta
 * desapareció del Control de Caja.
 *
 * La cadena completa, que es lo que este arnés fija:
 *
 *   1. `transaction.transactionDate` es TIMESTAMPTZ y el POS manda la fecha
 *      como TEXTO NAIVE. Un texto sin zona no es un instante: PostgreSQL lo
 *      resuelve con la TZ de la SESIÓN.
 *   2. Había dos zonas de sesión según el embudo de auth. `apiAuthTenant` →
 *      `data.php` → `TenantClock::apply()` (zona del comercio). `apiAuthPosContext`
 *      no cargaba `data.php` y se quedaba con el baseline de plataforma de
 *      `includes/db.php` — `APP_TIMEZONE`, sin definir en prod, o sea UTC.
 *   3. La apertura de turno entra por `drawer.php`, que va por `apiAuthTenant`:
 *      quedaba bien. La venta entra por `sales.php`, que va por
 *      `apiAuthPosContext`: quedaba corrida.
 *   4. `DrawerService::resolveDrawerIdForDate()` busca el turno que CONTIENE la
 *      fecha de la venta. Con la venta tres horas ANTES de la apertura, ningún
 *      turno la contiene → `drawerid` NULL.
 *
 * ── Qué protege ─────────────────────────────────────────────────────────────
 *
 *   (a) F1 — LA ZONA ES INVARIANTE DEL EMBUDO. Tras `apiAuthPosContext()`, la
 *       sesión de PG y el default de PHP están en la zona del comercio, sin que
 *       el endpoint tenga que acordarse de nada.
 *   (b) EL CASO DEL OWNER. Turno abierto y venta cobrada con segundos de
 *       diferencia, tenant en `America/Asuncion`: la venta queda con la hora de
 *       EMISIÓN y `resolveDrawerIdForDate()` encuentra el turno.
 *   (c) EL ARNÉS REPRODUCE DE VERDAD. El modo `legacy` (que emula el estado
 *       previo: sesión en UTC + fecha tomada del texto naive) TIENE que fallar
 *       las mismas aserciones. Sin este contra-caso, (a) y (b) podrían estar
 *       pasando por casualidad.
 *   (d) F2 — LA FECHA VIAJA COMO INSTANTE. Con un `date` naive deliberadamente
 *       MENTIROSO en el payload y un `timestamp` correcto, la venta se guarda
 *       según el `timestamp`. Es lo que vuelve al valor independiente del
 *       estado ambiental, y de paso arregla las cotizaciones (`create-quote.ts`
 *       formatea `date` con el offset del DISPOSITIVO, no el del tenant).
 *   (e) EL FALLBACK A `date` SIGUE VIVO. Un payload sin `timestamp` —los que ya
 *       están encolados en el IndexedDB de tablets reales y se van a drenar
 *       después del deploy— se sigue guardando bien, ahora que la sesión
 *       interpreta el texto en la zona correcta.
 *   (g) UN EPOCH IMPOSIBLE NO LLEGA A LA BASE. Un `timestamp` en milisegundos
 *       (el clásico `Date.now()` sin dividir) se descarta y cae al `date`. Sin
 *       ese techo, derivar del instante habría abierto un modo de falla que el
 *       código viejo no tenía: año 56639 escrito sobre un campo fiscal.
 *   (f) NADA ATADO A PARAGUAY. Todo (b)-(e) se corre otra vez con un tenant en
 *       `America/Mexico_City` (offset distinto, -06). Punto es multi-país: un
 *       fix que sólo funcione con -03 no es un fix.
 *
 * NO cubre: la venta NO pasa por `SaleService::save()` entero (ítems, stock,
 * impuestos, numeración). Este arnés aísla la cadena fecha→instante→turno, que
 * es donde estaba el defecto; el resto de la venta ya lo cubre `verify_chain`.
 *
 * Uso (necesita Postgres migrado — ver `run_pos_emission_timezone_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/pos_emission_timezone_test.php
 */

$companyPY = 'c0ffee00-1111-4a00-9000-0000000000a1';
$companyMX = 'c0ffee00-2222-4a00-9000-0000000000a2';

define('COMPANY_ID',  $companyPY);
define('OUTLET_ID',   'c0ffee00-1111-4a00-9000-0000000000b1');
define('REGISTER_ID', 'c0ffee00-1111-4a00-9000-0000000000c1');
define('USER_ID',     'c0ffee00-1111-4a00-9000-0000000000d1');
define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Support\TenantLocale;

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

/**
 * Tenant completo con su zona horaria. Los ids son fijos y propios de este
 * arnés: dos agentes corriendo a la vez no se pisan.
 *
 * @return array{outletId:string,registerId:string,userId:string}
 */
function seedTenant(string $companyId, string $suffix, string $timezone, string $country): array
{
    global $db;

    $outletId   = "c0ffee00-{$suffix}-4a00-9000-0000000000b1";
    $registerId = "c0ffee00-{$suffix}-4a00-9000-0000000000c1";
    $userId     = "c0ffee00-{$suffix}-4a00-9000-0000000000d1";

    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
         ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config, status = 'active'",
        [$companyId, json_encode([
            'settingName'              => "TZ Test {$timezone}",
            'settingDecimal'           => 'no',
            'settingThousandSeparator' => 'dot',
            'settingCountry'           => $country,
            'settingTimeZone'          => $timezone,
            'settingTaxName'           => 'IVA',
            'settingLanguage'          => 'es',
            'settingSocialMedia'       => '{}',
            'settingObj'               => '{}',
        ])]
    );
    $db->Execute(
        "INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, 'TZ Test - Sucursal', 1, ?)
         ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName",
        [$outletId, $companyId]
    );
    $db->Execute(
        "INSERT INTO register (registerid, registername, registerstatus,
            registerinvoicenumber, registerticketnumber, registerreturnnumber,
            registerschedulenumber, registerpedidonumber, registerquotenumber, outletid, companyid)
         VALUES (?, 'TZ Test - Caja', TRUE, 1, 1, 1, 1, 1, 1, ?, ?)
         ON CONFLICT (registerid) DO UPDATE SET registername = EXCLUDED.registername",
        [$registerId, $outletId, $companyId]
    );
    $db->Execute(
        "INSERT INTO contact (contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, outletId, companyId)
         VALUES (?, 'TZ Test Admin', ?, ?, 1, 0, 'admin', 1, ?, ?)
         ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName",
        [$userId, '5959910009' . substr($suffix, 0, 2), "tz-{$suffix}@local.test", $outletId, $companyId]
    );

    // El cache de TenantLocale es por proceso: si otro caso ya resolvió este
    // tenant con otra config, la nueva no se vería.
    TenantLocale::forget($companyId);

    return ['outletId' => $outletId, 'registerId' => $registerId, 'userId' => $userId];
}

/** Estado limpio: este arnés es la única fuente de cajas y ventas de sus tenants. */
function resetTenant(string $companyId): void
{
    global $db;
    $db->Execute('DELETE FROM transaction WHERE companyid = ?', [$companyId]);
    $db->Execute('DELETE FROM drawer WHERE companyid = ?', [$companyId]);
    $db->Execute('DELETE FROM device WHERE companyid = ?', [$companyId]);
}

/**
 * Abre el turno con la fecha de apertura tal cual la manda el cliente.
 *
 * Se hace ACÁ, en el proceso del arnés, y no en el subproceso de la venta: la
 * apertura entra por `drawer.php`, que va por `apiAuthTenant()` → `data.php` y
 * por lo tanto SIEMPRE tuvo la zona del comercio aplicada. Modelarla así es lo
 * que hace aparecer el bug — el turno bien, la venta corrida.
 */
function openShift(array $t, string $companyId, string $openDate, string $timezone): string
{
    global $db;
    // La sesión, en la zona del comercio: es el estado en el que `data.php`
    // deja la conexión para la request de apertura.
    $db->Execute("SET TIME ZONE '" . $timezone . "'");
    $drawerId = sprintf('c0ffee00-9999-4a00-9000-%012x', random_int(0, 0xFFFFFFFFFFFF));
    $db->Execute(
        'INSERT INTO drawer (drawerId, drawerOpenDate, drawerOpenAmount, drawerUID,
                             drawerUserOpen, registerId, outletId, companyId)
         VALUES (?::uuid, ?, 0, ?, ?, ?, ?, ?)',
        [$drawerId, $openDate, random_int(1, 2000000000), $t['userId'],
         $t['registerId'], $t['outletId'], $companyId]
    );
    return $drawerId;
}

/**
 * Corre una venta del POS en un proceso limpio y devuelve lo observado.
 *
 * @return array<string,mixed>
 */
function runSale(string $token, string $mode, string $saleDate, int $saleEpoch, string $timezone): array
{
    $cmd = sprintf(
        'php -d variables_order=EGPCS %s %s %s %s %d %s 2>&1',
        escapeshellarg(__DIR__ . '/_pos_tz_once_cli.php'),
        escapeshellarg($token),
        escapeshellarg($mode),
        escapeshellarg($saleDate),
        $saleEpoch,
        escapeshellarg($timezone)
    );
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^RESULT:(.*)$/m', $out, $m)) {
        throw new RuntimeException("El subproceso ($mode) no imprimió RESULT. Salida:\n$out");
    }
    return json_decode($m[1], true) ?: [];
}

/** Bearer de un device module=pos pareado a la caja del tenant. */
function issueToken(array $t, string $companyId): string
{
    $d = DeviceAuth::issueDeviceToken(
        $companyId, $t['outletId'], $t['registerId'], $t['userId'],
        'TZ Test device', 'harness', null, 'pos'
    );
    return (string) $d['token'];
}

/**
 * Batería completa contra un tenant. Se corre dos veces con zonas distintas
 * para que ningún assert quede atado a Paraguay.
 */
function runSuite(string $label, string $companyId, string $suffix, string $timezone, string $country, int &$failures, int &$checks): void
{
    echo "\n=== $label ($timezone) ===\n";

    $t = seedTenant($companyId, $suffix, $timezone, $country);
    resetTenant($companyId);
    $token = issueToken($t, $companyId);

    // El caso del owner: turno abierto y venta cobrada con segundos de
    // diferencia. El instante es fijo para que el arnés sea determinista.
    $epoch     = 1788275244;                       // 2026-09-01 15:07:24 UTC (12:07:24 en Asunción)
    $tz        = new DateTimeZone($timezone);
    $emitted   = (new DateTimeImmutable('@' . $epoch))->setTimezone($tz)->format('Y-m-d H:i:s');
    $openDate  = (new DateTimeImmutable('@' . ($epoch - 12)))->setTimezone($tz)->format('Y-m-d H:i:s');
    $utcOfEpoch = gmdate('Y-m-d H:i:s', $epoch);

    $drawerId = openShift($t, $companyId, $openDate, $timezone);
    echo "     turno abierto {$openDate} (hora del comercio) — venta emitida {$emitted}\n";

    // ── (c) contra-caso: el estado previo al fix TIENE que romperse ──────────
    $legacy = runSale($token, 'legacy', $emitted, $epoch, $timezone);
    printf(
        "     ANTES  (legacy): guardada %s (comercio) / %s UTC, drawerId=%s\n",
        (string) ($legacy['storedTenant'] ?? '?'),
        (string) ($legacy['storedUtc'] ?? '?'),
        var_export($legacy['drawerId'] ?? null, true)
    );
    check(
        "$label — el modo legacy reproduce el bug (venta fuera del turno)",
        $legacy['drawerId'] === null && $legacy['storedUtc'] !== $utcOfEpoch,
        'legacy = ' . json_encode($legacy) . " (esperaba drawerId null y storedUtc != $utcOfEpoch)",
        $failures, $checks
    );

    // ── (a) F1: la zona es invariante del embudo ─────────────────────────────
    $fixed = runSale($token, 'fixed', $emitted, $epoch, $timezone);
    printf(
        "     DESPUÉS (fixed): guardada %s (comercio) / %s UTC, drawerId=%s\n",
        (string) ($fixed['storedTenant'] ?? '?'),
        (string) ($fixed['storedUtc'] ?? '?'),
        var_export($fixed['drawerId'] ?? null, true)
    );
    check(
        "$label — apiAuthPosContext deja la sesión de PG en la zona del comercio",
        ($fixed['pgTimeZone'] ?? null) === $timezone && ($fixed['phpTimeZone'] ?? null) === $timezone,
        'pg=' . var_export($fixed['pgTimeZone'] ?? null, true) . ' php=' . var_export($fixed['phpTimeZone'] ?? null, true),
        $failures, $checks
    );

    // ── (b) el caso del owner, corregido ─────────────────────────────────────
    check(
        "$label — la venta queda con la hora de EMISIÓN, no corrida",
        ($fixed['storedTenant'] ?? null) === $emitted && ($fixed['storedUtc'] ?? null) === $utcOfEpoch,
        'guardado tenant=' . var_export($fixed['storedTenant'] ?? null, true)
            . " (esperaba $emitted), utc=" . var_export($fixed['storedUtc'] ?? null, true) . " (esperaba $utcOfEpoch)",
        $failures, $checks
    );
    check(
        "$label — resolveDrawerIdForDate encuentra el turno de la venta",
        ($fixed['drawerId'] ?? null) === $drawerId,
        'drawerId = ' . var_export($fixed['drawerId'] ?? null, true) . " (esperaba $drawerId)",
        $failures, $checks
    );

    // ── (d) F2: manda el instante, no el texto ───────────────────────────────
    // `date` miente por una hora (es lo que pasa cuando el reloj del que
    // formatea no es el del comercio: la tablet en otra zona, la cotización que
    // usa el offset del dispositivo). El `timestamp` es el mismo de siempre.
    $liar = (new DateTimeImmutable('@' . $epoch))->setTimezone($tz)->modify('-1 hour')->format('Y-m-d H:i:s');
    $byTs = runSale($token, 'fixed', $liar, $epoch, $timezone);
    check(
        "$label — con `date` mentiroso, gana el `timestamp` (instante absoluto)",
        ($byTs['storedUtc'] ?? null) === $utcOfEpoch && ($byTs['drawerId'] ?? null) === $drawerId,
        'storedUtc = ' . var_export($byTs['storedUtc'] ?? null, true) . " (esperaba $utcOfEpoch), drawerId = "
            . var_export($byTs['drawerId'] ?? null, true),
        $failures, $checks
    );

    // ── (g) epoch fuera de escala: no puede escribir una fecha imposible ─────
    // `Date.now()` en vez de `Math.floor(Date.now()/1000)` manda milisegundos.
    // Sin techo de plausibilidad eso se guardaba como el año 56639 sobre un
    // campo fiscal — un modo de falla que el código viejo NO tenía. Tiene que
    // caer al `date` del payload, que acá es la hora de emisión correcta.
    $ms = runSale($token, 'fixed', $emitted, $epoch * 1000, $timezone);
    check(
        "$label — un timestamp en milisegundos se descarta y cae al `date`",
        ($ms['storedUtc'] ?? null) === $utcOfEpoch && ($ms['drawerId'] ?? null) === $drawerId,
        'storedUtc = ' . var_export($ms['storedUtc'] ?? null, true) . " (esperaba $utcOfEpoch), drawerId = "
            . var_export($ms['drawerId'] ?? null, true),
        $failures, $checks
    );

    // ── (e) fallback: payload viejo sin `timestamp` ──────────────────────────
    $noTs = runSale($token, 'fixed', $emitted, 0, $timezone);
    check(
        "$label — un payload SIN timestamp (cola vieja) se sigue guardando bien",
        ($noTs['storedUtc'] ?? null) === $utcOfEpoch && ($noTs['drawerId'] ?? null) === $drawerId,
        'storedUtc = ' . var_export($noTs['storedUtc'] ?? null, true) . " (esperaba $utcOfEpoch), drawerId = "
            . var_export($noTs['drawerId'] ?? null, true),
        $failures, $checks
    );
}

try {
    runSuite('PY', $companyPY, '1111', 'America/Asuncion',   'PY', $failures, $checks);
    // (f) Otro país, otro offset: nada del fix puede estar atado a Paraguay.
    runSuite('MX', $companyMX, '2222', 'America/Mexico_City', 'MX', $failures, $checks);
} finally {
    resetTenant($companyPY);
    resetTenant($companyMX);
}

harnessFinish($failures, $checks);

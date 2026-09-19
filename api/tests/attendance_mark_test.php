<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la MARCACIÓN DE ASISTENCIA (context/83 F1, mig 230).
 *
 * Corre contra Postgres real. Lo que verifica no se puede verificar leyendo el
 * código:
 *
 *   (A) IDEMPOTENCIA — el mismo `opId` reenviado no crea una segunda
 *       marcación. Es el caso que la cola offline del POS provoca sola: la
 *       request llega, se aplica, y la respuesta se pierde en el camino; el
 *       device no puede distinguirlo de "no llegó" y reintenta. Sin esto, la
 *       persona queda con dos entradas a la misma hora y el reporte las aparea
 *       como si hubiera entrado dos veces.
 *   (B) SCOPING — una marcación de otro comercio no aparece en el reporte de
 *       este, ni se puede leer por id.
 *   (C) TARDANZA — se mide contra el horario declarado, respeta la tolerancia,
 *       cuenta UNA vez por día (volver del almuerzo no es llegar tarde) y vale
 *       `null` —no cero— cuando no hay horario contra el cual medir.
 *   (D) FAIL-OPEN (D4) — sin foto, con el PIN que no coincide y con el legajo
 *       dado de baja, la marcación ENTRA y queda flageada con su motivo. Es la
 *       decisión central del plan: dejar a alguien que sí fue a trabajar sin
 *       poder registrarlo es un daño concreto, el fraude se ataca con evidencia.
 *   (E) APAREO — entrada + salida suman horas; una entrada sin cerrar suma
 *       CERO y se cuenta aparte, en vez de estimarse (esas horas se pagan).
 *   (G) INTERRUPTOR DEL CÓDIGO (context/83) — la única excepción al fail-open.
 *       Prendido no cambia nada; apagado, una marcación NUEVA por código se
 *       rechaza y el rostro sigue igual. Y el reenvío de una marcación por
 *       código ya guardada sigue siendo duplicado, no rechazo: la idempotencia
 *       corre antes que el interruptor, así que apagarlo no reescribe el pasado
 *       ni deja una operación trabando su canal en la cola del dispositivo.
 *
 * El arnés ejercita el SERVICE y no el endpoint: la lógica que puede romperse
 * en silencio vive ahí, y el endpoint es ruteo + gates que otros arneses ya
 * cubren. La foto no se ejercita (necesitaría S3): lo que sí se ejercita es su
 * AUSENCIA, que es el camino que el fail-open promete.
 *
 * Uso (ver `run_attendance_mark_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/attendance_mark_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Hr\AttendanceService;
use Punto\Api\Hr\AttendanceSettings;
use Punto\Api\Hr\EmployeeService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ────────
$companyId = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId  = '1a282724-6073-49c3-8bc3-0114a132e349';
$adminId   = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
// Segundo tenant del mismo seed ("Verify MX"). Existe para el caso (B).
$otherCompanyId = 'fa8cf679-9003-417e-8726-5b772d3b6e88';
$userId = $adminId;
$roleId = '1';
require API_APP_DIR . '/data.php';

const MARCA_DEL_ARNES = 'attendance-mark-test';

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

/** Cuántas marcaciones tiene una persona en el comercio. */
function contarMarcas(string $companyId, string $employeeId): int
{
    $row = ncmExecute(
        'SELECT COUNT(*) AS n FROM attendance_mark WHERE companyid = ? AND contactid = ?',
        [$companyId, $employeeId]
    );
    return (int) ($row['n'] ?? 0);
}

/**
 * Prende o apaga "permitir marcar con código" en el comercio.
 *
 * Escribe la MISMA clave que escribe Ajustes (`config.settingObj`, sin
 * migración) con el mismo 1/0 que usa `SettingsService::updateGeneral()`, y
 * después invalida el cache por request igual que hace el CRUD real. Escribir
 * a mano un `true`/`false` JSON acá probaría un formato que el form nunca
 * produce.
 *
 * `$on = true` significa CÓDIGO HABILITADO. La clave se llamaba
 * `attendanceFaceOnly` (negada) hasta que el owner invirtió el default el
 * 2026-09-18: ahora ausente = solo rostro, y el nombre positivo es el que hace
 * que "ausente" caiga del lado correcto sin backfill.
 */
function setAllowPin(string $companyId, bool $on): void
{
    $row = ncmExecute(
        "SELECT config->>'settingObj' AS so FROM company WHERE companyId = ? LIMIT 1",
        [$companyId]
    );
    $obj = json_decode((string) ($row['so'] ?? ''), true);
    if (!is_array($obj)) {
        $obj = [];
    }
    $obj['attendanceAllowPin'] = $on ? 1 : 0;

    // `to_jsonb(?::text)`: `settingObj` se guarda como el TEXTO de un JSON
    // adentro de `config`, no como un objeto anidado — es lo que escribe
    // `updateGeneral()` y lo que leen los tres consumidores con `->>`. Meterlo
    // como objeto acá dejaría al arnés probando una forma que producción no
    // produce.
    ncmExecute(
        "UPDATE company
            SET config = jsonb_set(COALESCE(config, '{}'::jsonb), '{settingObj}', to_jsonb(?::text), true)
          WHERE companyId = ?",
        [json_encode($obj), $companyId]
    );
    AttendanceSettings::forget($companyId);
}

/** `opId` único por corrida: el arnés puede correr dos veces sobre la misma base. */
function opId(string $tag): string
{
    return 'att-test-' . $tag . '-' . bin2hex(random_bytes(6));
}

$employees = new EmployeeService();
$svc       = new AttendanceService(); // sin S3: acá no se suben fotos

$creados = [];

/**
 * Crea la PERSONA del legajo (context/83 §9.1, mig 233).
 *
 * Desde el refactor el legajo es un satélite 1:1 del `contact` type=0, así que
 * el arnés ya no puede crear un empleado con solo un nombre: primero existe la
 * persona —con su PIN, que es el único que hay— y después su legajo.
 */
function crearPersona(string $companyId, string $nombre, ?string $pin): string
{
    $id = ncmExecute('SELECT gen_random_uuid() AS id')['id'];
    ncmExecute(
        'INSERT INTO contact (contactId, contactName, companyId, type, contactStatus, pinhash)
         VALUES (?, ?, ?, 0, 1, ?)',
        [$id, $nombre, $companyId, $pin === null ? null : hash('sha256', $pin)]
    );
    return (string) $id;
}

try {
    // ── Fixture: dos personas del legajo ────────────────────────────────────
    //
    // `conHorario` declara 08:00 a 17:00 de lunes a domingo con 10 minutos de
    // tolerancia — los siete días a propósito, para que el arnés no dependa de
    // qué día de la semana se corra.
    $conHorario = $employees->create($companyId, [
        'contactId' => crearPersona($companyId, 'Marcacion ConHorario ' . bin2hex(random_bytes(3)), '4731'),
        'hireDate' => '2026-01-01',
        'outletId' => $outletId,
        'schedule' => json_encode([
            'days' => [
                'mon' => ['in' => '08:00', 'out' => '17:00'],
                'tue' => ['in' => '08:00', 'out' => '17:00'],
                'wed' => ['in' => '08:00', 'out' => '17:00'],
                'thu' => ['in' => '08:00', 'out' => '17:00'],
                'fri' => ['in' => '08:00', 'out' => '17:00'],
                'sat' => ['in' => '08:00', 'out' => '17:00'],
                'sun' => ['in' => '08:00', 'out' => '17:00'],
            ],
            'toleranceMinutes' => 10,
        ]),
    ], $adminId);
    $creados[] = $conHorario['id'];

    $sinHorario = $employees->create($companyId, [
        'contactId' => crearPersona($companyId, 'Marcacion SinHorario ' . bin2hex(random_bytes(3)), '8265'),
        'hireDate' => '2026-01-01',
        'outletId' => $outletId,
    ], $adminId);
    $creados[] = $sinHorario['id'];

    $hashOk   = hash('sha256', '4731');
    $hashOtro = hash('sha256', '0000');

    // Día fijo para todo el arnés: el rango del reporte se arma alrededor de
    // él, así que nada depende de la fecha en que se corra el test.
    $dia = '2026-03-04'; // miércoles
    $from = $dia . ' 00:00:00';
    $to   = $dia . ' 23:59:59';

    // ═══════════════════════════════════════════════════════════════════════
    // (A) Idempotencia
    // ═══════════════════════════════════════════════════════════════════════
    // Todo el arnés hasta la sección (G) marca por CÓDIGO, y desde el cambio
    // de default (owner 2026-09-18) el código nace APAGADO: se prende acá una
    // sola vez, como lo prendería Ajustes. La sección (G) lo apaga y ejercita
    // el default real.
    setAllowPin($companyId, true);

    echo "\n=== (A) Idempotencia: el mismo opId no duplica ===\n";

    $op = opId('idem');
    $primera = $svc->mark($companyId, [
        'opId'        => $op,
        'employeeId'  => $conHorario['id'],
        'pinHash'     => $hashOk,
        'kind'        => 'in',
        'markedAt'    => $dia . 'T08:05:00-03:00',
        'outletId'    => $outletId,
    ]);
    check('(A1) la primera marcación se registra',
        $primera['duplicate'] === false && !empty($primera['mark']['id']),
        json_encode($primera), $failures, $checks);

    $reenvio = $svc->mark($companyId, [
        'opId'        => $op,
        'employeeId'  => $conHorario['id'],
        'pinHash'     => $hashOk,
        'kind'        => 'in',
        'markedAt'    => $dia . 'T08:05:00-03:00',
        'outletId'    => $outletId,
    ]);
    check('(A2) el reenvío devuelve la MISMA marcación, no una nueva',
        $reenvio['duplicate'] === true && $reenvio['mark']['id'] === $primera['mark']['id'],
        json_encode($reenvio), $failures, $checks);

    check('(A3) y en la base hay UNA sola fila',
        contarMarcas($companyId, $conHorario['id']) === 1,
        'filas: ' . contarMarcas($companyId, $conHorario['id']), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (D) Fail-open: entra igual, flageada
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (D) Fail-open: la marcación entra aunque algo falle ===\n";

    check('(D1) sin foto entra y queda para revisar',
        $primera['mark']['needsReview'] === true,
        'needsReview vino: ' . json_encode($primera['mark']['needsReview']), $failures, $checks);
    check('(D1b) con el motivo que declaró el dispositivo',
        $primera['mark']['reviewReason'] === 'photo_failed',
        'reviewReason vino: ' . json_encode($primera['mark']['reviewReason']), $failures, $checks);

    $malPin = $svc->mark($companyId, [
        'opId'          => opId('pin'),
        'employeeId'    => $conHorario['id'],
        'pinHash'       => $hashOtro,
        'kind'          => 'out',
        'markedAt'      => $dia . 'T17:05:00-03:00',
        'outletId'      => $outletId,
        'noPhotoReason' => 'no_camera',
    ]);
    check('(D2) con el PIN que NO coincide la marcación entra igual',
        $malPin['duplicate'] === false && !empty($malPin['mark']['id']),
        json_encode($malPin), $failures, $checks);
    check('(D2b) y el motivo es el PIN, no la foto — la identidad explica mejor lo que se ve',
        $malPin['mark']['reviewReason'] === 'pin_stale',
        'reviewReason vino: ' . json_encode($malPin['mark']['reviewReason']), $failures, $checks);

    // Un empleado que no existe en ESTE comercio sí se rechaza: no hay hecho
    // que guardar, es un cliente mandando cualquier cosa.
    $rechazado = false;
    try {
        $svc->mark($companyId, [
            'opId'        => opId('ghost'),
            'employeeId'  => '00000000-0000-4000-8000-000000000000',
            'pinHash'     => $hashOk,
            'kind'        => 'in',
            'markedAt'    => $dia . 'T08:00:00-03:00',
        ]);
    } catch (\RuntimeException) {
        $rechazado = true;
    }
    check('(D3) un empleado inexistente SÍ se rechaza (no hay hecho que guardar)',
        $rechazado, 'la marcación de un empleado fantasma no tiró', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (E) Apareo entrada/salida
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (E) Apareo: horas trabajadas ===\n";

    // 08:05 → 17:05 son 9 horas. La salida de arriba (D2) es la que cierra.
    $reporte = $svc->report($companyId, $from, $to, ['employeeId' => $conHorario['id']]);
    $fila = $reporte['employees'][0] ?? [];
    check('(E1) entrada + salida suman las horas del intervalo',
        (int) ($fila['workedMinutes'] ?? 0) === 540,
        'workedMinutes vino: ' . json_encode($fila['workedMinutes'] ?? null), $failures, $checks);
    // La serie del gráfico (TimeBuckets) suma el par en el período de la
    // salida que lo cierra: las 9 h aparecen una sola vez en toda la serie.
    $serie = $reporte['series']['points'] ?? [];
    check('(E1s) la serie del gráfico suma las 9 h una sola vez',
        array_sum(array_column($serie, 'workedMinutes')) === 540
            && count(array_filter($serie, static fn($p) => $p['workedMinutes'] > 0)) === 1,
        json_encode($reporte['series'] ?? null), $failures, $checks);
    check('(E1b) y el par queda cerrado',
        (int) ($fila['openPairs'] ?? -1) === 0 && (int) ($fila['pairs'] ?? 0) === 1,
        json_encode(['openPairs' => $fila['openPairs'] ?? null, 'pairs' => $fila['pairs'] ?? null]),
        $failures, $checks);

    // Una entrada sin salida NO se estima hasta el fin del día: suma cero y se
    // cuenta aparte. Estimar sería inventar horas que después se pagan.
    $svc->mark($companyId, [
        'opId'          => opId('abierta'),
        'employeeId'    => $sinHorario['id'],
        'pinHash'       => hash('sha256', '8265'),
        'kind'          => 'in',
        'markedAt'      => $dia . 'T09:00:00-03:00',
        'outletId'      => $outletId,
        'noPhotoReason' => 'no_camera',
    ]);
    $reporteAbierto = $svc->report($companyId, $from, $to, ['employeeId' => $sinHorario['id']]);
    $filaAbierta = $reporteAbierto['employees'][0] ?? [];
    check('(E2) una entrada sin cerrar suma CERO horas',
        (int) ($filaAbierta['workedMinutes'] ?? -1) === 0,
        'workedMinutes vino: ' . json_encode($filaAbierta['workedMinutes'] ?? null), $failures, $checks);
    check('(E2b) y se cuenta como par abierto, para que el total corto se pueda explicar',
        (int) ($filaAbierta['openPairs'] ?? 0) === 1,
        'openPairs vino: ' . json_encode($filaAbierta['openPairs'] ?? null), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (C) Tardanza
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (C) Tardanza contra el horario declarado ===\n";

    // 08:05 con entrada 08:00 y 10 de tolerancia: NO es tardanza.
    check('(C1) dentro de la tolerancia no hay tardanza',
        (int) ($fila['lateCount'] ?? -1) === 0 && (int) ($fila['lateMinutes'] ?? -1) === 0,
        json_encode(['lateCount' => $fila['lateCount'] ?? null, 'lateMinutes' => $fila['lateMinutes'] ?? null]),
        $failures, $checks);

    // Sin horario declarado no se mide. `null` en la marcación, y la fila del
    // empleado lo declara — el reporte muestra un guión, no un cero: un cero
    // diría que llegó siempre en horario, que es algo que nadie afirmó.
    // `array_key_exists` y no `?? `: el `??` colapsa "la clave no está" con "la
    // clave vale null", que es EXACTAMENTE la distinción que este caso existe
    // para proteger. Con `??` el assert pasaba con la clave ausente.
    $marcaSinHorario = $reporteAbierto['marks'][0] ?? [];
    check('(C2) sin horario declarado la tardanza es null, no cero',
        array_key_exists('lateMinutes', $marcaSinHorario)
        && $marcaSinHorario['lateMinutes'] === null
        && ($filaAbierta['hasSchedule'] ?? true) === false,
        json_encode([
            'tieneClave'  => array_key_exists('lateMinutes', $marcaSinHorario),
            'lateMinutes' => $marcaSinHorario['lateMinutes'] ?? 'AUSENTE',
            'hasSchedule' => $filaAbierta['hasSchedule'] ?? null,
        ]), $failures, $checks);

    // Otro día, entrada 09:30 contra 08:00 + 10 de tolerancia = 80 minutos.
    // Y una SEGUNDA entrada el mismo día (volver del almuerzo) no puede sumar
    // otra tardanza: se mide la primera del día y nada más.
    $dia2 = '2026-03-05';
    $svc->mark($companyId, [
        'opId'          => opId('tarde'),
        'employeeId'    => $conHorario['id'],
        'pinHash'       => $hashOk,
        'kind'          => 'in',
        'markedAt'      => $dia2 . 'T09:30:00-03:00',
        'outletId'      => $outletId,
        'noPhotoReason' => 'no_camera',
    ]);
    $svc->mark($companyId, [
        'opId'          => opId('almuerzo-out'),
        'employeeId'    => $conHorario['id'],
        'pinHash'       => $hashOk,
        'kind'          => 'out',
        'markedAt'      => $dia2 . 'T12:00:00-03:00',
        'outletId'      => $outletId,
        'noPhotoReason' => 'no_camera',
    ]);
    $svc->mark($companyId, [
        'opId'          => opId('almuerzo-in'),
        'employeeId'    => $conHorario['id'],
        'pinHash'       => $hashOk,
        'kind'          => 'in',
        'markedAt'      => $dia2 . 'T13:00:00-03:00',
        'outletId'      => $outletId,
        'noPhotoReason' => 'no_camera',
    ]);

    $rep2 = $svc->report($companyId, $dia2 . ' 00:00:00', $dia2 . ' 23:59:59',
        ['employeeId' => $conHorario['id']]);
    $fila2 = $rep2['employees'][0] ?? [];
    check('(C3) la tardanza se mide descontando la tolerancia',
        (int) ($fila2['lateMinutes'] ?? -1) === 80,
        'lateMinutes vino: ' . json_encode($fila2['lateMinutes'] ?? null), $failures, $checks);
    check('(C4) y UNA sola vez por día: volver del almuerzo no es llegar tarde',
        (int) ($fila2['lateCount'] ?? -1) === 1,
        'lateCount vino: ' . json_encode($fila2['lateCount'] ?? null), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (B) Scoping multi-tenant
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (B) Scoping: el otro comercio no ve nada ===\n";

    $ajeno = $svc->report($otherCompanyId, $from, $to, []);
    $idsAjenos = array_column($ajeno['marks'], 'id');
    check('(B1) el reporte del OTRO comercio no incluye estas marcaciones',
        !in_array($primera['mark']['id'], $idsAjenos, true),
        'el reporte ajeno trajo ' . count($idsAjenos) . ' marcaciones', $failures, $checks);

    check('(B2) ni se puede leer una marcación por id desde el otro comercio',
        $svc->find($primera['mark']['id'], $otherCompanyId) === null,
        'find() con el companyId ajeno devolvió una fila', $failures, $checks);

    check('(B3) ni resolverla por su opId',
        $svc->findByOpId($otherCompanyId, $op) === null,
        'findByOpId() con el companyId ajeno devolvió una fila', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // Revisión
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (F) Revisión ===\n";

    $revisada = $svc->review($primera['mark']['id'], $companyId, $adminId);
    check('(F1) revisar apaga el flag y deja constancia de cuándo',
        $revisada['needsReview'] === false && $revisada['reviewedAt'] !== null,
        json_encode($revisada), $failures, $checks);
    check('(F2) y NO toca el hecho: sigue siendo la misma marcación, a la misma hora',
        $revisada['kind'] === $primera['mark']['kind']
        && $revisada['markedAt'] === $primera['mark']['markedAt'],
        json_encode(['antes' => $primera['mark'], 'después' => $revisada]), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (G) El interruptor "marcar con código" del comercio (context/83)
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Es la ÚNICA excepción al fail-open, así que se ejercita entera: que el
    // default de un comercio que nunca tocó la clave sea SOLO ROSTRO (owner
    // 2026-09-18 — el flag es positivo justamente para eso), que prendido el
    // código entre, que apagado se rechace, y que apagado NO toque el rostro.
    echo "\n=== (G) Interruptor de marcación con código ===\n";

    // Volver al estado "el comercio nunca lo prendió": la clave en 0 resuelve
    // igual que ausente (allowPin === false), que es lo que G0/G0b ejercitan.
    setAllowPin($companyId, false);

    check('(G0) por default el comercio NO deja marcar con código (clave ausente)',
        AttendanceSettings::allowPin($companyId) === false,
        'allowPin() dio true sin que nadie tocara la config', $failures, $checks);

    $rechazoPorDefault = null;
    try {
        $svc->mark($companyId, [
            'opId'          => opId('default-pin'),
            'employeeId'    => $sinHorario['id'],
            'pinHash'       => hash('sha256', '8265'),
            'kind'          => 'in',
            'markedAt'      => $dia . 'T07:55:00-03:00',
            'method'        => 'pin',
            'outletId'      => $outletId,
            'noPhotoReason' => 'no_camera',
        ]);
    } catch (\RuntimeException $e) {
        $rechazoPorDefault = $e;
    }
    check('(G0b) y el rechazo es REAL, no solo el resolver: sin tocar nada, el código no entra',
        $rechazoPorDefault !== null,
        'la marcación por código entró con la clave ausente', $failures, $checks);

    // Se prende como lo prende Ajustes: una clave más en `config.settingObj`,
    // sin migración. `forget()` es lo que hace el CRUD real al guardar.
    setAllowPin($companyId, true);

    $antesDelSwitch = contarMarcas($companyId, $sinHorario['id']);

    // El `opId` se guarda en una variable: `shape()` no lo devuelve (la fila que
    // sale del service no expone la clave de idempotencia), y el reenvío de
    // (G4) lo necesita idéntico.
    $opCodigo  = opId('switch-on-pin');
    $conCodigo = $svc->mark($companyId, [
        'opId'          => $opCodigo,
        'employeeId'    => $sinHorario['id'],
        'pinHash'       => hash('sha256', '8265'),
        'kind'          => 'in',
        'markedAt'      => $dia . 'T08:00:00-03:00',
        'method'        => 'pin',
        'outletId'      => $outletId,
        'noPhotoReason' => 'no_camera',
    ]);
    check('(G1) con el interruptor PRENDIDO la marcación por código entra',
        $conCodigo['duplicate'] === false && $conCodigo['mark']['method'] === 'pin',
        json_encode($conCodigo), $failures, $checks);

    // Y se apaga por el mismo camino.
    setAllowPin($companyId, false);

    $rechazoPorCodigo = null;
    try {
        $svc->mark($companyId, [
            'opId'          => opId('switch-off-pin'),
            'employeeId'    => $sinHorario['id'],
            'pinHash'       => hash('sha256', '8265'),
            'kind'          => 'out',
            'markedAt'      => $dia . 'T17:00:00-03:00',
            'method'        => 'pin',
            'outletId'      => $outletId,
            'noPhotoReason' => 'no_camera',
        ]);
    } catch (\RuntimeException $e) {
        $rechazoPorCodigo = $e;
    }
    check('(G2) apagado, una marcación por código se RECHAZA',
        $rechazoPorCodigo !== null,
        'la marcación por código entró con el interruptor apagado', $failures, $checks);
    check('(G2b) con un mensaje llano, sin tecnicismos',
        $rechazoPorCodigo !== null
        && str_contains($rechazoPorCodigo->getMessage(), 'código está desactivada'),
        'mensaje: ' . ($rechazoPorCodigo?->getMessage() ?? '(no tiró)'), $failures, $checks);
    check('(G2c) y NO deja una fila a medias: el hecho no se guardó flageado',
        contarMarcas($companyId, $sinHorario['id']) === $antesDelSwitch + 1,
        'filas: ' . contarMarcas($companyId, $sinHorario['id'])
        . ' (esperadas ' . ($antesDelSwitch + 1) . ')', $failures, $checks);

    $porRostro = $svc->mark($companyId, [
        'opId'          => opId('switch-off-face'),
        'employeeId'    => $sinHorario['id'],
        'kind'          => 'out',
        'markedAt'      => $dia . 'T17:05:00-03:00',
        'method'        => 'face',
        'outletId'      => $outletId,
        'noPhotoReason' => 'no_camera',
    ]);
    check('(G3) apagado, el ROSTRO sigue marcando normal',
        $porRostro['duplicate'] === false && $porRostro['mark']['method'] === 'face',
        json_encode($porRostro), $failures, $checks);

    // La idempotencia corre ANTES del interruptor: una marcación por código que
    // el servidor YA guardó se devuelve como duplicado aunque el comercio haya
    // apagado el código en el medio. Sin esto, el reenvío de la cola offline
    // —que no puede saber si la primera llegó— convertiría un hecho ya
    // registrado en un error, y quedaría trabando su canal.
    $reenvioViejo = $svc->mark($companyId, [
        'opId'       => $opCodigo,
        'employeeId' => $sinHorario['id'],
        'pinHash'    => hash('sha256', '8265'),
        'kind'       => 'in',
        'markedAt'   => $dia . 'T08:00:00-03:00',
        'method'     => 'pin',
        'outletId'   => $outletId,
    ]);
    check('(G4) el reenvío de una marcación por código YA guardada sigue siendo duplicado, no rechazo',
        $reenvioViejo['duplicate'] === true
        && $reenvioViejo['mark']['id'] === $conCodigo['mark']['id'],
        json_encode($reenvioViejo), $failures, $checks);

    setAllowPin($companyId, true);
    check('(G5) volver a prender el interruptor rehabilita el código',
        AttendanceSettings::allowPin($companyId) === true,
        'allowPin() siguió en false después de prender la clave', $failures, $checks);

} finally {
    // El interruptor es del TENANT del fixture, no de las personas que el
    // arnés creó: si algo tiró en el medio, la clave queda escrita y la
    // próxima corrida arranca con el código PRENDIDO (y (G0b) falla sin que
    // nadie haya roto nada). Se devuelve siempre al default, que es apagado.
    try {
        setAllowPin($companyId, false);
    } catch (\Throwable) {
        // Sin conexión no hay nada que limpiar; el error real ya se está
        // propagando y taparlo con este sería peor.
    }

    // Limpieza: borrar la PERSONA se lleva el legajo y sus marcaciones por
    // CASCADE (mig 233), que es justo el encadenamiento que el modelo promete.
    foreach ($creados as $contactId) {
        ncmExecute('DELETE FROM contact WHERE contactId = ? AND companyId = ?', [$contactId, $companyId]);
    }
}

harnessFinish($failures, $checks);

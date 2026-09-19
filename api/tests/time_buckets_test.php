<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de cualquier otra cosa (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la GRANULARIDAD de las series temporales (`Support\TimeBuckets`).
 *
 * Lo que cubre, y por qué cada caso muerde:
 *
 * Bloque A (puro, sin DB):
 *   - los CORTES de la regla del owner (31/32 y 120/121 días) — un off-by-one
 *     acá cambia el grano de todos los gráficos de un mes de 31 días;
 *   - el conteo de días INCLUYE los dos extremos — "del 1 al 31" son 31 días;
 *   - la semana es ISO (arranca el LUNES), también cuando el rango empieza en
 *     domingo;
 *   - los BORDES recortados se marcan `partial` y los de adentro no;
 *   - el mes de febrero y el cambio de año no se saltean ni duplican buckets;
 *   - `keyFor()` asigna cada fecha a SU bucket (la primera compra de un cliente
 *     cae en la semana/mes correcto);
 *   - `fill()` rellena con cero los buckets sin datos y no deja que un valor
 *     pise la identidad del bucket.
 *
 * Bloque B (Postgres real, si hay uno): la expresión SQL y `keyFor()` cortan
 * EXACTAMENTE igual en dos zonas horarias distintas, incluido un timestamp
 * pasada la medianoche y una columna `date` (grano de rollup). Si divergieran,
 * los clientes "nuevos" (bucket en PHP) y los activos (bucket en SQL) caerían
 * en semanas distintas y la barra de recurrentes saldría negativa.
 */

require_once dirname(__DIR__) . '/lib/Support/TimeBuckets.php';

use Punto\Api\Support\TimeBuckets;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "  OK   $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label — $detail\n";
}

echo "Bloque A — regla y calendario (sin DB)\n";

// ── A1. Cortes de la regla ──────────────────────────────────────────────────
$cases = [
    ['2026-09-18', '2026-09-18', TimeBuckets::DAY],   // un día
    ['2026-08-01', '2026-08-31', TimeBuckets::DAY],   // 31 días
    ['2026-08-01', '2026-09-01', TimeBuckets::WEEK],  // 32 días
    ['2026-01-01', '2026-04-30', TimeBuckets::WEEK],  // 120 días
    ['2026-01-01', '2026-05-01', TimeBuckets::MONTH], // 121 días
    ['2025-09-19', '2026-09-18', TimeBuckets::MONTH], // un año
];
foreach ($cases as [$f, $t, $want]) {
    $g = TimeBuckets::forRange("$f 00:00:00", "$t 23:59:59")->granularity;
    check("$f..$t → $want", $g === $want, "dio $g (días=" . TimeBuckets::daysInRange($f, $t) . ')', $failures, $checks);
}
check('31 días cuentan los dos extremos', TimeBuckets::daysInRange('2026-08-01', '2026-08-31') === 31, (string) TimeBuckets::daysInRange('2026-08-01', '2026-08-31'), $failures, $checks);
check('rango invertido = 0 días', TimeBuckets::daysInRange('2026-09-10', '2026-09-01') === 0, '', $failures, $checks);
check('rango invertido no enumera buckets', TimeBuckets::forRange('2026-09-10', '2026-09-01')->buckets() === [], '', $failures, $checks);

// ── A2. Diario: un bucket por día, ninguno parcial ──────────────────────────
$d = TimeBuckets::forRange('2026-09-01 00:00:00', '2026-09-30 23:59:59')->buckets();
check('septiembre diario = 30 buckets', count($d) === 30, (string) count($d), $failures, $checks);
check('el día no es nunca parcial', array_filter($d, static fn($b) => $b['partial']) === [], '', $failures, $checks);
check('bucket diario: inicio = fin', $d[0]['bucket'] === '2026-09-01' && $d[0]['end'] === '2026-09-01', json_encode($d[0]), $failures, $checks);

// ── A3. Semanal ISO con bordes parciales ────────────────────────────────────
// 2026-09-06 es DOMINGO: su semana ISO arranca el lunes 2026-08-31.
$tb = TimeBuckets::forRange('2026-09-06 00:00:00', '2026-10-20 23:59:59');
$w  = $tb->buckets();
check('45 días → semanal', $tb->granularity === TimeBuckets::WEEK, $tb->granularity, $failures, $checks);
check('la primera semana arranca el lunes anterior', $w[0]['bucket'] === '2026-08-31' && $w[0]['end'] === '2026-09-06', json_encode($w[0]), $failures, $checks);
check('la primera semana (recortada) es parcial', $w[0]['partial'] === true, json_encode($w[0]), $failures, $checks);
check('una semana del medio es completa', $w[1]['bucket'] === '2026-09-07' && $w[1]['partial'] === false, json_encode($w[1]), $failures, $checks);
$last = $w[count($w) - 1];
check('la última semana llega al domingo y es parcial', $last['bucket'] === '2026-10-19' && $last['end'] === '2026-10-25' && $last['partial'] === true, json_encode($last), $failures, $checks);
check('8 semanas sin saltos', count($w) === 8, (string) count($w), $failures, $checks);
$monotone = true;
for ($i = 1; $i < count($w); $i++) {
    if ((new DateTimeImmutable($w[$i]['bucket']))->diff(new DateTimeImmutable($w[$i - 1]['bucket']))->days !== 7) {
        $monotone = false;
    }
}
check('buckets semanales separados por 7 días exactos', $monotone, '', $failures, $checks);

// Rango que calza justo en semanas completas: ninguna parcial.
$exact = TimeBuckets::forRange('2026-08-31', '2026-10-11')->buckets(); // lunes → domingo, 42 días
check('rango lunes→domingo: sin parciales', array_filter($exact, static fn($b) => $b['partial']) === [] && count($exact) === 6, json_encode(array_column($exact, 'partial')), $failures, $checks);

// ── A4. Mensual: febrero, cambio de año, bordes ─────────────────────────────
$tb = TimeBuckets::forRange('2025-11-15 00:00:00', '2026-03-10 23:59:59');
$m  = $tb->buckets();
check('116 días → semanal (no mensual)', $tb->granularity === TimeBuckets::WEEK, $tb->granularity, $failures, $checks);

$tb = TimeBuckets::forRange('2025-10-15 00:00:00', '2026-03-10 23:59:59');
$m  = $tb->buckets();
check('147 días → mensual', $tb->granularity === TimeBuckets::MONTH, $tb->granularity, $failures, $checks);
check('oct..mar = 6 meses', array_column($m, 'bucket') === ['2025-10-01', '2025-11-01', '2025-12-01', '2026-01-01', '2026-02-01', '2026-03-01'], json_encode(array_column($m, 'bucket')), $failures, $checks);
check('febrero termina el 28', $m[4]['end'] === '2026-02-28', $m[4]['end'], $failures, $checks);
check('diciembre termina el 31', $m[2]['end'] === '2025-12-31', $m[2]['end'], $failures, $checks);
check('primer mes recortado = parcial', $m[0]['partial'] === true, '', $failures, $checks);
check('último mes recortado = parcial', $m[5]['partial'] === true, '', $failures, $checks);
check('meses del medio completos', !$m[1]['partial'] && !$m[2]['partial'] && !$m[3]['partial'] && !$m[4]['partial'], '', $failures, $checks);
$year = TimeBuckets::forRange('2026-01-01', '2026-12-31')->buckets();
check('año calendario = 12 meses, ninguno parcial', count($year) === 12 && array_filter($year, static fn($b) => $b['partial']) === [], (string) count($year), $failures, $checks);
// Rango que empieza el 31: "first day of" sobre el 31 no puede saltarse febrero.
$from31 = TimeBuckets::forRange('2026-01-31', '2026-06-30')->buckets();
check('arrancar un 31 no saltea febrero', array_column($from31, 'bucket') === ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'], json_encode(array_column($from31, 'bucket')), $failures, $checks);

// ── A5. keyFor ──────────────────────────────────────────────────────────────
$wk = TimeBuckets::forRange('2026-08-01', '2026-10-01'); // semanal
check('keyFor semana: domingo → lunes anterior', $wk->keyFor('2026-09-13 23:10:00') === '2026-09-07', $wk->keyFor('2026-09-13 23:10:00'), $failures, $checks);
check('keyFor semana: lunes → sí mismo', $wk->keyFor('2026-09-14') === '2026-09-14', $wk->keyFor('2026-09-14'), $failures, $checks);
check('keyFor semana: ignora el offset', $wk->keyFor('2026-09-14 00:05:00-03') === '2026-09-14', $wk->keyFor('2026-09-14 00:05:00-03'), $failures, $checks);
$mo = TimeBuckets::forRange('2026-01-01', '2026-12-31');
check('keyFor mes', $mo->keyFor('2026-02-28 18:00:00') === '2026-02-01', $mo->keyFor('2026-02-28 18:00:00'), $failures, $checks);
$dy = TimeBuckets::forRange('2026-09-01', '2026-09-30');
check('keyFor día', $dy->keyFor('2026-09-18 10:00:00') === '2026-09-18', $dy->keyFor('2026-09-18 10:00:00'), $failures, $checks);
check('keyFor basura → vacío', $dy->keyFor('ayer') === '', $dy->keyFor('ayer'), $failures, $checks);
$allKeys = array_column($wk->buckets(), 'bucket');
$inside  = true;
for ($d0 = new DateTimeImmutable('2026-08-01'); $d0 <= new DateTimeImmutable('2026-10-01'); $d0 = $d0->modify('+1 day')) {
    if (!in_array($wk->keyFor($d0->format('Y-m-d')), $allKeys, true)) {
        $inside = false;
    }
}
check('todo día del rango cae en un bucket enumerado', $inside, '', $failures, $checks);

// ── A6. fill ────────────────────────────────────────────────────────────────
$f = $dy->fill(['2026-09-02' => ['total' => 5.0, 'bucket' => 'x']], ['total' => 0.0]);
check('fill: un punto por día', count($f) === 30, (string) count($f), $failures, $checks);
check('fill: el vacío va en cero', $f[0]['total'] === 0.0, json_encode($f[0]), $failures, $checks);
check('fill: el valor llega a su bucket', $f[1]['total'] === 5.0, json_encode($f[1]), $failures, $checks);
check('fill: un valor no pisa la identidad del bucket', $f[1]['bucket'] === '2026-09-02', $f[1]['bucket'], $failures, $checks);

// ── A7. SQL ─────────────────────────────────────────────────────────────────
check("sql semana usa date_trunc('week')", str_contains($wk->sql('t.transactionDate'), "date_trunc('week', t.transactionDate)"), $wk->sql('t.transactionDate'), $failures, $checks);
check('sql no fija zona horaria', !str_contains(strtolower($wk->sql('x') . $mo->sql('x') . $dy->sql('x')), 'time zone'), '', $failures, $checks);

// ── Bloque B: SQL y PHP cortan igual ────────────────────────────────────────
echo "\nBloque B — SQL vs keyFor contra Postgres\n";
$host = getenv('POSTGRES_HOST') ?: '';
if ($host === '') {
    echo "  SKIP sin POSTGRES_HOST (el runner levanta uno con Docker)\n";
} else {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, getenv('POSTGRES_PORT') ?: '5432', getenv('POSTGRES_DB') ?: 'postgres'),
        getenv('POSTGRES_USER') ?: 'postgres',
        getenv('POSTGRES_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stamps = [
        '2026-09-13 23:59:00', // domingo tarde
        '2026-09-14 00:01:00', // lunes recién empezado
        '2026-10-04 00:30:00', // semana de un cambio de horario en algunos países
        '2026-02-28 12:00:00',
        '2026-03-01 00:00:00',
        '2025-12-31 23:30:00',
    ];
    foreach (['America/Asuncion', 'Asia/Tokyo'] as $tz) {
        $pdo->exec("SET TIME ZONE '$tz'");
        foreach ([$wk, $mo, $dy] as $tb) {
            foreach ($stamps as $s) {
                $got = $pdo->query('SELECT ' . $tb->sql("'$s'::timestamptz") . ' AS k')->fetchColumn();
                $gotDate = $pdo->query('SELECT ' . $tb->sql("'" . substr($s, 0, 10) . "'::date") . ' AS k')->fetchColumn();
                $want = $tb->keyFor($s);
                check("[$tz/{$tb->granularity}] $s timestamptz", $got === $want, "sql=$got php=$want", $failures, $checks);
                check("[$tz/{$tb->granularity}] $s date", $gotDate === $want, "sql=$gotDate php=$want", $failures, $checks);
            }
        }
    }
}

harnessFinish($failures, $checks);

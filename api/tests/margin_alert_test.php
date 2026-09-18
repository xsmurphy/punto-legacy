<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de cualquier otra cosa (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la aritmética de la alerta de margen (`MarginAlert`). Puro, sin
 * Postgres: margen, precio sugerido, redondeo y los casos que NO alertan.
 *
 * Uso: php api/tests/margin_alert_test.php
 */

require_once dirname(__DIR__) . '/lib/Items/MarginAlert.php';

use Punto\Api\Items\MarginAlert;

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

function near(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.0001;
}

// ── Objetivo ────────────────────────────────────────────────────────────────
check('objetivo vacío = apagada', MarginAlert::parseTarget('') === null && MarginAlert::parseTarget(null) === null, '', $failures, $checks);
check('objetivo 0 = apagada', MarginAlert::parseTarget(0) === null, '', $failures, $checks);
check('objetivo negativo = apagada', MarginAlert::parseTarget('-5') === null, '', $failures, $checks);
check('objetivo 100 = apagada (sugerido infinito)', MarginAlert::parseTarget(100) === null, '', $failures, $checks);
check('objetivo basura = apagada', MarginAlert::parseTarget('abc') === null && MarginAlert::parseTarget(['x']) === null && MarginAlert::parseTarget(true) === null, '', $failures, $checks);
check('objetivo numérico', near(MarginAlert::parseTarget('30'), 30.0), (string) MarginAlert::parseTarget('30'), $failures, $checks);
check('objetivo con coma decimal', near(MarginAlert::parseTarget('32,5'), 32.5), (string) MarginAlert::parseTarget('32,5'), $failures, $checks);

// ── Margen ──────────────────────────────────────────────────────────────────
check('margen = (precio − costo) / precio', near(MarginAlert::marginPct(100.0, 70.0), 30.0), (string) MarginAlert::marginPct(100.0, 70.0), $failures, $checks);
check('margen negativo si el costo supera al precio', near(MarginAlert::marginPct(100.0, 120.0), -20.0), '', $failures, $checks);
check('precio cero = sin margen', MarginAlert::marginPct(0.0, 50.0) === null, '', $failures, $checks);

// ── Redondeo ────────────────────────────────────────────────────────────────
$cases = [
    [12345.6, false, 12400.0],
    [12400.0, false, 12400.0],   // exacto: no sube un escalón
    [950.2,   false, 951.0],
    [4.5,     false, 5.0],       // piso: unidad mínima sin decimales
    [0.3,     false, 1.0],
    [45.31,   true,  45.4],
    [3.871,   true,  3.88],
    [0.004,   true,  0.01],      // piso: unidad mínima con decimales
    [1234567.0, false, 1240000.0],
];
foreach ($cases as [$in, $dec, $want]) {
    $got = MarginAlert::roundUpPrice($in, $dec);
    check("redondeo $in (" . ($dec ? '2 dec' : 'sin dec') . ") → $want", near($got, $want), (string) $got, $failures, $checks);
}
check('redondeo de cero/negativo = 0', MarginAlert::roundUpPrice(0.0, false) === 0.0 && MarginAlert::roundUpPrice(-3.0, true) === 0.0, '', $failures, $checks);

// ── Precio sugerido ─────────────────────────────────────────────────────────
// 7000 / (1 − 0,30) = 10000 exacto.
check('sugerido exacto', near(MarginAlert::suggestedPrice(7000.0, 30.0, false), 10000.0), (string) MarginAlert::suggestedPrice(7000.0, 30.0, false), $failures, $checks);
// 7777 / 0,7 = 11110 → 11200 (3 cifras, hacia arriba).
$s = MarginAlert::suggestedPrice(7777.0, 30.0, false);
check('sugerido redondeado hacia arriba', near($s, 11200.0), (string) $s, $failures, $checks);
check('el sugerido nunca queda bajo el objetivo', MarginAlert::marginPct($s, 7777.0) >= 30.0, (string) MarginAlert::marginPct($s, 7777.0), $failures, $checks);
// 3,5 / 0,6 = 5,8333 → 5,84 con decimales.
check('sugerido con decimales', near(MarginAlert::suggestedPrice(3.5, 40.0, true), 5.84), (string) MarginAlert::suggestedPrice(3.5, 40.0, true), $failures, $checks);
check('sugerido con costo cero = 0', MarginAlert::suggestedPrice(0.0, 30.0, false) === 0.0, '', $failures, $checks);

// ── evaluate() ──────────────────────────────────────────────────────────────
$row = static fn(string $id, float $price, ?float $before, ?float $after) => [
    'itemId' => $id, 'name' => "Art $id", 'price' => $price, 'costBefore' => $before, 'costAfter' => $after,
];

$rows = [
    $row('sube-bajo',    10000.0, 7000.0, 8000.0),  // 20% < 30% y subió → alerta
    $row('sube-ok',      10000.0, 5000.0, 6000.0),  // 40% ≥ 30%          → no
    $row('baja-bajo',    10000.0, 9000.0, 8500.0),  // 15% pero bajó      → no
    $row('igual-bajo',   10000.0, 8500.0, 8500.0),  // no subió           → no
    $row('primera',      10000.0, null,   9000.0),  // sin costo previo   → alerta
    $row('precio-cero',  0.0,     5000.0, 6000.0),  // sin precio         → no
    $row('costo-cero',   10000.0, 0.0,    0.0),     // sin costo          → no
    $row('costo-null',   10000.0, 5000.0, null),    // costo desconocido  → no
    $row('justo',        10000.0, 6000.0, 7000.0),  // 30% exacto         → no
];

$out = MarginAlert::evaluate(30.0, $rows, false);
$ids = array_column($out, 'itemId');
check('alerta solo lo que subió y quedó bajo', $ids === ['primera', 'sube-bajo'], json_encode($ids), $failures, $checks);
check('ordenado por peor margen primero', ($out[0]['marginPct'] ?? null) === 10.0, json_encode($out[0] ?? null), $failures, $checks);

$sb = $out[1] ?? [];
check('fila: costo, precio y margen', near($sb['cost'] ?? null, 8000.0) && near($sb['price'] ?? null, 10000.0) && near($sb['marginPct'] ?? null, 20.0), json_encode($sb), $failures, $checks);
// 8000 / 0,7 = 11428,57 → 11500.
check('fila: precio sugerido', near($sb['suggestedPrice'] ?? null, 11500.0), json_encode($sb), $failures, $checks);

check('objetivo vacío = sin alertas', MarginAlert::evaluate(null, $rows, false) === [], '', $failures, $checks);
check('sin filas = sin alertas', MarginAlert::evaluate(30.0, [], false) === [], '', $failures, $checks);

echo "\n";
harnessFinish($failures, $checks);

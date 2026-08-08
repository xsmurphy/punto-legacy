<?php

declare(strict_types=1);

/**
 * verify_engine.php — runner de paridad del lado PHP.
 *
 * Corre `fixtures.json` (misma carpeta, copia canónica única — el runner
 * TS la lee por path relativo desde frontend/lib/tax/) contra
 * TaxEngine::computeTaxes y compara con `expected` con tolerancia 0 (los
 * valores ya vienen redondeados a `decimals` fijos, deben calzar exacto).
 *
 * Uso: php api/lib/Tax/verify_engine.php
 * Exit code 0 si todo pasa, 1 si algún caso difiere.
 *
 * Script standalone: NO bootstrapea la app completa (sin DB, sin config),
 * solo requiere TaxEngine.php directamente — el motor es puro y no necesita
 * nada más.
 */

require_once __DIR__ . '/TaxEngine.php';

use Punto\Api\Tax\TaxEngine;

$fixturesPath = __DIR__ . '/fixtures.json';
$raw = file_get_contents($fixturesPath);
if ($raw === false) {
    fwrite(STDERR, "No se pudo leer fixtures.json en {$fixturesPath}\n");
    exit(1);
}

$cases = json_decode($raw, true);
if (!is_array($cases)) {
    fwrite(STDERR, "fixtures.json inválido: " . json_last_error_msg() . "\n");
    exit(1);
}

/**
 * Compara dos valores de forma exacta (tolerancia 0), tratando arrays
 * asociativos y listas de forma recursiva.
 */
function valuesMatch($expected, $actual): bool
{
    if (is_array($expected) && is_array($actual)) {
        if (count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                return false;
            }
            if (!valuesMatch($value, $actual[$key])) {
                return false;
            }
        }
        return true;
    }

    if (is_float($expected) || is_float($actual) || is_int($expected) || is_int($actual)) {
        return (float) $expected === (float) $actual;
    }

    return $expected === $actual;
}

function formatDiff($expected, $actual): string
{
    return '  esperado: ' . json_encode($expected) . "\n" .
           '  obtenido: ' . json_encode($actual) . "\n";
}

$total = count($cases);
$failed = 0;

foreach ($cases as $index => $case) {
    $description = $case['description'] ?? "caso #{$index}";
    $input = $case['input'];
    $expected = $case['expected'];

    $actual = TaxEngine::computeTaxes($input['lines'], $input['config']);

    if (valuesMatch($expected, $actual)) {
        echo "PASS  [{$index}] {$description}\n";
        continue;
    }

    $failed++;
    echo "FAIL  [{$index}] {$description}\n";
    echo formatDiff($expected, $actual);
}

$passed = $total - $failed;
echo "\n{$passed}/{$total} casos OK (PHP)\n";

exit($failed > 0 ? 1 : 0);

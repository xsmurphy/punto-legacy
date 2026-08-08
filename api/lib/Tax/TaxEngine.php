<?php

declare(strict_types=1);

namespace Punto\Api\Tax;

/**
 * TaxEngine — motor de cálculo de impuestos, espejo exacto de
 * frontend/lib/tax/engine-core.mjs.
 *
 * F1 del plan multi-país (context/38-impuestos-multi-pais.md, sección
 * "Arquitectura propuesta -> B"). Funciones puras, cero I/O, cero
 * dependencia de catálogo/BD/config global. Nadie consume este motor
 * todavía — el cableado a carrito/SaleService es F2, fuera de alcance.
 *
 * Los fixtures de paridad viven en fixtures.json (misma carpeta) y se
 * corren contra este archivo con verify_engine.php; el lado JS/TS corre los
 * MISMOS fixtures con frontend/lib/tax/verify-engine.mjs.
 */
final class TaxEngine
{
    /**
     * Redondeo half-up (away from zero) determinístico a `$decimals`
     * posiciones.
     *
     * Estrategia: PHP round() con el modo PHP_ROUND_HALF_UP. A diferencia de
     * un `round($v * $factor) / $factor` ingenuo, la implementación interna
     * de round() en PHP (desde 5.3) aplica una pre-corrección contra el
     * error de representación binaria de floats — el mismo problema que en
     * JS hace que `1.005 * 100` dé `100.49999999999999` en vez de `100.5`.
     * Por eso NO hace falta reimplementar manualmente el shift a mano ni
     * usar BCMath: round() nativo ya resuelve ese borde correctamente
     * (round(1.005, 2, PHP_ROUND_HALF_UP) === 1.01).
     *
     * Esto es la MISMA estrategia matemática que el lado JS/TS
     * (desplazar la coma decimal, redondear half-away-from-zero, volver a
     * desplazar) — cada lado usa la herramienta idiomática de su lenguaje
     * para lograrlo sin arrastrar el ruido binario: PHP vía round() nativo,
     * JS vía el truco de shift por string (ver comentario en
     * engine-core.mjs). PHP_ROUND_HALF_UP ya es away-from-zero nativo
     * (ej. round(-1.5, 0, PHP_ROUND_HALF_UP) === -2.0), coincidiendo con el
     * manejo explícito de signo que hace el lado JS.
     */
    public static function roundHalfUp(float $value, int $decimals): float
    {
        if (!is_finite($value)) {
            return $value;
        }
        if ($value === 0.0) {
            return 0.0;
        }
        return round($value, $decimals, PHP_ROUND_HALF_UP);
    }

    /**
     * Calcula impuestos para una lista de líneas de venta.
     *
     * @param array<int, array{qty: float, unitPrice: float, discount?: float, taxRate: float, taxKind: string, taxIncluded: bool}> $lines
     * @param array{decimals: int} $config
     * @return array{
     *   lines: list<array{net: float, tax: float, gross: float}>,
     *   byRate: list<array{taxRate: float, taxKind: string, base: float, tax: float, gross: float}>,
     *   totals: array{net: float, tax: float, gross: float}
     * }
     */
    public static function computeTaxes(array $lines, array $config): array
    {
        $decimals = $config['decimals'];

        $outLines = [];
        $bucketOrder = [];
        $buckets = [];

        $totalNet = 0.0;
        $totalTax = 0.0;
        $totalGross = 0.0;

        foreach ($lines as $line) {
            $qty = (float) $line['qty'];
            $unitPrice = (float) $line['unitPrice'];
            $discount = (float) ($line['discount'] ?? 0);
            $taxRate = (float) $line['taxRate'];
            $taxKind = $line['taxKind'];
            $taxIncluded = (bool) $line['taxIncluded'];

            if ($taxKind === 'exempt') {
                // Exento: impuesto 0 sin importar taxRate (kind es la
                // dimensión, no la tasa). La base entera va al bucket exento.
                $base = self::roundHalfUp($qty * $unitPrice - $discount, $decimals);
                $net = $base;
                $tax = 0.0;
                $gross = $base;
            } elseif ($taxIncluded) {
                // Precio final (impuesto incluido): el bruto de línea se
                // redondea primero a `$decimals`, el impuesto se calcula y
                // redondea sobre ese bruto ya normalizado, y el neto se
                // DERIVA por resta (gross - tax) para que net + tax = gross
                // cierre exacto por línea.
                $gross = self::roundHalfUp($qty * $unitPrice - $discount, $decimals);
                $tax = self::roundHalfUp(($gross * $taxRate) / (100 + $taxRate), $decimals);
                $net = self::roundHalfUp($gross - $tax, $decimals);
            } else {
                // Precio neto (impuesto añadido): análogo pero desde el
                // neto; el bruto se deriva por suma.
                $net = self::roundHalfUp($qty * $unitPrice - $discount, $decimals);
                $tax = self::roundHalfUp(($net * $taxRate) / 100, $decimals);
                $gross = self::roundHalfUp($net + $tax, $decimals);
            }

            $outLines[] = ['net' => $net, 'tax' => $tax, 'gross' => $gross];

            $key = $taxRate . '|' . $taxKind;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'taxRate' => $taxRate,
                    'taxKind' => $taxKind,
                    'base' => 0.0,
                    'tax' => 0.0,
                    'gross' => 0.0,
                ];
                $bucketOrder[] = $key;
            }
            $buckets[$key]['base'] += $net;
            $buckets[$key]['tax'] += $tax;
            $buckets[$key]['gross'] += $gross;

            $totalNet += $net;
            $totalTax += $tax;
            $totalGross += $gross;
        }

        // Limpieza de ruido de representación binaria en las sumas (ver
        // comentario equivalente en engine-core.mjs) — NO es recalcular el
        // impuesto sobre el agregado, solo vuelve a expresar la suma ya
        // correcta sin el ruido del punto flotante binario.
        $cleanSum = static fn (float $value): float => self::roundHalfUp($value, $decimals);

        $byRate = [];
        foreach ($bucketOrder as $key) {
            $bucket = $buckets[$key];
            $byRate[] = [
                'taxRate' => $bucket['taxRate'],
                'taxKind' => $bucket['taxKind'],
                'base' => $cleanSum($bucket['base']),
                'tax' => $cleanSum($bucket['tax']),
                'gross' => $cleanSum($bucket['gross']),
            ];
        }

        return [
            'lines' => $outLines,
            'byRate' => $byRate,
            'totals' => [
                'net' => $cleanSum($totalNet),
                'tax' => $cleanSum($totalTax),
                'gross' => $cleanSum($totalGross),
            ],
        ];
    }
}

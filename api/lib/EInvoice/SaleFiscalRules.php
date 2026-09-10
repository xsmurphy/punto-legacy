<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Reglas FISCALES de una venta que no dependen del motor que la emite.
 *
 * Todo lo que vive acá lo manda SIFEN, no el proveedor: cuántos decimales
 * admite una moneda, cuánto IVA lleva una línea, cómo se parte una línea para
 * que `cantidad × unitario` vuelva exactamente al total, qué tipo de
 * transacción declara una venta, y de dónde sale el código de seguridad del
 * CDC. Cambiar cualquiera de estas cosas cambia el documento que se firma.
 *
 * ── Por qué es una clase aparte ──────────────────────────────────────────
 *
 * Estos métodos nacieron dentro del mapper del PRIMER proveedor y el mapper
 * del segundo los llamaba por estático, o sea que el motor vigente dependía
 * del archivo del motor que se estaba retirando. Al quedar un solo motor, ese
 * archivo se va — y con él se habría ido la aritmética fiscal, que no tiene
 * nada de específico de ningún proveedor.
 *
 * La regla, para el día que haya un segundo motor: si el método responde "qué
 * exige SIFEN", va acá; si responde "cómo lo espera ESTE proveedor" (nombres
 * de campos, códigos propios, shape del payload), va en su mapper.
 */
final class SaleFiscalRules
{
    /** Tipos de transacción de SIFEN (D011). */
    public const TRANSACTION_TYPE_MERCADERIA = 1;
    public const TRANSACTION_TYPE_SERVICIOS  = 2;
    public const TRANSACTION_TYPE_MIXTO      = 3;

    /**
     * Código de seguridad del CDC. Se CONGELA en la venta la primera vez y de
     * ahí en más se reusa: forma parte del CDC, y un reintento con otro código
     * sobre un documento que el motor ya creó es un duplicado ante SIFEN
     * (rechazo 1002). Una reemisión es un documento nuevo, con fila nueva, y
     * por lo tanto código nuevo — eso es correcto y sale solo.
     *
     * Generador único: `Cdc::securityCode()` (CSPRNG, ver ahí el porqué).
     *
     * @param array<string,mixed> $sale
     */
    public static function resolveSecurityCode(array $sale): string
    {
        $frozen = trim((string) ($sale['securityCode'] ?? ''));
        // Se acepta solo si es lo que el CDC espera: 9 dígitos exactos. Un
        // valor corrupto se descarta en vez de viajar — del otro lado se
        // parsea numéricamente y el error no nombra el campo que falló.
        if (preg_match('/^\d{9}$/', $frozen) === 1) {
            return $frozen;
        }

        return Cdc::securityCode();
    }

    /**
     * `transactionType` a partir de lo que la venta tiene adentro:
     * mercadería, servicios, o las dos cosas (MIXTO).
     *
     * Cada línea llega con `isService` (bool) desde el caller, que es quien
     * conoce el `kind` del ítem de Punto. Una línea sin el dato cuenta como
     * mercadería: es lo que es la enorme mayoría del catálogo, y el default
     * anterior —servicios para TODO— era el que estaba mal.
     *
     * @param array<int,mixed> $items
     */
    public static function resolveTransactionType(array $items): int
    {
        $hasService = false;
        $hasGoods   = false;
        foreach ($items as $item) {
            if (!empty(((array) $item)['isService'])) {
                $hasService = true;
            } else {
                $hasGoods = true;
            }
        }

        if ($hasService && $hasGoods) {
            return self::TRANSACTION_TYPE_MIXTO;
        }

        return $hasService ? self::TRANSACTION_TYPE_SERVICIOS : self::TRANSACTION_TYPE_MERCADERIA;
    }

    /**
     * Decimales de una moneda ISO 4217. La regla es de la MONEDA, no del país
     * del comercio: PYG, CLP, JPY, KRW, VND y compañía no tienen parte
     * decimal, así que su unitario tiene que ser entero; el resto usa 2.
     */
    public static function currencyDecimals(string $currency): int
    {
        static $zeroDecimal = [
            'PYG' => true, 'CLP' => true, 'JPY' => true, 'KRW' => true,
            'VND' => true, 'ISK' => true, 'COP' => true, 'UGX' => true,
            'RWF' => true, 'XAF' => true, 'XOF' => true, 'XPF' => true,
        ];

        return isset($zeroDecimal[strtoupper($currency)]) ? 0 : 2;
    }

    /**
     * IVA de una línea: taxRate/(100+taxRate) * total, redondeado POR LÍNEA
     * (no al final) para que la suma cierre igual que como SIFEN la deriva de
     * la tasa y la proporción gravada. taxRate=0 (exenta) da 0 sin dividir.
     *
     * @param array<string,mixed> $item
     */
    public static function lineTax(array $item, int $index): float
    {
        $taxRate = self::assertTaxRate($item, $index);
        if ($taxRate <= 0) {
            return 0.0;
        }
        $total = (float) ($item['total'] ?? ((float) ($item['unitPrice'] ?? 0) * (float) ($item['quantity'] ?? 0)));

        return round($total * $taxRate / (100 + $taxRate));
    }

    /**
     * @param array<string,mixed> $item
     * @return int 10, 5 o 0
     */
    public static function assertTaxRate(array $item, int $index): int
    {
        $taxRate = (int) ($item['taxRate'] ?? 10);
        if (!in_array($taxRate, [10, 5, 0], true)) {
            throw new \RuntimeException("Item #$index tiene taxRate inválido ($taxRate) — solo se admite 10, 5 o 0.");
        }

        return $taxRate;
    }

    /**
     * Convierte UNA línea de la venta en las líneas que van al documento,
     * garantizando que `Σ(quantity × unitPrice)` dé exactamente el total de la
     * línea en los decimales de la moneda.
     *
     * ── El problema ──────────────────────────────────────────────────
     *
     * El payload NO lleva un total por ítem: SIFEN lo recalcula como
     * `quantity × unitPrice`. Con un unitario redondeado a los decimales de la
     * moneda esa multiplicación no vuelve al total: 10.000 en 3 unidades da
     * 3.333,33 y 3 × 3.333 = 9.999. Una unidad de moneda de diferencia entre
     * lo que el documento declara y lo que se cobró.
     *
     * ── La solución ──────────────────────────────────────────────────
     *
     * Cuando la división NO es exacta, la línea se parte en dos: (qty-1)
     * unidades al unitario redondeado hacia abajo y 1 unidad que absorbe el
     * resto. 2 × 3.333 + 1 × 3.334 = 10.000, exacto, y cada unitario sigue
     * siendo un entero declarable. El comprobante muestra dos renglones del
     * mismo producto, que es el costo aceptado de que el fisco recalcule
     * multiplicando.
     *
     * La partición solo aplica con cantidad ENTERA ≥ 2. Con cantidad
     * fraccionaria (2,5 kg) no hay "una unidad" que separar, así que se
     * declara el unitario con la precisión necesaria y el guard del mapper
     * —tolerancia de 1 unidad de moneda— absorbe el resto. No se inventa una
     * partición por peso: cambiaría lo que dice el comprobante sobre lo que se
     * entregó.
     *
     * Idempotente respecto del caso feliz: si la división es exacta (el caso de
     * lejos más común, un precio de lista por una cantidad entera) devuelve la
     * línea tal cual, sin partir nada.
     *
     * @param array<string,mixed> $item
     * @return array<int,array<string,mixed>> Una o dos líneas.
     */
    public static function fiscalLines(array $item, int $decimals): array
    {
        $quantity  = (float) ($item['quantity'] ?? 0);
        $unitPrice = (float) ($item['unitPrice'] ?? 0);
        $total     = (float) ($item['total'] ?? ($unitPrice * $quantity));

        if ($quantity <= 0) {
            return [$item]; // El mapper ya filtró estos casos; defensivo.
        }

        $exactUnit = round($total / $quantity, $decimals);
        if (self::sameMoney($exactUnit * $quantity, $total, $decimals)) {
            // La división cierra: se declara el unitario en la precisión de la
            // moneda (no el de 8 decimales que venía del caller).
            $item['unitPrice'] = $exactUnit;
            return [$item];
        }

        $isWholeQty = abs($quantity - round($quantity)) < 1e-9;
        if (!$isWholeQty || $quantity < 2) {
            $item['unitPrice'] = $exactUnit;
            return [$item];
        }

        $wholeQty = (int) round($quantity);
        $step     = 10 ** -$decimals;
        // floor a la precisión de la moneda: el resto queda SIEMPRE positivo y
        // se acumula en la última unidad, nunca al revés.
        $baseUnit = floor($total / $wholeQty / $step) * $step;
        $baseUnit = round($baseUnit, $decimals);

        // Caso degenerado: el total no alcanza a una unidad de moneda por
        // unidad vendida (1 repartido en 3). Partir daría renglones con precio
        // unitario CERO, que es peor que la diferencia de redondeo — se declara
        // una sola línea y el guard del mapper decide si pasa.
        if ($baseUnit <= 0) {
            $item['unitPrice'] = $exactUnit;
            return [$item];
        }

        $lastUnit = round($total - $baseUnit * ($wholeQty - 1), $decimals);

        $head = $item;
        $head['quantity']  = $wholeQty - 1;
        $head['unitPrice'] = $baseUnit;
        $head['total']     = round($baseUnit * ($wholeQty - 1), $decimals);

        $tail = $item;
        $tail['quantity']  = 1;
        $tail['unitPrice'] = $lastUnit;
        $tail['total']     = $lastUnit;

        return [$head, $tail];
    }

    private static function sameMoney(float $a, float $b, int $decimals): bool
    {
        return abs(round($a, $decimals) - round($b, $decimals)) < (10 ** -($decimals + 3));
    }
}

<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Helper compartido: cuantifica ventas que NO suman al total (gift card / store credit /
 * puntos / ventas internas) en un período. Reproduce `getNonAddingToSales()` del panel
 * (no existe en /app) más los helpers de los que depende — porque sus versiones de /app
 * están latente rotas en PG (USE INDEX MySQL, columna `tags` literal en vez de
 * `meta->>'tags'`, semántica de discount distinta).
 *
 * Helpers globales que SÍ existen en /app y son compatibles:
 *   - isInternalSale, isParentInternalSale, groupByPaymentMethod → resuelven por fallback.
 *   - $_fullSettings → poblado por `app/data.php` (lo carga apiAuthTenant antes del Service).
 *
 * Helpers SOLO en panel (port inline):
 *   - getSalesByPayment (firma 6-arg con su propio cálculo de $roc adentro)
 *   - lessInternalTotals (resta `transactionDiscount` al sumar — distinto a la /app)
 *   - getPreviousPeriod (mismo intervalo, desplazado atrás)
 *
 * Usado por: SummaryYearService (batch 7), próximamente SalesService.
 */
final class NonAddingSales
{
    /**
     * @param string $from       Y-m-d H:i:s
     * @param string $to         Y-m-d H:i:s
     * @param string $roc        fragmento de companyId/outletId — del endpoint vía Roc::build()
     * @param bool   $backThen   si true, agrega totales del período anterior con prefijo B
     * @param int    $cache      ttl (0 = sin cache)
     * @param HourBand $hours    franja horaria (F1 de context/67); vacía = sin filtro.
     *                           Se aplica también al período anterior de `$backThen`: comparar
     *                           una franja contra el día completo del período previo daría una
     *                           variación inventada.
     * @return array  ['total','totalGiftCards','totalGiftCredit','totalPoints', opcional 'totalB','*B']
     */
    public function compute(string $from, string $to, string $roc, bool $backThen = false, int $cache = 0, HourBand $hours = new HourBand()): array
    {
        $cur = $this->summarize($from, $to, $roc, $cache, $hours);
        $out = [
            'total'           => $cur['gift'] + $cur['credit'] + $cur['points'] + $cur['internal'],
            'totalGiftCards'  => $cur['gift'],
            'totalGiftCredit' => $cur['credit'],
            'totalPoints'     => $cur['points'],
        ];

        if ($backThen) {
            [$fromB, $toB] = self::previousPeriod($from, $to);
            $prev = $this->summarize($fromB, $toB, $roc, $cache, $hours);
            $out['totalB']           = $prev['gift'] + $prev['credit'] + $prev['points'] + $prev['internal'];
            $out['totalGiftCardsB']  = $prev['gift'];
            $out['totalGiftCreditB'] = $prev['credit'];
            $out['totalPointsB']     = $prev['points'];
        }

        return $out;
    }

    /** Devuelve [gift, credit, points, internal] para un período (usado por compute y backThen). */
    private function summarize(string $from, string $to, string $roc, int $cache, HourBand $hours = new HourBand()): array
    {
        $pmnts = self::salesByPayment($from, $to, $roc, $cache, $hours);
        $gift = $credit = $points = 0.0;
        foreach ($pmnts as $m) {
            $type = $m['type'] ?? '';
            $price = (float) ($m['price'] ?? 0);
            if ($type === 'giftcard')         { $gift   += $price; }
            elseif ($type === 'storeCredit')  { $credit += $price; }
            elseif ($type === 'points')       { $points += $price; }
        }
        $internal = self::lessInternalTotals($roc, $from, $to, false, $hours);
        return ['gift' => $gift, 'credit' => $credit, 'points' => $points, 'internal' => (float) ($internal['total'] ?? 0)];
    }

    /**
     * Port fiel de getSalesByPayment del panel (firma reducida: el `$regId` del original
     * se ignoraba en la práctica porque la función recalculaba `$roc` adentro). Tipo 0,5
     * + meta->>'tags' (Phase PG). Devuelve la lista agrupada por groupByPaymentMethod.
     *
     * Público porque también lo usa SalesService::summary (batch 8). En /api NO se puede
     * llamar al global `getSalesByPayment()` porque resuelve a la versión de /app (firma
     * 3-arg con registerId, que para el panel siempre llega vacío → query sin matches).
     */
    public static function salesByPayment(string $from, string $to, string $roc, int $cache = 0, HourBand $hours = new HourBand()): array
    {
        $group = [];
        self::eachPaidSale($from, $to, $roc, $cache, $hours, static function (array $methods) use (&$group): void {
            $group = groupByPaymentMethod($methods, $group);
        });
        return $group;
    }

    /**
     * Lo mismo que `salesByPayment()`, repartido por sucursal:
     * `[outletId => grupo de medios]`, con el mismo shape de grupo. Misma
     * lectura y misma regla (`eachPaidSale()`), así que la suma de las
     * sucursales da el total del reporte de medios de pago y del dashboard.
     *
     * @return array<string, array<int|string, array<string,mixed>>>
     */
    public static function salesByPaymentByOutlet(string $from, string $to, string $roc): array
    {
        $byOutlet = [];
        self::eachPaidSale($from, $to, $roc, 0, new HourBand(), static function (array $methods, string $outletId) use (&$byOutlet): void {
            $byOutlet[$outletId] = groupByPaymentMethod($methods, $byOutlet[$outletId] ?? []);
        });
        return $byOutlet;
    }

    /**
     * Recorre los cobros del período (ventas de contado tipo 0 y pagos de
     * crédito tipo 5, no anulados) y le pasa a `$each` los medios de pago de
     * cada uno que NO sea una venta interna (ni el pago de una). Única lectura
     * de las dos agregaciones de arriba.
     *
     * @param callable(array<int,array<string,mixed>>, string):void $each  ($methods, $outletId)
     */
    private static function eachPaidSale(string $from, string $to, string $roc, int $cache, HourBand $hours, callable $each): void
    {
        if ($from === '') { return; }

        if ($to !== '') {
            $where = "transactionDate >= ? AND transactionDate <= ?";
            $args  = [$from, $to];
        } else {
            $where = "transactionDate > ?";
            $args  = [$from];
        }

        // La franja va al FINAL del WHERE y sus binds al final de `$args`: es el
        // único orden que vale para las dos ramas de arriba, que no bindean la
        // misma cantidad de extremos.
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        $sql = "SELECT transactionId, transactionPaymentType, transactionType, meta->>'tags' AS tags,
                       outletId AS \"outletId\"
                FROM transaction
                WHERE " . $where . " AND transactionType IN (0,5)
                AND " . SaleFilters::notVoidedSql() . $roc . $hourSql;

        $result = ncmExecute($sql, array_merge($args, $hourParams), $cache, true);
        if (!$result) {
            return;
        }

        $rows = [];
        while (!$result->EOF) {
            $rows[] = $result->fields;
            $result->MoveNext();
        }
        $result->Close();

        // mig 115: transactionParentId dropeada — batch lookup del origen de
        // los pagos (type 5) vía transaction_link, sin N+1. companyId sale de
        // $roc (Roc::build lo embebe como literal validado — mismo dato que
        // ya viaja acá, sin threadear el parámetro por los ~6 callers de
        // compute()/salesByPayment()).
        $companyId = self::companyIdFromRoc($roc);
        $paymentIds = array_values(array_filter(array_map(
            static fn($f) => (int) $f['transactionType'] === 5 ? (string) $f['transactionId'] : null,
            $rows
        )));
        $originByPayment = ($paymentIds !== [] && $companyId !== '')
            ? (new \Punto\Api\Services\TransactionLinkService())->mapOriginIdByDerivedIds($companyId, $paymentIds, 'credit_payment')
            : [];

        foreach ($rows as $f) {
            $methods = json_decode((string) ($f['transactionPaymentType'] ?? ''), true);

            if ((int) $f['transactionType'] === 5) {
                $parentId = $originByPayment[(string) $f['transactionId']] ?? null;
                $ignore   = $parentId ? isParentInternalSale($parentId) : false;
            } else {
                $tags   = json_decode((string) ($f['tags'] ?? ''), true);
                $ignore = isInternalSale($tags);
            }

            if (is_array($methods) && $methods && !$ignore) {
                $each($methods, (string) ($f['outletId'] ?? $f['outletid'] ?? ''));
            }
        }
    }

    /**
     * Extrae companyId del fragmento `$roc` (Roc::build() lo embebe como
     * literal validado: `AND companyId = 'uuid'`). Evita threadear companyId
     * como parámetro nuevo por los ~6 callers de compute()/salesByPayment().
     */
    private static function companyIdFromRoc(string $roc): string
    {
        return preg_match("/companyId\\s*=\\s*'([0-9a-f-]{36})'/i", $roc, $m) === 1 ? $m[1] : '';
    }

    /**
     * Port fiel de lessInternalTotals del panel (versión PG-correcta: meta->>'tags', sin
     * USE INDEX, ints parametrizados). La de /app está rota en PG: no se usa.
     *
     * Público porque también lo usa ProductsService::internals (batch 14).
     */
    public static function lessInternalTotals(string $roc, string $from, string $to, $tTypes = false, HourBand $hours = new HourBand()): array
    {
        global $_fullSettings;

        if (empty($_fullSettings['ignoreInternal']) || !$_fullSettings['ignoreInternal']) {
            return ['total' => 0, 'discount' => 0, 'tax' => 0, 'qty' => 0, 'count' => 0];
        }

        $total = $discount = $tax = $qty = 0.0;
        $count = 0;
        self::eachInternalSale($roc, $from, $to, $tTypes, $hours, static function (array $f) use (&$total, &$discount, &$tax, &$qty, &$count): void {
            $total    += (float) $f['transactionTotal'] - (float) $f['transactionDiscount'];
            $discount += (float) $f['transactionDiscount'];
            $tax      += (float) $f['transactionTax'];
            $qty      += (float) $f['transactionUnitsSold'];
            $count++;
        });

        return ['total' => $total, 'discount' => $discount, 'tax' => $tax, 'qty' => $qty, 'count' => (float) $count];
    }

    /**
     * Lo mismo que `lessInternalTotals()['total']`, repartido por sucursal:
     * `[outletId => total interno]`. Es lo que permite que "Ventas por
     * sucursal" del dashboard reste las ventas internas igual que el KPI de
     * Ingresos, y que la suma de las sucursales cierre con ese KPI.
     *
     * Misma consulta y misma regla que el total (`eachInternalSale()`), no una
     * segunda copia del criterio de "interna".
     *
     * @return array<string, float>
     */
    public static function internalTotalsByOutlet(string $roc, string $from, string $to): array
    {
        global $_fullSettings;

        if (empty($_fullSettings['ignoreInternal']) || !$_fullSettings['ignoreInternal']) {
            return [];
        }

        $out = [];
        self::eachInternalSale($roc, $from, $to, false, new HourBand(), static function (array $f) use (&$out): void {
            $oid        = (string) ($f['outletId'] ?? '');
            $out[$oid]  = ($out[$oid] ?? 0.0) + (float) $f['transactionTotal'] - (float) $f['transactionDiscount'];
        });
        return $out;
    }

    /**
     * Ventas internas por sucursal Y por bucket de tiempo:
     * `[outletId => [bucket => total interno]]`. Es lo que permite que la
     * serie por sucursal del reporte de Sucursales reste las internas igual
     * que el KPI, y que la suma de sus puntos cierre con la fila de la tabla.
     *
     * @return array<string, array<string, float>>
     */
    public static function internalTotalsByOutletAndBucket(string $roc, string $from, string $to, \Punto\Api\Support\TimeBuckets $tb): array
    {
        global $_fullSettings;

        if (empty($_fullSettings['ignoreInternal']) || !$_fullSettings['ignoreInternal']) {
            return [];
        }

        $out = [];
        self::eachInternalSale($roc, $from, $to, false, new HourBand(), static function (array $f) use (&$out, $tb): void {
            $oid = (string) ($f['outletId'] ?? '');
            $key = $tb->keyFor((string) ($f['transactionDate'] ?? ''));
            $out[$oid][$key] = ($out[$oid][$key] ?? 0.0) + (float) $f['transactionTotal'] - (float) $f['transactionDiscount'];
        });
        return $out;
    }

    /**
     * Recorre las ventas INTERNAS del período (tag interno, `isInternalSale`) y
     * le pasa cada una a `$each`. Única lectura de las dos agregaciones de
     * arriba.
     *
     * @param callable(array<string,mixed>):void $each
     */
    private static function eachInternalSale(string $roc, string $from, string $to, $tTypes, HourBand $hours, callable $each): void
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $tTypes)), fn($v) => $v !== '');
        $types = $parts ? array_map('intval', $parts) : [0, 3];
        $ph    = implode(',', array_fill(0, count($types), '?'));

        // Los tipos se bindean DESPUÉS del rango, así que la franja tiene que ir
        // después de los dos: el orden de `array_merge` sigue al de los `?` en el
        // SQL, y el fragmento se concatena al final del WHERE (antes del LIMIT).
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        $result = ncmExecute(
            "SELECT transactionTotal, meta->>'tags' AS tags, transactionDiscount, transactionUnitsSold, transactionTax,
                    outletId AS \"outletId\", transactionDate AS \"transactionDate\"
             FROM transaction
             WHERE transactionDate BETWEEN ? AND ? AND transactionType IN (" . $ph . ")
             AND " . SaleFilters::notVoidedSql() . $roc . $hourSql . " LIMIT 5000",
            array_merge([$from, $to], $types, $hourParams), 1200, true
        );

        if ($result) {
            while (!$result->EOF) {
                $f    = $result->fields;
                $tags = json_decode((string) ($f['tags'] ?? ''), true);
                if (isInternalSale($tags)) {
                    $each([
                        'transactionTotal'     => $f['transactionTotal'],
                        'transactionDiscount'  => $f['transactionDiscount'],
                        'transactionTax'       => $f['transactionTax'],
                        'transactionUnitsSold' => $f['transactionUnitsSold'],
                        'outletId'             => $f['outletId'] ?? $f['outletid'] ?? '',
                        'transactionDate'      => $f['transactionDate'] ?? $f['transactiondate'] ?? '',
                    ]);
                }
                $result->MoveNext();
            }
            $result->Close();
        }
    }

    /**
     * Mismo intervalo desplazado hacia atrás. Delega en
     * `Date::previousRange()`, la definición única del período anterior: el
     * port del legacy formateaba con `H:i:00` y dejaba afuera el último minuto
     * del período (ver el docblock de ese helper).
     *
     * Público porque también lo usa ProductsService::general (batch 14).
     */
    public static function previousPeriod(string $start, string $end): array
    {
        return \Punto\App\Helpers\Date::previousRange($start, $end);
    }
}

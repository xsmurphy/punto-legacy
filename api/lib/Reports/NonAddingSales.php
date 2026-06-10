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
     * @return array  ['total','totalGiftCards','totalGiftCredit','totalPoints', opcional 'totalB','*B']
     */
    public function compute(string $from, string $to, string $roc, bool $backThen = false, int $cache = 0): array
    {
        $cur = $this->summarize($from, $to, $roc, $cache);
        $out = [
            'total'           => $cur['gift'] + $cur['credit'] + $cur['points'] + $cur['internal'],
            'totalGiftCards'  => $cur['gift'],
            'totalGiftCredit' => $cur['credit'],
            'totalPoints'     => $cur['points'],
        ];

        if ($backThen) {
            [$fromB, $toB] = self::previousPeriod($from, $to);
            $prev = $this->summarize($fromB, $toB, $roc, $cache);
            $out['totalB']           = $prev['gift'] + $prev['credit'] + $prev['points'] + $prev['internal'];
            $out['totalGiftCardsB']  = $prev['gift'];
            $out['totalGiftCreditB'] = $prev['credit'];
            $out['totalPointsB']     = $prev['points'];
        }

        return $out;
    }

    /** Devuelve [gift, credit, points, internal] para un período (usado por compute y backThen). */
    private function summarize(string $from, string $to, string $roc, int $cache): array
    {
        $pmnts = self::salesByPayment($from, $to, $roc, $cache);
        $gift = $credit = $points = 0.0;
        foreach ($pmnts as $m) {
            $type = $m['type'] ?? '';
            $price = (float) ($m['price'] ?? 0);
            if ($type === 'giftcard')         { $gift   += $price; }
            elseif ($type === 'storeCredit')  { $credit += $price; }
            elseif ($type === 'points')       { $points += $price; }
        }
        $internal = self::lessInternalTotals($roc, $from, $to);
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
    public static function salesByPayment(string $from, string $to, string $roc, int $cache = 0): array
    {
        if ($from === '') { return []; }

        if ($to !== '') {
            $where = "transactionDate >= ? AND transactionDate <= ?";
            $args  = [$from, $to];
        } else {
            $where = "transactionDate > ?";
            $args  = [$from];
        }

        $sql = "SELECT transactionPaymentType, transactionType, transactionParentId, meta->>'tags' AS tags
                FROM transaction
                WHERE " . $where . " AND transactionType IN (0,5)" . $roc;

        $result = ncmExecute($sql, $args, $cache, true);
        if (!$result) {
            return [];
        }

        $group = [];
        while (!$result->EOF) {
            $f       = $result->fields;
            $methods = json_decode((string) ($f['transactionPaymentType'] ?? ''), true);

            if ((int) $f['transactionType'] === 5) {
                $ignore = isParentInternalSale($f['transactionParentId']);
            } else {
                $tags   = json_decode((string) ($f['tags'] ?? ''), true);
                $ignore = isInternalSale($tags);
            }

            if (is_array($methods) && $methods && !$ignore) {
                $group = groupByPaymentMethod($methods, $group);
            }
            $result->MoveNext();
        }
        $result->Close();
        return $group;
    }

    /**
     * Port fiel de lessInternalTotals del panel (versión PG-correcta: meta->>'tags', sin
     * USE INDEX, ints parametrizados). La de /app está rota en PG: no se usa.
     *
     * Público porque también lo usa ProductsService::internals (batch 14).
     */
    public static function lessInternalTotals(string $roc, string $from, string $to, $tTypes = false): array
    {
        global $_fullSettings;

        if (empty($_fullSettings['ignoreInternal']) || !$_fullSettings['ignoreInternal']) {
            return ['total' => 0, 'discount' => 0, 'tax' => 0, 'qty' => 0, 'count' => 0];
        }

        $parts = array_filter(array_map('trim', explode(',', (string) $tTypes)), fn($v) => $v !== '');
        $types = $parts ? array_map('intval', $parts) : [0, 3];
        $ph    = implode(',', array_fill(0, count($types), '?'));

        $result = ncmExecute(
            "SELECT transactionTotal, meta->>'tags' AS tags, transactionDiscount, transactionUnitsSold, transactionTax
             FROM transaction
             WHERE transactionDate BETWEEN ? AND ? AND transactionType IN (" . $ph . ")" . $roc . " LIMIT 5000",
            array_merge([$from, $to], $types), 1200, true
        );

        $total = $discount = $tax = $qty = 0.0;
        $count = 0;
        if ($result) {
            while (!$result->EOF) {
                $f    = $result->fields;
                $tags = json_decode((string) ($f['tags'] ?? ''), true);
                if (isInternalSale($tags)) {
                    $total    += (float) $f['transactionTotal'] - (float) $f['transactionDiscount'];
                    $discount += (float) $f['transactionDiscount'];
                    $tax      += (float) $f['transactionTax'];
                    $qty      += (float) $f['transactionUnitsSold'];
                    $count++;
                }
                $result->MoveNext();
            }
            $result->Close();
        }

        return ['total' => $total, 'discount' => $discount, 'tax' => $tax, 'qty' => $qty, 'count' => (float) $count];
    }

    /**
     * Port fiel de getPreviousPeriod del panel: mismo intervalo desplazado hacia atrás.
     *
     * Público porque también lo usa ProductsService::general (batch 14).
     */
    public static function previousPeriod(string $start, string $end): array
    {
        $startF    = strtotime($start);
        $endF      = strtotime($end);
        $diference = ($endF - $startF) + 1;
        return [
            date('Y-m-d H:i:00', $startF - $diference),
            date('Y-m-d H:i:00', $endF   - $diference),
        ];
    }
}

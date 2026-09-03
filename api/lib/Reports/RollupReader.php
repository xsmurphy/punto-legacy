<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

// Mismo motivo que en `Roc.php`: el autoloader de `bootstrap.php` resuelve
// `Punto\Api\Outlets\OutletScope`, pero este lector también lo alcanzan arneses
// y scripts CLI que no pasan por el bootstrap.
require_once __DIR__ . '/../Outlets/OutletScope.php';

use Punto\Api\Outlets\OutletScope;

/**
 * RollupReader — lectura del rollup pre-agregado para reportes.
 *
 * D8 de context/48-escalamiento-de-datos.md (mig 160): 'sales'/'item_sales'/
 * 'payments' migraron de `report_rollup` (grano genérico day/month/year) a
 * tres tablas TIPADAS de grano día único (`rollup_sales_day`,
 * `rollup_item_sales_day`, `rollup_payments_day`) — mes/año se derivan acá
 * con SUM sobre `day`, no se almacenan más. 'expenses' sigue en
 * `report_rollup` (E2 la migra). 'returns'/'item_returns' fueron ABSORBIDOS:
 * ya no son dominios propios, viven como kind='devolucion' dentro de
 * 'sales'/'item_sales' — con signo negativo, restan solas al sumar.
 *
 * Firmas públicas SIN CAMBIOS respecto a la versión pre-D8 — los callers
 * (SummaryYearService, CategoriesService, BrandsService, PaymentMethodsService)
 * no se tocan.
 *
 * ── El alcance por sucursal es una LISTA (2026-09-02) ────────────────────────
 * Antes cada lector recibía `?string $outletId` y el endpoint cortaba con 422
 * cuando el usuario alcanzaba un SUBCONJUNTO de 2 o más sucursales, porque ese
 * estado no entra en un valor único. Para el panel eso no era fallar seguro: la
 * persona eligió "Todas" y esperaba sus números. Estas tres consultas ya hacen
 * `GROUP BY` y suman, así que el subconjunto sale gratis con un `IN (...)` — lo
 * único que faltaba era poder recibirlo.
 *
 * El fragmento lo arma `OutletScope::sqlFilter()`, que INTERPOLA los uuids en
 * vez de bindearlos (ver su docblock): así el `$params` que cada método arma a
 * mano no cambia de largo según cuántas sucursales haya, y no hay forma de
 * correr los binds sin darse cuenta.
 */
final class RollupReader
{
    /**
     * @param list<string> $outletIds Alcance por sucursal; `[]` = sin filtro.
     * @return array<int, array{cnt:int,total:float,tax:float,discount:float,qty:float}>
     *         map month(1-12) => métricas. domain: 'sales'|'expenses'|'returns'.
     */
    public function monthlyBuckets(string $companyId, string $domain, int $year, array $outletIds): array
    {
        if ($domain === 'expenses') {
            return $this->monthlyBucketsFromReportRollup($companyId, $domain, $year, $outletIds);
        }
        if ($domain !== 'sales' && $domain !== 'returns') {
            return [];
        }

        $from = sprintf('%04d-01-01', $year);
        $to   = sprintf('%04d-12-31', $year);

        // 'sales': solo contado/crédito vigentes — mismo scope que el
        // dominio 'sales' viejo (transactionType IN (0,3), sin anuladas).
        // 'returns': kind=devolucion SIN filtro de status — el dominio
        // 'returns' viejo (mig 41/155) tampoco filtraba voidedat para
        // transactionType=6, se preserva la paridad exacta.
        $kindFilter = $domain === 'sales'
            ? "kind IN ('contado', 'credito') AND status = 'vigente'"
            : "kind = 'devolucion'";

        // Interpolado, no bindeado: `$params` queda con los mismos 3 binds
        // (companyid + los dos extremos del rango) tenga el alcance 0, 1 o N
        // sucursales.
        $params    = [$companyId, $from, $to];
        $outletSql = OutletScope::sqlFilter('outletid', $outletIds);

        $rs = ncmExecute(
            // tax = taxtotal (SUM(itemsoldtax) sin filtrar por tasa), NO
            // tax10+tax5: ese par es el DESGLOSE fiscal PY y pierde en
            // silencio cualquier línea con otra tasa (tenant de otro país,
            // tasa nueva, o una tasa reconstruida por el backfill que no
            // cayó en un bucket). El reporte de "impuesto del mes" tiene que
            // ser el total, no la suma de dos buckets.
            "SELECT EXTRACT(MONTH FROM day)::int AS month,
                    COALESCE(SUM(cnt), 0)          AS cnt,
                    COALESCE(SUM(net), 0)          AS net,
                    COALESCE(SUM(taxtotal), 0)     AS tax,
                    COALESCE(SUM(discount), 0)     AS discount,
                    COALESCE(SUM(units), 0)        AS units
             FROM rollup_sales_day
             WHERE companyid = ?
               AND day BETWEEN ?::date AND ?::date
               AND {$kindFilter}{$outletSql}
             GROUP BY month",
            $params,
            false,
            true
        );

        $map = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f   = $rs->fields;
                $mon = (int) $f['month'];
                if ($domain === 'sales') {
                    $map[$mon] = [
                        'cnt'      => (int)   ($f['cnt']      ?? 0),
                        'total'    => (float) ($f['net']      ?? 0),
                        'tax'      => (float) ($f['tax']      ?? 0),
                        'discount' => (float) ($f['discount'] ?? 0),
                        'qty'      => (float) ($f['units']    ?? 0),
                    ];
                } else {
                    // 'returns' viejo (mig 41/155) solo poblaba cnt+total
                    // (magnitud positiva, SUM(ABS(transactionTotal))) — tax/
                    // discount/qty nunca se calcularon para este dominio,
                    // se preservan en 0 para no inventar un dato que no
                    // existía. net de una devolución siempre es negativo
                    // (ReturnService lo escribe así) — ABS(SUM(net)) da la
                    // misma magnitud que antes.
                    $map[$mon] = [
                        'cnt'      => (int) ($f['cnt'] ?? 0),
                        'total'    => abs((float) ($f['net'] ?? 0)),
                        'tax'      => 0.0,
                        'discount' => 0.0,
                        'qty'      => 0.0,
                    ];
                }
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $map;
    }

    /**
     * 'expenses' — sigue en report_rollup (E2 la migra).
     *
     * ── Acá el subconjunto NO puede reusar ninguna de las dos ramas viejas ───
     * `report_rollup` guarda, por mes, UNA fila por sucursal (`GROUP BY
     * outletid` en la mig 155) MÁS una fila con `outletid IS NULL` que ya trae
     * el total del tenant pre-sumado. Por eso:
     *
     *   - sin filtro → la fila NULL, que es exactamente ese total;
     *   - una sucursal → su fila;
     *   - un subconjunto → NINGUNA fila lo representa. Leer la NULL daría el
     *     tenant COMPLETO (más sucursales de las que el usuario alcanza) y leer
     *     una sola daría de menos. Hay que sumar las filas de esas sucursales,
     *     que es lo que hace la tercera rama.
     *
     * `qty` viene NULL para este dominio (la mig 155 no lo escribe), así que el
     * `COALESCE` de la suma reproduce el 0 que ya devolvían las otras dos ramas.
     *
     * @param list<string> $outletIds `[]` = sin filtro.
     */
    private function monthlyBucketsFromReportRollup(string $companyId, string $domain, int $year, array $outletIds): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to   = sprintf('%04d-12-31', $year);

        if (count($outletIds) > 1) {
            // El fragmento interpola los uuids, así que los 4 binds siguen
            // siendo los mismos que en las otras dos ramas.
            $rs = ncmExecute(
                "SELECT EXTRACT(MONTH FROM periodStart)::int AS month,
                        COALESCE(SUM(cnt), 0)      AS cnt,
                        COALESCE(SUM(total), 0)    AS total,
                        COALESCE(SUM(tax), 0)      AS tax,
                        COALESCE(SUM(discount), 0) AS discount,
                        COALESCE(SUM(qty), 0)      AS qty
                 FROM report_rollup
                 WHERE companyId = ?
                   AND domain = ?
                   AND periodType = 'month'
                   AND periodStart BETWEEN ?::date AND ?::date"
                   . OutletScope::sqlFilter('outletId', $outletIds) . "
                 GROUP BY month",
                [$companyId, $domain, $from, $to],
                false,
                true
            );
        } elseif ($outletIds !== []) {
            $rs = ncmExecute(
                "SELECT EXTRACT(MONTH FROM periodStart)::int AS month,
                        cnt, total, tax, discount, qty
                 FROM report_rollup
                 WHERE companyId = ?
                   AND domain = ?
                   AND periodType = 'month'
                   AND periodStart BETWEEN ?::date AND ?::date
                   AND outletId = ?",
                [$companyId, $domain, $from, $to, $outletIds[0]],
                false,
                true
            );
        } else {
            $rs = ncmExecute(
                "SELECT EXTRACT(MONTH FROM periodStart)::int AS month,
                        cnt, total, tax, discount, qty
                 FROM report_rollup
                 WHERE companyId = ?
                   AND domain = ?
                   AND periodType = 'month'
                   AND periodStart BETWEEN ?::date AND ?::date
                   AND outletId IS NULL",
                [$companyId, $domain, $from, $to],
                false,
                true
            );
        }

        $map = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f   = $rs->fields;
                $mon = (int) $f['month'];
                $map[$mon] = [
                    'cnt'      => (int)   ($f['cnt']      ?? 0),
                    'total'    => (float) ($f['total']    ?? 0),
                    'tax'      => (float) ($f['tax']      ?? 0),
                    'discount' => (float) ($f['discount'] ?? 0),
                    'qty'      => (float) ($f['qty']      ?? 0),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $map;
    }

    /**
     * status='vigente' + kind IN ('contado','credito').
     *
     * kind='devolucion' se EXCLUYE a propósito: los consumers de este método
     * (CategoriesService::salesByCategory, BrandsService::salesByBrand) son
     * la contraparte rollup de ramas live que filtran `transactionType IN
     * (0, 3)` — ver CategoriesService.php:101 y BrandsService.php:98 — o sea
     * ventas, sin devoluciones. Sin este filtro las devoluciones (que mig
     * 159 guarda con signo negativo) neteaban las ventas y el mismo reporte
     * daba números distintos con el rollup encendido que con
     * REPORTS_ROLLUP_ENABLED apagado.
     *
     * Las devoluciones NO se pierden: siguen en rollup_item_sales_day con
     * kind='devolucion' y se consultan aparte (es lo que va a leer el
     * catálogo de reportes de context/47 — "ventas netas" es una métrica
     * distinta de "ventas", y ahí la resta es deliberada).
     *
     * GROUP BY itemid solo (no categoryId): un ítem recategorizado a mitad
     * del rango cae en más de una fila física de rollup_item_sales_day, pero
     * acá se pliegan en un solo total por ítem — igual que el dominio
     * 'item_sales' viejo, que no tenía categoría como dimensión propia.
     */
    /** @param list<string> $outletIds Alcance por sucursal; `[]` = sin filtro. */
    public function itemSalesRange(string $companyId, string $from, string $to, array $outletIds): array
    {
        $fromDate = substr($from, 0, 10);
        $toDate   = substr($to,   0, 10);

        // 3 binds fijos: el fragmento de sucursal va interpolado.
        $params    = [$companyId, $fromDate, $toDate];
        $outletSql = OutletScope::sqlFilter('outletid', $outletIds);

        $rs = ncmExecute(
            "SELECT itemid::text AS itemid,
                    COALESCE(SUM(qty), 0)         AS qty,
                    COALESCE(SUM(gross), 0)       AS total,
                    COALESCE(SUM(tax), 0)         AS tax,
                    COALESCE(SUM(cogs), 0)        AS cogs,
                    COALESCE(SUM(discount), 0)    AS discount,
                    COALESCE(SUM(comission), 0)   AS comission,
                    COALESCE(SUM(cogsabsflat), 0) AS cogsabsflat
             FROM rollup_item_sales_day
             WHERE companyid = ?
               AND day BETWEEN ?::date AND ?::date
               AND status = 'vigente'
               AND kind IN ('contado', 'credito'){$outletSql}
             GROUP BY itemid",
            $params,
            false,
            true
        );

        $map = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $map[(string) $f['itemid']] = [
                    'qty'         => (float) ($f['qty']         ?? 0),
                    'total'       => (float) ($f['total']       ?? 0),
                    'tax'         => (float) ($f['tax']         ?? 0),
                    'cogs'        => (float) ($f['cogs']        ?? 0),
                    // Un solo `discount` (mig 160): la columna ya es
                    // SUM(itemsolddiscount) plano, igual que las ramas live.
                    // `discountFlat` desapareció con la columna que lo
                    // alimentaba — existía solo para tener el valor correcto
                    // al lado del inflado por *ABS(units).
                    'discount'    => (float) ($f['discount']    ?? 0),
                    'comission'   => (float) ($f['comission']   ?? 0),
                    'cogsAbsFlat' => (float) ($f['cogsabsflat'] ?? 0),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $map;
    }

    /**
     * kind IN ('contado','cobro') únicamente — 'devolucion' se EXCLUYE a
     * propósito: PaymentMethodsService (el único consumer) nunca leyó pagos
     * de devoluciones (su query live filtra transactionType IN (0,5)), y
     * rollup_payments_day sí guarda kind=devolucion (para context/47) pero
     * incluirlo acá rompería la paridad con la rama live.
     */
    /** @param list<string> $outletIds Alcance por sucursal; `[]` = sin filtro. */
    public function paymentsRange(string $companyId, string $from, string $to, array $outletIds): array
    {
        $fromDate = substr($from, 0, 10);
        $toDate   = substr($to,   0, 10);

        // 3 binds fijos: el fragmento de sucursal va interpolado.
        $params    = [$companyId, $fromDate, $toDate];
        $outletSql = OutletScope::sqlFilter('outletid', $outletIds);

        $rs = ncmExecute(
            "SELECT method,
                    COALESCE(SUM(amount), 0) AS amount,
                    COALESCE(SUM(cnt), 0)    AS cnt
             FROM rollup_payments_day
             WHERE companyid = ?
               AND day BETWEEN ?::date AND ?::date
               AND kind IN ('contado', 'cobro'){$outletSql}
             GROUP BY method",
            $params,
            false,
            true
        );

        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f      = $rs->fields;
                $amount = (float) ($f['amount'] ?? 0);
                // 'total' y 'price' con el MISMO valor: el dominio viejo los
                // sumaba por separado (elem->>'total' vs elem->>'price'),
                // pero el único consumer (PaymentMethodsService) siempre usó
                // 'price' para ambos ('total' => (float) $pr['price']) — la
                // tabla nueva unifica en una sola columna `amount` desde el
                // recompute (mismo criterio que groupByPaymentMethod), no
                // hay pérdida de información real.
                $rows[] = [
                    'type'  => (string) ($f['method'] ?? ''),
                    'total' => $amount,
                    'price' => $amount,
                    'cnt'   => (int) ($f['cnt'] ?? 0),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        usort($rows, fn($a, $b) => $b['price'] <=> $a['price']);

        return $rows;
    }

    /**
     * Grano diario crudo de rollup_sales_day, con filtros opcionales sobre
     * TODAS las dimensiones de la clave. Sin endpoint todavía — es lo que
     * context/47-reportes-personalizados-y-export.md va a consumir (F0,
     * catálogo + ejecutor).
     *
     * `outletId` es el ÚNICO filtro que acepta además una LISTA de uuids (el
     * alcance de un usuario con varias sucursales asignadas): es la dimensión
     * que gobierna el view-scope, y ahí "2 o más" es un estado real y no una
     * consulta mal armada. El resto siguen siendo valores únicos bindeados.
     *
     * @param array{outletId?:string|list<string>,registerId?:string,userId?:string,kind?:string,status?:string,channel?:string} $filters
     */
    public function salesDaily(string $companyId, string $from, string $to, array $filters = []): array
    {
        $where  = ['companyid = ?', 'day BETWEEN ?::date AND ?::date'];
        $params = [$companyId, substr($from, 0, 10), substr($to, 0, 10)];

        // Interpolado y fuera del bucle de binds: agregar N placeholders en el
        // medio de `$params` es precisamente lo que `sqlFilter()` evita.
        $outletIds = $filters['outletId'] ?? [];
        $outletIds = is_array($outletIds) ? $outletIds : [(string) $outletIds];
        $outletSql = OutletScope::sqlFilter('outletid', $outletIds);

        $columnByFilter = [
            'registerId' => 'registerid',
            'userId'     => 'userid',
            'kind'       => 'kind',
            'status'     => 'status',
            'channel'    => 'channel',
        ];
        foreach ($columnByFilter as $key => $col) {
            if (!empty($filters[$key])) {
                $where[]  = "{$col} = ?";
                $params[] = (string) $filters[$key];
            }
        }

        $rs = ncmExecute(
            'SELECT * FROM rollup_sales_day WHERE ' . implode(' AND ', $where)
                . $outletSql . ' ORDER BY day',
            $params,
            false,
            true
        );

        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $rs->fields;
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $rows;
    }
}

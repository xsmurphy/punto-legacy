<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Ventas por Usuarios / Recursos (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportUsersService.php (Fase 2 batch 2). Único cambio
 * vs el original: namespace + `final`. SQL idéntico (sin ROC: companyId siempre bound).
 *
 * Incluye usuarios sin actividad (en 0). El filtro de outlet del legacy era no-op (uuid
 * vs int → 0), se omite por paridad con el comportamiento efectivo del legacy.
 *
 * 2026-09-11: se suman `summary()` (KPIs + ranking + serie diaria) y
 * `commissions()` (detalle liquidable por vendedor) detrás del parámetro
 * `view`. `salesByUser()` NO se tocó — sigue siendo la respuesta sin `view`,
 * con su `count` por LÍNEAS y todo. Las tres leen el mismo universo de filas;
 * las diferencias están documentadas en cada método.
 */
final class UsersService
{
    /** @return array filas [{userId, name, usold, total, comission, discount, count}] */
    public function salesByUser($from, $to, $companyId)
    {
        $rows = [];

        // `itemSold.userId` NO es quien hizo la venta: es el vendedor asignado A
        // LA LÍNEA (la función "vendedor por línea" del POS, line-seller-dialog).
        // Es una columna NULLABLE que solo se escribe si el cajero asigna un
        // vendedor a mano (SaleService::2171 → `$sD['user'] ?: null`). Como casi
        // nadie la usa, el JOIN contra ella no matcheaba ninguna fila y el
        // reporte salía en cero para todos — el bug que reportó el owner.
        //
        // `transaction.userId` sí es el operador que emitió la venta (NOT NULL,
        // sale de `$this->ctx->userId`). El COALESCE atribuye cada línea a su
        // vendedor propio cuando lo tiene, y al operador de la venta cuando no
        // — que es lo que un reporte llamado "Equipo" tiene que mostrar.
        //
        // Identificadores sin comillas a propósito: `itemSold` y `transaction`
        // se crearon sin comillas en el schema, así que Postgres ya plegó las
        // columnas a minúsculas y `i.userId` resuelve a `userid`.
        $sql = 'SELECT COALESCE(i.userId, t.userId) AS userid,
                       c.contactName AS name,
                       SUM(i.itemSoldUnits)     AS usold,
                       SUM(i.itemSoldTotal)     AS total,
                       SUM(i.itemSoldComission) AS comission,
                       SUM(i.itemSoldDiscount)  AS discount,
                       COUNT(i.transactionId)   AS count
                FROM itemSold i
                JOIN transaction t ON i.transactionId = t.transactionId
                JOIN contact c     ON COALESCE(i.userId, t.userId) = c.contactId
                WHERE t.transactionDate >= ? AND t.transactionDate <= ?
                  AND t.companyId = ?
                  AND t.transactionType IN (0, 3, 6)
                  AND ' . SaleFilters::notVoidedSql('t') . '
                  AND c.contactStatus = 1
                  AND c.type = 0
                GROUP BY COALESCE(i.userId, t.userId), c.contactName';

        $res = ncmExecute($sql, [$from, $to, $companyId], false, true);
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f  = $res->fields;
                $id = (string) $f['userid'];
                if (isset($rows[$id])) {
                    $rows[$id]['usold']     += (float) $f['usold'];
                    $rows[$id]['total']     += (float) $f['total'];
                    $rows[$id]['comission'] += (float) $f['comission'];
                    $rows[$id]['discount']  += (float) $f['discount'];
                    $rows[$id]['count']     += (int) $f['count'];
                } else {
                    $rows[$id] = [
                        'userId'    => $id,
                        'name'      => (string) ($f['name'] ?? ''),
                        'usold'     => (float) $f['usold'],
                        'total'     => (float) $f['total'],
                        'comission' => (float) $f['comission'],
                        'discount'  => (float) $f['discount'],
                        'count'     => (int) $f['count'],
                    ];
                }
                $res->MoveNext();
            }
            $res->Close();
        }

        $resU = ncmExecute(
            'SELECT contactId, contactName FROM contact WHERE companyId = ? AND contactStatus = 1 AND type = 0',
            [$companyId], false, true
        );
        if ($resU && is_object($resU)) {
            while (!$resU->EOF) {
                $f  = $resU->fields;
                $id = (string) $f['contactId'];
                if (!isset($rows[$id])) {
                    $rows[$id] = [
                        'userId'    => $id,
                        'name'      => (string) ($f['contactName'] ?? ''),
                        'usold'     => 0,
                        'total'     => 0,
                        'comission' => 0,
                        'discount'  => 0,
                        'count'     => 0,
                    ];
                }
                $resU->MoveNext();
            }
            $resU->Close();
        }

        return array_values($rows);
    }

    /* ══════════════════════════════════════════════════════════════════════
     * Vistas nuevas (2026-09-11). `salesByUser()` arriba queda INTACTA: es lo
     * que devuelve el endpoint sin `view` y lo que consume la pestaña Detalle.
     *
     * Las tres comparten el mismo universo de filas —el de `salesByUser()`—
     * para que los números de una pestaña expliquen los de la otra:
     * `transactionType IN (0,3,6)`, no anuladas, y el vendedor resuelto por
     * COALESCE contra un contacto activo de tipo usuario.
     * ══════════════════════════════════════════════════════════════════════ */

    /** FROM + WHERE compartido. `$alias` de itemSold = i, transaction = t, contact = c. */
    private static function baseFromWhere(): string
    {
        return 'FROM itemSold i
                JOIN transaction t ON i.transactionId = t.transactionId
                JOIN contact c     ON COALESCE(i.userId, t.userId) = c.contactId
                WHERE t.transactionDate >= ? AND t.transactionDate <= ?
                  AND t.companyId = ?
                  AND t.transactionType IN (0, 3, 6)
                  AND ' . SaleFilters::notVoidedSql('t') . '
                  AND c.contactStatus = 1
                  AND c.type = 0';
    }

    /**
     * `view=summary` — el dashboard del período.
     *
     * @return array{totals:array,ranking:array,daily:array}
     *
     * Tres cosas que NO son obvias y por eso están acá:
     *
     * 1. Los tickets son `COUNT(DISTINCT t.transactionId)`, no
     *    `COUNT(i.transactionId)`. La respuesta legacy cuenta LÍNEAS bajo el
     *    nombre `count` — una venta de 7 ítems son 7 "transacciones" ahí. Para
     *    un ticket promedio eso no sirve: daría el promedio por línea. `count`
     *    se mantiene tal cual en la vista default (hay consumidores), y acá se
     *    expone `tickets` como magnitud distinta.
     *
     * 2. La suma de los `tickets` por vendedor puede ser MAYOR que
     *    `totals.tickets`. Es correcto: una venta con líneas de dos vendedores
     *    es un ticket de cada uno y uno solo del comercio. Por eso el
     *    promedio global se calcula con el distinct global, no sumando filas.
     *
     * 3. La serie diaria corta con `::date` sin `AT TIME ZONE`: `TenantClock`
     *    ya fija la zona del tenant en la sesión de Postgres antes de la query
     *    (mismo razonamiento que `EXTRACT(HOUR …)` en `SalesService`).
     */
    public function summary($from, $to, $companyId): array
    {
        $args = [$from, $to, $companyId];

        $ranking = [];
        $sql = 'SELECT COALESCE(i.userId, t.userId) AS userid,
                       c.contactName AS name,
                       SUM(i.itemSoldUnits)         AS usold,
                       SUM(i.itemSoldTotal)         AS total,
                       SUM(i.itemSoldComission)     AS comission,
                       SUM(i.itemSoldDiscount)      AS discount,
                       COUNT(DISTINCT t.transactionId) AS tickets
                ' . self::baseFromWhere() . '
                GROUP BY COALESCE(i.userId, t.userId), c.contactName';

        $res = ncmExecute($sql, $args, false, true);
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f     = $res->fields;
                $total = (float) $f['total'];
                $disc  = (float) $f['discount'];
                $tk    = (int) $f['tickets'];
                $ranking[] = [
                    'userId'      => (string) $f['userid'],
                    'name'        => (string) ($f['name'] ?? ''),
                    'usold'       => (float) $f['usold'],
                    'total'       => $total,
                    'comission'   => (float) $f['comission'],
                    'discount'    => $disc,
                    'tickets'     => $tk,
                    'avgTicket'   => $tk > 0 ? $total / $tk : 0.0,
                    // `itemSoldTotal` es el BRUTO de la línea y el descuento
                    // viaja aparte (ver SaleService, armado de `$lines`), así
                    // que el porcentaje es descuento sobre bruto.
                    'discountPct' => $total > 0 ? $disc / $total * 100 : 0.0,
                ];
                $res->MoveNext();
            }
            $res->Close();
        }

        usort($ranking, static fn($a, $b) => $b['total'] <=> $a['total']);

        $totals = [
            'total' => 0.0, 'comission' => 0.0, 'discount' => 0.0,
            'usold' => 0.0, 'tickets' => 0, 'avgTicket' => 0.0, 'sellers' => 0,
        ];
        foreach ($ranking as $r) {
            $totals['total']     += $r['total'];
            $totals['comission'] += $r['comission'];
            $totals['discount']  += $r['discount'];
            $totals['usold']     += $r['usold'];
        }
        $totals['sellers'] = count($ranking);

        $resT = ncmExecute(
            'SELECT COUNT(DISTINCT t.transactionId) AS tickets ' . self::baseFromWhere(),
            $args, false, true
        );
        if ($resT && is_object($resT)) {
            if (!$resT->EOF) {
                $totals['tickets'] = (int) $resT->fields['tickets'];
            }
            $resT->Close();
        }
        $totals['avgTicket'] = $totals['tickets'] > 0 ? $totals['total'] / $totals['tickets'] : 0.0;

        $daily = [];
        $resD = ncmExecute(
            'SELECT t.transactionDate::date AS day,
                    COALESCE(i.userId, t.userId) AS userid,
                    SUM(i.itemSoldTotal) AS total
             ' . self::baseFromWhere() . '
             GROUP BY t.transactionDate::date, COALESCE(i.userId, t.userId)
             ORDER BY 1',
            $args, false, true
        );
        if ($resD && is_object($resD)) {
            while (!$resD->EOF) {
                $f = $resD->fields;
                $daily[] = [
                    'date'   => substr((string) $f['day'], 0, 10),
                    'userId' => (string) $f['userid'],
                    'total'  => (float) $f['total'],
                ];
                $resD->MoveNext();
            }
            $resD->Close();
        }

        return ['totals' => $totals, 'ranking' => $ranking, 'daily' => $daily];
    }

    /**
     * `view=commissions` — el detalle liquidable, agrupado por vendedor.
     *
     * @return array{sellers:array,totals:array}
     *
     * La comisión es SIEMPRE `itemSoldComission`, la que se congeló por línea
     * al vender. Nunca se recalcula contra el porcentaje vigente del artículo
     * o del usuario: liquidar con la tasa de hoy una venta de hace dos meses
     * cambia la plata que ya se le prometió a alguien.
     *
     * El grano es (vendedor, transacción): una venta con dos líneas del mismo
     * vendedor es UNA fila. Las filas quedan ordenadas por fecha descendente
     * dentro de cada vendedor, y los vendedores por comisión descendente.
     */
    public function commissions($from, $to, $companyId): array
    {
        $sql = 'SELECT COALESCE(i.userId, t.userId) AS userid,
                       c.contactName    AS name,
                       t.transactionId  AS txid,
                       t.transactionDate AS txdate,
                       t.invoiceNo      AS invoiceno,
                       t.invoicePrefix  AS invoiceprefix,
                       SUM(i.itemSoldTotal)     AS total,
                       SUM(i.itemSoldComission) AS comission
                ' . self::baseFromWhere() . '
                GROUP BY COALESCE(i.userId, t.userId), c.contactName, t.transactionId,
                         t.transactionDate, t.invoiceNo, t.invoicePrefix
                ORDER BY t.transactionDate DESC';

        $sellers = [];
        $res = ncmExecute($sql, [$from, $to, $companyId], false, true);
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f  = $res->fields;
                $id = (string) $f['userid'];
                if (!isset($sellers[$id])) {
                    $sellers[$id] = [
                        'userId'    => $id,
                        'name'      => (string) ($f['name'] ?? ''),
                        'total'     => 0.0,
                        'comission' => 0.0,
                        'tickets'   => 0,
                        'rows'      => [],
                    ];
                }
                $total = (float) $f['total'];
                $com   = (float) $f['comission'];
                $sellers[$id]['total']     += $total;
                $sellers[$id]['comission'] += $com;
                $sellers[$id]['tickets']++;
                $sellers[$id]['rows'][] = [
                    'transactionId' => (string) $f['txid'],
                    'date'          => (string) $f['txdate'],
                    // Formateador único (mig 159 / 209): concatenar prefijo y
                    // número a mano da "001-002838", que no es un documento.
                    'invoiceNo'     => \Punto\Api\Documents\DocumentNumber::format(
                        $f['invoiceno'], (string) ($f['invoiceprefix'] ?? '')
                    ),
                    'total'         => $total,
                    'comission'     => $com,
                ];
                $res->MoveNext();
            }
            $res->Close();
        }

        $sellers = array_values($sellers);
        usort($sellers, static fn($a, $b) => $b['comission'] <=> $a['comission']);

        $totals = ['total' => 0.0, 'comission' => 0.0, 'tickets' => 0];
        foreach ($sellers as $s) {
            $totals['total']     += $s['total'];
            $totals['comission'] += $s['comission'];
            $totals['tickets']   += $s['tickets'];
        }

        return ['sellers' => $sellers, 'totals' => $totals];
    }
}

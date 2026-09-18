<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Sales\SaleService;
use Punto\Api\Sales\SaleType;

/**
 * Reporte de BOLSILLOS (wallet, context/74 §13): cuánto se cargó, cuánto se
 * consumió, cuánto se debe todavía y —el motivo por el que existe— qué
 * consumos se cobraron por menos de lo que valían.
 *
 * ── Por qué es el control de los consumos ──────────────────────────────────
 * El importe de un consumo con saldo (tipo 15) lo manda la caja. Una venta
 * normal con un precio bajado queda expuesta en el arqueo (falta plata) y en
 * el margen; un consumo NO entra a ninguno de los dos por diseño (D12/D13).
 * Sin este reporte, un consumo por debajo de lista no dejaría rastro visible.
 *
 * La comparación es contra el valor de LISTA congelado en cada línea al
 * guardar (`itemsold.itemsoldlisttotal`, mig 235, resuelto en el servidor por
 * `SaleService::freezeWalletListTotals()`), y lo cobrado es el DÉBITO real del
 * bolsillo (`wallet_movement` tipo `spend`), no el total que declaró la caja.
 * Por consumo:
 *
 *   diferencia    = max(lista − debitado, 0)
 *   con descuento = min(diferencia, descuento registrado en el comprobante)
 *   sin descuento = diferencia − con descuento
 *
 * "Descuento registrado" es el mayor entre el del comprobante y la suma de los
 * de sus líneas: la caja manda los dos iguales, pero un descuento declarado
 * solo por línea no se le puede cargar al usuario como faltante.
 *
 * "Cargado" excluye las ventas de carga ANULADAS. (Anular la venta hoy no
 * revierte el `load` del bolsillo — gap de F2 anotado en context/74 §13.)
 *
 * "Con descuento" es plata que la caja declaró como descuento (el POS lo pide
 * con su permiso); "sin descuento" es la que no tiene explicación en el
 * documento: precio de la línea bajado, lista elegida a mano, o un total
 * declarado menor a la suma de las líneas.
 *
 * Un consumo con alguna línea sin valor de lista congelado (anteriores a la
 * mig 235) NO se evalúa: inventarle la lista con el catálogo de hoy daría
 * diferencias falsas.
 *
 * ── Qué acota el alcance de sucursal y qué no ──────────────────────────────
 * Cargas y consumos son documentos con sucursal: el `$roc` (construido con
 * `Roc::build(..., 't')` sobre `transaction`) los acota como a cualquier
 * reporte de ventas. El SALDO VIGENTE no: el saldo es del comercio (§3.1, se
 * consume en cualquier sucursal) y no hay forma honesta de partirlo.
 *
 * ── Por qué no hay rollup ──────────────────────────────────────────────────
 * Lee `transaction` tipo 15 + `wallet_movement` + `itemsold` del rango, con
 * índices que ya existen (`idx_wallet_movement_source`, la partición por fecha
 * de `transaction`/`itemsold`). El volumen de consumos con saldo es una
 * fracción de las ventas de un comercio; un rollup sería otra copia que
 * mantener sin una consulta lenta que lo justifique.
 */
final class WalletReportService
{
    /** Tope de filas del detalle de diferencias. Los totales NO se cortan. */
    private const DIFF_ROW_LIMIT = 1000;

    /** Diferencias menores a esto son redondeo, no un control. */
    private const EPS = 0.005;

    /**
     * KPIs del período. Es lo que el panel pide también para el período
     * ANTERIOR (comparación de los StatTile).
     *
     * @param string $from naive tenant-local 'Y-m-d H:i:s' (Date::reportRange)
     * @param string $to   idem
     * @param string $roc  " AND t.companyId = '…' [AND t.outletId …]" — `Roc::build($cid, $oid, 't')`
     * @return array{loaded: float, consumed: float, consumptions: int, liability: float,
     *               differences: array{unexplained: float, withDiscount: float, count: int, unexplainedCount: int}}
     */
    public function summary(string $from, string $to, string $roc, string $companyId): array
    {
        global $db;

        $loaded = $db->Execute(
            'SELECT COALESCE(SUM(m.amount), 0) AS total
               FROM wallet_movement m
               JOIN transaction t
                 ON t.transactionid = m.sourceid AND t.companyid = m.companyid
              WHERE m.companyid = ? AND m.type = \'load\' AND m.sourcetype = ? AND t.voidedat IS NULL
                AND t.transactiondate BETWEEN ? AND ?' . $roc,
            [$companyId, SaleService::WALLET_SOURCE_LOAD, $from, $to]
        );

        [$cte, $params] = $this->differencesCte($from, $to, $roc, $companyId);
        $cons = $db->Execute(
            $cte . ' SELECT COALESCE(SUM(charged), 0) AS consumed, COUNT(*) AS n FROM c',
            $params
        );
        $diff = $db->Execute(
            $cte . ' SELECT COALESCE(SUM(unexplained), 0) AS unexplained,
                            COALESCE(SUM(registered), 0) AS registered,
                            COUNT(*) AS n,
                            COUNT(*) FILTER (WHERE unexplained > ' . self::EPS . ') AS nunexplained
                       FROM dd',
            $params
        );

        return [
            'loaded'       => round((float) ($loaded->fields['total'] ?? 0), 2),
            'consumed'     => round((float) ($cons->fields['consumed'] ?? 0), 2),
            'consumptions' => (int) ($cons->fields['n'] ?? 0),
            'liability'    => round(array_sum(array_column($this->liabilityByPocket($to, $companyId), 'balance')), 2),
            'differences'  => [
                'unexplained'      => round((float) ($diff->fields['unexplained'] ?? 0), 2),
                'withDiscount'     => round((float) ($diff->fields['registered'] ?? 0), 2),
                'count'            => (int) ($diff->fields['n'] ?? 0),
                'unexplainedCount' => (int) ($diff->fields['nunexplained'] ?? 0),
            ],
        ];
    }

    /**
     * El reporte entero del período: KPIs + día a día + productos + bolsillos
     * + diferencias (detalle y agrupadas por usuario y por caja).
     */
    public function full(string $from, string $to, string $roc, string $companyId): array
    {
        return [
            'summary'     => $this->summary($from, $to, $roc, $companyId),
            'byDay'       => $this->byDay($from, $to, $roc, $companyId),
            'byProduct'   => $this->byProduct($from, $to, $roc, $companyId),
            'byPocket'    => $this->byPocket($from, $to, $roc, $companyId),
            'differences' => $this->differences($from, $to, $roc, $companyId),
        ];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * Consumos del período (`c`), su valor de lista (`l`) y sus diferencias
     * (`dd`, solo los que tienen). Una sola definición para KPIs, detalle y
     * agrupaciones: si la cuenta cambia, cambia en los tres.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function differencesCte(string $from, string $to, string $roc, string $companyId): array
    {
        $type = SaleType::WalletConsumption->value;
        $eps  = self::EPS;
        $sql = "WITH c AS (
                    SELECT t.companyid, t.transactionid, t.transactiondate, t.invoiceno, t.userid,
                           t.registerid, t.outletid, t.customerid,
                           COALESCE(t.transactiondiscount, 0) AS discount,
                           -m.amount AS charged, m.pocketid
                      FROM transaction t
                      JOIN wallet_movement m
                        ON m.sourceid = t.transactionid AND m.companyid = t.companyid
                       AND m.type = 'spend' AND m.sourcetype = ?
                     WHERE t.transactiontype = {$type}
                       AND t.transactiondate BETWEEN ? AND ?{$roc}
                ), l AS (
                    SELECT i.transactionid,
                           SUM(i.itemsoldlisttotal) AS listvalue,
                           SUM(COALESCE(i.itemsolddiscount, 0)) AS linediscount,
                           bool_and(i.itemsoldlisttotal IS NOT NULL) AS complete
                      FROM itemsold i
                     WHERE i.companyid = ?
                       AND i.itemsolddate BETWEEN ? AND ?
                       AND i.transactionid IN (SELECT transactionid FROM c)
                     GROUP BY i.transactionid
                ), d AS (
                    SELECT c.*, l.listvalue,
                           GREATEST(c.discount, l.linediscount) AS recorded,
                           GREATEST(l.listvalue - c.charged, 0) AS gap
                      FROM c JOIN l ON l.transactionid = c.transactionid
                     WHERE l.complete
                ), dd AS (
                    SELECT d.*,
                           LEAST(d.gap, d.recorded)         AS registered,
                           GREATEST(d.gap - d.recorded, 0) AS unexplained
                      FROM d
                     WHERE d.gap > {$eps}
                )";
        return [$sql, [SaleService::WALLET_SOURCE_CONSUMPTION, $from, $to, $companyId, $from, $to]];
    }

    /**
     * Saldo vivo por bolsillo a la fecha de fin: el `balanceafter` del último
     * movimiento de cada (cliente, bolsillo) hasta `$to`, sumado. Es plata ya
     * cobrada y todavía no entregada. De TODO el comercio (ver docblock).
     *
     * `$to` llega al segundo ('… 23:59:59') y `createdat` tiene fracción: con
     * `<=` un movimiento de las 23:59:59.4 quedaba afuera y el saldo salía con
     * el `balanceafter` anterior. Por eso `< $to + 1 segundo`.
     *
     * @return list<array{pocketId: string, balance: float}>
     */
    private function liabilityByPocket(string $to, string $companyId): array
    {
        global $db;
        $rs = $db->Execute(
            'SELECT pocketid, SUM(balanceafter) AS balance
               FROM (
                 SELECT DISTINCT ON (contactid, pocketid) pocketid, balanceafter
                   FROM wallet_movement
                  WHERE companyid = ? AND createdat < ?::timestamp + interval \'1 second\'
                  ORDER BY contactid, pocketid, seq DESC
               ) x
              GROUP BY pocketid',
            [$companyId, $to]
        );
        $out = [];
        while ($rs && !$rs->EOF) {
            $out[] = ['pocketId' => (string) $rs->fields['pocketid'], 'balance' => round((float) $rs->fields['balance'], 2)];
            $rs->MoveNext();
        }
        return $out;
    }

    /**
     * Cargado y consumido por día, con TODOS los días del rango (el gráfico
     * no puede saltearse los días en cero).
     *
     * @return list<array{date: string, loaded: float, consumed: float}>
     */
    private function byDay(string $from, string $to, string $roc, string $companyId): array
    {
        global $db;
        $days = [];
        $d    = new \DateTimeImmutable(substr($from, 0, 10));
        $end  = new \DateTimeImmutable(substr($to, 0, 10));
        // Tope de un año de puntos: el panel no pide más, y un rango absurdo
        // por la API no puede devolver un array sin límite.
        for ($i = 0; $d <= $end && $i < 400; $i++, $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'loaded' => 0.0, 'consumed' => 0.0];
        }

        $rs = $db->Execute(
            'SELECT to_char(t.transactiondate, \'YYYY-MM-DD\') AS day, SUM(m.amount) AS total
               FROM wallet_movement m
               JOIN transaction t
                 ON t.transactionid = m.sourceid AND t.companyid = m.companyid
              WHERE m.companyid = ? AND m.type = \'load\' AND m.sourcetype = ? AND t.voidedat IS NULL
                AND t.transactiondate BETWEEN ? AND ?' . $roc . '
              GROUP BY 1',
            [$companyId, SaleService::WALLET_SOURCE_LOAD, $from, $to]
        );
        while ($rs && !$rs->EOF) {
            $k = (string) $rs->fields['day'];
            if (isset($days[$k])) {
                $days[$k]['loaded'] = round((float) $rs->fields['total'], 2);
            }
            $rs->MoveNext();
        }

        [$cte, $params] = $this->differencesCte($from, $to, $roc, $companyId);
        $rs = $db->Execute(
            $cte . ' SELECT to_char(transactiondate, \'YYYY-MM-DD\') AS day, SUM(charged) AS total FROM c GROUP BY 1',
            $params
        );
        while ($rs && !$rs->EOF) {
            $k = (string) $rs->fields['day'];
            if (isset($days[$k])) {
                $days[$k]['consumed'] = round((float) $rs->fields['total'], 2);
            }
            $rs->MoveNext();
        }
        return array_values($days);
    }

    /**
     * Productos que salieron con saldo: unidades y valor (neto de la línea).
     * APARTE de los vendidos cobrados — el tipo 15 no está en la lista blanca
     * de los reportes de productos (D13).
     *
     * Las hijas de un combo fijo (`meta.compound`) no se listan: son
     * trazabilidad con valor cero y su costo ya está en la línea del combo.
     * Las de add-on sí: tienen su propio precio.
     *
     * @return list<array{itemId: string, name: string, units: float, value: float}>
     */
    private function byProduct(string $from, string $to, string $roc, string $companyId): array
    {
        global $db;
        [$cte, $params] = $this->differencesCte($from, $to, $roc, $companyId);
        $rs = $db->Execute(
            $cte . ' SELECT i.itemid, MAX(it.itemname) AS name,
                            SUM(i.itemsoldunits) AS units,
                            SUM(i.itemsoldtotal - COALESCE(i.itemsolddiscount, 0)) AS value
                       FROM itemsold i
                       LEFT JOIN item it ON it.itemid = i.itemid AND it.companyid = i.companyid
                      WHERE i.companyid = ?
                        AND i.itemsolddate BETWEEN ? AND ?
                        AND i.transactionid IN (SELECT transactionid FROM c)
                        AND NOT jsonb_exists(i.meta, \'compound\')
                      GROUP BY i.itemid
                      ORDER BY value DESC',
            array_merge($params, [$companyId, $from, $to])
        );
        $out = [];
        while ($rs && !$rs->EOF) {
            $out[] = [
                'itemId' => (string) $rs->fields['itemid'],
                'name'   => (string) ($rs->fields['name'] ?? ''),
                'units'  => round((float) $rs->fields['units'], 3),
                'value'  => round((float) $rs->fields['value'], 2),
            ];
            $rs->MoveNext();
        }
        return $out;
    }

    /**
     * Cargado / consumido del período y saldo vigente, por bolsillo. Incluye
     * los bolsillos desactivados que tuvieron movimiento o saldo.
     *
     * @return list<array{pocketId: string, name: string, active: bool, loaded: float, consumed: float, balance: float}>
     */
    private function byPocket(string $from, string $to, string $roc, string $companyId): array
    {
        global $db;
        $rows = [];
        $rs = $db->Execute(
            'SELECT id, name, active FROM wallet_pocket WHERE companyid = ? ORDER BY name',
            [$companyId]
        );
        while ($rs && !$rs->EOF) {
            $id = (string) $rs->fields['id'];
            $rows[$id] = [
                'pocketId' => $id,
                'name'     => (string) $rs->fields['name'],
                'active'   => in_array($rs->fields['active'], [true, 't', 1, '1'], true),
                'loaded'   => 0.0,
                'consumed' => 0.0,
                'balance'  => 0.0,
            ];
            $rs->MoveNext();
        }

        $rs = $db->Execute(
            'SELECT m.pocketid, SUM(m.amount) AS total
               FROM wallet_movement m
               JOIN transaction t
                 ON t.transactionid = m.sourceid AND t.companyid = m.companyid
              WHERE m.companyid = ? AND m.type = \'load\' AND m.sourcetype = ? AND t.voidedat IS NULL
                AND t.transactiondate BETWEEN ? AND ?' . $roc . '
              GROUP BY m.pocketid',
            [$companyId, SaleService::WALLET_SOURCE_LOAD, $from, $to]
        );
        while ($rs && !$rs->EOF) {
            $id = (string) $rs->fields['pocketid'];
            if (isset($rows[$id])) {
                $rows[$id]['loaded'] = round((float) $rs->fields['total'], 2);
            }
            $rs->MoveNext();
        }

        [$cte, $params] = $this->differencesCte($from, $to, $roc, $companyId);
        $rs = $db->Execute($cte . ' SELECT pocketid, SUM(charged) AS total FROM c GROUP BY pocketid', $params);
        while ($rs && !$rs->EOF) {
            $id = (string) $rs->fields['pocketid'];
            if (isset($rows[$id])) {
                $rows[$id]['consumed'] = round((float) $rs->fields['total'], 2);
            }
            $rs->MoveNext();
        }

        foreach ($this->liabilityByPocket($to, $companyId) as $l) {
            if (isset($rows[$l['pocketId']])) {
                $rows[$l['pocketId']]['balance'] = $l['balance'];
            }
        }

        // Un bolsillo desactivado sin nada que mostrar no suma una fila vacía.
        return array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['active'] || $r['loaded'] != 0.0 || $r['consumed'] != 0.0 || $r['balance'] != 0.0
        ));
    }

    /**
     * Consumos cobrados por debajo de su valor de lista: el detalle (con
     * tope) y las agrupaciones por usuario (operador del PIN) y por caja,
     * calculadas sobre TODOS, no sobre las filas devueltas.
     */
    private function differences(string $from, string $to, string $roc, string $companyId): array
    {
        global $db;
        [$cte, $params] = $this->differencesCte($from, $to, $roc, $companyId);

        $rs = $db->Execute(
            $cte . ' SELECT dd.transactionid,
                            to_char(dd.transactiondate, \'YYYY-MM-DD HH24:MI:SS\') AS transactiondate,
                            dd.invoiceno,
                            dd.userid, u.contactname AS username,
                            dd.registerid, r.registername,
                            cu.contactname AS customername,
                            p.name AS pocketname,
                            dd.listvalue, dd.charged, dd.discount, dd.registered, dd.unexplained
                       FROM dd
                       LEFT JOIN contact u  ON u.contactid = dd.userid AND u.companyid = dd.companyid
                       LEFT JOIN register r ON r.registerid = dd.registerid AND r.companyid = dd.companyid
                       LEFT JOIN contact cu ON cu.contactid = dd.customerid AND cu.companyid = dd.companyid
                       LEFT JOIN wallet_pocket p ON p.id = dd.pocketid AND p.companyid = dd.companyid
                      ORDER BY dd.unexplained DESC, dd.transactiondate DESC
                      LIMIT ' . self::DIFF_ROW_LIMIT,
            $params
        );
        $rows = [];
        while ($rs && !$rs->EOF) {
            $f      = $rs->fields;
            $rows[] = [
                'transactionId' => (string) $f['transactionid'],
                'date'          => (string) $f['transactiondate'],
                'number'        => $f['invoiceno'] !== null ? (int) $f['invoiceno'] : null,
                'userId'        => (string) ($f['userid'] ?? ''),
                'userName'      => (string) ($f['username'] ?? ''),
                'registerId'    => (string) ($f['registerid'] ?? ''),
                'registerName'  => (string) ($f['registername'] ?? ''),
                'customerName'  => (string) ($f['customername'] ?? ''),
                'pocketName'    => (string) ($f['pocketname'] ?? ''),
                'listValue'     => round((float) $f['listvalue'], 2),
                'charged'       => round((float) $f['charged'], 2),
                'discount'      => round((float) $f['registered'], 2),
                'unexplained'   => round((float) $f['unexplained'], 2),
            ];
            $rs->MoveNext();
        }

        return [
            'rows'       => $rows,
            'byUser'     => $this->groupDifferences($cte, $params, 'dd.userid', 'u.contactname', 'LEFT JOIN contact u ON u.contactid = dd.userid AND u.companyid = dd.companyid'),
            'byRegister' => $this->groupDifferences($cte, $params, 'dd.registerid', 'r.registername', 'LEFT JOIN register r ON r.registerid = dd.registerid AND r.companyid = dd.companyid'),
        ];
    }

    /**
     * @param list<string> $params
     * @return list<array{id: string, name: string, count: int, discount: float, unexplained: float}>
     */
    private function groupDifferences(string $cte, array $params, string $key, string $nameCol, string $join): array
    {
        global $db;
        $rs = $db->Execute(
            $cte . " SELECT {$key} AS id, MAX({$nameCol}) AS name, COUNT(*) AS n,
                            SUM(dd.registered) AS registered, SUM(dd.unexplained) AS unexplained
                       FROM dd {$join}
                      GROUP BY {$key}
                      ORDER BY unexplained DESC, registered DESC",
            $params
        );
        $out = [];
        while ($rs && !$rs->EOF) {
            $out[] = [
                'id'          => (string) ($rs->fields['id'] ?? ''),
                'name'        => (string) ($rs->fields['name'] ?? ''),
                'count'       => (int) $rs->fields['n'],
                'discount'    => round((float) $rs->fields['registered'], 2),
                'unexplained' => round((float) $rs->fields['unexplained'], 2),
            ];
            $rs->MoveNext();
        }
        return $out;
    }
}

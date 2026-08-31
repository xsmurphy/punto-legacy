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
}

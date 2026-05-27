<?php
/**
 * Dominio de Reportes — Ventas por Usuarios / Recursos (capa API, motor ERP).
 *
 * Devuelve filas CRUDAS por usuario (vendido, total, comisión, descuento, # ventas) en un
 * período, incluyendo a los usuarios sin actividad (en 0). Sin formatear, sin HTML.
 * El BFF suma los totales; el front formatea y arma tabla/KPIs/chart. Ver REGLA RAÍZ 2.
 *
 * Reemplaza la lógica inline de panel/a_report_users.php (action=generalTable, que armaba HTML).
 * El filtro de outlet del legacy (`OUTLET_ID > 0`) era un no-op (UUID comparado como int → 0)
 * → se omite, igual que el comportamiento efectivo del legacy.
 *
 * Tenant: companyId del JWT, siempre bound.
 */
class ReportUsersService
{
    /** @return array filas [{userId, name, usold, total, comission, discount, count}] */
    public function salesByUser($from, $to, $companyId)
    {
        $rows = [];

        // 1) Agregados por usuario desde itemSold ⋈ transaction ⋈ contact (ventas tipos 0,3,6).
        $sql = 'SELECT i.userId AS userid,
                       c.contactName AS name,
                       SUM(i.itemSoldUnits)     AS usold,
                       SUM(i.itemSoldTotal)     AS total,
                       SUM(i.itemSoldComission) AS comission,
                       SUM(i.itemSoldDiscount)  AS discount,
                       COUNT(i.transactionId)   AS count
                FROM itemSold i
                JOIN transaction t ON i.transactionId = t.transactionId
                JOIN contact c     ON i.userId = c.contactId
                WHERE t.transactionDate >= ? AND t.transactionDate <= ?
                  AND t.companyId = ?
                  AND t.transactionType IN (0, 3, 6)
                  AND c.contactStatus = 1
                  AND c.type = 0
                GROUP BY i.userId, c.contactName';

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

        // 2) Todos los usuarios activos (los sin actividad entran en 0).
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

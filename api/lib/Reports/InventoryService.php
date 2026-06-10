<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Historial de Stock / Inventario (API compartida, motor ERP).
 *

 * Port de panel/lib/reports/ReportInventoryService.php (Fase 2 batch 6). SOLO migra
 * `movements()`. El KPI `widget()` (getAllInventoryAndItemsModule) se queda en el panel
 * local — está latente roto en PG (devuelve 0,0,0 con datos reales) pero cambiar a la
 * semántica correcta es una decisión de PRODUCTO (sucursal vs company, fila única vs
 * suma de locations) que requiere validación. Deuda registrada en el roadmap.
 *
 * Cambios vs el original:
 *  - namespace + `final`
 *  - el ROC se recibe por PARÁMETRO (no `getROC(1)` interno).
 *  - `getAllUsers()` (sólo en panel, lee `$_SESSION`) → reemplazado por `userNames()` lookup
 *    batch parametrizado (mejor higiene multi-tenant + sin session).
 *  - `getLocationName($id)` (sólo en panel) → reemplazado por `locationNames()` lookup batch.
 *  - `getCurrentOutletName($id)` (existe en /app) → resuelve por global.
 *
 * Tenant: companyId bound + $roc en la query principal de movements.
 */
final class InventoryService
{
    /**
     * Movimientos de stock de un período (o el historial completo de un ítem si $itemId).
     * Si $byDay, agrupa por (día, ítem) quedándose con el último valor del día.
     */
    public function movements($from, $to, $itemId, $byDay, $roc, $companyId, $limit = 200, $offset = 0)
    {
        $where  = ' WHERE 1=1';
        $params = [];

        if ($itemId) {
            $where   .= ' AND itemId = ?';
            $params[] = $itemId;
        } else {
            $where   .= ' AND stockDate BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        }

        $order = $byDay ? 'ASC' : 'DESC';
        $sql   = 'SELECT * FROM stock' . $where . $roc
               . ' ORDER BY stockId ' . $order
               . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $res = ncmExecute($sql, $params, false, true);
        if (!$res || !is_object($res)) {
            return [];
        }

        // Recolectar ids para lookups batch (item, location, user) — sin god functions.
        $itemIds = $locIds = $userIds = $rawRows = [];
        while (!$res->EOF) {
            $f = $res->fields;
            $itemIds[] = (string) ($f['itemId'] ?? '');
            if (!empty($f['locationId'])) { $locIds[] = (string) $f['locationId']; }
            if (!empty($f['userId']))     { $userIds[] = (string) $f['userId']; }
            $rawRows[] = $f;
            $res->MoveNext();
        }
        $res->Close();

        $items     = $this->itemNames($itemIds, $companyId);
        $locations = $this->locationNames($locIds, $companyId);
        $users     = $this->userNames($userIds, $companyId);

        $rows  = [];
        $byKey = []; // para byDay: clave (día+item) → fila (última gana)

        foreach ($rawRows as $f) {
            $iid = (string) ($f['itemId'] ?? '');
            $loc = (string) ($f['locationId'] ?? '');
            $usr = (string) ($f['userId'] ?? '');

            $row = [
                'stockId'       => (string) $f['stockId'],
                'stockDate'     => (string) $f['stockDate'],
                'itemId'        => $iid,
                'itemName'      => $items[$iid]['name'] ?? '',
                'itemSKU'       => $items[$iid]['sku'] ?? '',
                'outletName'    => (string) getCurrentOutletName($f['outletId']),
                'locationName'  => $loc === '' ? 'Principal' : ($locations[$loc] ?? 'Principal'),
                'userName'      => $users[$usr] ?? 'Sin Usuario',
                'source'        => (string) $f['stockSource'],
                'transactionId' => $f['transactionId'] ? (string) $f['transactionId'] : '',
                'note'          => (string) ($f['stockNote'] ?? ''),
                'count'         => (float) $f['stockCount'],
                'onHand'        => (float) $f['stockOnHand'],
                'cogs'          => (float) $f['stockCOGS'],
            ];

            if ($byDay) {
                $key         = substr($row['stockDate'], 0, 10) . $iid;
                $byKey[$key] = $row;
            } else {
                $rows[] = $row;
            }
        }

        return $byDay ? array_values($byKey) : $rows;
    }

    /** Lookup batch itemId → {name, sku} scopeado por companyId. */
    private function itemNames(array $ids, $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            'SELECT itemId, itemName, itemSKU FROM item WHERE companyId = ? AND itemId IN (' . $ph . ')',
            array_merge([$companyId], $ids), false, true
        );
        $map = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f = $res->fields;
                $map[(string) $f['itemId']] = [
                    'name' => toUTF8($f['itemName'] ?? ''),
                    'sku'  => toUTF8($f['itemSKU'] ?? ''),
                ];
                $res->MoveNext();
            }
            $res->Close();
        }
        return $map;
    }

    /** Lookup batch locationId (taxonomy) → name, scopeado por companyId. Reemplaza getLocationName(). */
    private function locationNames(array $ids, $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT taxonomyId, taxonomyName FROM taxonomy WHERE companyId = ? AND taxonomyId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['taxonomyId']] = toUTF8((string) ($r['taxonomyName'] ?? ''));
        }
        return $map;
    }

    /**
     * Lookup batch contactId → "Nombre Apellido", scopeado por companyId.
     * Reemplaza getAllUsers() del panel (que cachea en $_SESSION y devuelve todos los
     * contactos type=0). Acá sólo traemos los que aparecen en las rows.
     */
    private function userNames(array $ids, $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName, contactSecondName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $c) {
            $name = trim(((string) ($c['contactName'] ?? '')) . ' ' . ((string) ($c['contactSecondName'] ?? '')));
            if ($name === '') { continue; }
            $map[(string) $c['contactId']] = $name;
        }
        return $map;
    }
}

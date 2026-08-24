<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Contacts\ContactDisplayName;

/**
 * Dominio de Reportes — Agendamientos / Schedule (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportScheduleService.php (Fase 2 batch 11). Cambios vs original:
 *  - namespace + `final`
 *  - el ROC y companyId se reciben por PARÁMETRO en las 3 vistas (no globals).
 *  - `dec($str)` → `dec` private static (identity, mismo que el panel actual).
 *  - `getAllItems(false, true, $ids, true)` (sólo para mapear itemId → itemName) →
 *    reemplazado por lookup batch directo en `itemNames()` (mismo patrón que otros services).
 *
 * 3 vistas: detail (citas tipo 13 con lookups), stats (conteos por contacto+estado),
 * sessions (paquetes con sesiones). El modal de sesiones y el delete siguen en el panel
 * legacy vía ?action= (migración parcial).
 *
 * Tenant: $roc por query; companyId bound en todos los lookups.
 * Fixes PG ya en el original: contactInCalendar/transactionDetails en JSONB, lookups batch
 * parametrizados (no N+1), agregado único por estado en statusTotals/countsByContact.
 */
final class ScheduleService
{
    private const TX_TYPE = 13;

    /** Citas. $filters: ['ui'=>userId uuid, 'customerId'=>contactId uuid|null]. */
    public function detail(array $filters, $from, $to, string $roc, string $companyId): array
    {
        $userClause     = '';
        $customerClause = '';
        $params = [$from, $to];
        if (!empty($filters['ui'])) {
            $userClause = ' AND userId = ?';
            $params[] = $filters['ui'];
        }
        if (!empty($filters['customerId'])) {
            $customerClause = ' AND customerId = ?';
            $params[] = $filters['customerId'];
        }

        $cols = "transactionId, transactionStatus, invoiceNo, transactionDate, outletId, userId,
                 responsibleId, customerId, transactionNote, fromDate, toDate, transactionTotal,
                 meta->>'transactionDetails' AS transactionDetails";
        $sql = "SELECT $cols FROM transaction
                WHERE transactionType = " . self::TX_TYPE . "
                AND fromDate BETWEEN ? AND ?" . $userClause . $customerClause . $roc . "
                ORDER BY transactionDate DESC LIMIT 5000";

        $res = ncmExecute($sql, $params, false, false, true);
        $res = is_array($res) ? $res : [];

        $summary = $this->statusTotals($from, $to, $roc, $filters['ui']);

        if (!$res) {
            return ['rows' => [], 'summary' => $summary];
        }

        $apptIds = $custIds = $usrIds = $respIds = $outletIds = $itemIds = [];
        foreach ($res as $f) {
            $apptIds[]  = (string) $f['transactionId'];
            $custIds[]  = (string) $f['customerId'];
            $usrIds[]   = (string) $f['userId'];
            $respIds[]  = (string) $f['responsibleId'];
            $outletIds[] = (string) $f['outletId'];
            foreach ($this->decodeItemIds($f['transactionDetails'] ?? null) as $iid) {
                $itemIds[] = $iid;
            }
        }

        // mig 115: transactionParentId dropeada — origen (kind='package_session',
        // la venta que generó la cita) vía transaction_link, batch por apptId.
        $originByAppt = (new \Punto\Api\Services\TransactionLinkService())
            ->mapOriginIdByDerivedIds($companyId, $apptIds, 'package_session');
        $parentIds = array_values(array_unique(array_filter($originByAppt)));

        $contacts  = ContactDisplayName::batch(array_merge($custIds, $usrIds, $respIds), $companyId);
        $outlets   = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds, $companyId);
        $itemNames = $this->itemNames($itemIds, $companyId);
        $attended  = $this->attendedSet($apptIds);
        $docByParent = $this->invoiceByTxId($parentIds, $companyId);
        $docBySchedule = $this->invoiceBySchedule($apptIds, $companyId);

        $rows = [];
        foreach ($res as $f) {
            $apptId = (string) $f['transactionId'];
            $usrId  = (string) $f['userId'];
            $respId = (string) $f['responsibleId'];

            $doc = null;
            $parentId = $originByAppt[$apptId] ?? null;
            if ($parentId) {
                $doc = $docByParent[$parentId] ?? null;
            } elseif (isset($docBySchedule[$apptId])) {
                $doc = $docBySchedule[$apptId];
            }

            $itemsList = [];
            foreach ($this->decodeItemIds($f['transactionDetails'] ?? null) as $iid) {
                if (isset($itemNames[$iid])) {
                    $itemsList[] = $itemNames[$iid];
                }
            }

            $rows[] = [
                'transactionId'     => $apptId,
                'transactionStatus' => (int) $f['transactionStatus'],
                'docInvoice'        => $doc ? (string) $doc['invoice'] : '',
                'docTxId'           => $doc ? (string) $doc['txId'] : '',
                'scheduleNo'        => (string) ($f['invoiceNo'] ?? ''),
                'scheduledAt'       => (string) ($f['transactionDate'] ?? ''),
                'outletName'        => $outlets[(string) $f['outletId']] ?? '',
                'userName'          => $usrId ? ($contacts[$usrId] ?? '') : '',
                'responsibleName'   => $respId ? ($contacts[$respId] ?? '') : ($usrId ? ($contacts[$usrId] ?? '') : ''),
                'customerName'      => ($cid = (string) $f['customerId']) ? ($contacts[$cid] ?? '') : '',
                'note'              => (string) ($f['transactionNote'] ?? ''),
                'items'             => $itemsList,
                'fromDate'          => (string) ($f['fromDate'] ?? ''),
                'toDate'            => (string) ($f['toDate'] ?? ''),
                'total'             => (float) $f['transactionTotal'],
                'attended'          => isset($attended[$apptId]),
            ];
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /** Conteos por contacto. $filters: ['uit'=>'usr'|'cus', 'ui'=>contactId]. */
    public function stats(array $filters, $from, $to, string $roc, string $companyId): array
    {
        $isCustomer = ($filters['uit'] === 'cus');
        $type = $isCustomer ? 1 : 0;

        $where  = "companyId = ? AND type = ?";
        $params = [$companyId, $type];
        if (!$isCustomer) {
            $where .= " AND data->>'contactInCalendar' = '1'";
        }
        if ($filters['ui']) {
            $where .= " AND contactId = ?";
            $params[] = $filters['ui'];
        }
        $contacts = ncmExecute(
            "SELECT contactId, contactName FROM contact WHERE $where ORDER BY contactName LIMIT 2000",
            $params, false, false, true
        );
        $contacts = is_array($contacts) ? $contacts : [];
        if (!$contacts) {
            return ['rows' => []];
        }

        $col = $isCustomer ? 'customerId' : 'userId';
        $byContact = $this->countsByContact($col, $from, $to, $roc);

        $rows = [];
        foreach ($contacts as $c) {
            $cid = (string) $c['contactId'];
            $s = $byContact[$cid] ?? [];
            $rows[] = [
                'name'      => (string) ($c['contactName'] ?? ''),
                'pending'   => (int) ($s['0'] ?? 0),
                'ended'     => (int) ($s['6'] ?? 0),
                'cancelled' => (int) ($s['4'] ?? 0),
                'noshow'    => (int) ($s['5'] ?? 0),
                'blocked'   => (int) ($s['7'] ?? 0),
            ];
        }
        return ['rows' => $rows];
    }

    /** Paquetes con sesiones (ítems itemSessions > 1) y sesiones realizadas. */
    public function sessions($from, $to, string $companyId): array
    {
        $sessExpr = "COALESCE(NULLIF(c.data->>'itemSessions',''),'0')::int";
        $sql = "SELECT a.invoiceNo, a.customerId, contact.contactName, b.itemSoldDate, b.itemId,
                       b.itemSoldId, c.itemName, $sessExpr as itemSessions
                FROM transaction a
                LEFT JOIN contact ON contact.contactId = a.customerId
                LEFT JOIN itemSold b ON b.transactionId = a.transactionId
                LEFT JOIN item c ON c.itemId = b.itemId
                WHERE a.companyId = ? AND a.transactionDate BETWEEN ? AND ? AND $sessExpr > 1
                GROUP BY a.invoiceNo, a.customerId, contact.contactName, b.itemSoldDate, b.itemId,
                         b.itemSoldId, c.itemName, $sessExpr
                LIMIT 2000";
        // El grano de esta vista es el PAQUETE (`b.itemSoldId`, que es lo que
        // el GROUP BY discrimina y lo que `donesByPackage()` consulta), pero
        // se leía con `getAssoc`, que indexa por la primera columna
        // proyectada: `a.invoiceNo`. Una venta con dos paquetes-con-sesiones
        // genera dos filas con el MISMO invoiceNo, así que el segundo paquete
        // desaparecía del tab "Sesiones" — y con él las sesiones que el
        // cliente compró y todavía puede reclamar. `ncmRows` trae la fila por
        // paquete, que es la que el caller siempre creyó estar leyendo.
        $res = ncmRows($sql, [$companyId, $from, $to]);
        if (!$res) {
            return ['rows' => []];
        }

        $pkgIds = [];
        foreach ($res as $r) {
            $pkgIds[] = (string) $r['itemSoldId'];
        }
        $donesMap = $this->donesByPackage($pkgIds, $companyId);

        $rows = [];
        foreach ($res as $r) {
            $pkg      = (string) $r['itemSoldId'];
            $sessions = (int) $r['itemSessions'];
            $done     = (int) ($donesMap[$pkg] ?? 0);
            $rows[] = [
                'itemSoldId'   => $pkg,
                'customerId'   => (string) $r['customerId'],
                'invoiceNo'    => (string) ($r['invoiceNo'] ?? ''),
                'acquired'     => (string) ($r['itemSoldDate'] ?? ''),
                'itemName'     => (string) ($r['itemName'] ?? ''),
                'customerName' => (string) ($r['contactName'] ?? ''),
                'sessions'     => $sessions,
                'done'         => $done,
                'pending'      => $sessions - $done,
            ];
        }
        return ['rows' => $rows];
    }

    /* ───────────── helpers ───────────── */

    private function statusTotals($from, $to, string $roc, $userId): array
    {
        $userClause = '';
        $params = [$from, $to];
        if ($userId) {
            $userClause = ' AND userId = ?';
            $params[] = $userId;
        }
        $res = ncmExecute(
            "SELECT transactionStatus, COUNT(*) as total FROM transaction
             WHERE transactionType = " . self::TX_TYPE . "
             AND fromDate BETWEEN ? AND ?" . $userClause . $roc . "
             GROUP BY transactionStatus",
            $params, false, false, true
        );
        $res = is_array($res) ? $res : [];
        $by = [];
        $totals = 0;
        foreach ($res as $r) {
            $by[(string) $r['transactionStatus']] = (int) $r['total'];
            $totals += (int) $r['total'];
        }
        return [
            'new'       => $by['0'] ?? 0,
            'ended'     => $by['6'] ?? 0,
            'cancelled' => $by['4'] ?? 0,
            'noshow'    => $by['5'] ?? 0,
            'blocked'   => $by['7'] ?? 0,
            'totals'    => $totals,
        ];
    }

    /** Map[contactId][status] = count. forceObj para no colapsar filas por contactId duplicado. */
    private function countsByContact(string $col, $from, $to, string $roc): array
    {
        $res = ncmExecute(
            "SELECT $col as cid, transactionStatus, COUNT(*) as total FROM transaction
             WHERE transactionType = " . self::TX_TYPE . "
             AND fromDate BETWEEN ? AND ?" . $roc . "
             GROUP BY $col, transactionStatus",
            [$from, $to], false, true
        );
        $map = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $cid = (string) $res->fields['cid'];
                if ($cid !== '') {
                    $map[$cid][(string) $res->fields['transactionStatus']] = (int) $res->fields['total'];
                }
                $res->MoveNext();
            }
            $res->Close();
        }
        return $map;
    }

    private function donesByPackage(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT packageId, COUNT(transactionId) as dones FROM transaction
             WHERE companyId = ? AND transactionType = " . self::TX_TYPE . " AND transactionStatus = 6
             AND packageId IN ($ph) GROUP BY packageId",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['packageId']] = (int) $r['dones'];
        }
        return $map;
    }

    /** Decodifica transactionDetails (meta JSONB) → itemIds. */
    private function decodeItemIds($raw): array
    {
        if (!$raw) {
            return [];
        }
        $arr = json_decode((string) $raw, true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $v) {
            if (!empty($v['itemId'])) {
                $out[] = (string) self::dec($v['itemId']);
            }
        }
        return $out;
    }

    /** Set de appointment ids con asistencia. */
    private function attendedSet(array $apptIds): array
    {
        $ids = array_values(array_unique(array_filter($apptIds)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT DISTINCT sourceId FROM taxonomy WHERE sourceId IN ($ph)",
            $ids, false, false, true
        );
        $res = is_array($res) ? $res : [];
        $out = [];
        foreach ($res as $r) {
            $out[(string) $r['sourceId']] = true;
        }
        return $out;
    }

    private function invoiceByTxId(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionId, invoiceNo, invoicePrefix FROM transaction
             WHERE companyId = ? AND transactionId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['transactionId']] = [
                'invoice' => (string) ($r['invoicePrefix'] ?? '') . (string) ($r['invoiceNo'] ?? ''),
                'txId'    => (string) $r['transactionId'],
            ];
        }
        return $map;
    }

    private function invoiceBySchedule(array $scheduleIds, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($scheduleIds)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $links = ncmExecute(
            "SELECT scheduleId, transactionUID FROM toScheduleUID WHERE scheduleId IN ($ph)",
            $ids, false, false, true
        );
        $links = is_array($links) ? $links : [];
        if (!$links) {
            return [];
        }
        $uidBySchedule = [];
        $uids = [];
        foreach ($links as $l) {
            $uid = (string) $l['transactionUID'];
            if ($uid === '') { continue; }
            $uidBySchedule[(string) $l['scheduleId']] = $uid;
            $uids[] = $uid;
        }
        if (!$uids) {
            return [];
        }
        $uph = implode(',', array_fill(0, count($uids), '?'));
        $txs = ncmExecute(
            "SELECT transactionUID, transactionId, invoiceNo, invoicePrefix FROM transaction
             WHERE companyId = ? AND transactionUID IN ($uph)",
            array_merge([$companyId], $uids), false, false, true
        );
        $txs = is_array($txs) ? $txs : [];
        $byUid = [];
        foreach ($txs as $t) {
            $byUid[(string) $t['transactionUID']] = [
                'invoice' => (string) ($t['invoicePrefix'] ?? '') . (string) ($t['invoiceNo'] ?? ''),
                'txId'    => (string) $t['transactionId'],
            ];
        }
        $map = [];
        foreach ($uidBySchedule as $schedId => $uid) {
            if (isset($byUid[$uid])) {
                $map[$schedId] = $byUid[$uid];
            }
        }
        return $map;
    }

    /**
     * Lookup batch itemId → itemName, scopeado por companyId. Reemplaza
     * getAllItems(false, true, $ids, true) del panel — sólo necesitamos itemName.
     */
    private function itemNames(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT itemId, itemName FROM item WHERE companyId = ? AND itemId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['itemId']] = (string) ($r['itemName'] ?? '');
        }
        return $map;
    }

    private function nameMap(string $table, string $idCol, string $nameCol, array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT $idCol, $nameCol FROM $table WHERE companyId = ? AND $idCol IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r[$idCol]] = (string) ($r[$nameCol] ?? '');
        }
        return $map;
    }

    /** Elimina una cita (transaction type 13) scopeada por companyId. */
    public function deleteAppointment(string $id, string $companyId): bool
    {
        global $db;
        $r = $db->Execute(
            'DELETE FROM transaction WHERE transactionId = ? AND companyId = ?',
            [$id, $companyId]
        );
        return $r !== false;
    }

    /** Port fiel de dec (identity en PG — historicamente base64). */
    private static function dec(string $str): string
    {
        return $str;
    }
}

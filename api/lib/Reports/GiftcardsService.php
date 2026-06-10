<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Gift Cards (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportGiftcardsService.php (Fase 2 batch 5). Cambios vs original:
 *  - namespace + `final`
 *  - el ROC se recibe por PARÁMETRO (el original llamaba `getROC(1)` adentro)
 *  - los lookups batch reciben `$companyId` por parámetro en vez de leerlo de la constante
 *    global (mejor higiene multi-tenant, mismo patrón que OpenInvoicesService).
 *
 * Read-only. El form de edición (`giftcard`) y los writes (`update`, `delete`) siguen sirviéndose
 * por el PHP legacy de a_report_giftcards.php vía ?action= (migración parcial: ver router).
 *
 * Tenant: $roc por query principal; companyId bound en cada lookup.
 */
final class GiftcardsService
{
    /** Gift cards activadas. $filters: ['singleRow'=>uuid]. */
    public function detail(array $filters, string $roc, string $companyId)
    {
        if ($filters['singleRow']) {
            $sql = "SELECT * FROM giftCardSold
                    WHERE transactionId IS NOT NULL AND giftCardSoldId = ?" . $roc;
            $params = [$filters['singleRow']];
        } else {
            $sql = "SELECT * FROM giftCardSold
                    WHERE transactionId IS NOT NULL" . $roc . "
                    ORDER BY giftCardSoldExpires DESC LIMIT 5000";
            $params = [];
        }

        $res = ncmExecute($sql, $params, false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => []];
        }

        $benefIds = $outletIds = $txIds = [];
        foreach ($res as $f) {
            $benefIds[]  = (string) $f['giftCardSoldBeneficiaryId'];
            $outletIds[] = (string) $f['outletId'];
            $txIds[]     = (string) $f['transactionId'];
        }
        $benefs  = $this->contactNames($benefIds, $companyId);
        $outlets = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds, $companyId);
        $docs    = $this->invoiceDocs($txIds, $companyId);

        $rows = [];
        foreach ($res as $f) {
            $tid = (string) $f['transactionId'];
            $rows[] = [
                'giftCardSoldId' => (string) $f['giftCardSoldId'],
                'transactionId'  => $tid,
                'doc'            => $docs[$tid] ?? '-',
                'beneficiary'    => $benefs[(string) $f['giftCardSoldBeneficiaryId']] ?? '',
                'expires'        => (string) ($f['giftCardSoldExpires'] ?? ''),
                'code'           => (string) ($f['giftCardSoldCode'] ?? ''),
                'ucode'          => (string) ($f['timestamp'] ?? ''),
                'note'           => $this->decodeNote($f['giftCardSoldNote'] ?? ''),
                'lastUsed'       => (string) ($f['giftCardSoldLastUsed'] ?? ''),
                'sendDate'       => (string) ($f['giftCardSoldSendDate'] ?? ''),
                'outletName'     => $outlets[(string) $f['outletId']] ?? '',
                'value'          => (float) $f['giftCardSoldValue'],
                'color'          => (string) ($f['giftCardSoldColor'] ?? ''),
            ];
        }

        return ['rows' => $rows];
    }

    /** Decodifica la nota (base64 con fallback a texto plano), como isBase64Decode del legacy. */
    private function decodeNote($raw)
    {
        $raw = (string) $raw;
        if ($raw === '') {
            return '';
        }
        $decoded = base64_decode($raw, true);
        return ($decoded !== false && base64_encode($decoded) === $raw) ? $decoded : $raw;
    }

    /** Lookup batch contactId → nombre, scopeado por companyId. */
    private function contactNames(array $ids, $companyId)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = trim((string) ($c['contactName'] ?? ''));
        }
        return $map;
    }

    /** Lookup batch transactionId → "invoicePrefix+invoiceNo", scopeado por companyId. */
    private function invoiceDocs(array $ids, $companyId)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionId, invoiceNo, invoicePrefix FROM transaction WHERE companyId = ? AND transactionId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $no = (string) ($r['invoiceNo'] ?? '');
            $map[(string) $r['transactionId']] = $no !== '' ? ((string) ($r['invoicePrefix'] ?? '') . $no) : '-';
        }
        return $map;
    }

    /** Lookup batch id→name de outlet, scopeado por companyId. */
    private function nameMap($table, $idCol, $nameCol, array $ids, $companyId)
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
}

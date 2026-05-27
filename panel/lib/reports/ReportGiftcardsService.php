<?php
/**
 * Dominio de Reportes — Gift Cards (capa API, motor ERP).
 *
 * UNA vista de LECTURA: detail() — gift cards activadas (giftCardSold con transactionId)
 * con beneficiario/sucursal/documento resueltos. Devuelve datos CRUDOS; el BFF computa los
 * KPIs (vencidas/por-vencer/canjeadas/vigentes) y el front formatea + arma la tabla.
 *
 * NO se migran (siguen en el PHP legacy vía ?action=): el form de edición (`giftcard`) y los
 * writes (`update`, `delete`). El front los carga en el modal del shell.
 *
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * Fixes PG: lookups de beneficiario/sucursal/documento batch parametrizados (el legacy hacía
 * getContactData + getValue por fila = N+1); note decodificado en el service.
 *
 * Tenant: $roc (getROC) por query; companyId bound en cada lookup.
 */
class ReportGiftcardsService
{
    /** Gift cards activadas. $filters: ['singleRow'=>uuid]. */
    public function detail(array $filters)
    {
        $roc = getROC(1);

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

        // IDs para lookups batch (foreach, no array_map — ver nota en ReportTransactionsService).
        $benefIds = $outletIds = $txIds = [];
        foreach ($res as $f) {
            $benefIds[]  = (string) $f['giftCardSoldBeneficiaryId'];
            $outletIds[] = (string) $f['outletId'];
            $txIds[]     = (string) $f['transactionId'];
        }
        $benefs  = $this->contactNames($benefIds);
        $outlets = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds);
        $docs    = $this->invoiceDocs($txIds);

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

    /* ───────────────────────── helpers ───────────────────────── */

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
    private function contactNames(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([COMPANY_ID], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = trim((string) ($c['contactName'] ?? ''));
        }
        return $map;
    }

    /** Lookup batch transactionId → "invoicePrefix+invoiceNo", scopeado por companyId. */
    private function invoiceDocs(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionId, invoiceNo, invoicePrefix FROM transaction WHERE companyId = ? AND transactionId IN ($ph)",
            array_merge([COMPANY_ID], $ids), false, false, true
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
    private function nameMap($table, $idCol, $nameCol, array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT $idCol, $nameCol FROM $table WHERE companyId = ? AND $idCol IN ($ph)",
            array_merge([COMPANY_ID], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r[$idCol]] = (string) ($r[$nameCol] ?? '');
        }
        return $map;
    }
}

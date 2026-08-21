<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Clientes (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportCustomersService.php (Fase 2 batch 5). Único cambio
 * vs el original: namespace + `final`. SQL idéntico, incluye fixes PG documentados
 * (STRING_AGG(meta->>'tags',...) en vez de columna `tags`; lookup batch parametrizado de
 * contactos con bound params, sin getContactData/getCustomerData del panel — el legacy de
 * esos helpers tenía bug en PG).
 *
 * Tenant: $roc en la agregación; companyId bound en el lookup de contactos.
 */
final class CustomersService
{
    /** @return array filas crudas por cliente, ordenadas por total bruto desc. */
    public function ranking($from, $to, $roc, $companyId)
    {
        $sql = "SELECT customerId                          AS id,
                       SUM(transactionUnitsSold)           AS usold,
                       SUM(transactionTotal)               AS grosstotal,
                       SUM(transactionDiscount)            AS discount,
                       COUNT(transactionId)                AS count,
                       STRING_AGG(meta->>'tags', ',')      AS tags
                FROM transaction
                WHERE transactionType IN (0, 3)
                  AND " . SaleFilters::notVoidedSql() . "
                  AND transactionDate BETWEEN ? AND ?
                  AND customerId IS NOT NULL" . $roc . "
                GROUP BY customerId
                ORDER BY grosstotal DESC
                LIMIT 500";

        $res = ncmExecute($sql, [$from, $to], false, true);
        if (!$res || !is_object($res)) {
            return [];
        }

        $agg = [];
        $ids = [];
        while (!$res->EOF) {
            $f   = $res->fields;
            $cId = (string) $f['id'];
            $ids[$cId] = true;
            $agg[] = [
                'customerId' => $cId,
                'tags'       => $this->parseTags($f['tags'] ?? ''),
                'usold'      => (float) $f['usold'],
                'grossTotal' => (float) $f['grosstotal'],
                'discount'   => (float) $f['discount'],
                'count'      => (int)   $f['count'],
            ];
            $res->MoveNext();
        }
        $res->Close();

        $contacts = $this->contactsByIds(array_keys($ids), $companyId);

        $rows = [];
        foreach ($agg as $a) {
            $c = $contacts[$a['customerId']] ?? [];
            $rows[] = [
                'customerId' => $a['customerId'],
                'name'       => (string) ($c['contactName'] ?? ''),
                'secondName' => (string) ($c['contactSecondName'] ?? ''),
                'ruc'        => (string) ($c['contactTIN'] ?? ''),
                'ci'         => (string) ($c['contactCI'] ?? ''),
                'bday'       => (string) ($c['contactBirthDay'] ?? ''),
                'email'      => (string) ($c['contactEmail'] ?? ''),
                'phone'      => (string) ($c['contactPhone'] ?? ''),
                'phone2'     => (string) ($c['contactPhone2'] ?? ''),
                'address'    => (string) ($c['contactAddress'] ?? ''),
                'location'   => (string) ($c['contactLocation'] ?? ''),
                'city'       => (string) ($c['contactCity'] ?? ''),
                'tags'       => $a['tags'],
                'loyalty'    => (float)  ($c['contactLoyaltyAmount'] ?? 0),
                'usold'      => $a['usold'],
                'grossTotal' => $a['grossTotal'],
                'discount'   => $a['discount'],
                'count'      => $a['count'],
            ];
        }

        return $rows;
    }

    /** Lookup batch contactId → fila de contacto, scopeado por companyId, con bound params. */
    private function contactsByIds(array $ids, $companyId)
    {
        if (!$ids || $companyId === '') {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT * FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];

        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = $c;
        }
        return $map;
    }

    /**
     * Normaliza el STRING_AGG de `meta->>'tags'` (cada valor puede ser un JSON array string
     * `["a","b"]` o texto plano) a un array de etiquetas únicas. Las labels las arma el front.
     */
    private function parseTags($raw)
    {
        $raw = (string) $raw;
        if ($raw === '') {
            return [];
        }
        $clean = str_replace(['[', ']', '"', '\\'], '', $raw);
        $parts = array_map('trim', explode(',', $clean));
        $parts = array_filter($parts, fn($t) => $t !== '');
        return array_values(array_unique($parts));
    }
}

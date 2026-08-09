<?php
declare(strict_types=1);

namespace Punto\Api\Contacts;

/**
 * Analítica de comportamiento de un contacto (cliente o proveedor).
 *
 *   GET /v1/contacts?id=<uuid>&resource=analytics
 *
 * Devuelve un blob con KPIs + series + tops para alimentar el tab "Resumen"
 * y "Comportamiento" del perfil de contacto en frontend.
 *
 * Diseño: TODA la query agregada está scopeada por tenant (companyId) +
 * customerId/supplierId según el type. Para clientes (type=1) se agrega por
 * transacciones de venta (transactionType IN (0,3)); para proveedores
 * (type=2) por compras (transactionType IN (1,2,4)).
 *
 * Estado financiero: loyalty/store credit/credit line salen del row de
 * contact directo. El saldo de cuentas por cobrar/pagar (`openInvoices`) NO
 * se recalcula acá — delega en `OpenInvoicesService::forContact()`, la misma
 * fuente que usa el reporte general de cuentas por cobrar (evita que ambos
 * KPIs diverjan con dos definiciones de "cuánto debe").
 */
final class ContactAnalyticsService
{
    /**
     * Tipos de transacción según el dominio Punto:
     *   - 0: venta
     *   - 1: compra
     *   - 2: nota de compra
     *   - 3: venta a crédito
     *   - 4: compra a crédito (asumido)
     */
    private const VENTA_TYPES   = [0, 3];
    private const COMPRA_TYPES  = [1, 2, 4];

    /**
     * Genera el blob analytics. Devuelve null si el contacto no existe / no
     * pertenece al tenant; el endpoint mapea null → 404.
     *
     * `$type` = 1 (customer) | 2 (supplier).
     */
    public function compute(string $contactId, int $type, string $companyId): ?array
    {
        // Validación de pertenencia + datos básicos (loyalty, credit, etc).
        $contact = ncmExecute(
            "SELECT contactId, contactDate, contactStatus, contactLoyaltyAmount,
                    contactStoreCredit, contactCreditLine, contactCreditable, type
             FROM contact
             WHERE contactId = ? AND companyId = ? AND type = ?
             LIMIT 1",
            [$contactId, $companyId, $type]
        );
        if (!$contact) {
            return null;
        }

        $isCustomer = ($type === 1);
        $txTypes    = $isCustomer ? self::VENTA_TYPES : self::COMPRA_TYPES;
        $linkCol    = $isCustomer ? 'customerId'     : 'supplierId';
        $txCol      = 'transactionType';

        // Marcadores SQL inline para IN(?,?,?) — PDO/PG no expande arrays en un placeholder.
        $txMarkers  = implode(',', array_fill(0, count($txTypes), '?'));

        // ── Totales agregados (sumario) ─────────────────────────────────────
        $totals = $this->fetchOne(
            "SELECT
                COUNT(transactionId)                AS purchase_count,
                COALESCE(SUM(transactionTotal), 0)  AS spent_total,
                COALESCE(SUM(transactionUnitsSold), 0) AS items_total,
                COALESCE(SUM(transactionDiscount), 0)  AS discount_total,
                MIN(transactionDate)                AS first_date,
                MAX(transactionDate)                AS last_date
             FROM transaction
             WHERE companyId = ? AND $linkCol = ? AND $txCol IN ($txMarkers)",
            array_merge([$companyId, $contactId], $txTypes)
        );

        $purchaseCount = (int)   ($totals['purchase_count']  ?? 0);
        $spentTotal    = (float) ($totals['spent_total']     ?? 0);
        $itemsTotal    = (float) ($totals['items_total']     ?? 0);
        $firstDate     = $totals['first_date'] ?? null;
        $lastDate      = $totals['last_date']  ?? null;
        $avgTicket     = $purchaseCount > 0 ? $spentTotal / $purchaseCount : 0.0;

        // Frecuencia: días promedio entre la primera y última operación dividido
        // por purchases-1. Si hay <2 compras devolvemos null (no hay rate aún).
        $avgDaysBetween = null;
        if ($purchaseCount >= 2 && $firstDate && $lastDate) {
            $span = (int) round((strtotime($lastDate) - strtotime($firstDate)) / 86400);
            $avgDaysBetween = $span > 0 ? (float) ($span / ($purchaseCount - 1)) : null;
        }

        // Días desde la última operación — útil para flag "inactivo".
        $daysSinceLast = $lastDate ? (int) round((time() - strtotime($lastDate)) / 86400) : null;

        // ── Top 5 items adquiridos ─────────────────────────────────────────
        $topItems = $this->fetchAll(
            "SELECT a.itemId AS id,
                    COALESCE(SUM(a.itemSoldUnits), 0) AS count,
                    COALESCE(SUM(a.itemSoldTotal), 0) AS total
             FROM itemSold a
             JOIN transaction b ON a.transactionId = b.transactionId
             WHERE b.companyId = ? AND b.$linkCol = ? AND b.$txCol IN ($txMarkers)
                   AND a.itemSoldTotal > 0
             GROUP BY a.itemId
             ORDER BY count DESC
             LIMIT 5",
            array_merge([$companyId, $contactId], $txTypes)
        );
        // Resolución de nombre + UOM por item (1 query batch).
        $itemIds  = array_map(fn($r) => (string) $r['id'], $topItems);
        $itemMeta = $this->itemMetaByIds($itemIds, $companyId);
        $topItems = array_map(function ($r) use ($itemMeta) {
            $id   = (string) $r['id'];
            $meta = $itemMeta[$id] ?? null;
            return [
                'itemId' => $id,
                'name'   => (string) ($meta['name'] ?? '(sin nombre)'),
                'uom'    => $meta['uom'] ?? null,
                'count'  => (float) $r['count'],
                'total'  => (float) $r['total'],
            ];
        }, $topItems);

        // ── Top 5 categorías ────────────────────────────────────────────────
        $topCategories = $this->fetchAll(
            "SELECT b.categoryId AS tax,
                    COALESCE(SUM(a.itemSoldUnits), 0) AS count,
                    COALESCE(SUM(a.itemSoldTotal), 0) AS total
             FROM itemSold a
             JOIN item b        ON a.itemId = b.itemId
             JOIN transaction c ON a.transactionId = c.transactionId
             WHERE c.companyId = ? AND c.$linkCol = ? AND c.$txCol IN ($txMarkers)
                   AND b.categoryId IS NOT NULL
             GROUP BY b.categoryId
             ORDER BY total DESC
             LIMIT 5",
            array_merge([$companyId, $contactId], $txTypes)
        );
        $catIds   = array_map(fn($r) => (string) $r['tax'], $topCategories);
        $catName  = $this->taxonomyNamesByIds($catIds, $companyId);
        $topCategories = array_map(function ($r) use ($catName) {
            $id = (string) $r['tax'];
            return [
                'taxonomyId' => $id,
                'name'       => (string) ($catName[$id] ?? '(sin categoría)'),
                'count'      => (float) $r['count'],
                'total'      => (float) $r['total'],
            ];
        }, $topCategories);

        // ── Mix de pago: contado vs crédito (cliente) o equivalente compra ──
        $paymentMix = $this->fetchAll(
            "SELECT $txCol AS tx_type,
                    COUNT(transactionId)            AS cnt,
                    COALESCE(SUM(transactionTotal), 0) AS total
             FROM transaction
             WHERE companyId = ? AND $linkCol = ? AND $txCol IN ($txMarkers)
             GROUP BY $txCol",
            array_merge([$companyId, $contactId], $txTypes)
        );
        $paymentMix = array_map(fn($r) => [
            'type'  => (int) $r['tx_type'],
            'label' => $this->labelForTxType((int) $r['tx_type']),
            'count' => (int) $r['cnt'],
            'total' => (float) $r['total'],
        ], $paymentMix);

        // ── Distribución por hora del día (top 6) ──────────────────────────
        $byHour = $this->fetchAll(
            "SELECT EXTRACT(HOUR FROM transactionDate)::int AS hora,
                    COUNT(transactionId)                    AS cnt,
                    COALESCE(SUM(transactionTotal), 0)      AS total
             FROM transaction
             WHERE companyId = ? AND $linkCol = ? AND $txCol IN ($txMarkers)
             GROUP BY hora
             ORDER BY cnt DESC
             LIMIT 6",
            array_merge([$companyId, $contactId], $txTypes)
        );
        $byHour = array_map(fn($r) => [
            'hour'  => str_pad((string)(int)$r['hora'], 2, '0', STR_PAD_LEFT) . ':00',
            'count' => (int)   $r['cnt'],
            'total' => (float) $r['total'],
        ], $byHour);

        // ── Distribución por día de la semana (0=domingo .. 6=sábado en PG) ─
        $byDow = $this->fetchAll(
            "SELECT EXTRACT(DOW FROM transactionDate)::int AS dow,
                    COUNT(transactionId)                   AS cnt,
                    COALESCE(SUM(transactionTotal), 0)     AS total
             FROM transaction
             WHERE companyId = ? AND $linkCol = ? AND $txCol IN ($txMarkers)
             GROUP BY dow
             ORDER BY dow ASC",
            array_merge([$companyId, $contactId], $txTypes)
        );
        $dowNames = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
        $byDow = array_map(fn($r) => [
            'dow'   => (int) $r['dow'],
            'label' => $dowNames[(int) $r['dow']] ?? '?',
            'count' => (int)   $r['cnt'],
            'total' => (float) $r['total'],
        ], $byDow);

        // ── Historial mensual últimos 12 meses (chart de tendencia) ────────
        $byMonth = $this->fetchAll(
            "SELECT TO_CHAR(date_trunc('month', transactionDate), 'YYYY-MM') AS month_key,
                    COUNT(transactionId)                                     AS cnt,
                    COALESCE(SUM(transactionTotal), 0)                       AS total
             FROM transaction
             WHERE companyId = ? AND $linkCol = ? AND $txCol IN ($txMarkers)
                   AND transactionDate >= (NOW() - INTERVAL '12 months')
             GROUP BY month_key
             ORDER BY month_key ASC",
            array_merge([$companyId, $contactId], $txTypes)
        );
        $byMonth = array_map(fn($r) => [
            'month' => (string) $r['month_key'],
            'count' => (int)    $r['cnt'],
            'total' => (float)  $r['total'],
        ], $byMonth);

        // ── Sucursales preferidas (top 3) — solo informativo, tiene sentido en multi-outlet
        $byOutlet = $this->fetchAll(
            "SELECT outletId,
                    COUNT(transactionId)            AS cnt,
                    COALESCE(SUM(transactionTotal), 0) AS total
             FROM transaction
             WHERE companyId = ? AND $linkCol = ? AND $txCol IN ($txMarkers)
                   AND outletId IS NOT NULL
             GROUP BY outletId
             ORDER BY cnt DESC
             LIMIT 3",
            array_merge([$companyId, $contactId], $txTypes)
        );
        $outletIds   = array_map(fn($r) => (string) $r['outletId'], $byOutlet);
        $outletName  = $this->outletNamesByIds($outletIds, $companyId);
        $byOutlet = array_map(function ($r) use ($outletName) {
            $id = (string) $r['outletId'];
            return [
                'outletId' => $id,
                'name'     => (string) ($outletName[$id] ?? '(sin nombre)'),
                'count'    => (int)   $r['cnt'],
                'total'    => (float) $r['total'],
            ];
        }, $byOutlet);

        // ── Estado financiero (snapshot del contact) ────────────────────────
        $financial = [
            'loyalty'        => (float) ($contact['contactLoyaltyAmount'] ?? 0),
            'storeCredit'    => (float) ($contact['contactStoreCredit']   ?? 0),
            'creditLine'     => (float) ($contact['contactCreditLine']    ?? 0),
            'isCreditable'   => (bool)  ((int) ($contact['contactCreditable'] ?? 0) > 0),
            'openInvoices'   => (new \Punto\Api\Reports\OpenInvoicesService())->forContact($contactId, $companyId, $isCustomer),
        ];

        // Segmento del cliente (RFM-lite). Reglas simples basadas en frecuencia +
        // días desde la última visita — el front puede mapear a badge.
        $segment = $this->segment($purchaseCount, $daysSinceLast, $avgDaysBetween);

        return [
            'totals' => [
                'spent'              => $spentTotal,
                'purchases'          => $purchaseCount,
                'itemsBought'        => $itemsTotal,
                'avgTicket'          => $avgTicket,
                'discountTotal'      => (float) ($totals['discount_total'] ?? 0),
            ],
            'visits' => [
                'firstAt'        => $firstDate,
                'lastAt'         => $lastDate,
                'daysSinceLast'  => $daysSinceLast,
                'avgDaysBetween' => $avgDaysBetween,
            ],
            'segment'        => $segment,
            'financial'      => $financial,
            'topItems'       => $topItems,
            'topCategories'  => $topCategories,
            'paymentMix'     => $paymentMix,
            'byHour'         => $byHour,
            'byDayOfWeek'    => $byDow,
            'byMonth'        => $byMonth,
            'byOutlet'       => $byOutlet,
        ];
    }

    /**
     * Categoriza al cliente como Nuevo / Activo / En riesgo / Inactivo / VIP
     * basado en frecuencia + tiempo desde la última visita. Reglas conservadoras:
     *
     *  - Nuevo       : <2 compras  ó  primera compra hace <30 días
     *  - VIP         : >=10 compras  y  daysSince < 60
     *  - Activo      : daysSince <= 1.5 * avgDaysBetween
     *  - En riesgo   : 1.5x < daysSince <= 3x
     *  - Inactivo    : daysSince > 3x  ó  daysSince > 180
     */
    private function segment(int $purchases, ?int $daysSince, ?float $avgDays): array
    {
        if ($purchases < 2) {
            return ['key' => 'nuevo', 'label' => 'Nuevo'];
        }
        if ($daysSince === null) {
            return ['key' => 'sin_actividad', 'label' => 'Sin actividad'];
        }
        if ($purchases >= 10 && $daysSince < 60) {
            return ['key' => 'vip', 'label' => 'VIP'];
        }
        if ($avgDays === null || $avgDays <= 0) {
            return ['key' => 'activo', 'label' => 'Activo'];
        }
        if ($daysSince <= $avgDays * 1.5) {
            return ['key' => 'activo', 'label' => 'Activo'];
        }
        if ($daysSince <= $avgDays * 3) {
            return ['key' => 'en_riesgo', 'label' => 'En riesgo'];
        }
        return ['key' => 'inactivo', 'label' => 'Inactivo'];
    }

    private function labelForTxType(int $type): string
    {
        return match ($type) {
            0 => 'Contado',
            1 => 'Compra',
            2 => 'Nota de compra',
            3 => 'A crédito',
            4 => 'Compra crédito',
            default => 'Tipo ' . $type,
        };
    }

    // ── Helpers de query ────────────────────────────────────────────────────

    /** @return array Row plano o []. */
    private function fetchOne(string $sql, array $params): array
    {
        $row = ncmExecute($sql, $params);
        if (!$row) return [];
        // Normalizamos a array plano (CaseInsensitiveArray → array).
        $out = [];
        foreach ($row as $k => $v) { $out[(string)$k] = $v; }
        return $out;
    }

    /** @return array<int,\CaseInsensitiveArray> Lista de rows o []. */
    private function fetchAll(string $sql, array $params): array
    {
        $rs = ncmExecute($sql, $params, false, true);
        if (!$rs || !is_object($rs)) return [];
        $out = [];
        while (!$rs->EOF) {
            // Preservar CaseInsensitiveArray (DB wrapper) — sin esto, los
            // accesos camelCase como $r['itemId'] caen porque PG folds-to-
            // lowercase y un array plano no resolvería la diferencia.
            $out[] = new \CaseInsensitiveArray(iterator_to_array($rs->fields));
            $rs->MoveNext();
        }
        $rs->Close();
        return $out;
    }

    /**
     * Map id => {name, uom}. Batch SELECT escapado por companyId.
     * itemUOM vive en el JSONB `data` (demoted en mig 07 — ver
     * ItemCompoundService); se lee con `data->>'itemUOM'`.
     */
    private function itemMetaByIds(array $ids, string $companyId): array
    {
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->fetchAll(
            "SELECT itemId, itemName, data->>'itemUOM' AS itemUOM FROM item
             WHERE companyId = ? AND itemId IN ($marks)",
            array_merge([$companyId], $ids)
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['itemId']] = [
                'name' => (string) ($r['itemName'] ?? ''),
                'uom'  => $r['itemUOM'] !== null && $r['itemUOM'] !== ''
                    ? (string) $r['itemUOM']
                    : null,
            ];
        }
        return $map;
    }

    /** Map taxonomyId => taxonomyName. */
    private function taxonomyNamesByIds(array $ids, string $companyId): array
    {
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->fetchAll(
            "SELECT taxonomyId, taxonomyName FROM taxonomy
             WHERE companyId = ? AND taxonomyId IN ($marks)",
            array_merge([$companyId], $ids)
        );
        $map = [];
        foreach ($rows as $r) { $map[(string)$r['taxonomyId']] = (string)($r['taxonomyName'] ?? ''); }
        return $map;
    }

    /** Map outletId => outletName. */
    private function outletNamesByIds(array $ids, string $companyId): array
    {
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->fetchAll(
            "SELECT outletId, outletName FROM outlet
             WHERE companyId = ? AND outletId IN ($marks)",
            array_merge([$companyId], $ids)
        );
        $map = [];
        foreach ($rows as $r) { $map[(string)$r['outletId']] = (string)($r['outletName'] ?? ''); }
        return $map;
    }
}

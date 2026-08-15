<?php
declare(strict_types=1);
namespace Punto\Api\Services;

use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Documents\RemisionMotivo;

/**
 * RemisionService — nota de remisión (context/42-remision.md).
 *
 * Cubre los motivos de traslado donde el destino NO es un outlet propio:
 * venta, devolución a proveedor, consignación, exposición/demostración,
 * compra. El traslado entre sucursales/depósitos propios ya es
 * `StockTransferService` — no se duplica acá.
 *
 * Documental, no mueve stock: ver RemisionMotivo::ownsStockMovementElsewhere()
 * y el docblock de la migración 137 para el porqué de cada motivo.
 */
final class RemisionService
{
    public function create(
        string  $companyId,
        string  $userId,
        string  $motivo,
        string  $outletId,
        ?string $locationId,
        ?string $destinationContactId,
        ?string $destinationNote,
        ?string $transactionId,
        ?string $transferDate,
        ?string $note,
        array   $items
    ): array {
        global $db;

        $motivoEnum = RemisionMotivo::tryFrom($motivo);
        if ($motivoEnum === null) {
            throw new \InvalidArgumentException(
                'motivo inválido (debe ser uno de: ' . implode(', ', array_column(RemisionMotivo::cases(), 'value')) . ')',
                422
            );
        }

        $outlet = ncmExecute(
            'SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1',
            [$outletId, $companyId]
        );
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant', 422);
        }

        if ($locationId !== null) {
            $loc = ncmExecute(
                "SELECT taxonomyid FROM taxonomy WHERE taxonomyid = ? AND outletid = ? AND taxonomytype = 'location' LIMIT 1",
                [$locationId, $outletId]
            );
            if (!$loc) {
                throw new \InvalidArgumentException('locationId no pertenece al outlet', 422);
            }
        }

        if ($destinationContactId !== null) {
            $contact = ncmExecute(
                'SELECT contactid FROM contact WHERE contactid = ? AND companyid = ? LIMIT 1',
                [$destinationContactId, $companyId]
            );
            if (!$contact) {
                throw new \InvalidArgumentException('destinationContactId inválido para este tenant', 422);
            }
        }

        if ($transactionId !== null) {
            $tx = ncmExecute(
                'SELECT transactionid FROM transaction WHERE transactionid = ? AND companyid = ? LIMIT 1',
                [$transactionId, $companyId]
            );
            if (!$tx) {
                throw new \InvalidArgumentException('transactionId inválido para este tenant', 422);
            }
        }

        if (empty($items)) {
            throw new \InvalidArgumentException('items debe ser un array no vacío', 422);
        }
        foreach ($items as $item) {
            if (!isset($item['itemId'], $item['qty']) || (float) $item['qty'] <= 0) {
                throw new \InvalidArgumentException('Todos los items deben tener itemId y qty > 0', 422);
            }
        }

        $itemIds      = array_map(fn($i) => $i['itemId'], $items);
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params       = array_merge([$companyId], $itemIds);
        $validRs = ncmExecute(
            'SELECT itemid FROM item WHERE companyid = ? AND itemstatus = 1 AND itemid IN (' . $placeholders . ')',
            $params,
            false,
            true
        );
        $validIds = [];
        if ($validRs) {
            while (!$validRs->EOF) {
                $validIds[] = $validRs->fields['itemid'];
                $validRs->MoveNext();
            }
        }
        $invalid = array_diff($itemIds, $validIds);
        if (!empty($invalid)) {
            throw new \InvalidArgumentException('Item(s) no encontrados para este tenant: ' . implode(', ', $invalid), 422);
        }

        $db->StartTrans();

        // Correlativo (context/37, mig 137). Scope OUTLET — ver docblock de
        // la migración para el porqué de no usar register acá. Dentro de la
        // TX: si algo falla, el rollback devuelve el número.
        $docNumber = DocumentNumber::allocate(
            'remision',
            DocumentNumber::SCOPE_OUTLET,
            $outletId,
            $companyId,
        );

        $headerRow = ncmExecute(
            'INSERT INTO document_remision
                 (companyid, motivo, docnumber, outletid, locationid, destinationcontactid,
                  destinationnote, transactionid, transferdate, note, createdby)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?::timestamptz, now()), ?, ?)
             RETURNING remisionid',
            [
                $companyId,
                $motivoEnum->value,
                $docNumber,
                $outletId,
                $locationId,
                $destinationContactId,
                $destinationNote ?: null,
                $transactionId,
                $transferDate,
                $note ?: null,
                $userId,
            ]
        );

        if (!$headerRow || empty($headerRow['remisionid'])) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear la remisión');
        }

        $remisionId = $headerRow['remisionid'];

        foreach ($items as $item) {
            ncmExecute(
                'INSERT INTO document_remision_item (remisionid, itemid, qty, note) VALUES (?, ?, ?, ?)',
                [$remisionId, $item['itemId'], (float) $item['qty'], $item['note'] ?? null]
            );
        }

        $db->CompleteTrans();

        realtimePublish('remision', 'create', $remisionId);

        return ['id' => $remisionId, 'docNumber' => $docNumber];
    }

    public function list(string $companyId, array $filters = []): array
    {
        $where  = ['r.companyid = ?'];
        $params = [$companyId];

        if (!empty($filters['outletId'])) {
            $where[]  = 'r.outletid = ?';
            $params[] = $filters['outletId'];
        }
        if (!empty($filters['motivo'])) {
            $where[]  = 'r.motivo = ?';
            $params[] = $filters['motivo'];
        }
        if (isset($filters['status']) && $filters['status'] !== null) {
            $where[]  = 'r.status = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['dateFrom'])) {
            $where[]  = 'r.transferdate >= ?';
            $params[] = $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $where[]  = 'r.transferdate <= ?';
            $params[] = $filters['dateTo'];
        }

        $whereStr = implode(' AND ', $where);

        $totalRow = ncmExecute('SELECT COUNT(*) as total FROM document_remision r WHERE ' . $whereStr, $params);
        $total    = (int) ($totalRow['total'] ?? 0);

        $limit  = max(1, min(200, (int) ($filters['limit']  ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $listParams = array_merge($params, [$limit, $offset]);
        $rowsRs = ncmExecute(
            'SELECT r.remisionid, r.docnumber, r.motivo, r.status, r.transferdate, r.note,
                    r.outletid, o.outletname as outletname,
                    r.locationid, loc.taxonomyname as locationname,
                    r.destinationcontactid, c.contactname as destinationcontactname,
                    r.destinationnote, r.transactionid,
                    (SELECT COUNT(*) FROM document_remision_item ri WHERE ri.remisionid = r.remisionid) as itemscount
             FROM document_remision r
             JOIN outlet o ON o.outletid = r.outletid
             LEFT JOIN taxonomy loc ON loc.taxonomyid = r.locationid
             LEFT JOIN contact c ON c.contactid = r.destinationcontactid
             WHERE ' . $whereStr . '
             ORDER BY r.transferdate DESC
             LIMIT ? OFFSET ?',
            $listParams,
            false,
            true
        );

        $rows = [];
        if ($rowsRs) {
            while (!$rowsRs->EOF) {
                $r      = $rowsRs->fields;
                $rows[] = $this->presentListRow($r);
                $rowsRs->MoveNext();
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public function get(string $id, string $companyId): ?array
    {
        $header = ncmExecute(
            'SELECT r.*, o.outletname as outletname, loc.taxonomyname as locationname,
                    c.contactname as destinationcontactname, u.contactname as createdbyname
             FROM document_remision r
             JOIN outlet o ON o.outletid = r.outletid
             LEFT JOIN taxonomy loc ON loc.taxonomyid = r.locationid
             LEFT JOIN contact c ON c.contactid = r.destinationcontactid
             LEFT JOIN contact u ON u.contactid = r.createdby
             WHERE r.remisionid = ? AND r.companyid = ?
             LIMIT 1',
            [$id, $companyId]
        );

        if (!$header) {
            return null;
        }

        $itemsRs = ncmExecute(
            'SELECT ri.remisionitemid, ri.itemid, ri.qty, ri.note,
                    i.itemname as name, i.itemsku as sku
             FROM document_remision_item ri
             JOIN item i ON i.itemid = ri.itemid
             JOIN document_remision r ON r.remisionid = ri.remisionid AND r.companyid = ?
             WHERE ri.remisionid = ?
             ORDER BY i.itemname ASC',
            [$companyId, $id],
            false,
            true
        );

        $lineItems = [];
        if ($itemsRs) {
            while (!$itemsRs->EOF) {
                $r           = $itemsRs->fields;
                $lineItems[] = [
                    'remisionItemId' => $r['remisionitemid'],
                    'itemId'         => $r['itemid'],
                    'name'           => $r['name'],
                    'sku'            => $r['sku'],
                    'qty'            => (float) $r['qty'],
                    'note'           => $r['note'],
                ];
                $itemsRs->MoveNext();
            }
        }

        return [
            'remision' => $this->presentDetail($header),
            'items'    => $lineItems,
        ];
    }

    public function cancel(string $id, string $companyId, string $userId): array
    {
        global $db;

        $db->StartTrans();

        $header = ncmExecute(
            'SELECT remisionid, status FROM document_remision WHERE remisionid = ? AND companyid = ? FOR UPDATE',
            [$id, $companyId]
        );

        if (!$header) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException('Remisión no encontrada', 404);
        }

        if ((int) $header['status'] !== 1) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('La remisión ya fue cancelada', 409);
        }

        // Sin reversa de stock: document_remision nunca mueve stock (ver
        // docblock de la mig 137) — cancelar es solo marcar el status.
        ncmExecute(
            'UPDATE document_remision SET status = 0 WHERE remisionid = ? AND companyid = ?',
            [$id, $companyId]
        );

        $db->CompleteTrans();

        realtimePublish('remision', 'cancel', $id);

        return ['ok' => true];
    }

    private function presentListRow(array|\CaseInsensitiveArray $r): array
    {
        return [
            'remisionId'              => $r['remisionid'],
            'docNumber'               => isset($r['docnumber']) ? (int) $r['docnumber'] : null,
            'motivo'                  => $r['motivo'],
            'status'                  => (int) $r['status'],
            'transferDate'            => $r['transferdate'],
            'note'                    => $r['note'],
            'outletId'                => $r['outletid'],
            'outletName'              => $r['outletname'],
            'locationId'              => $r['locationid'],
            'locationName'            => $r['locationname'],
            'destinationContactId'    => $r['destinationcontactid'],
            'destinationContactName'  => $r['destinationcontactname'],
            'destinationNote'         => $r['destinationnote'],
            'transactionId'           => $r['transactionid'],
            'itemsCount'              => (int) $r['itemscount'],
        ];
    }

    private function presentDetail(array|\CaseInsensitiveArray $r): array
    {
        return [
            'remisionId'              => $r['remisionid'],
            'docNumber'               => isset($r['docnumber']) ? (int) $r['docnumber'] : null,
            'companyId'               => $r['companyid'],
            'motivo'                  => $r['motivo'],
            'status'                  => (int) $r['status'],
            'transferDate'            => $r['transferdate'],
            'note'                    => $r['note'],
            'outletId'                => $r['outletid'],
            'outletName'              => $r['outletname'],
            'locationId'              => $r['locationid'],
            'locationName'            => $r['locationname'],
            'destinationContactId'    => $r['destinationcontactid'],
            'destinationContactName'  => $r['destinationcontactname'],
            'destinationNote'         => $r['destinationnote'],
            'transactionId'           => $r['transactionid'],
            'createdBy'               => $r['createdby'],
            'createdByName'           => $r['createdbyname'],
            'createdAt'               => $r['createdat'],
        ];
    }
}

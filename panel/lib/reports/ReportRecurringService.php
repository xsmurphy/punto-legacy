<?php
/**
 * Dominio de Reportes — Facturas Recurrentes (capa API, motor ERP).
 *
 * Lectura: filas CRUDAS de suscripciones/recurrencias (cliente, documento inicial, próxima
 * fecha, finalización, frecuencia, estado, total). Escritura: pausar / activar / eliminar.
 * Sin formatear, sin HTML. El front mapea frecuencia→label y estado→{label,color}, formatea
 * fechas + total y arma los links/botones de acción. Ver REGLA RAÍZ 2.
 *
 * Reemplaza la lógica inline de panel/a_report_recurring.php (action=generalTable/pause/activate/remove).
 *
 * Tenant: SOLO `companyId` (la tabla `recurring` NO tiene outletId → no se usa $roc, igual que
 * el legacy). El payload de la venta vive en `data->>'recurringSaleData'`: la vieja columna
 * recurringSaleData fue absorbida por el JSONB `data` vía _routeToJsonb, que la guarda como un
 * VALOR STRING (el writer hace `json_encode($sale)` — ver cronCreateRecurringInvoice.php:103 y
 * app/action.php:2502). Por eso se lee `data->>'recurringSaleData'` (texto) y se `json_decode` en
 * PHP, igual que el reader legacy. `->>` devuelve el texto tanto si el valor es string-de-json
 * como si fuera un objeto anidado → robusto a ambas formas. Trae total + client (contactId) +
 * uid (tx) + invoiceno. El nombre del cliente se resuelve con un lookup parametrizado por IN
 * (no via getAllContacts, god function).
 * Seguridad: todo WRITE exige `companyId = ?` (aislamiento de tenant), igual que el legacy.
 */
class ReportRecurringService
{
    /** @return array filas [{recurringId, clientId, clientName, invoiceNo, txUid, nextDate, endDate, frecuency, status, total}] */
    public function listAll($companyId)
    {
        $res = ncmExecute(
            "SELECT recurringId, recurringNextDate, recurringEndDate, recurringFrecuency, recurringStatus,
                    data->>'recurringSaleData' AS saleData
             FROM recurring
             WHERE companyId = ?
             ORDER BY recurringNextDate DESC
             LIMIT 500",
            [$companyId], false, true
        );
        if (!$res || !is_object($res)) {
            return [];
        }

        $raw       = [];
        $clientIds = [];
        $uuidRe    = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        while (!$res->EOF) {
            $f    = $res->fields;
            $sale = json_decode((string) ($f['saleData'] ?? ''), true);
            $sale = is_array($sale) ? $sale : [];

            $client = (string) ($sale['client'] ?? '');
            if ($client !== '' && preg_match($uuidRe, $client)) {
                $clientIds[$client] = true;
            }
            $raw[] = [
                'recurringId' => (string) $f['recurringId'],
                'nextDate'    => (string) ($f['recurringNextDate'] ?? ''),
                'endDate'     => (string) ($f['recurringEndDate'] ?? ''),
                'frecuency'   => (string) ($f['recurringFrecuency'] ?? ''),
                'status'      => (int) $f['recurringStatus'],
                'sale'        => $sale,
                'client'      => $client,
            ];
            $res->MoveNext();
        }
        $res->Close();

        $names = $this->contactNames(array_keys($clientIds), $companyId);

        $rows = [];
        foreach ($raw as $r) {
            $sale   = $r['sale'];
            $client = $r['client'];
            $rows[] = [
                'recurringId'      => $r['recurringId'],
                'clientId'         => $client,
                'clientName'       => $names[$client]['name'] ?? '',
                'clientSecondName' => $names[$client]['secondName'] ?? '',
                'invoiceNo'        => (string) ($sale['invoiceno'] ?? ''),
                'txUid'            => (string) ($sale['uid'] ?? ''),
                'nextDate'         => $r['nextDate'],   // ISO crudo
                'endDate'          => $r['endDate'],    // ISO crudo (NULL = nunca)
                'frecuency'        => $r['frecuency'],
                'status'           => $r['status'],
                'total'            => (float) ($sale['total'] ?? 0),
            ];
        }

        return $rows;
    }

    /** Lookup parametrizado contactId → {name, secondName}, scopeado por companyId. */
    private function contactNames(array $ids, $companyId)
    {
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        // getAssoc=true (5º arg) → array de filas; en ncmExecute getAssoc tiene prioridad sobre
        // forceObj, por eso el 4º arg va en false.
        $res = ncmExecute(
            "SELECT contactId, contactName, contactSecondName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];

        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = [
                'name'       => (string) ($c['contactName'] ?? ''),
                'secondName' => (string) ($c['contactSecondName'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * Cambia el estado de una recurrencia o la elimina. SCOPEADO por companyId.
     *   pause → status 2, activate → status 1, remove → DELETE.
     * Devuelve true si ok.
     */
    public function mutate($action, $id, $companyId)
    {
        global $db;
        // $db->Execute (no ncmExecute): UPDATE/DELETE no son SELECT → ncmExecute devuelve false
        // aunque la operación funcione. ADOdb Execute devuelve objeto en éxito, false en error.
        if ($action === 'pause' || $action === 'activate') {
            $status = $action === 'pause' ? 2 : 1;
            $r = $db->Execute(
                'UPDATE recurring SET recurringStatus = ? WHERE recurringId = ? AND companyId = ?',
                [$status, $id, $companyId]
            );
            return $r !== false;
        }
        if ($action === 'remove') {
            $r = $db->Execute('DELETE FROM recurring WHERE recurringId = ? AND companyId = ?', [$id, $companyId]);
            return $r !== false;
        }
        return false;
    }
}

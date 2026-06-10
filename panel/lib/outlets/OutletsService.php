<?php
/**
 * Dominio de Sucursales (Outlets) — capa API, motor ERP.
 *
 *   GET  list  → todas las sucursales de la company (crudas, para la tabla)
 *   GET  get   → una sucursal por id (campos completos, para el form de edición)
 *   POST update→ actualiza los campos editables de una sucursal
 *
 * Read-only + UPDATE. El blank-insert (`?action=insert`, cascadea register + inventory vía
 * helpers god) y el delete (cascading `deleteOutlet`) quedan en el PHP legacy `a_outlets.php`
 * vía `?action=` — son operaciones pesadas/destructivas mejor servidas por el path probado.
 * La gestión de depósitos (`adm()`/`?tableExtra=`) también queda legacy (infra compartida).
 *
 * Tenant: companyId bound en cada query (nunca interpolado). Devuelve datos CRUDOS (sin
 * formatear, sin HTML); el front formatea + arma la tabla y el form. Ver REGLA RAÍZ 2.
 *
 * Schema PG (tabla `outlet`): columnas reales (outletName/Status/Address/Phone/WhatsApp/Email/
 * BillingName/RUC/LatLng/Description/PurchaseOrderNo, taxId, companyId) + `data` JSONB que
 * absorbe outletEcom / outletBusinessHours / itemsTaxIncluded.
 */
class OutletsService
{
    /** Todas las sucursales de la company. Forma reducida para la tabla. */
    public function listAll($companyId)
    {
        $res = ncmExecute(
            "SELECT * FROM outlet WHERE companyId = ? ORDER BY outletName ASC",
            [$companyId], false, true   // forceObj → iterar recordset
        );
        $out = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f = $res->fields;
                if (function_exists('_flattenJsonb')) { $f = _flattenJsonb($f); }
                $out[] = $this->shape($f, false);
                $res->MoveNext();
            }
            $res->Close();
        }
        return $out;
    }

    /** Una sucursal por id, campos completos para el form. NULL si no existe / no es del tenant. */
    public function get($id, $companyId)
    {
        // Single-row ncmExecute → CaseInsensitiveArray con `data` JSONB ya aplanado.
        $r = ncmExecute(
            "SELECT * FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1",
            [$id, $companyId]
        );
        if (!$r) { return null; }
        return $this->shape($r, true);
    }

    /** Actualiza los campos editables. SCOPEADO por companyId. @return bool */
    public function update($id, $companyId, array $f)
    {
        global $db;

        // data JSONB: preservar TODAS las claves existentes + override de las absorbidas.
        // forceObj (no getAssoc, no single-row): el single-row ncmExecute aplana y hace
        // `unset($row['data'])` (_flattenJsonb), así que perderíamos el blob crudo → reescribiría
        // `data` a {} borrando outletBusinessHours y demás claves diferidas. forceObj NO aplana,
        // así que `$res->fields['data']` trae el JSONB crudo.
        $data = [];
        $cur  = ncmExecute("SELECT data FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1", [$id, $companyId], false, true);
        if ($cur && is_object($cur) && !$cur->EOF) {
            $raw = $cur->fields['data'] ?? null;
            if ($raw) {
                $d = is_array($raw) ? $raw : json_decode((string) $raw, true);
                if (is_array($d)) { $data = $d; }
            }
            $cur->Close();
        }
        $data['outletEcom']       = $f['ecom'] ? 1 : 0;
        $data['itemsTaxIncluded'] = $f['taxIncluded'] ? 1 : 0;
        if ($f['businessHours'] !== '' && $f['businessHours'] !== null) {
            $bh = json_decode((string) $f['businessHours'], true);
            if (is_array($bh)) { $data['outletBusinessHours'] = $bh; }
        }

        // $db->Execute (no ncmExecute): UPDATE no es SELECT.
        $r = $db->Execute(
            "UPDATE outlet
             SET outletName = ?, outletAddress = ?, outletPhone = ?, outletEmail = ?,
                 outletDescription = ?, outletStatus = ?, outletBillingName = ?, outletRUC = ?,
                 outletWhatsApp = ?, outletPurchaseOrderNo = ?, outletLatLng = ?, taxId = ?, data = ?
             WHERE outletId = ? AND companyId = ?",
            [
                $f['name'], $f['address'], $f['phone'], $f['email'],
                $f['description'], $f['status'], $f['billingName'], $f['ruc'],
                $f['whatsApp'], ($f['purchaseOrderNo'] !== null ? $f['purchaseOrderNo'] : null), $f['latLng'],
                ($f['taxId'] !== '' ? $f['taxId'] : null), json_encode($data),
                $id, $companyId,
            ]
        );
        return $r !== false;
    }

    /**
     * Crea una sucursal en blanco: outlet + caja + filas de inventario a 0 para items rastreados.
     * Usa RETURNING para obtener el UUID generado (compatible con PG; Insert_ID() no funciona con UUIDs).
     * @return string|null UUID de la nueva sucursal, null en error.
     */
    public function create($companyId)
    {
        global $db, $plansValues;

        $db->StartTrans();

        $res = $db->Execute(
            "INSERT INTO outlet (outletName, outletStatus, companyId, itemsTaxIncluded, data)
             VALUES ('Nueva Sucursal', 1, ?, 1, ?)
             RETURNING outletId",
            [$companyId, json_encode(['itemsTaxIncluded' => 1])]
        );

        // Read UUID by name (PG lowercases column names in result sets)
        $outletId = '';
        if ($res && !$res->EOF) {
            $outletId = (string) ($res->fields['outletid'] ?? $res->fields['outletId'] ?? $res->fields[0] ?? '');
        }

        if (!$outletId) {
            $db->FailTrans();
            $db->CompleteTrans();
            return null;
        }

        $db->Execute(
            "INSERT INTO register (registerName, registerStatus, outletId, companyId) VALUES ('Nueva Caja', 1, ?, ?)",
            [$outletId, $companyId]
        );

        $planKey = defined('PLAN') ? PLAN : '';
        if (!empty($plansValues[$planKey]['inventory'])) {
            $db->Execute(
                "INSERT INTO inventory (inventoryCount, itemId, inventorySource, companyId, outletId)
                 SELECT 0, itemId, 'new_outlet', ?, ?
                 FROM item
                 WHERE companyId = ? AND itemTrackInventory > 0 AND itemStatus = 1",
                [$companyId, $outletId, $companyId]
            );
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        return $failed ? null : $outletId;
    }

    /**
     * Elimina una sucursal y su cascada completa dentro de una transacción.
     * Verifica ownership por $companyId; delega el cascade a deleteOutlet() (god-function con StartTrans).
     * El caller debe verificar que $id != OUTLET_ID (no borrar el outlet activo).
     * @return bool
     */
    public function delete($id, $companyId)
    {
        $outlet = ncmExecute(
            'SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ?',
            [$id, $companyId]
        );
        if (!$outlet) { return false; }
        return deleteOutlet($id) !== false;
    }

    /** Impuestos de venta de la company (para el dropdown del form). */
    public function taxes($companyId)
    {
        $res = ncmExecute(
            "SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = 'tax' AND companyId = ? ORDER BY taxonomyName ASC",
            [$companyId], false, true
        );
        $out = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $out[] = ['id' => (string) $res->fields['taxonomyId'], 'name' => (string) $res->fields['taxonomyName']];
                $res->MoveNext();
            }
            $res->Close();
        }
        return $out;
    }

    /** Da forma a una fila de `outlet` (ya aplanada) → claves que consume el front. */
    private function shape($r, $full)
    {
        $row = [
            'id'          => (string) ($r['outletId'] ?? ''),
            'name'        => (string) ($r['outletName'] ?? ''),
            'billingName' => (string) ($r['outletBillingName'] ?? ''),
            'ruc'         => (string) ($r['outletRUC'] ?? ''),
            'phone'       => (string) ($r['outletPhone'] ?? ''),
            'address'     => (string) ($r['outletAddress'] ?? ''),
            'status'      => (int) ($r['outletStatus'] ?? 0),
            'ecom'        => !empty($r['outletEcom']),
        ];
        if ($full) {
            $row['email']           = (string) ($r['outletEmail'] ?? '');
            $row['whatsApp']        = (string) ($r['outletWhatsApp'] ?? '');
            $row['latLng']          = (string) ($r['outletLatLng'] ?? '');
            $row['description']     = (string) ($r['outletDescription'] ?? '');
            $row['purchaseOrderNo'] = ($r['outletPurchaseOrderNo'] ?? '') !== '' ? (int) $r['outletPurchaseOrderNo'] : null;
            $row['taxId']           = $r['taxId'] ? (string) $r['taxId'] : '';
            $row['taxIncluded']     = !empty($r['itemsTaxIncluded']);
            $bh = $r['outletBusinessHours'] ?? null;
            $row['businessHours']   = is_string($bh) ? json_decode($bh, true) : $bh;
        }
        return $row;
    }
}

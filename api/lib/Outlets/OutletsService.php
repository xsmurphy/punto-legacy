<?php
declare(strict_types=1);

namespace Punto\Api\Outlets;

/**
 * Dominio de Sucursales (Outlets) — API compartida (motor ERP).
 *
 *   GET  list  → todas las sucursales de la company (crudas, para la tabla)
 *   GET  get   → una sucursal por id (campos completos, para el form de edición)
 *   POST update→ actualiza los campos editables de una sucursal
 *
 * Read + UPDATE. El blank-insert (`?action=insert`, cascadea register + inventory vía helpers
 * god) y el delete (cascading `deleteOutlet`) quedan en el PHP legacy `a_outlets.php` vía
 * `?action=` — son operaciones pesadas/destructivas mejor servidas por el path probado, y
 * dependen de globals del panel (`$plansValues`, `deleteOutlet()`) no portados a /api. La
 * gestión de depósitos (`adm()`/`?tableExtra=`) también queda legacy (infra compartida).
 *
 * Port FIEL de panel/lib/outlets/OutletsService.php (Fase 2 del desacople de /panel). Únicos
 * cambios respecto al original: namespace, `final`, `declare(strict_types=1)`. Dos métodos
 * (`create`/`delete`) traen lógica inline porque dependían de globals del panel:
 *  - `create()`: el guard `$plansValues[PLAN]['inventory']` → sub-query directa a `plans` por
 *    el plan_code de la company. Mismo comportamiento, menos acoplamiento.
 *  - `delete()`: el cascade vivía en `deleteOutlet()` de panel/includes/functions.php; portado
 *    como `cascadeDelete()` privado, idéntico al original (mismas tablas, mismo orden, misma TX).
 *
 * Tenant: companyId bindeado en cada query (nunca interpolado). Devuelve datos CRUDOS (sin
 * formatear, sin HTML); el front formatea + arma la tabla y el form. Ver REGLA RAÍZ 2.
 *
 * Schema PG (tabla `outlet`): columnas reales (outletName/Status/Address/Phone/WhatsApp/Email/
 * BillingName/RUC/LatLng/Description/PurchaseOrderNo, taxId, companyId) + `data` JSONB que
 * absorbe outletEcom / outletBusinessHours / itemsTaxIncluded.
 *
 * Nota namespace: las funciones globales (ncmExecute, _flattenJsonb) y `global $db` resuelven
 * a la global por fallback de PHP — no requieren `use`.
 */
final class OutletsService
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
        // Tras la migración 14 (jsonb demote), `outletAddress/Phone/WhatsApp/Email/
        // BillingName/RUC/Description` viven en `data` JSONB y `outletLatLng` quedó
        // splitteado en columnas `lat`/`lng`. La lista de columnas reales del schema
        // whitelist (_getTableSchema 'outlet') excluye los demoted — ncmUpdate los
        // rutea al JSONB con merge no-destructivo automáticamente. Esto nos deja
        // un único path de write sin el UPDATE explícito anterior.

        $record = [
            'outletName'            => $f['name'],
            'outletStatus'          => (int) $f['status'],
            'outletPurchaseOrderNo' => $f['purchaseOrderNo'] !== null ? (int) $f['purchaseOrderNo'] : null,
            'taxId'                 => $f['taxId'] !== '' ? $f['taxId'] : null,
            // Demoted al JSONB data — ncmUpdate los rutea.
            'outletAddress'         => $f['address'],
            'outletPhone'           => $f['phone'],
            'outletWhatsApp'        => $f['whatsApp'],
            'outletEmail'           => $f['email'],
            'outletBillingName'     => $f['billingName'],
            'outletRUC'             => $f['ruc'],
            'outletDescription'     => $f['description'],
            // Flags JSONB que no vienen de columnas.
            'outletEcom'            => $f['ecom'] ? 1 : 0,
            'itemsTaxIncluded'      => $f['taxIncluded'] ? 1 : 0,
        ];

        // lat/lng: nuevas columnas numéricas para cálculo de distancia haversine
        // (sucursal más cercana al cliente). Aceptan null si el usuario no las
        // completa todavía.
        if (array_key_exists('lat', $f)) {
            $record['lat'] = $f['lat'] !== '' && $f['lat'] !== null ? (float) $f['lat'] : null;
        }
        if (array_key_exists('lng', $f)) {
            $record['lng'] = $f['lng'] !== '' && $f['lng'] !== null ? (float) $f['lng'] : null;
        }

        // businessHours sigue siendo JSON serializado por el front; lo desempaquetamos
        // antes de meter al JSONB para que data.outletBusinessHours sea un objeto
        // (no un string-de-json).
        if (!empty($f['businessHours'])) {
            $bh = json_decode((string) $f['businessHours'], true);
            if (is_array($bh)) { $record['outletBusinessHours'] = $bh; }
        }

        $result = ncmUpdate([
            'records'     => $record,
            'table'       => 'outlet',
            'where'       => 'outletId = ? AND companyId = ?',
            'whereParams' => [$id, $companyId],
        ]);
        return is_array($result) && empty($result['error']);
    }

    /**
     * Crea una sucursal en blanco: outlet + caja + filas de inventario a 0 para items rastreados.
     * Usa RETURNING para obtener el UUID generado (compatible con PG; Insert_ID() no funciona con UUIDs).
     * @return string|null UUID de la nueva sucursal, null en error.
     */
    public function create($companyId)
    {
        global $db;

        $db->StartTrans();

        // `itemsTaxIncluded` está demoted al JSONB `data` (ver comentario del
        // schema línea 132). Antes el INSERT lo listaba como columna y fallaba
        // silenciosamente — la query revertía la TX y create() devolvía null.
        $res = $db->Execute(
            "INSERT INTO outlet (outletName, outletStatus, companyId, data)
             VALUES ('Nueva Sucursal', 1, ?, ?)
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

        // Inventory blank-rows: solo si el plan de la company incluye `inventory`. Antes esto
        // venía de `$plansValues[PLAN]['inventory']` (global del panel, cargado desde
        // `getAllPlans()` que hace `SELECT * FROM plans` y aplana JSONB). Ahora va por sub-query
        // directa: `plans` se joinea por `plan_code = company.plan`. CRÍTICO: `inventory` vive
        // dentro del JSONB `features` (db-schema-postgres.sql:78), NO como columna top — leer
        // con `features->>'inventory'`. Match-fail → null → 0 = no insertar (comportamiento
        // conservador, mismo que el panel original cuando `$plansValues[PLAN]` no existe).
        $planRow = ncmExecute(
            "SELECT COALESCE((p.features->>'inventory')::int, 0) AS inventory
               FROM company c JOIN plans p ON p.plan_code = c.plan
              WHERE c.companyId = ? LIMIT 1",
            [$companyId]
        );
        $allowsInventory = (int) ($planRow['inventory'] ?? 0);

        if ($allowsInventory > 0) {
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
     * Elimina una sucursal y su cascada completa dentro de una transacción. Verifica ownership
     * por $companyId. El caller debe verificar que $id != OUTLET_ID (no borrar el outlet activo).
     *
     * Port FIEL de `deleteOutlet()` (panel/includes/functions.php:3035): mismas tablas, mismo
     * orden, misma TX. `$companyId` parametrizado en vez de leer `COMPANY_ID` global.
     * @return bool
     */
    public function delete($id, $companyId)
    {
        global $db;

        // Ownership: la sucursal pertenece al tenant que pide el borrado.
        $outlet = ncmExecute(
            'SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ?',
            [$id, $companyId]
        );
        if (!$outlet) { return false; }

        return $this->cascadeDelete($id);
    }

    /** Cascade DELETE — copia exacta del cuerpo de deleteOutlet() del panel. */
    private function cascadeDelete($id, $fullDelete = false)
    {
        global $db;

        $db->StartTrans();

        $db->Execute('DELETE FROM drawer WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM expenses WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM inventory WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM register WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM taxonomy WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM stock WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM stockTrigger WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM satisfaction WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM production WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM notify WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM giftCardSold WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM comission WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM toItemLocation WHERE outletId = ?', [$id]);

        if (!$fullDelete) {
            $db->Execute('UPDATE item SET outletId = NULL WHERE outletId = ?', [$id]);
            $db->Execute('UPDATE contact SET outletId = NULL WHERE outletId = ?', [$id]);
        }

        $db->Execute(
            'DELETE FROM itemSold WHERE transactionId IN (SELECT transactionId FROM transaction WHERE outletId = ?)',
            [$id]
        );
        $db->Execute('DELETE FROM transaction WHERE outletId = ?', [$id]);
        $db->Execute('DELETE FROM outlet WHERE outletId = ?', [$id]);

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        return !$failed;
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

    /** Da forma a una fila de `outlet` (ya aplanada) → claves que consume el front.
     *
     * Tras la migración 14 (jsonb demote), address/phone/whatsApp/email/billingName/
     * ruc/description vienen aplanados desde el JSONB `data` por _flattenJsonb —
     * los lookups `$r['outletAddress']` siguen funcionando sin código condicional.
     * lat/lng salen de columnas numéricas nuevas.
     */
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
            $row['lat']             = ($r['lat'] ?? null) !== null ? (float) $r['lat'] : null;
            $row['lng']             = ($r['lng'] ?? null) !== null ? (float) $r['lng'] : null;
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

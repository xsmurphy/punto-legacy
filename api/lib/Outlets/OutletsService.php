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
            // priceListId vive en data JSONB; ncmUpdate lo rutea solo.
            'priceListId'           => isset($f['priceListId']) && $f['priceListId'] !== '' ? $f['priceListId'] : null,
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
     * Crea una sucursal: outlet + caja + filas de inventario a 0 para items rastreados.
     *
     * Si `$fields` está vacío, crea un placeholder ("Nueva Sucursal" activa). Esto
     * sigue soportado por compatibilidad con el flujo legacy (click → blank → edit)
     * pero el flujo nuevo de panel-next pasa el payload completo del form para
     * crear la sucursal con los datos finales en un solo round-trip — evita
     * sucursales huérfanas si el usuario abandona el form sin guardar.
     *
     * Usa RETURNING para obtener el UUID generado (compatible con PG; Insert_ID()
     * no funciona con UUIDs).
     * @param array|null $fields Mismo shape que update() recibe. Null = blank.
     * @return string|null UUID de la nueva sucursal, null en error.
     */
    public function create($companyId, ?array $fields = null)
    {
        global $db;

        // Defaults para el INSERT inicial. ncmInsert rutea automáticamente
        // los campos no-whitelist al JSONB `data`, así que solo entregamos
        // los campos relevantes; AutoExecute castea bool/int/string a la
        // columna correcta (incl. data → jsonb).
        $name   = ($fields && isset($fields['name']) && $fields['name'] !== '') ? $fields['name'] : 'Nueva Sucursal';
        $status = ($fields && isset($fields['status'])) ? (int) (bool) $fields['status'] : 1;
        // Precedencia explícita: el array literal del legacy tenía bug
        // ('itemsTaxIncluded' => $f['taxIncluded'] ?? 1 ? 1 : 0 evaluaba siempre 1).
        $taxIncluded = ($fields && array_key_exists('taxIncluded', $fields))
            ? ((bool) $fields['taxIncluded'] ? 1 : 0)
            : 1;

        $db->StartTrans();

        // Outlet — ncmInsert genera UUID v7, rutea unknowns al JSONB y
        // castea correctamente. Antes era $db->Execute con cast text→jsonb
        // manual que en algunas builds de PG no resolvía y devolvía 0 filas
        // afectadas → outletId vacío → 500 silente "No se pudo crear".
        $outletId = ncmInsert([
            'table'   => 'outlet',
            'records' => [
                'outletName'      => $name,
                'outletStatus'    => $status,
                'companyId'       => $companyId,
                // `itemsTaxIncluded` no está en el whitelist outlet → va a JSONB.
                'itemsTaxIncluded' => $taxIncluded,
            ],
        ]);

        if (!$outletId) {
            $errMsg = method_exists($db, 'ErrorMsg') ? (string) $db->ErrorMsg() : '';
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException(
                'No se pudo crear la sucursal (outlet INSERT): ' . ($errMsg !== '' ? $errMsg : 'sin detalle del driver')
            );
        }

        // `register.registerStatus` es BOOLEAN NOT NULL en PG (db-schema-postgres.sql:145).
        // ncmInsert via AutoExecute coerciona int→bool correctamente.
        $registerId = ncmInsert(['records' => [
            'registerName'   => 'Nueva Caja',
            'registerStatus' => 1,
            'outletId'       => $outletId,
            'companyId'      => $companyId,
        ], 'table' => 'register']);

        if (!$registerId) {
            $errMsg = method_exists($db, 'ErrorMsg') ? (string) $db->ErrorMsg() : '';
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException(
                'No se pudo crear la caja inicial: ' . ($errMsg !== '' ? $errMsg : 'sin detalle del driver')
            );
        }

        // Inventory blank-rows: solo si el plan de la company incluye `inventory`.
        // `inventory` vive en el JSONB `features` (db-schema-postgres.sql:78) como
        // BOOLEAN (`true`/`false`), no integer — el seed `plans` serializa PHP
        // bool→json bool. `(features->>'inventory')::int` tira 22P02
        // "invalid input syntax for type integer: \"true\"" y aborta la TX → el
        // INSERT inicial se rollbackea silente. Comparamos jsonb directo y
        // emitimos 0/1 (NULL/key ausente → 0).
        $planRow = ncmExecute(
            "SELECT CASE WHEN p.features->'inventory' = 'true'::jsonb THEN 1 ELSE 0 END AS inventory
               FROM company c JOIN plans p ON p.plan_code = c.plan
              WHERE c.companyId = ? LIMIT 1",
            [$companyId]
        );
        $allowsInventory = (int) ($planRow['inventory'] ?? 0);

        if ($allowsInventory > 0) {
            // SAVEPOINT defensivo: el comentario original prometía "log y seguimos"
            // si el backfill falla, pero en PG cualquier error dentro de una TX la
            // pone en estado *aborted* — el siguiente query (UPDATE outlet del
            // update() de abajo) cae con 25P02 "current transaction is aborted".
            // Con SAVEPOINT podemos rollbackear SOLO el INSERT y mantener la TX
            // viva, cumpliendo el contrato del comentario.
            //
            // itemTrackInventory pasó a BOOLEAN en la Migración 15 (item_kind) —
            // `> 0` tira 42883 "operator does not exist: boolean > integer". Usar
            // `IS TRUE` matchea el patrón ya aplicado en la propia Migración 15.
            // itemStatus sigue siendo smallint, `= 1` es correcto.
            $db->Execute('SAVEPOINT inventory_backfill');
            $invRes = $db->Execute(
                "INSERT INTO inventory (inventoryCount, itemId, inventorySource, companyId, outletId)
                 SELECT 0, itemId, 'new_outlet', ?, ?
                 FROM item
                 WHERE companyId = ? AND itemTrackInventory IS TRUE AND itemStatus = 1",
                [$companyId, $outletId, $companyId]
            );
            if ($invRes === false) {
                // hay tenants con miles de items y un timeout acá no debe
                // perder la sucursal recién creada. Log y seguimos.
                $errMsg = method_exists($db, 'ErrorMsg') ? (string) $db->ErrorMsg() : '';
                $db->Execute('ROLLBACK TO SAVEPOINT inventory_backfill');
                error_log('[outlets.create] inventory backfill failed for outlet ' . $outletId . ': ' . $errMsg);
            } else {
                $db->Execute('RELEASE SAVEPOINT inventory_backfill');
            }
        }

        // Si el caller mandó campos del form, aplicamos el resto via update() para
        // no duplicar la lógica de routing JSONB (address/phone/etc) ni de
        // lat/lng. El name/status ya fueron al INSERT inicial; update() los
        // reescribe con los mismos valores (no-op cost).
        if ($fields) {
            $this->update($outletId, $companyId, $fields);
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('La transacción de creación abortó luego del INSERT inicial.');
        }
        return $outletId;
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
            // priceListId vive en data JSONB — flattened por ncmExecute/_flattenJsonb.
            $row['priceListId'] = $r['priceListId'] ?? null;
        }
        return $row;
    }
}

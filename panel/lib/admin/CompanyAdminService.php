<?php

/**
 * CompanyAdminService.php — capa de datos del panel /admin para gestión de empresas.
 *
 * Realm aislado del tenant: queries cross-tenant SIN companyId filter
 * (precisamente porque listan/leen TODAS las empresas para super-admins).
 *
 * F3.1 (esta entrega): solo lecturas — listAll, get, getCounts.
 * F3.2: update (company+setting+module en TX).
 * F3.3: delete (cascada de ~30 DELETEs en TX).
 *
 * CRÍTICO §22.8: muchas columnas legacy (settingName, settingCountry, plan,
 * blocked, epos, moduleData, eposData, …) fueron DEMOTED a company.config JSONB
 * en la migración PG. Este servicio aplica el flatten inline (mergeConfig) en vez
 * de depender del ncmExecute global de includes/functions.php — el endpoint
 * admin no carga functions.php (es realm aislado y limpio).
 *
 * Patrón listAll: pagina + filtra en PHP porque las keys de búsqueda viven en
 * JSONB y el volumen de empresas es bajo (cientos, no millones). owner+counts
 * se traen BATCHED con un único IN() para evitar N+1.
 *
 * Ver context/02-arquitectura.md § Admin realm + 10-roadmap.md (F3).
 */

class CompanyAdminService
{
    /**
     * Lista paginada+filtrada de empresas con owner y counts pre-cargados.
     *
     * Total devuelto es el COUNT del set filtrado (no de toda la tabla) para
     * que la UI muestre stats coherentes con la búsqueda.
     */
    public function listAll(int $limit = 200, int $offset = 0, string $filter = ''): array
    {
        global $db;

        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        // 1) Cargar todas las empresas con su config JSONB ya aplanada.
        //    Volumen esperado: cientos, no millones.
        $r = $db->Execute('SELECT * FROM company ORDER BY createdAt DESC NULLS LAST');
        $all = [];
        if ($r) {
            while (!$r->EOF) {
                $flat = $this->mergeConfig($r->fields);
                $all[] = $this->shapeListRow($flat);
                $r->MoveNext();
            }
        }

        // 2) Filtrar por texto (case-insensitive) sobre name + companyName.
        if ($filter !== '') {
            $needle = mb_strtolower($filter, 'UTF-8');
            $all = array_values(array_filter($all, function ($row) use ($needle) {
                $hay = mb_strtolower(($row['name'] ?? '') . ' ' . ($row['companyName'] ?? ''), 'UTF-8');
                return strpos($hay, $needle) !== false;
            }));
        }

        $total = count($all);
        $page  = array_slice($all, $offset, $limit);

        // 3) Enriquecer SOLO las filas de la página con owner + counts (batched).
        $ids = array_values(array_filter(array_map(fn($r) => $r['id'] ?? null, $page)));
        if ($ids) {
            $owners = $this->getOwnersBatched($ids);
            $counts = $this->getCountsBatched($ids, false);
            foreach ($page as &$row) {
                $row['owner']  = $owners[$row['id']] ?? null;
                $row['counts'] = $counts[$row['id']] ?? ['outlets' => 0, 'registers' => 0];
            }
            unset($row);
        }

        return ['rows' => $page, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * Una empresa con toda su metadata: settings, modules, owner, counts, ePOS.
     */
    public function get(string $id): ?array
    {
        global $db;

        $r = $db->Execute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$id]);
        if (!$r || $r->EOF) {
            return null;
        }
        $flat = $this->mergeConfig($r->fields);

        $owner  = $this->getOwner($id);
        $counts = $this->getCounts($id, true);

        $moduleData = $this->jsonOrArr($this->pick($flat, 'moduleData'));
        $eposData   = $this->jsonOrArr($this->pick($flat, 'eposData'));

        return [
            'id'                  => (string) ($this->pick($flat, 'companyId') ?? $id),
            'name'                => (string) ($this->pick($flat, 'companyName') ?? ''),
            'settingName'         => (string) ($this->pick($flat, 'settingName') ?? ''),
            'slug'                => (string) ($this->pick($flat, 'slug') ?? ''),
            'status'              => (string) ($this->pick($flat, 'status') ?? ''),
            'plan'                => $this->pick($flat, 'plan'),
            'discount'            => $this->pick($flat, 'discount'),
            'smsCredit'           => $this->pick($flat, 'smsCredit'),
            'country'             => (string) ($this->pick($flat, 'settingCountry') ?? ''),
            'companyCategoryId'   => (string) ($this->pick($flat, 'settingCompanyCategoryId') ?? ''),
            'externalCustomerId'  => $this->pick($flat, 'settingEncomID'),
            'blocked'             => (int) ($this->pick($flat, 'blocked') ?? 0),
            'partialBlock'        => (int) ($this->pick($flat, 'settingPartialBlock') ?? 0),
            'planExpired'         => $this->pick($flat, 'planExpired'),
            'autoSMSCredit'       => $this->pick($flat, 'settingAutoSMSCredit'),
            'epos'                => (int) ($this->pick($flat, 'epos') ?? 0),
            'ecom'                => (int) ($this->pick($flat, 'ecom') ?? 0),
            'createdAt'           => $this->pick($flat, 'createdAt'),
            'customersLastUpdate' => $this->pick($flat, 'customersLastUpdate'),
            'companyLastUpdate'   => $this->pick($flat, 'companyLastUpdate'),
            'moduleData'          => $moduleData ?: new \stdClass(),
            'eposData'            => $eposData   ?: new \stdClass(),
            'owner'               => $owner,
            'counts'              => $counts,
        ];
    }

    /**
     * Counts de outlets/registers/users/customers para UNA empresa.
     */
    public function getCounts(string $id, bool $full = true): array
    {
        global $db;

        $q = function ($sql, $bind) use ($db) {
            $r = $db->Execute($sql, $bind);
            return ($r && !$r->EOF) ? (int) ($r->fields['n'] ?? $r->fields['count'] ?? 0) : 0;
        };

        $out = [
            'outlets'   => $q('SELECT COUNT(*) AS n FROM outlet   WHERE companyId = ?', [$id]),
            'registers' => $q('SELECT COUNT(*) AS n FROM register WHERE companyId = ?', [$id]),
        ];
        if ($full) {
            $out['users']     = $q('SELECT COUNT(*) AS n FROM contact WHERE type = 0 AND companyId = ?', [$id]);
            $out['customers'] = $q('SELECT COUNT(*) AS n FROM contact WHERE type = 1 AND companyId = ?', [$id]);
        }
        return $out;
    }

    // --- batched ------------------------------------------------------------

    /**
     * Owner (contact main=true role=1 type=0) por companyId — batched.
     * Devuelve mapa companyId => owner array (o ausente si no hay owner).
     */
    private function getOwnersBatched(array $ids): array
    {
        global $db;
        if (!$ids) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $r = $db->Execute(
            "SELECT companyId, contactId, contactName, contactSecondName, contactEmail, contactPhone
               FROM contact
              WHERE companyId IN ($place) AND main = 'true' AND role = 1 AND type = 0",
            $ids
        );

        $out = [];
        if ($r) {
            while (!$r->EOF) {
                $f = $r->fields;
                $get = fn(string $k) => $f[$k] ?? $f[strtolower($k)] ?? '';
                $cid = (string) $get('companyId');
                // Primer match gana (un company debería tener 1 owner; si hay dups, ignorar resto).
                if (!isset($out[$cid])) {
                    $out[$cid] = [
                        'id'         => (string) $get('contactId'),
                        'name'       => (string) $get('contactName'),
                        'secondName' => (string) $get('contactSecondName'),
                        'email'      => (string) $get('contactEmail'),
                        'phone'      => (string) $get('contactPhone'),
                    ];
                }
                $r->MoveNext();
            }
        }
        return $out;
    }

    /**
     * Counts (outlets+registers, opcional users+customers) batched por companyId.
     * Devuelve mapa companyId => ['outlets'=>n, 'registers'=>n, ...].
     */
    private function getCountsBatched(array $ids, bool $full = true): array
    {
        global $db;
        if (!$ids) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));

        $accumulate = function (string $sql, array $bind, string $key) use ($db, $ids) {
            $out = array_fill_keys($ids, 0);
            $r = $db->Execute($sql, $bind);
            if ($r) {
                while (!$r->EOF) {
                    $cid = (string) ($r->fields['companyid'] ?? $r->fields['companyId'] ?? '');
                    $out[$cid] = (int) ($r->fields['n'] ?? 0);
                    $r->MoveNext();
                }
            }
            return $out;
        };

        $outlets   = $accumulate("SELECT companyId, COUNT(*) AS n FROM outlet   WHERE companyId IN ($place) GROUP BY companyId", $ids, 'outlets');
        $registers = $accumulate("SELECT companyId, COUNT(*) AS n FROM register WHERE companyId IN ($place) GROUP BY companyId", $ids, 'registers');

        $users = $customers = [];
        if ($full) {
            $users     = $accumulate("SELECT companyId, COUNT(*) AS n FROM contact WHERE type = 0 AND companyId IN ($place) GROUP BY companyId", $ids, 'users');
            $customers = $accumulate("SELECT companyId, COUNT(*) AS n FROM contact WHERE type = 1 AND companyId IN ($place) GROUP BY companyId", $ids, 'customers');
        }

        $out = [];
        foreach ($ids as $id) {
            $row = [
                'outlets'   => $outlets[$id]   ?? 0,
                'registers' => $registers[$id] ?? 0,
            ];
            if ($full) {
                $row['users']     = $users[$id]     ?? 0;
                $row['customers'] = $customers[$id] ?? 0;
            }
            $out[$id] = $row;
        }
        return $out;
    }

    // --- F3.3: delete -------------------------------------------------------

    /**
     * Suspensión suave: status='cancelled' + blocked=1. Reversible desde update().
     * No toca datos de negocio — solo impide el acceso al tenant.
     *
     * Devuelve ['ok'=>true] o ['ok'=>false,'error'=>'…','code'=>NNN].
     */
    public function softDelete(string $id): array
    {
        global $db;

        $exists = $db->Execute('SELECT 1 FROM company WHERE companyId = ? LIMIT 1', [$id]);
        if (!$exists || $exists->EOF) {
            return ['ok' => false, 'error' => 'Empresa no encontrada', 'code' => 404];
        }

        $r = $db->Execute(
            "UPDATE company SET status = 'cancelled', blocked = 1, updatedAt = now() WHERE companyId = ?",
            [$id]
        );
        if ($r === false) {
            return ['ok' => false, 'error' => 'Error de BD: ' . $db->ErrorMsg(), 'code' => 500];
        }
        return ['ok' => true];
    }

    /**
     * Eliminación permanente en cascada de una empresa y TODOS sus datos.
     *
     * IRREVERSIBLE. Ejecuta ~55 statements en una única transacción PG ordenados
     * por dependencias de FK. En caso de cualquier error, hace ROLLBACK completo.
     *
     * Orden de eliminación:
     *   0. NULL self-referential FKs (transaction.transactionParentId, contact.parentId+userId,
     *      item.itemParentId, accountCategory.accountCategoryParentId, outlet.taxId←circular)
     *   1. Tablas que referencian transaction (itemSold, toTransaction, toTaxObj, toAddress,
     *      toScheduleUID, giftCardSold, satisfaction, vPayments, comission, stock, printServer)
     *   2. Tablas que referencian contact/item/register (cRecordValue→cRecordField→customerRecord,
     *      customerAddress, contactNote, campaign, drawer, toContact, production, reminder,
     *      activityLog, attendance, inventory, inventoryCount)
     *   3. Join tables polimórficas sin companyId (toTag, toCategory, toPaymentMethod,
     *      tableTags, toLocation, stockTrigger, toCompound)
     *   4. Tablas simples con companyId (itemLocation, upsell, itemDeleted, accountCategory, notify,
     *      files, paymentMethods, priceList, processorId, recurring, expenses, tasks, cpayments)
     *   5. Tablas core (transaction → item → contact → taxonomy → register → outlet)
     *   6. Empresa (NULL company.parentId de hijos, DELETE company — device via ON DELETE CASCADE)
     *
     * Devuelve ['ok'=>true] o ['ok'=>false,'error'=>'…','code'=>NNN].
     */
    public function hardDelete(string $id): array
    {
        global $db;

        $exists = $db->Execute('SELECT 1 FROM company WHERE companyId = ? LIMIT 1', [$id]);
        if (!$exists || $exists->EOF) {
            return ['ok' => false, 'error' => 'Empresa no encontrada', 'code' => 404];
        }

        $db->BeginTrans();
        try {
            // Lanza RuntimeException en cualquier fallo de BD.
            $run = function (string $sql, array $binds = []) use ($db): void {
                $r = $db->Execute($sql, $binds);
                if ($r === false) {
                    throw new \RuntimeException('BD: ' . $db->ErrorMsg() . ' — SQL: ' . substr($sql, 0, 120));
                }
            };

            // ── Paso 0: romper FKs auto-referenciales ──────────────────────────
            $run('UPDATE transaction     SET transactionParentId      = NULL WHERE companyId = ?', [$id]);
            $run('UPDATE contact         SET parentId = NULL, userId  = NULL WHERE companyId = ?', [$id]);
            $run('UPDATE item            SET itemParentId              = NULL WHERE companyId = ?', [$id]);
            $run('UPDATE accountCategory SET accountCategoryParentId  = NULL WHERE companyId = ?', [$id]);
            // outlet.taxId → taxonomy y taxonomy.outletId → outlet forman un ciclo;
            // nullificar taxId en outlet primero, así podemos borrar taxonomy antes que outlet.
            $run('UPDATE outlet SET taxId = NULL WHERE companyId = ?', [$id]);

            // ── Paso 1: tablas que referencian transaction ─────────────────────
            $run(
                'DELETE FROM itemSold WHERE transactionId IN ' .
                '(SELECT transactionId FROM transaction WHERE companyId = ?)',
                [$id]
            );
            $run(
                'DELETE FROM toTransaction ' .
                'WHERE parentId     IN (SELECT transactionId FROM transaction WHERE companyId = ?) ' .
                '   OR transactionId IN (SELECT transactionId FROM transaction WHERE companyId = ?)',
                [$id, $id]
            );
            $run('DELETE FROM toTaxObj   WHERE companyId = ?', [$id]);
            $run(
                'DELETE FROM toAddress WHERE transactionId IN ' .
                '(SELECT transactionId FROM transaction WHERE companyId = ?)',
                [$id]
            );
            $run(
                'DELETE FROM toScheduleUID WHERE transactionUID IN ' .
                '(SELECT transactionUID FROM transaction WHERE companyId = ? AND transactionUID IS NOT NULL)',
                [$id]
            );
            $run('DELETE FROM giftCardSold WHERE companyId = ?', [$id]);
            $run('DELETE FROM satisfaction WHERE companyId = ?', [$id]);
            $run('DELETE FROM vPayments    WHERE companyId = ?', [$id]);
            $run('DELETE FROM comission    WHERE companyId = ?', [$id]);
            $run('DELETE FROM stock        WHERE companyId = ?', [$id]);
            $run('DELETE FROM printServer  WHERE companyId = ?', [$id]);

            // ── Paso 2: tablas que referencian contact / item / register ───────
            $run(
                'DELETE FROM cRecordValue WHERE customerId IN ' .
                '(SELECT contactId FROM contact WHERE companyId = ?)',
                [$id]
            );
            $run(
                'DELETE FROM cRecordField WHERE customerRecordId IN ' .
                '(SELECT customerRecordId FROM customerRecord WHERE companyId = ?)',
                [$id]
            );
            $run('DELETE FROM customerRecord  WHERE companyId = ?', [$id]);
            $run('DELETE FROM customerAddress WHERE companyId = ?', [$id]);
            $run('DELETE FROM contactNote     WHERE companyId = ?', [$id]);
            $run('DELETE FROM campaign        WHERE companyId = ?', [$id]);
            $run('DELETE FROM drawer          WHERE companyId = ?', [$id]);
            $run(
                'DELETE FROM toContact ' .
                'WHERE parentId  IN (SELECT contactId FROM contact WHERE companyId = ?) ' .
                '   OR contactId IN (SELECT contactId FROM contact WHERE companyId = ?)',
                [$id, $id]
            );
            $run('DELETE FROM production    WHERE companyId = ?', [$id]);
            $run('DELETE FROM reminder      WHERE companyId = ?', [$id]);
            $run('DELETE FROM activityLog   WHERE companyId = ?', [$id]);
            $run('DELETE FROM attendance    WHERE companyId = ?', [$id]);
            $run('DELETE FROM inventory     WHERE companyId = ?', [$id]);
            $run('DELETE FROM inventoryCount WHERE companyId = ?', [$id]);

            // ── Paso 3: join tables polimórficas (sin companyId propio) ────────
            $run(
                'DELETE FROM toTag WHERE parentId IN (' .
                '    SELECT transactionId FROM transaction WHERE companyId = ? ' .
                '    UNION ALL SELECT contactId FROM contact WHERE companyId = ? ' .
                '    UNION ALL SELECT itemId FROM item WHERE companyId = ?' .
                ')',
                [$id, $id, $id]
            );
            $run(
                'DELETE FROM toCategory WHERE parentId IN (' .
                '    SELECT itemId FROM item WHERE companyId = ? ' .
                '    UNION ALL SELECT outletId FROM outlet WHERE companyId = ?' .
                ')',
                [$id, $id]
            );
            $run(
                'DELETE FROM toPaymentMethod WHERE parentId IN ' .
                '(SELECT outletId FROM outlet WHERE companyId = ?)',
                [$id]
            );
            $run(
                'DELETE FROM tableTags WHERE tableId IN (' .
                '    SELECT outletId FROM outlet WHERE companyId = ? ' .
                '    UNION ALL SELECT registerId FROM register WHERE companyId = ?' .
                ')',
                [$id, $id]
            );
            // toLocation: outletId y itemId son nullable; locationId (→taxonomy) NOT NULL siempre presente.
            // Cubrir los tres para evitar filas huérfanas si alguno es NULL.
            $run(
                'DELETE FROM toLocation ' .
                'WHERE outletId    IN (SELECT outletId    FROM outlet    WHERE companyId = ?) ' .
                '   OR itemId      IN (SELECT itemId      FROM item      WHERE companyId = ?) ' .
                '   OR locationId  IN (SELECT taxonomyId  FROM taxonomy  WHERE companyId = ?)',
                [$id, $id, $id]
            );
            $run(
                'DELETE FROM stockTrigger WHERE outletId IN ' .
                '(SELECT outletId FROM outlet WHERE companyId = ?)',
                [$id]
            );
            $run(
                'DELETE FROM toCompound WHERE compoundId IN ' .
                '(SELECT itemId FROM item WHERE companyId = ?)',
                [$id]
            );

            // ── Paso 4: tablas simples con companyId ───────────────────────────
            // itemLocation: itemId tiene ON DELETE CASCADE (se borra con item en paso 5),
            // pero outletId NO tiene cascade. Borrar explícitamente antes del core delete
            // para que DELETE FROM outlet no choque con FK de outletId.
            $run('DELETE FROM itemLocation    WHERE companyId = ?', [$id]);
            $run('DELETE FROM upsell          WHERE companyId = ?', [$id]);
            $run('DELETE FROM itemDeleted     WHERE companyId = ?', [$id]);
            $run('DELETE FROM accountCategory WHERE companyId = ?', [$id]);
            $run('DELETE FROM notify          WHERE companyId = ?', [$id]);
            $run('DELETE FROM files           WHERE companyId = ?', [$id]);
            $run('DELETE FROM paymentMethods  WHERE companyId = ?', [$id]);
            $run('DELETE FROM priceList       WHERE companyId = ?', [$id]);
            $run('DELETE FROM processorId     WHERE companyId = ?', [$id]);
            $run('DELETE FROM recurring       WHERE companyId = ?', [$id]);
            $run('DELETE FROM expenses        WHERE companyId = ?', [$id]);
            $run('DELETE FROM tasks           WHERE companyId = ?', [$id]);
            $run('DELETE FROM cpayments       WHERE companyId = ?', [$id]);

            // ── Paso 5: tablas core (order = FK dependency graph) ──────────────
            // transaction antes que item/contact/register/outlet
            $run('DELETE FROM transaction WHERE companyId = ?', [$id]);
            // item antes que contact (item.supplierId → contact nullable)
            $run('DELETE FROM item        WHERE companyId = ?', [$id]);
            // contact antes que taxonomy (contact.categoryId → taxonomy nullable)
            $run('DELETE FROM contact     WHERE companyId = ?', [$id]);
            // taxonomy antes que outlet (taxonomy.outletId → outlet; taxId ya NULLed en paso 0)
            $run('DELETE FROM taxonomy    WHERE companyId = ?', [$id]);
            // register antes que outlet (register.outletId NOT NULL)
            $run('DELETE FROM register    WHERE companyId = ?', [$id]);
            $run('DELETE FROM outlet      WHERE companyId = ?', [$id]);

            // ── Paso 6: empresa (device via ON DELETE CASCADE) ─────────────────
            $run('UPDATE company SET parentId = NULL WHERE parentId = ?', [$id]);
            $run('DELETE FROM company WHERE companyId = ?', [$id]);

            $db->CommitTrans();
            return ['ok' => true];

        } catch (\Throwable $e) {
            $db->RollbackTrans();
            return ['ok' => false, 'error' => 'Error en cascada: ' . $e->getMessage(), 'code' => 500];
        }
    }

    // --- F3.2: update -------------------------------------------------------

    /**
     * Actualiza campos de una empresa. Solo los campos presentes en `$input`
     * son tocados (PATCH semántico — no reemplaza todo el row).
     *
     * Columnas directas soportadas: status, plan, blocked, planExpired, isTrial,
     *   smsCredit, discount, expiresAt.
     * Columnas JSONB (config merge): settingName, settingCountry.
     *
     * Devuelve ['ok'=>true] o ['ok'=>false,'error'=>'…','code'=>NNN].
     */
    public function update(string $id, array $input): array
    {
        global $db;

        $exists = $db->Execute('SELECT 1 FROM company WHERE companyId = ? LIMIT 1', [$id]);
        if (!$exists || $exists->EOF) {
            return ['ok' => false, 'error' => 'Empresa no encontrada', 'code' => 404];
        }

        $sets  = [];
        $binds = [];

        // ── status ─────────────────────────────────────────────────────────
        if (array_key_exists('status', $input)) {
            $v = (string) $input['status'];
            if (!in_array($v, ['active', 'suspended', 'cancelled'], true)) {
                return ['ok' => false, 'error' => "status inválido: {$v}", 'code' => 422];
            }
            $sets[] = 'status = ?';
            $binds[] = $v;
        }

        // ── enteros ────────────────────────────────────────────────────────
        foreach (['plan', 'blocked', 'smsCredit'] as $col) {
            if (!array_key_exists($col, $input)) { continue; }
            $v = (int) $input[$col];
            if ($col === 'blocked' && !in_array($v, [0, 1], true)) {
                return ['ok' => false, 'error' => 'blocked debe ser 0 o 1', 'code' => 422];
            }
            if ($col === 'plan' && $v < 0) {
                return ['ok' => false, 'error' => 'plan no puede ser negativo', 'code' => 422];
            }
            $sets[] = "$col = ?";
            $binds[] = $v;
        }

        // ── decimales ──────────────────────────────────────────────────────
        if (array_key_exists('discount', $input)) {
            $sets[] = 'discount = ?';
            $binds[] = (float) $input['discount'];
        }

        // ── booleanos ──────────────────────────────────────────────────────
        foreach (['planExpired', 'isTrial'] as $col) {
            if (!array_key_exists($col, $input)) { continue; }
            $sets[] = "$col = ?";
            $binds[] = filter_var($input[$col], FILTER_VALIDATE_BOOLEAN);
        }

        // ── timestamp nullable ─────────────────────────────────────────────
        if (array_key_exists('expiresAt', $input)) {
            $v = ($input['expiresAt'] === null || $input['expiresAt'] === '') ? null : (string) $input['expiresAt'];
            $sets[] = 'expiresAt = ?';
            $binds[] = $v;
        }

        // ── config JSONB — merge parcial ───────────────────────────────────
        $configPatch = [];
        foreach (['settingName', 'settingCountry'] as $key) {
            if (array_key_exists($key, $input)) {
                $configPatch[$key] = (string) $input[$key];
            }
        }
        if ($configPatch) {
            $sets[] = 'config = config || ?::jsonb';
            $binds[] = json_encode($configPatch, JSON_UNESCAPED_UNICODE);
        }

        if (!$sets) {
            return ['ok' => true]; // noop — nada que actualizar
        }

        $sets[] = 'updatedAt = now()';
        $binds[] = $id;

        $r = $db->Execute(
            'UPDATE company SET ' . implode(', ', $sets) . ' WHERE companyId = ?',
            $binds
        );

        if ($r === false) {
            return ['ok' => false, 'error' => 'Error de BD: ' . $db->ErrorMsg(), 'code' => 500];
        }
        return ['ok' => true];
    }

    // --- internos -----------------------------------------------------------

    private function getOwner(string $companyId): ?array
    {
        $map = $this->getOwnersBatched([$companyId]);
        return $map[$companyId] ?? null;
    }

    private function shapeListRow(array $flat): array
    {
        return [
            'id'                  => (string) ($this->pick($flat, 'companyId') ?? ''),
            'name'                => (string) ($this->pick($flat, 'settingName') ?: $this->pick($flat, 'companyName') ?: ''),
            'companyName'         => (string) ($this->pick($flat, 'companyName') ?? ''),
            'status'              => (string) ($this->pick($flat, 'status') ?? ''),
            'plan'                => $this->pick($flat, 'plan'),
            'discount'            => $this->pick($flat, 'discount'),
            'smsCredit'           => $this->pick($flat, 'smsCredit'),
            'country'             => (string) ($this->pick($flat, 'settingCountry') ?? ''),
            'companyCategoryId'   => (string) ($this->pick($flat, 'settingCompanyCategoryId') ?? ''),
            'externalCustomerId'  => $this->pick($flat, 'settingEncomID'),
            'blocked'             => (int) ($this->pick($flat, 'blocked') ?? 0),
            'planExpired'         => $this->pick($flat, 'planExpired'),
            'epos'                => (int) ($this->pick($flat, 'epos') ?? 0),
            'ecom'                => (int) ($this->pick($flat, 'ecom') ?? 0),
            'createdAt'           => $this->pick($flat, 'createdAt'),
            'customersLastUpdate' => $this->pick($flat, 'customersLastUpdate'),
            'companyLastUpdate'   => $this->pick($flat, 'companyLastUpdate'),
        ];
    }

    /**
     * Aplana keys de `data`/`meta`/`config` (cuando son JSON strings) en el array
     * de la fila. La columna nombrada gana sobre la key del JSONB (verbatim §22.8).
     *
     * IMPORTANTE: `$r->fields` es un `CaseInsensitiveArray` (objeto con props
     * privadas). `(array) $obj` no funciona — hay que llamar `->toArray()`.
     *
     * Detalle sobre el "row gana": las columnas nativas de PG llegan en lowercase
     * (`companyid`) y las keys del JSONB suelen ser camelCase (`companyId`).
     * Cuando una key coincide en casing (p.ej. ambas `blocked`), array_merge da
     * prioridad al row. Cuando no coinciden, ambas sobreviven — `pick()` las
     * resuelve case-insensitive, así que el resultado es estable.
     */
    private function mergeConfig($row): array
    {
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        } elseif (!is_array($row)) {
            $row = (array) $row;
        }
        foreach (['data', 'meta', 'config'] as $col) {
            $val = $row[$col] ?? $row[strtolower($col)] ?? null;
            if (is_string($val) && $val !== '') {
                $decoded = json_decode($val, true);
                if (is_array($decoded) && !array_is_list($decoded)) {
                    $row = array_merge($decoded, $row); // row gana sobre JSONB cuando hay key match
                    unset($row[$col], $row[strtolower($col)]);
                }
            }
        }
        return $row;
    }

    /** Acceso case-insensitive a una clave (PG suele devolver lowercase). */
    private function pick(array $row, string $key): mixed
    {
        return $row[$key] ?? $row[strtolower($key)] ?? null;
    }

    private function jsonOrArr($v): ?array
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v) || $v === '') {
            return null;
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : null;
    }
}

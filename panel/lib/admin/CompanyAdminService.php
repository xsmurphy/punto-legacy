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

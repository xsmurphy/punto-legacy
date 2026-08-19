<?php
require_once __DIR__ . '/PermissionCatalog.php';

/**
 * Servicio de roles y permisos del tenant.
 *
 * Storage: tabla `taxonomy`.
 *   - taxonomytype='role'     → rol del tenant
 *   - taxonomytype='roleData' → permisos del rol (sourceid=roleId)
 *
 * El `roleId` en el JWT puede ser:
 *   - int legacy (1,2,3,4,5,7) → se resuelve via resolveLegacyRole()
 *   - UUID → ya es un role custom o seed migrado
 *
 * hasPermission() es O(1) post-primer-llamado (cache por request).
 *
 * Backfill de permisos nuevos a roles existentes (context: agregar un
 * permiso al catálogo NO lo propagaba a roles ya creados — solo se
 * sembraba al crear el rol). Mecanismo:
 *   - roleData guarda, junto a `permissions`, el `slug` del rol y el
 *     `catalogVersion` (PermissionCatalog::CURRENT_VERSION) vigente al
 *     momento del último guardado — sea por seed o por updateRole().
 *   - En cada lectura (_loadPermissions), para roles seed (manager/cashier;
 *     owner se resuelve aparte, ver abajo) se compara el seed default
 *     (SEED_PERMISSIONS) contra lo guardado: un permiso default ausente se
 *     agrega SOLO si su `since` es posterior al `catalogVersion` guardado
 *     del rol. Un permiso con `since` <= catalogVersion guardado que no
 *     está en la lista se asume revocado a propósito por el admin — nunca
 *     se revive. Roles sin `catalogVersion` (guardados antes de este
 *     mecanismo) se asumen sincronizados hasta PermissionCatalog::BASELINE_VERSION.
 *   - Roles custom (isSeed=false, slug=null) NUNCA se reconcilian — su
 *     lista de permisos es enteramente decisión del admin, sin default
 *     asociado.
 *   - Owner sigue resuelto en runtime como TODO el catálogo (sin tocar
 *     storage) — ya era auto-sync antes de este cambio.
 *   - Reconciliación es lazy (on-read) e idempotente: si no hay gap, no
 *     escribe nada; si lo hay, persiste el merge una sola vez.
 */
final class RoleService
{
    /** Cache por request: [companyId][roleId] => permissions[] */
    private static array $cache = [];

    /** Cache slug → UUID: ["companyId:slug"] => uuid|'' */
    private static array $slugCache = [];

    // ─── Seed slugs ────────────────────────────────────────────────────────
    // Mapeo de int legacy → slug del seed role.
    // Decisión 2026-06-25: reducir a 3 seeds (owner/manager/cashier). Los obsoletos
    // (admin, viewer) se mapean al equivalente más cercano para no romper users.
    private const LEGACY_MAP = [
        1 => 'owner',
        2 => 'manager',  // admin viejo → manager
        3 => 'manager',
        4 => 'cashier',
        5 => 'cashier',
        7 => 'cashier',  // viewer viejo → cashier (read-only puro casi nunca se usa)
    ];

    // Permisos default por seed slug.
    private const SEED_PERMISSIONS = [
        'owner' => null, // null = TODOS (computed en seedCompanyRoles)
        'manager' => [
            'pos.sale.create','pos.sale.void','pos.sale.refund','pos.sale.creditPayment',
            'pos.drawer.open','pos.drawer.close','pos.discount.apply',
            'inventory.item.view','inventory.item.create','inventory.item.edit','inventory.item.delete',
            'inventory.stock.adjust','inventory.transfer',
            'contacts.customer.view','contacts.customer.create','contacts.customer.edit','contacts.customer.delete',
            'contacts.supplier.view','contacts.supplier.manage',
            'contacts.user.view','contacts.user.manage',
            'reports.sales.view','reports.drawers.view','reports.audit.view','reports.expenses.view',
            'reports.satisfaction.view','reports.giftcards.view','reports.purchases.view',
            'reports.schedule.view','reports.recurring.view',
            'settings.outlet.manage','settings.register.manage','settings.register.release','settings.tax.manage',
            'settings.template.manage','settings.device.pair','settings.device.manage',
            'settings.company.edit',
            'ai.agent.use','ai.agent.elevated',
            'finance.manage',
            'production.manage',
            'einvoice.manage',
        ],
        'cashier' => [
            'pos.sale.create','pos.sale.creditPayment','pos.drawer.open','pos.drawer.close',
            'inventory.item.view',
            'contacts.customer.view','contacts.customer.create',
        ],
    ];

    private const SEED_NAMES = [
        'owner'   => 'Dueño',
        'manager' => 'Encargado',
        'cashier' => 'Cajero',
    ];

    // ─── Queries ────────────────────────────────────────────────────────────

    public static function getRoles(string $companyId): array
    {
        $rs = ncmExecute(
            "SELECT taxonomyid, taxonomyname, taxonomyextra FROM taxonomy
             WHERE taxonomytype = 'role' AND companyid = ?
             ORDER BY
               CASE (taxonomyextra::json->>'isSeed')::bool WHEN true THEN 0 ELSE 1 END,
               taxonomyname ASC",
            [$companyId], false, true
        );
        $roles = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f    = $rs->fields;
                $id   = (string)($f['taxonomyid'] ?? '');
                $meta = json_decode((string)($f['taxonomyextra'] ?? '{}'), true) ?? [];
                $roles[] = [
                    'id'          => $id,
                    'name'        => (string)($f['taxonomyname'] ?? ''),
                    'isSeed'      => (bool)($meta['isSeed'] ?? false),
                    'slug'        => $meta['slug'] ?? null,
                    'permissions' => (($meta['slug'] ?? null) === 'owner')
                        ? PermissionCatalog::ids()
                        : self::_loadPermissions($id, $companyId),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        if (empty($roles)) {
            self::seedCompanyRoles($companyId);
            self::clearSlugCache($companyId);
            // No reintentar recursivamente — el llamante puede volver a llamar
        }
        return $roles;
    }

    public static function getPermissions(string $roleId, string $companyId): array
    {
        if (ctype_digit($roleId)) {
            $resolvedId = self::_resolveSlugId((int)$roleId, $companyId);
            if ($resolvedId === '') {
                self::seedCompanyRoles($companyId);
                self::clearSlugCache($companyId);
                $resolvedId = self::_resolveSlugId((int)$roleId, $companyId);
                if ($resolvedId === '') return [];
            }
            $roleId = $resolvedId;
        }
        // Owner siempre tiene TODOS los permisos del catálogo — auto-sync
        // cuando se agregan permisos nuevos sin necesidad de re-seed.
        if (self::_isOwnerRole($roleId, $companyId)) {
            return PermissionCatalog::ids();
        }
        return self::_loadPermissions($roleId, $companyId);
    }

    public static function hasPermission(string $perm, string $roleId, string $companyId): bool
    {
        // roleId puede ser int-string legacy
        if (ctype_digit($roleId)) {
            $resolvedId = self::_resolveSlugId((int)$roleId, $companyId);
            if ($resolvedId === '') {
                self::seedCompanyRoles($companyId);
                self::clearSlugCache($companyId);
                $resolvedId = self::_resolveSlugId((int)$roleId, $companyId);
                if ($resolvedId === '') return false;
            }
            $roleId = $resolvedId;
        }
        // Owner puede TODO incondicionalmente. No validamos contra el catálogo
        // para que un perm aún no registrado no produzca un false-negativo.
        if (self::_isOwnerRole($roleId, $companyId)) {
            return true;
        }
        $perms = self::_loadPermissions($roleId, $companyId);
        return in_array($perm, $perms, true);
    }

    /** Crea un role custom (no-seed). @throws RuntimeException */
    public static function createRole(string $name, array $permissions, string $companyId, string $createdBy): string
    {
        $name = trim($name);
        if ($name === '') throw new RuntimeException('El nombre del role no puede estar vacío');

        // Validar permisos
        $validIds = PermissionCatalog::ids();
        $invalid = array_diff($permissions, $validIds);
        if (!empty($invalid)) {
            throw new RuntimeException('Permisos inválidos: ' . implode(', ', $invalid));
        }

        // Verificar nombre único dentro del tenant
        $existing = ncmExecute(
            "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND LOWER(taxonomyname)=LOWER(?)",
            [$companyId, $name]
        );
        if ($existing && !empty($existing['taxonomyid'])) {
            throw new RuntimeException("Ya existe un role con el nombre '$name'");
        }

        $roleId = ncmInsert(['records' => [
            'taxonomyname'  => $name,
            'taxonomytype'  => 'role',
            'taxonomyextra' => json_encode(['isSeed' => false, 'slug' => null, 'createdBy' => $createdBy]),
            'companyid'     => $companyId,
        ], 'table' => 'taxonomy']);

        // slug=null: role custom, sin default de catálogo asociado — nunca se reconcilia.
        self::_savePermissions((string)$roleId, $permissions, $companyId, null);
        return (string)$roleId;
    }

    /**
     * Actualiza name (solo si no-seed) y/o permissions.
     * @throws RuntimeException
     */
    public static function updateRole(string $roleId, ?string $name, ?array $permissions, string $companyId): void
    {
        $row = ncmExecute(
            "SELECT taxonomyname, taxonomyextra FROM taxonomy WHERE taxonomyid=?::uuid AND taxonomytype='role' AND companyid=?",
            [$roleId, $companyId]
        );
        if (!$row) throw new RuntimeException('Role no encontrado');

        $meta   = json_decode((string)($row['taxonomyextra'] ?? '{}'), true) ?? [];
        $isSeed = (bool)($meta['isSeed'] ?? false);
        $slug   = $meta['slug'] ?? null;

        if ($name !== null) {
            if ($isSeed) throw new RuntimeException('No se puede renombrar un role del sistema');
            $name = trim($name);
            if ($name === '') throw new RuntimeException('El nombre no puede estar vacío');
        }

        if ($permissions !== null) {
            // Owner es derivado en runtime (PermissionCatalog::ids()), grabar un
            // subset confunde: la UI mostraría algo distinto a lo que se aplica.
            if ($slug === 'owner') {
                throw new RuntimeException('Los permisos del rol Dueño se asignan automáticamente y no se editan');
            }
            $validIds = PermissionCatalog::ids();
            $invalid = array_diff($permissions, $validIds);
            if (!empty($invalid)) {
                throw new RuntimeException('Permisos inválidos: ' . implode(', ', $invalid));
            }
        }

        if ($name !== null) {
            ncmExecute(
                "UPDATE taxonomy SET taxonomyname=? WHERE taxonomyid=?::uuid AND taxonomytype='role' AND companyid=?",
                [$name, $roleId, $companyId],
                true
            );
        }

        if ($permissions !== null) {
            // El admin eligió esta lista explícitamente, validada contra el
            // catálogo VIGENTE — se guarda como "sincronizado hasta CURRENT_VERSION"
            // (ver _savePermissions). Cualquier permiso default que el admin haya
            // dejado afuera queda respetado como revocación intencional.
            self::_savePermissions($roleId, $permissions, $companyId, $slug);
        }

        // Invalidar cache
        unset(self::$cache[$companyId][$roleId]);
    }

    /**
     * Elimina un role no-seed y sin usuarios asignados.
     * @throws RuntimeException
     */
    public static function deleteRole(string $roleId, string $companyId): void
    {
        $row = ncmExecute(
            "SELECT taxonomyname, taxonomyextra FROM taxonomy WHERE taxonomyid=?::uuid AND taxonomytype='role' AND companyid=?",
            [$roleId, $companyId]
        );
        if (!$row) throw new RuntimeException('Role no encontrado');

        $meta   = json_decode((string)($row['taxonomyextra'] ?? '{}'), true) ?? [];
        $isSeed = (bool)($meta['isSeed'] ?? false);
        if ($isSeed) throw new RuntimeException('No se puede eliminar un role del sistema');

        // Verificar si hay usuarios con este role
        $usersRow = ncmExecute(
            "SELECT COUNT(*) AS c FROM contact WHERE companyid=? AND role::text=? AND type=0 AND contactstatus>0",
            [$companyId, $roleId]
        );
        $count = (int)($usersRow['c'] ?? 0);
        if ($count > 0) {
            throw new RuntimeException("No se puede eliminar: $count usuario(s) tienen este role asignado");
        }

        // Borrar roleData primero, luego el role
        ncmExecute(
            "DELETE FROM taxonomy WHERE taxonomytype='roleData' AND sourceid=?::uuid AND companyid=?",
            [$roleId, $companyId], true
        );
        ncmExecute(
            "DELETE FROM taxonomy WHERE taxonomyid=?::uuid AND taxonomytype='role' AND companyid=?",
            [$roleId, $companyId], true
        );

        unset(self::$cache[$companyId][$roleId]);
    }

    /**
     * Mapea int legacy a UUID del seed role correspondiente.
     * Fallback: cashier si el int no está en el mapa.
     */
    public static function resolveLegacyRole(int $legacyId, string $companyId): string
    {
        $slug = self::LEGACY_MAP[$legacyId] ?? 'cashier';
        return self::_resolveSlugId_bySlug($slug, $companyId);
    }

    /**
     * Crea los 5 seed roles + permisos default para una company nueva.
     * Idempotente: si ya existen (mismo slug), no duplica.
     */
    public static function seedCompanyRoles(string $companyId): void
    {
        $allPerms = PermissionCatalog::ids();

        foreach (self::SEED_NAMES as $slug => $name) {
            // Verificar idempotencia
            $existing = ncmExecute(
                "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'=?",
                [$companyId, $slug]
            );
            if ($existing && !empty($existing['taxonomyid'] ?? '')) {
                continue; // ya existe
            }

            $perms = $slug === 'owner' ? $allPerms : (self::SEED_PERMISSIONS[$slug] ?? []);

            $roleId = ncmInsert(['records' => [
                'taxonomyname'  => $name,
                'taxonomytype'  => 'role',
                'taxonomyextra' => json_encode(['isSeed' => true, 'slug' => $slug]),
                'companyid'     => $companyId,
            ], 'table' => 'taxonomy']);

            self::_savePermissions((string)$roleId, $perms, $companyId, $slug);
        }
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    private static function _loadPermissions(string $roleId, string $companyId): array
    {
        if (isset(self::$cache[$companyId][$roleId])) {
            return self::$cache[$companyId][$roleId];
        }

        // JOIN contra el role padre: necesitamos su slug para saber si hay
        // default de seed contra el cual reconciliar. Ambos lados filtrados
        // por companyid — nunca cruza tenants aunque sourceid/taxonomyid
        // colisionaran entre compañías.
        $row = ncmExecute(
            "SELECT rd.taxonomyextra AS roledataextra, r.taxonomyextra AS roleextra
             FROM taxonomy r
             JOIN taxonomy rd ON rd.sourceid = r.taxonomyid AND rd.taxonomytype = 'roleData' AND rd.companyid = r.companyid
             WHERE r.taxonomyid = ?::uuid AND r.taxonomytype = 'role' AND r.companyid = ?",
            [$roleId, $companyId]
        );

        $perms = [];
        if ($row) {
            $roleDataExtra = json_decode((string)($row['roledataextra'] ?? '{}'), true) ?? [];
            $roleExtra     = json_decode((string)($row['roleextra'] ?? '{}'), true) ?? [];
            $perms         = $roleDataExtra['permissions'] ?? [];
            $slug          = $roleDataExtra['slug'] ?? ($roleExtra['slug'] ?? null);
            $storedVersion = (int)($roleDataExtra['catalogVersion'] ?? PermissionCatalog::BASELINE_VERSION);

            $perms = self::_reconcileSeedGaps($roleId, $companyId, $slug, $perms, $storedVersion);
        }

        self::$cache[$companyId][$roleId] = $perms;
        return $perms;
    }

    /**
     * Backfill lazy: agrega a $perms los permisos del seed default de $slug
     * que sean posteriores a $storedVersion y todavía no estén presentes.
     * NUNCA agrega un permiso con since <= $storedVersion — eso se asume
     * revocado a propósito por el admin (ver comentario de clase). Roles
     * custom (slug null) o sin default (owner) se devuelven sin tocar.
     */
    private static function _reconcileSeedGaps(string $roleId, string $companyId, ?string $slug, array $perms, int $storedVersion): array
    {
        if ($slug === null || !isset(self::SEED_PERMISSIONS[$slug]) || self::SEED_PERMISSIONS[$slug] === null) {
            return $perms; // role custom, o slug sin default fijo (owner se resuelve aparte)
        }

        $missing = [];
        foreach (self::SEED_PERMISSIONS[$slug] as $permId) {
            if (in_array($permId, $perms, true)) continue;
            if (PermissionCatalog::since($permId) > $storedVersion) {
                $missing[] = $permId;
            }
        }

        if (empty($missing)) {
            return $perms;
        }

        $merged = array_values(array_unique(array_merge($perms, $missing)));
        self::_savePermissions($roleId, $merged, $companyId, $slug);
        return $merged;
    }

    private static function _savePermissions(string $roleId, array $permissions, string $companyId, ?string $slug): void
    {
        // Upsert: borrar + reinsert
        ncmExecute(
            "DELETE FROM taxonomy WHERE taxonomytype='roleData' AND sourceid=?::uuid AND companyid=?",
            [$roleId, $companyId], true
        );
        ncmInsert(['records' => [
            'taxonomytype'  => 'roleData',
            'sourceid'      => $roleId,
            'taxonomyextra' => json_encode([
                'permissions'    => array_values($permissions),
                'slug'           => $slug,
                'catalogVersion' => PermissionCatalog::CURRENT_VERSION,
            ]),
            'companyid'     => $companyId,
        ], 'table' => 'taxonomy']);

        self::$cache[$companyId][$roleId] = array_values($permissions);
    }

    private static function _resolveSlugId(int $legacyId, string $companyId): string
    {
        $slug = self::LEGACY_MAP[$legacyId] ?? 'cashier';
        return self::_resolveSlugId_bySlug($slug, $companyId);
    }

    private static function _resolveSlugId_bySlug(string $slug, string $companyId): string
    {
        $key = "$companyId:$slug";
        if (isset(self::$slugCache[$key])) return self::$slugCache[$key];

        $row = ncmExecute(
            "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'=?",
            [$companyId, $slug]
        );
        $id = (string)($row['taxonomyid'] ?? '');
        self::$slugCache[$key] = $id;
        return $id;
    }

    private static function clearSlugCache(string $companyId = ''): void
    {
        if ($companyId === '') {
            self::$slugCache = [];
        } else {
            foreach (array_keys(self::$slugCache) as $key) {
                if (str_starts_with($key, "$companyId:")) {
                    unset(self::$slugCache[$key]);
                }
            }
        }
    }

    /** True si el roleId resuelto coincide con el seed slug='owner' del tenant. */
    private static function _isOwnerRole(string $roleId, string $companyId): bool
    {
        $ownerId = self::_resolveSlugId_bySlug('owner', $companyId);
        return $ownerId !== '' && $ownerId === $roleId;
    }
}

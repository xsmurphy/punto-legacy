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
 */
final class RoleService
{
    /** Cache por request: [companyId][roleId] => permissions[] */
    private static array $cache = [];

    // ─── Seed slugs ────────────────────────────────────────────────────────
    // Mapeo de int legacy → slug del seed role.
    private const LEGACY_MAP = [
        1 => 'owner',
        2 => 'admin',
        3 => 'manager',
        4 => 'cashier',
        5 => 'cashier',
        7 => 'viewer',
    ];

    // Permisos default por seed slug.
    private const SEED_PERMISSIONS = [
        'owner' => null, // null = TODOS (computed en seedCompanyRoles)
        'admin' => [
            'pos.sale.create','pos.sale.void','pos.sale.refund',
            'pos.drawer.open','pos.drawer.close','pos.discount.apply',
            'inventory.item.view','inventory.item.create','inventory.item.edit','inventory.item.delete',
            'inventory.stock.adjust','inventory.transfer',
            'contacts.customer.view','contacts.customer.create','contacts.customer.edit','contacts.customer.delete',
            'contacts.supplier.view','contacts.supplier.manage',
            'contacts.user.view','contacts.user.manage',
            'reports.sales.view','reports.drawers.view','reports.audit.view','reports.expenses.view',
            'reports.satisfaction.view','reports.giftcards.view','reports.purchases.view',
            'reports.schedule.view','reports.recurring.view',
            'settings.outlet.manage','settings.register.manage','settings.tax.manage',
            'settings.template.manage','settings.device.pair','settings.device.manage',
            'settings.company.edit',
            'ai.agent.use','ai.agent.elevated',
        ],
        'manager' => [
            'pos.sale.create','pos.sale.void','pos.sale.refund',
            'pos.drawer.open','pos.drawer.close','pos.discount.apply',
            'inventory.item.view','inventory.item.create','inventory.item.edit',
            'inventory.stock.adjust','inventory.transfer',
            'contacts.customer.view','contacts.customer.create','contacts.customer.edit',
            'contacts.supplier.view',
            'contacts.user.view',
            'reports.sales.view','reports.drawers.view','reports.expenses.view',
            'reports.satisfaction.view','reports.giftcards.view','reports.purchases.view',
            'reports.schedule.view','reports.recurring.view',
            'ai.agent.use',
        ],
        'cashier' => [
            'pos.sale.create','pos.drawer.open','pos.drawer.close',
            'inventory.item.view',
            'contacts.customer.view','contacts.customer.create',
        ],
        'viewer' => [
            'inventory.item.view',
            'contacts.customer.view',
            'reports.sales.view','reports.drawers.view','reports.expenses.view',
            'reports.satisfaction.view','reports.giftcards.view','reports.purchases.view',
            'reports.schedule.view','reports.recurring.view',
        ],
    ];

    private const SEED_NAMES = [
        'owner'   => 'Owner',
        'admin'   => 'Admin',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
        'viewer'  => 'Viewer',
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
                    'permissions' => self::_loadPermissions($id, $companyId),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $roles;
    }

    public static function getPermissions(string $roleId, string $companyId): array
    {
        return self::_loadPermissions($roleId, $companyId);
    }

    public static function hasPermission(string $perm, string $roleId, string $companyId): bool
    {
        // roleId puede ser int-string legacy
        if (ctype_digit($roleId)) {
            $roleId = self::_resolveSlugId((int)$roleId, $companyId);
            if ($roleId === '') return false;
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

        self::_savePermissions((string)$roleId, $permissions, $companyId);
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

        if ($name !== null) {
            if ($isSeed) throw new RuntimeException('No se puede renombrar un role del sistema');
            $name = trim($name);
            if ($name === '') throw new RuntimeException('El nombre no puede estar vacío');
        }

        if ($permissions !== null) {
            $validIds = PermissionCatalog::ids();
            $invalid = array_diff($permissions, $validIds);
            if (!empty($invalid)) {
                throw new RuntimeException('Permisos inválidos: ' . implode(', ', $invalid));
            }
        }

        if ($name !== null) {
            ncmUpdate([
                'records' => ['taxonomyname' => $name],
                'table'   => 'taxonomy',
                'where'   => "taxonomyid = '$roleId'::uuid AND companyid = '$companyId'",
            ]);
        }

        if ($permissions !== null) {
            self::_savePermissions($roleId, $permissions, $companyId);
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

            self::_savePermissions((string)$roleId, $perms, $companyId);
        }
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    private static function _loadPermissions(string $roleId, string $companyId): array
    {
        if (isset(self::$cache[$companyId][$roleId])) {
            return self::$cache[$companyId][$roleId];
        }

        $row = ncmExecute(
            "SELECT taxonomyextra FROM taxonomy WHERE taxonomytype='roleData' AND sourceid=?::uuid AND companyid=?",
            [$roleId, $companyId]
        );
        $perms = [];
        if ($row) {
            $extra = json_decode((string)($row['taxonomyextra'] ?? '{}'), true) ?? [];
            $perms = $extra['permissions'] ?? [];
        }

        self::$cache[$companyId][$roleId] = $perms;
        return $perms;
    }

    private static function _savePermissions(string $roleId, array $permissions, string $companyId): void
    {
        // Upsert: borrar + reinsert
        ncmExecute(
            "DELETE FROM taxonomy WHERE taxonomytype='roleData' AND sourceid=?::uuid AND companyid=?",
            [$roleId, $companyId], true
        );
        ncmInsert(['records' => [
            'taxonomytype'  => 'roleData',
            'sourceid'      => $roleId,
            'taxonomyextra' => json_encode(['permissions' => array_values($permissions)]),
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
        static $slugCache = [];
        $key = "$companyId:$slug";
        if (isset($slugCache[$key])) return $slugCache[$key];

        $row = ncmExecute(
            "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'=?",
            [$companyId, $slug]
        );
        $id = (string)($row['taxonomyid'] ?? '');
        $slugCache[$key] = $id;
        return $id;
    }
}

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
    // OJO: este mapa NO tiene entrada para el rol `device`, y no debe tenerla.
    // El device NUNCA se identifica con un int legacy — su sesión lleva el UUID
    // del rol `device` resuelto por deviceRoleId(). Que el 1 signifique `owner`
    // acá es justamente lo que convertía al token del dispositivo en un token
    // de Dueño (ver deviceRoleId()).
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
            // El encargado interviene espacios ajenos (mozo que se fue, espacio que
            // hay que mover/unir a media noche). Sin esto la exclusividad de
            // context/15 no tendría válvula de escape y se evadiría
            // compartiendo el PIN del dueño.
            'pos.space.override',
            // Asistente de IA en la caja (context/59 D4). Va al default del
            // Encargado por el mismo criterio con que ya tiene `ai.agent.use`
            // más abajo: es el rol que el comercio autoriza a usar IA. `cashier`
            // NO lo recibe — no tiene `ai.agent.use` tampoco, y el asistente se
            // habilita por rol desde Ajustes → Roles, no por default.
            'pos.ai.use',
            // Conteo de stock desde la caja (context/63 F1). Va al default del
            // Encargado por el mismo criterio que `pos.ai.use`: es el rol que
            // ya tiene `inventory.stock.adjust` más abajo, o sea el que el
            // comercio autoriza a mover inventario. `cashier` NO lo recibe por
            // default —el conteo ajusta stock— y se lo tilda un admin desde
            // Ajustes → Roles, que es justamente el comercio que quiere que su
            // cajero cuente el mostrador.
            'pos.stock.count',
            // Anulación de ítems de comanda. El Encargado recibe las DOS: la
            // base porque también toma pedidos, y la elevación `.late` porque
            // es el rol que se hace cargo de la merma cuando la cocina ya
            // empezó a preparar el plato. `cashier` recibe solo la base (más
            // abajo) y `device` ninguna — se evalúan contra el operador del PIN.
            'pos.order.item.cancel','pos.order.item.cancel.late',
            'inventory.item.view','inventory.item.create','inventory.item.edit','inventory.item.delete',
            'inventory.stock.adjust','inventory.transfer',
            'contacts.customer.view','contacts.customer.create','contacts.customer.edit','contacts.customer.delete',
            'contacts.supplier.view','contacts.supplier.manage',
            'contacts.user.view','contacts.user.manage',
            // Órdenes de pago a proveedor. El Encargado recibe las TRES: ya
            // tiene `finance.manage` (o sea, hoy ya puede pagarle a un
            // proveedor directamente) y `reports.purchases.view`. `approve` va
            // solo a manager/owner — es la clave que sostiene la segregación
            // de tareas, y el comercio se la tilda a otro rol desde Ajustes →
            // Roles si quiere abrirla. `cashier` no recibe ninguna, `device`
            // tampoco (ver PermissionCatalog).
            'purchases.paymentorder.view','purchases.paymentorder.create','purchases.paymentorder.approve',
            'reports.sales.view','reports.drawers.view','reports.audit.view','reports.expenses.view',
            'reports.satisfaction.view','reports.giftcards.view','reports.purchases.view',
            'reports.schedule.view','reports.recurring.view',
            'settings.outlet.manage','settings.register.manage','settings.register.release','settings.tax.manage',
            'settings.template.manage','settings.device.pair','settings.device.manage',
            'settings.company.edit',
            'billing.view',
            'ai.agent.use','ai.agent.elevated',
            'finance.manage',
            'production.manage',
            'einvoice.manage',
        ],
        'cashier' => [
            'pos.sale.create','pos.sale.creditPayment','pos.drawer.open','pos.drawer.close',
            // Anula la línea que él mismo cargó mal, DENTRO de la ventana que
            // configure el comercio (`settingOrderItemCancelWindowMinutes`; el
            // default 0 = sin límite, o sea que la feature nace apagada). Pasada
            // esa ventana necesita a alguien con `pos.order.item.cancel.late`,
            // que el cajero NO tiene por default.
            'pos.order.item.cancel',
            'inventory.item.view',
            'contacts.customer.view','contacts.customer.create',
        ],
        // ── Rol del DISPOSITIVO, no de una persona ───────────────────────
        //
        // Es el rol con el que se emite la sesión `pos-app` (DeviceAuth). Antes
        // esa sesión llevaba roleId='1', que LEGACY_MAP resuelve a `owner`, y
        // `hasPermission()` le devuelve true a todo sin mirar el catálogo: los
        // gates de 7 endpoints eran letra muerta bajo `pos-app` y una caja
        // comprometida tenía los permisos del Dueño del comercio.
        //
        // El token del device es ETERNO y se emite AL PAREAR, mucho antes de
        // que haya un operador en la caja: no puede representar a nadie. Lo que
        // representa es una TERMINAL, y este es el piso de capacidades de una
        // terminal. Re-emitirlo por operador está descartado a propósito —
        // rompe offline-first (el token tiene que servir sin red) y revive la
        // confusión device/operador del incidente 2026-07-19.
        //
        // El piso se derivó ENDPOINT POR ENDPOINT: es exactamente el conjunto
        // de claves que hoy gatean algo que el POS consume con el Bearer del
        // device. Sacar cualquiera de estas rompe una caja en producción;
        // agregar otra amplía la superficie de un token que vive en el
        // localStorage de una tablet del mostrador.
        //
        //   pos.*                        venta, anulación, devolución, cobro de
        //                                crédito, apertura/cierre de caja.
        //   finance.manage               extracción e ingreso de efectivo de la
        //                                caja (drawer.php los mapea a esta clave).
        //                                Todo /v1/finance/* es panel-only, y el
        //                                pago/anulación a PROVEEDOR de
        //                                credit-payments.php está cerrado al
        //                                realm `panel`, así que desde un device
        //                                esta clave no alcanza nada más.
        //   inventory.item.view          catálogo (bootstrap, bulk-get, ficha).
        //   contacts.customer.*          alta y edición de clientes en el
        //                                mostrador. SIN `delete`: el POS no
        //                                archiva contactos (y contacts.php ya
        //                                restringe el archivado al panel).
        //   reports.sales.view           opciones de devolución y detalle de una
        //                                venta para reimprimir/anular.
        //   settings.register.manage     hotkeys y toggles de SU PROPIA caja
        //                                (register.php PUT ?resource=hotkeys|config;
        //                                el alta/baja de cajas está cerrada al
        //                                realm `panel` en el mismo archivo).
        //
        // Deliberadamente AFUERA: todo `contacts.user.*` (el equipo del comercio
        // no se toca desde una caja — era el vector de toma del tenant),
        // `contacts.supplier.*`, la escritura del catálogo, impuestos,
        // plantillas (el POS las lee, y el GET no está gateado), sucursales,
        // dispositivos, roles, billing, IA y producción.
        'device' => [
            'pos.sale.create','pos.sale.void','pos.sale.refund','pos.sale.creditPayment',
            'pos.drawer.open','pos.drawer.close','pos.discount.apply',
            'finance.manage',
            'inventory.item.view',
            'contacts.customer.view','contacts.customer.create','contacts.customer.edit',
            'reports.sales.view',
            'settings.register.manage',
        ],
    ];

    private const SEED_NAMES = [
        'owner'   => 'Dueño',
        'manager' => 'Encargado',
        'cashier' => 'Cajero',
        // No es un rol para asignarle a una persona: lo lleva la sesión del
        // dispositivo pareado. Se siembra como los otros para que viva en la
        // misma tabla y pase por el mismo mecanismo de reconciliación de
        // permisos, en vez de ser una lista hardcodeada invisible desde el
        // panel. Hoy la pantalla de roles lo muestra en solo lectura, como a
        // los otros seeds (`isSeed`), y el selector de rol de un usuario lo
        // ofrece igual que a los demás — asignárselo a una persona no escala
        // nada (el piso es más chico que el de `cashier` en todo lo
        // administrativo), pero conviene filtrarlo por `slug === 'device'` en
        // ese selector cuando se toque esa pantalla.
        'device'  => 'Dispositivo POS',
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
     * UUID del rol `device` del tenant — el que lleva la sesión `pos-app`.
     *
     * ÚNICO punto donde se decide con qué rol opera un dispositivo pareado.
     * Siembra los roles del tenant si el slug todavía no existe (companies
     * anteriores a la mig 161, o una company creada por un camino que no pasó
     * por seedCompanyRoles).
     *
     * Lanza si después de sembrar sigue sin resolver: devolver '' dejaría al
     * device con `hasPermission()` en false para todo, o —peor— invitaría a un
     * fallback a `owner`, que es exactamente el bug que este rol cierra. Un
     * tenant cuyo `taxonomy` no se puede escribir está roto de una forma que
     * hay que ver, no absorber.
     *
     * @throws RuntimeException
     */
    public static function deviceRoleId(string $companyId): string
    {
        $id = self::_resolveSlugId_bySlug('device', $companyId);
        if ($id !== '') {
            return $id;
        }
        self::seedCompanyRoles($companyId);
        self::clearSlugCache($companyId);
        $id = self::_resolveSlugId_bySlug('device', $companyId);
        if ($id === '') {
            throw new RuntimeException(
                "No se pudo resolver el rol 'device' de la company $companyId"
            );
        }
        return $id;
    }

    /**
     * Fragmento SQL: el contacto tiene ROL de propietario.
     *
     * `contact.role` es varchar(64) desde la mig 58 y convive con dos formatos:
     *   - '1' legacy (contactos anteriores a la mig)
     *   - UUID del taxonomy role con slug='owner' (todo lo creado después)
     *
     * Comparar contra el entero 1 (`role = 1`) revienta en Postgres
     * ("operator does not exist: character varying = integer") y comparar solo
     * contra '1' deja sin dueño a todo tenant creado post-mig 58. Este método
     * es la ÚNICA definición del predicado — no reimplementarlo en cada query.
     *
     * Las columnas SIEMPRE van calificadas (default: el nombre de la tabla).
     * Sin calificar, el `companyid` del EXISTS se resuelve contra `taxonomy`
     * —la única tabla del subquery— y la correlación con el contacto se pierde
     * en silencio: el predicado aceptaría el rol owner de CUALQUIER tenant.
     *
     * NO incluye `main` ni `type`: son filtros del caller. El login del panel
     * (`findPhoneLogin`) autentica por rol solo — sumarle `main = 'true'`
     * dejaría afuera a los contactos con `main = 'admin'` de los seeds viejos.
     *
     * @param string $alias alias de la tabla `contact` en la query
     */
    public static function ownerRoleSql(string $alias = 'contact'): string
    {
        $a = self::qualify($alias);

        return "({$a}role = '1' OR EXISTS ("
             .     'SELECT 1 FROM taxonomy owner_role '
             .      "WHERE owner_role.taxonomyid::text = {$a}role "
             .        "AND owner_role.taxonomytype = 'role' "
             .        "AND owner_role.companyid = {$a}companyid "
             .        "AND owner_role.taxonomyextra::json->>'slug' = 'owner'"
             . '))';
    }

    /**
     * Fragmento SQL: el contacto es el propietario REGISTRADO del comercio —
     * rol de dueño + `main = 'true'`, que es como lo marca el alta de tenant.
     *
     * Es lo que usan las lecturas de /admin para poner nombre y contacto del
     * dueño en la ficha. Para autenticar alcanza el rol (ver ownerRoleSql).
     *
     * No incluye `type = 0`: ese filtro es "usuario del panel" y lo pone el
     * caller, que a veces cuenta contactos de otro tipo.
     *
     * @param string $alias alias de la tabla `contact` en la query
     */
    public static function ownerContactSql(string $alias = 'contact'): string
    {
        $a = self::qualify($alias);

        return "({$a}main = 'true' AND " . self::ownerRoleSql($alias) . ')';
    }

    /** Prefijo calificador validado (evita interpolar cualquier cosa en el SQL). */
    private static function qualify(string $alias): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new RuntimeException("Alias SQL inválido: $alias");
        }
        return $alias . '.';
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
     * Crea los seed roles + permisos default para una company nueva.
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
        // LEFT JOIN, no JOIN: hay que poder distinguir "el rol no existe" de
        // "el rol existe pero le falta la fila roleData" — el INNER las
        // colapsaba en el mismo `null` y la segunda terminaba resuelta como
        // "rol sin ningún permiso" (ver _repairMissingRoleData).
        $row = ncmExecute(
            "SELECT rd.taxonomyextra AS roledataextra, r.taxonomyextra AS roleextra
             FROM taxonomy r
             LEFT JOIN taxonomy rd ON rd.sourceid = r.taxonomyid AND rd.taxonomytype = 'roleData' AND rd.companyid = r.companyid
             WHERE r.taxonomyid = ?::uuid AND r.taxonomytype = 'role' AND r.companyid = ?",
            [$roleId, $companyId]
        );

        $perms = [];
        if ($row) {
            $roleExtra   = json_decode((string)($row['roleextra'] ?? '{}'), true) ?? [];
            $rawRoleData = $row['roledataextra'] ?? null;

            if ($rawRoleData === null) {
                $perms = self::_repairMissingRoleData($roleId, $companyId, $roleExtra['slug'] ?? null);
            } else {
                $roleDataExtra = json_decode((string)$rawRoleData, true) ?? [];
                $perms         = $roleDataExtra['permissions'] ?? [];
                $slug          = $roleDataExtra['slug'] ?? ($roleExtra['slug'] ?? null);
                $storedVersion = (int)($roleDataExtra['catalogVersion'] ?? PermissionCatalog::BASELINE_VERSION);

                $perms = self::_reconcileSeedGaps($roleId, $companyId, $slug, $perms, $storedVersion);
            }
        }

        self::$cache[$companyId][$roleId] = $perms;
        return $perms;
    }

    /**
     * El rol existe pero su fila `roleData` no.
     *
     * Complemento en RUNTIME de la migración
     * `160_repair_missing_roledata.php`, que reparó los huérfanos que ya
     * estaban en la base. Sigue existiendo porque la CAUSA no se cerró:
     * seedCompanyRoles() inserta el rol y llama a _savePermissions() en dos
     * escrituras separadas, sin una transacción que las abarque, así que un
     * fallo entre medio vuelve a producir el mismo estado. La migración es de
     * una sola pasada; esto atrapa los que nazcan después.
     *
     * MISMA política que la migración, y por la misma razón — una fila
     * `roleData` faltante tiene dos orígenes y solo uno es seguro de
     * reconstruir:
     *
     *   (A) seedCompanyRoles() nunca logró escribirla → el rol NUNCA tuvo
     *       permisos. Re-crear el default es exacto, no una adivinanza.
     *   (B) updateRole() la borró y no la reescribió (el upsert es DELETE +
     *       INSERT) → el rol SÍ tenía permisos y falta justamente porque un
     *       admin la estaba editando, típicamente para QUITAR permisos.
     *       Escribirle el default completo le devolvería todo lo que acababa
     *       de revocar: revivir una revocación deliberada es exactamente el
     *       incidente que el mecanismo de `catalogVersion` existe para evitar.
     *
     * Rol por rol los dos casos son indistinguibles. Por COMPANY sí: si la
     * company no tiene NINGUNA fila `roleData`, nunca se persistió una lista
     * ahí, no pudo haber revocación, y todos sus roles seed son caso (A). Esa
     * es la única condición que se considera probada y la única que se repara.
     *
     * Todo lo demás se queda en [] — fail-closed. Sin permisos el usuario no
     * puede operar, que es recuperable re-guardando el rol desde el panel; un
     * permiso revivido no lo es.
     */
    private static function _repairMissingRoleData(string $roleId, string $companyId, ?string $slug): array
    {
        if ($slug === null || !array_key_exists($slug, self::SEED_PERMISSIONS)) {
            return []; // rol custom: no hay default del cual reconstruirlo
        }

        // ¿La company tiene ALGUNA fila roleData? Si sí, este faltante puede
        // ser el caso (B) y no se toca.
        $any = ncmExecute(
            "SELECT 1 AS x FROM taxonomy WHERE taxonomytype = 'roleData' AND companyid = ? LIMIT 1",
            [$companyId]
        );
        if ($any) {
            error_log("[RoleService] rol seed '$slug' ($roleId, company $companyId) sin fila roleData, "
                . 'pero la company tiene otras — puede ser una edición interrumpida. No se repara (fail-closed); '
                . 're-guardá el rol desde el panel.');
            return [];
        }

        $perms = $slug === 'owner'
            ? PermissionCatalog::ids()
            : (self::SEED_PERMISSIONS[$slug] ?? []);

        error_log("[RoleService] company $companyId sin ninguna fila roleData — resembrando el default de '$slug' ($roleId)");

        try {
            self::_savePermissions($roleId, $perms, $companyId, $slug);
        } catch (\Throwable $e) {
            // Misma razón que en _reconcileSeedGaps: esto corre dentro de una
            // LECTURA de permisos. Si la escritura falla se sigue con el
            // default en memoria, que es la respuesta correcta para ESTA
            // request; la próxima lectura vuelve a intentar.
            error_log('[RoleService] _repairMissingRoleData: no se pudo persistir el reseed de '
                . $roleId . ': ' . $e->getMessage());
        }

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

        // Este backfill corre dentro de una LECTURA de permisos (getPermissions
        // → _loadPermissions), así que una falla de escritura NO puede
        // propagarse: dejaría al tenant sin poder autorizar ninguna acción por
        // un problema de persistencia. Se loguea y se sigue con el merge, que
        // es la respuesta correcta para ESTA request; la próxima lectura vuelve
        // a intentar reconciliar (el mecanismo ya es idempotente).
        try {
            self::_savePermissions($roleId, $merged, $companyId, $slug);
        } catch (\Throwable $e) {
            error_log('[RoleService] _reconcileSeedGaps: no se pudo persistir el backfill del rol '
                . $roleId . ': ' . $e->getMessage());
        }

        return $merged;
    }

    /**
     * Nombre de la fila `roleData` de un rol.
     *
     * `taxonomy.taxonomyName` es NOT NULL (db-schema-postgres.sql) y además
     * tiene un índice UNIQUE por `(companyId, taxonomyType, LOWER(taxonomyName))`
     * (mig 38) — o sea que la fila de permisos necesita un nombre, y ese nombre
     * tiene que ser único DENTRO del tipo 'roleData' de la company.
     *
     * Derivarlo del `roleId` (UUID, PK de la tabla) es lo único que garantiza
     * las dos cosas a la vez y sin depender del slug: dos roles custom
     * (slug=null) de la misma company colisionarían con cualquier nombre fijo
     * tipo '_permissions', y renombrar el rol no puede invalidar sus permisos.
     */
    private static function _roleDataName(string $roleId): string
    {
        return 'roleData:' . $roleId;
    }

    /**
     * Persiste la lista de permisos de un rol. ÚNICO punto de escritura de
     * `roleData` — createRole, updateRole, seedCompanyRoles y
     * _reconcileSeedGaps pasan todos por acá.
     *
     * ATÓMICO y RUIDOSO, las dos cosas a propósito:
     *
     *  - El upsert es DELETE + INSERT. Sin transacción, un INSERT que falla
     *    deja el rol SIN NINGÚN permiso — el borrado ya se hizo. Es exactamente
     *    lo que pasaba cuando el INSERT reventaba con `23502` por no mandar
     *    `taxonomyname` (NOT NULL): editar permisos desde el panel BORRABA la
     *    fila y no la reescribía. La transacción hace que el par sea todo-o-nada.
     *  - Lanza si no pudo escribir. `ncmInsert()` solo loguea y devuelve false,
     *    y esta función seteaba el cache igual: el caller creía haber guardado,
     *    la request seguía sirviendo la lista en memoria, y el dato no estaba
     *    en ningún lado. Ese silencio es lo que hizo que el bug de
     *    `taxonomyname` sobreviviera sin que nadie lo notara — el fix de la
     *    columna sin este cambio dejaría el mismo agujero abierto para la
     *    próxima constraint que se agregue a `taxonomy`.
     *
     * El único caller que NO puede propagar la excepción es
     * `_reconcileSeedGaps()` (corre dentro de una LECTURA de permisos: tirar
     * ahí dejaría al tenant sin poder autenticar nada) — la atrapa y sigue,
     * ver su comentario.
     *
     * @throws RuntimeException si la fila no quedó persistida.
     */
    private static function _savePermissions(string $roleId, array $permissions, string $companyId, ?string $slug): void
    {
        global $db;

        // StartTrans/CompleteTrans son reentrantes (llevan `transDepth`), así
        // que esto anida sin romper una transacción externa del caller.
        $db->StartTrans();

        ncmExecute(
            "DELETE FROM taxonomy WHERE taxonomytype='roleData' AND sourceid=?::uuid AND companyid=?",
            [$roleId, $companyId], true
        );
        $inserted = ncmInsert(['records' => [
            'taxonomyname'  => self::_roleDataName($roleId),
            'taxonomytype'  => 'roleData',
            'sourceid'      => $roleId,
            'taxonomyextra' => json_encode([
                'permissions'    => array_values($permissions),
                'slug'           => $slug,
                'catalogVersion' => PermissionCatalog::CURRENT_VERSION,
            ]),
            'companyid'     => $companyId,
        ], 'table' => 'taxonomy']);

        if ($inserted === false) {
            $db->FailTrans();
        }
        // CompleteTrans() devuelve false tanto por FailTrans() como por
        // cualquier error de PG que el layer haya registrado en el camino.
        if ($db->CompleteTrans() === false || $inserted === false) {
            throw new RuntimeException(
                "No se pudieron persistir los permisos del rol $roleId (company $companyId)"
            );
        }

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

<?php
declare(strict_types=1);

namespace Punto\Api\Users;

/**
 * Gestión del equipo de la empresa (contactos type=0 — empleados).
 *
 * Los empleados viven en la tabla `contact` con `type = 0`, igual que en el
 * legacy (`panel/a_contacts.php`). El servicio los encapsula separado de
 * ContactService (que solo cubre type 1/2 — clientes/proveedores).
 *
 * Campos principales:
 *   contactName, contactEmail, contactPhone, contactPassword, salt,
 *   role (FK taxonomy type='role'), outletId (null=todas), lockPass,
 *   contactInCalendar, contactCalendarPosition, contactColor, contactStatus.
 *
 * Los roles vienen de la tabla `role` (slices de refactoring de taxonomy).
 */
final class UsersService
{
    private const TYPE_USER = 0;

    /**
     * Catálogo de roles del sistema. En el legacy estos vivían en
     * `taxonomy WHERE taxonomyType='role'` indexados por `taxonomyExtra`
     * (numérico), pero la BD de prod nunca tuvo seed → los IDs viven
     * hardcoded en código (ver SignupService:221 — `role=1` al signup).
     * Si en el futuro se reintroducen roles custom por empresa, joinear
     * con `taxonomy r ON r.taxonomyExtra = c.role::text` (NO taxonomyId).
     */
    private const ROLES = [
        '1' => 'Super Admin',
    ];

    /** Listado de empleados activos/inactivos con info de rol y sucursal. */
    public function list(string $companyId, array $opts = []): array
    {
        $where  = "c.companyId = ? AND c.type = ?";
        $params = [$companyId, self::TYPE_USER];

        if (isset($opts['status']) && $opts['status'] !== '' && $opts['status'] !== null) {
            $where   .= " AND c.contactStatus = ?";
            $params[] = (int) $opts['status'];
        }
        if (!empty($opts['q'])) {
            $where   .= " AND (c.contactName ILIKE ? OR c.contactEmail ILIKE ?)";
            $params[] = '%' . $opts['q'] . '%';
            $params[] = '%' . $opts['q'] . '%';
        }

        $sql = "
            SELECT
                c.contactId       AS id,
                c.contactName     AS name,
                c.contactEmail    AS email,
                c.contactPhone    AS phone,
                c.contactStatus   AS status,
                c.data->>'contactColor' AS color,
                c.lockPass,
                c.lockpasshash,
                c.pinhash,
                (c.data->>'contactInCalendar' = 'true') AS inCalendar,
                COALESCE(NULLIF(c.data->>'contactCalendarPosition','')::int, 0) AS calendarPosition,
                c.role            AS roleId,
                r.taxonomyname    AS roleName,
                c.outletId,
                o.outletName,
                COALESCE(
                  (SELECT json_agg(co.outletid::text)
                     FROM contact_outlet co
                    WHERE co.contactid = c.contactid
                      AND co.companyid = c.companyid),
                  '[]'::json
                ) AS outletids_json,
                COALESCE(
                  (SELECT json_agg(o2.outletname)
                     FROM contact_outlet co
                     JOIN outlet o2 ON o2.outletid = co.outletid
                    WHERE co.contactid = c.contactid
                      AND co.companyid = c.companyid),
                  '[]'::json
                ) AS outletnames_json,
                c.contactDate     AS createdAt,
                c.updated_at      AS updatedAt
            FROM contact c
            LEFT JOIN outlet o ON o.outletId = c.outletId AND o.companyId = c.companyId
            -- Join al sistema nuevo de roles (RoleService -- taxonomy roleData
            -- + role). c.role guarda el taxonomyid::text del rol custom.
            -- Sin esto, todo rol que NO sea legacy Super Admin caia a Sin rol
            -- en el front. Incidente 2026-06-28.
            LEFT JOIN taxonomy r ON r.taxonomyid::text = c.role
                AND r.taxonomytype = 'role' AND r.companyid = c.companyId
            WHERE {$where}
            ORDER BY c.contactName ASC
        ";

        $res  = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $rows[] = $this->shape($res->fields);
                $res->MoveNext();
            }
            $res->Close();
        }
        return $rows;
    }

    /**
     * Roster de la PANTALLA DE BLOQUEO del POS — proyección mínima, por sucursal.
     *
     * Devuelve SOLO `id`, `name`, `pinhash`. Nada más: ni email, ni teléfono, ni
     * `lockPass`/`lockPassHash`, ni rol, ni sucursales. Cada campo extra sería
     * superficie filtrada a un token de device que vive para siempre en el
     * localStorage de una tablet del mostrador, así que la proyección es la
     * frontera de seguridad — no un detalle de performance.
     *
     * ── Por qué NO hay gate `contacts.user.view`, y qué lo reemplaza ────────
     * Este dato NO es gestión de equipo: es dato OPERATIVO de la caja (¿qué
     * PIN abre esta caja?). Lo autoriza el REALM + el scope de sucursal.
     *
     * El rol `device` (mig 162) NO tiene `contacts.user.*` A PROPÓSITO: ese
     * permiso abre `/v1/contacts` y `/v1/users` completos — el vector de toma
     * del tenant que la mig 162 cerró. Pedirle al device la lista de equipo
     * por `/v1/users` daba 403 y dejaba el lock screen sin roster (lockout
     * reportado 2026-08-24). La solución NO es devolverle el permiso: es esta
     * proyección de tres campos, servida por el bootstrap del realm.
     *
     * Pero "lo autoriza el realm" es una obligación, no una descripción: el
     * ÚNICO caller (`api/v1/bootstrap.php`) llama a este método SOLO cuando el
     * realm es `pos-app`, y omite la clave `users` de la respuesta para
     * `panel`. `pinhash` es un SHA-256 sin sal de 4 dígitos (10.000
     * combinaciones): entregárselo a un rol de panel sin `contacts.user.view`
     * sería regalarle el PIN del encargado y, con él, su identidad en la caja.
     * Si sumás un caller nuevo, replicá ese gate — este método NO lo aplica por
     * su cuenta, solo proyecta y scopea.
     *
     * ── Alcance por sucursal (decisión del owner 2026-08-24) ────────────────
     * La fuente de verdad es `contact_outlet` (tabla canónica desde la mig 66),
     * NUNCA la columna legacy `contact.outletid` (que sigue existiendo sin drop
     * y quedó como back-compat: la mig 66 la usó para el backfill).
     *
     *   - Usuario con ≥1 fila en `contact_outlet` → aparece solo si una de esas
     *     filas es `$outletId`.
     *   - Usuario con CERO filas → es GLOBAL, aparece en todas las sucursales.
     *     Misma semántica que `fin_account.outletid IS NULL` (ver
     *     `context/25-sucursales-y-scopes.md`).
     *   - `$outletId === ''` → sin filtro de sucursal (todos los del tenant).
     *     Contrato del método para un caller futuro; el único caller de hoy
     *     (`/v1/bootstrap` en realm `pos-app`) nunca lo usa, porque el device
     *     opera siempre con la sucursal fija de su pairing.
     *
     * @return list<array{id:string,name:string,pinhash:?string}>
     */
    public function rosterForOutlet(string $companyId, string $outletId): array
    {
        // Solo activos: `contactStatus = 1`. Un usuario dado de baja no puede
        // abrir la caja aunque su PIN siga en la fila.
        $sql = "
            SELECT c.contactId   AS id,
                   c.contactName AS name,
                   c.pinhash     AS pinhash
              FROM contact c
             WHERE c.companyId = ?
               AND c.type = ?
               AND c.contactStatus = 1
        ";
        $params = [$companyId, self::TYPE_USER];

        if ($outletId !== '') {
            // `contact_outlet` es TODO lowercase (mig 66) — a diferencia de
            // `contact`, que se escribe camelCase sin comillas (PG lo pliega a
            // lowercase igual).
            $sql .= "
               AND (
                     NOT EXISTS (
                       SELECT 1 FROM contact_outlet co
                        WHERE co.contactid = c.contactId
                          AND co.companyid = c.companyId
                     )
                     OR EXISTS (
                       SELECT 1 FROM contact_outlet co
                        WHERE co.contactid = c.contactId
                          AND co.companyid = c.companyId
                          AND co.outletid  = ?
                     )
                   )
            ";
            $params[] = $outletId;
        }

        $sql .= ' ORDER BY c.contactName ASC';

        // `forceObj=true` (4to arg) → recordset multi-row, se itera con
        // `while (!$rs->EOF)`. Tratarlo como array devuelve [] siempre.
        $res  = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f      = $res->fields;
                $pin    = $f['pinhash'] ?? null;
                $rows[] = [
                    'id'      => (string) ($f['id'] ?? ''),
                    'name'    => (string) ($f['name'] ?? ''),
                    'pinhash' => ($pin === null || $pin === '') ? null : (string) $pin,
                ];
                $res->MoveNext();
            }
            $res->Close();
        }
        return $rows;
    }

    /** Un empleado por ID, con datos completos. NULL si no existe / no es del tenant. */
    public function get(string $id, string $companyId): ?array
    {
        $sql = "
            SELECT
                c.contactId       AS id,
                c.contactName     AS name,
                c.contactEmail    AS email,
                c.contactPhone    AS phone,
                c.contactStatus   AS status,
                c.data->>'contactColor' AS color,
                c.lockPass,
                c.lockpasshash,
                c.pinhash,
                (c.data->>'contactInCalendar' = 'true') AS inCalendar,
                COALESCE(NULLIF(c.data->>'contactCalendarPosition','')::int, 0) AS calendarPosition,
                c.role            AS roleId,
                r.taxonomyname    AS roleName,
                c.outletId,
                o.outletName,
                COALESCE(
                  (SELECT json_agg(co.outletid::text)
                     FROM contact_outlet co
                    WHERE co.contactid = c.contactid
                      AND co.companyid = c.companyid),
                  '[]'::json
                ) AS outletids_json,
                COALESCE(
                  (SELECT json_agg(o2.outletname)
                     FROM contact_outlet co
                     JOIN outlet o2 ON o2.outletid = co.outletid
                    WHERE co.contactid = c.contactid
                      AND co.companyid = c.companyid),
                  '[]'::json
                ) AS outletnames_json,
                c.contactDate     AS createdAt,
                c.updated_at      AS updatedAt
            FROM contact c
            LEFT JOIN outlet o ON o.outletId = c.outletId AND o.companyId = c.companyId
            LEFT JOIN taxonomy r ON r.taxonomyid::text = c.role
                AND r.taxonomytype = 'role' AND r.companyid = c.companyId
            WHERE c.contactId = ? AND c.companyId = ? AND c.type = ?
        ";
        $row = ncmExecute($sql, [$id, $companyId, self::TYPE_USER]);
        return $row ? $this->shape($row) : null;
    }

    /** Roles disponibles. Catálogo del sistema (hardcoded — ver const ROLES). */
    public function roles(string $companyId): array
    {
        unset($companyId); // catálogo es global; firma se mantiene por compat
        $rows = [];
        foreach (self::ROLES as $id => $name) {
            $rows[] = ['id' => $id, 'name' => $name];
        }
        return $rows;
    }

    /**
     * Crea un empleado. Retorna el nuevo contactId.
     *
     * @throws \InvalidArgumentException si falta algún campo requerido.
     * @throws \RuntimeException si la inserción falla.
     */
    public function create(string $companyId, array $in): string
    {
        $name = trim($in['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre es obligatorio');
        }
        $password = $in['password'] ?? '';
        if (empty($password)) {
            throw new \InvalidArgumentException('La contraseña es obligatoria para nuevos usuarios');
        }

        $lockPass = trim((string) ($in['lockPass'] ?? ''));
        if ($lockPass !== '' && !preg_match('/^\d{4}$/', $lockPass)) {
            throw new \InvalidArgumentException('El código POS debe tener 4 dígitos numéricos');
        }
        if ($this->pinIsTaken($lockPass, $companyId, null)) {
            throw new \InvalidArgumentException('El código POS ya está en uso por otro usuario');
        }

        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && $this->emailIsTaken($email, $companyId, null)) {
            throw new \InvalidArgumentException('Ya hay un usuario con ese email');
        }

        $this->assertPlanLimit($companyId);

        [$hash, $salt] = self::hashPassword((string) $password);

        // Normalizar outletIds: si viene outletIds usa eso;
        // si solo viene outletId legacy, lo convierte; si nada, array vacío.
        $outletIds = $this->resolveOutletIds($in, $companyId);

        // back-compat: contact.outletid = primer outlet asignado (o null)
        $primaryOutletId = $outletIds[0] ?? ($in['outletId'] ?? null);

        $rec = [
            'contactName'              => $name,
            'contactEmail'             => $email !== '' ? $email : null,
            'contactPhone'             => (function () use ($in) {
                require_once dirname(__DIR__, 3) . '/api/includes/phone.php';
                return phoneValidateForStorage($in['phone'] ?? null, (string)($in['country'] ?? 'PY'));
            })(),
            'contactPassword'          => $hash,
            'salt'                     => $salt,
            'role'                     => $in['roleId']           ?? null,
            'outletId'                 => $primaryOutletId,
            'lockPass'                 => $lockPass !== '' ? $lockPass : null,
            'lockPassHash'             => $lockPass !== '' ? password_hash($lockPass, PASSWORD_BCRYPT) : null,
            'pinhash'                  => $lockPass !== '' ? hash('sha256', $lockPass) : null,
            'contactInCalendar'        => !empty($in['inCalendar']) ? 1 : 0,
            'contactCalendarPosition'  => isset($in['calendarPosition']) ? (int) $in['calendarPosition'] : 0,
            'contactColor'             => $in['color']            ?? null,
            'contactStatus'            => 1,
            'type'                     => self::TYPE_USER,
            'companyId'                => $companyId,
            'contactDate'              => TODAY,
            'updated_at'               => TODAY,
        ];

        global $db;
        $newId = ncmInsert(['table' => 'contact', 'records' => $rec]);
        if ($newId === false) {
            throw new \RuntimeException('No se pudo crear el usuario');
        }

        // Bulk INSERT en contact_outlet
        if (!empty($outletIds)) {
            $this->syncContactOutlets((string) $newId, $companyId, $outletIds);
        }

        return (string) $newId;
    }

    /**
     * Actualiza un empleado (patch parcial).
     * Si se pasa `password` no vacío, cambia el hash.
     */
    public function update(string $id, string $companyId, array $in): bool
    {
        $rec = [];

        if (isset($in['name']) && trim($in['name']) !== '') {
            $rec['contactName'] = trim($in['name']);
        }
        if (array_key_exists('email', $in)) {
            $email = trim((string) ($in['email'] ?? ''));
            if ($email !== '' && $this->emailIsTaken($email, $companyId, $id)) {
                throw new \InvalidArgumentException('Ya hay un usuario con ese email');
            }
            $rec['contactEmail'] = $email !== '' ? $email : null;
        }
        if (array_key_exists('phone', $in)) {
            require_once dirname(__DIR__, 3) . '/api/includes/phone.php';
            $rec['contactPhone'] = phoneValidateForStorage($in['phone'] ?? null, (string)($in['country'] ?? 'PY'));
        }
        if (!empty($in['password'])) {
            [$hash, $salt] = self::hashPassword((string) $in['password']);
            $rec['contactPassword'] = $hash;
            $rec['salt']            = $salt;
        }
        if (array_key_exists('roleId', $in)) {
            $rec['role']    = $in['roleId'] ?: null;
        }
        // outletIds (nuevo) tiene precedencia sobre outletId (legacy).
        // Si viene outletIds, sincroniza contact_outlet y actualiza contact.outletid.
        // Si solo viene outletId legacy (sin outletIds), lo trata como array de 1 o [].
        if (array_key_exists('outletIds', $in) || array_key_exists('outletId', $in)) {
            $outletIds = $this->resolveOutletIds($in, $companyId);
            $primaryOutletId = $outletIds[0] ?? null;
            $rec['outletId'] = $primaryOutletId;
            $this->syncContactOutlets($id, $companyId, $outletIds);
        }
        if (array_key_exists('lockPass', $in)) {
            $lockPass = trim((string) ($in['lockPass'] ?? ''));
            if ($lockPass !== '' && !preg_match('/^\d{4}$/', $lockPass)) {
                throw new \InvalidArgumentException('El código POS debe tener 4 dígitos numéricos');
            }
            if ($this->pinIsTaken($lockPass, $companyId, $id)) {
                throw new \InvalidArgumentException('El código POS ya está en uso por otro usuario');
            }
            $rec['lockPass']     = $lockPass !== '' ? $lockPass : null;
            $rec['lockPassHash'] = $lockPass !== '' ? password_hash($lockPass, PASSWORD_BCRYPT) : null;
            $rec['pinhash']      = $lockPass !== '' ? hash('sha256', $lockPass) : null;
        }
        if (array_key_exists('inCalendar', $in)) {
            $rec['contactInCalendar'] = !empty($in['inCalendar']) ? 1 : 0;
        }
        if (array_key_exists('calendarPosition', $in)) {
            $rec['contactCalendarPosition'] = (int) $in['calendarPosition'];
        }
        if (array_key_exists('color', $in)) {
            $rec['contactColor'] = $in['color'] ?: null;
        }
        if (array_key_exists('status', $in)) {
            $rec['contactStatus'] = (int) $in['status'];
        }

        if (empty($rec)) {
            return true;
        }
        $rec['updated_at'] = TODAY;

        $result = ncmUpdate([
            'table'       => 'contact',
            'records'     => $rec,
            'where'       => 'contactId = ? AND companyId = ?',
            'whereParams' => [$id, $companyId],
        ]);
        return $result !== false && empty($result['error']);
    }

    /** Activa (1) o desactiva (0) un empleado. */
    public function setStatus(string $id, string $companyId, int $status): bool
    {
        return $this->update($id, $companyId, ['status' => $status]);
    }

    // ── privados ──────────────────────────────────────────────────────────────

    /** True si ya existe otro empleado activo en la empresa con ese PIN. */
    private function pinIsTaken(string $pin, string $companyId, ?string $excludeId): bool
    {
        if ($pin === '') return false;
        $sql = "SELECT contactId FROM contact
                WHERE companyId = ? AND type = ? AND lockPass = ? AND contactStatus > 0";
        $params = [$companyId, self::TYPE_USER, $pin];
        if ($excludeId !== null) {
            $sql .= " AND contactId <> ?";
            $params[] = $excludeId;
        }
        $sql .= " LIMIT 1";
        $row = ncmExecute($sql, $params);
        return $row !== false && $row !== null;
    }

    /** True si ya existe otro empleado activo en la empresa con ese email. */
    private function emailIsTaken(string $email, string $companyId, ?string $excludeId): bool
    {
        if ($email === '') return false;
        $sql = "SELECT contactId FROM contact
                WHERE companyId = ? AND type = ? AND LOWER(contactEmail) = LOWER(?) AND contactStatus > 0";
        $params = [$companyId, self::TYPE_USER, $email];
        if ($excludeId !== null) {
            $sql .= " AND contactId <> ?";
            $params[] = $excludeId;
        }
        $sql .= " LIMIT 1";
        $row = ncmExecute($sql, $params);
        return $row !== false && $row !== null;
    }

    /**
     * Lanza InvalidArgumentException si la empresa alcanzó el tope de usuarios
     * del plan. Paridad con `checkPlanMaxReached` del legacy: el tope efectivo
     * es `plans.max_users * max(1, outlets)`. Si el plan no define tope (0/null),
     * no hay límite.
     */
    private function assertPlanLimit(string $companyId): void
    {
        $current = (int) (ncmExecute(
            "SELECT COUNT(*) AS c FROM contact
              WHERE companyId = ? AND type = ? AND contactStatus > 0",
            [$companyId, self::TYPE_USER]
        )['c'] ?? 0);

        $planCode = (string) (ncmExecute(
            "SELECT plan FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        )['plan'] ?? '');
        if ($planCode === '') return;

        $planRow = ncmExecute(
            "SELECT max_users FROM plans WHERE plan_code = ? LIMIT 1",
            [$planCode]
        );
        $maxPerOutlet = (int) ($planRow['max_users'] ?? 0);
        if ($maxPerOutlet <= 0) return;

        $outlets = (int) (ncmExecute(
            "SELECT COUNT(*) AS c FROM outlet WHERE companyId = ?",
            [$companyId]
        )['c'] ?? 0);
        $max = $maxPerOutlet * max(1, $outlets);

        if ($current >= $max) {
            throw new \InvalidArgumentException(
                "Alcanzaste el límite de usuarios de tu plan ({$current}/{$max})"
            );
        }
    }

    /** Shape canónico para respuestas. Acepta array o CaseInsensitiveArray (ncmExecute). */
    private function shape(array|\CaseInsensitiveArray $row): array
    {
        $roleId = $row['roleid'] ?? $row['roleId'] ?? null;
        // Normalizamos a string para el lookup ('1' tanto desde int como string)
        $roleKey = $roleId !== null && $roleId !== '' ? (string) $roleId : null;
        // roleName: prioridad al JOIN con taxonomy 'role' (sistema nuevo
        // RoleService — soporta roles custom Dueño/Encargado/Cajero/etc).
        // Fallback al catálogo legacy ROLES (solo '1' => Super Admin) para
        // back-compat con users viejos que no estén pasados al nuevo sistema.
        $joinedRoleName = $row['rolename'] ?? $row['roleName'] ?? null;
        return [
            'id'               => $row['id']               ?? $row['contactid']               ?? null,
            'name'             => $row['name']              ?? $row['contactname']              ?? null,
            'email'            => $row['email']             ?? $row['contactemail']             ?? null,
            'phone'            => $row['phone']             ?? $row['contactphone']             ?? null,
            'status'           => (int) ($row['status']    ?? $row['contactstatus']            ?? 1),
            'color'            => $row['color']             ?? $row['contactcolor']             ?? null,
            'lockPass'         => $row['lockpass']          ?? $row['lockPass']                 ?? null,
            'lockPassHash'     => $row['lockpasshash']      ?? null,
            'pinhash'          => $row['pinhash']           ?? null,
            'inCalendar'       => (bool) ($row['incalendar'] ?? $row['contactincalendar']      ?? false),
            'calendarPosition' => (int) ($row['calendarposition'] ?? $row['contactcalendarposition'] ?? 0),
            'roleId'           => $roleKey,
            'roleName'         => $joinedRoleName ?? ($roleKey !== null ? (self::ROLES[$roleKey] ?? null) : null),
            'outletId'         => $row['outletid']          ?? $row['outletId']                 ?? null,
            'outletName'       => $row['outletname']        ?? $row['outletName']               ?? null,
            'outletIds'        => json_decode((string) ($row['outletids_json']   ?? '[]'), true) ?: [],
            'outletNames'      => json_decode((string) ($row['outletnames_json'] ?? '[]'), true) ?: [],
            'createdAt'        => $row['createdat']         ?? $row['createdAt']                ?? null,
            'updatedAt'        => $row['updatedat']         ?? $row['updatedAt']                ?? null,
        ];
    }

    /**
     * Resuelve el array canónico de outletIds desde el payload de entrada.
     * Prioridad: outletIds (nuevo) > outletId legacy > [].
     * Valida que cada outletId pertenezca al companyId (previene injection cross-tenant).
     *
     * @return string[]  Array de UUID strings válidos y del tenant.
     * @throws \InvalidArgumentException si algún outletId no pertenece al tenant.
     */
    private function resolveOutletIds(array $in, string $companyId): array
    {
        if (array_key_exists('outletIds', $in)) {
            $ids = is_array($in['outletIds']) ? $in['outletIds'] : [];
        } elseif (array_key_exists('outletId', $in) && !empty($in['outletId'])) {
            $ids = [$in['outletId']];
        } else {
            return [];
        }

        // Filtrar vacíos
        $ids = array_values(array_filter($ids, fn($v) => !empty($v)));
        if (empty($ids)) return [];

        // Validar pertenencia al tenant — un outletId por fuera es 422
        foreach ($ids as $oid) {
            $exists = ncmExecute(
                "SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1",
                [$oid, $companyId]
            );
            if (!$exists) {
                throw new \InvalidArgumentException("La sucursal '{$oid}' no pertenece a esta empresa");
            }
        }

        return $ids;
    }

    /**
     * Reemplaza todas las filas de contact_outlet para un contacto.
     * Envuelto en transacción explícita: DELETE + INSERT bulk.
     *
     * @param string   $contactId UUID del contacto.
     * @param string   $companyId UUID del tenant.
     * @param string[] $outletIds Array de outlet UUIDs (puede ser vacío).
     */
    private function syncContactOutlets(string $contactId, string $companyId, array $outletIds): void
    {
        // DELETE previos del contacto en este tenant
        ncmExecute(
            "DELETE FROM contact_outlet WHERE contactid = ? AND companyid = ?",
            [$contactId, $companyId]
        );

        if (empty($outletIds)) return;

        // INSERT bulk con un solo query
        $placeholders = implode(', ', array_fill(0, count($outletIds), '(?, ?, ?)'));
        $params = [];
        foreach ($outletIds as $oid) {
            $params[] = $contactId;
            $params[] = $oid;
            $params[] = $companyId;
        }
        ncmExecute(
            "INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES {$placeholders} ON CONFLICT DO NOTHING",
            $params
        );
    }

    /**
     * Genera hash + salt de la contraseña usando el mismo algoritmo que
     * PanelAuth::checkPassword() para que el login del panel y del POS funcionen.
     * SHA-256 iterated × HASH_TIMES (65646 por defecto) + random salt hex.
     *
     * @return array{0: string, 1: string}  [$hash, $salt]
     */
    private static function hashPassword(string $password): array
    {
        $salt   = bin2hex(random_bytes(8));
        $hash   = hash('sha256', $password . $salt);
        $rounds = defined('HASH_TIMES') ? (int) constant('HASH_TIMES') : 65646;
        for ($i = 0; $i < $rounds; $i++) {
            $hash = hash('sha256', $hash . $salt);
        }
        return [$hash, $salt];
    }
}

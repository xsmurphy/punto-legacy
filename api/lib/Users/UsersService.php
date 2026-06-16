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
                (c.data->>'contactInCalendar' = 'true') AS inCalendar,
                COALESCE(NULLIF(c.data->>'contactCalendarPosition','')::int, 0) AS calendarPosition,
                c.role            AS roleId,
                c.outletId,
                o.outletName,
                c.contactDate     AS createdAt,
                c.updated_at      AS updatedAt
            FROM contact c
            LEFT JOIN outlet o ON o.outletId = c.outletId AND o.companyId = c.companyId
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
                (c.data->>'contactInCalendar' = 'true') AS inCalendar,
                COALESCE(NULLIF(c.data->>'contactCalendarPosition','')::int, 0) AS calendarPosition,
                c.role            AS roleId,
                c.outletId,
                o.outletName,
                c.contactDate     AS createdAt,
                c.updated_at      AS updatedAt
            FROM contact c
            LEFT JOIN outlet o ON o.outletId = c.outletId AND o.companyId = c.companyId
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

        $rec = [
            'contactName'              => $name,
            'contactEmail'             => $email !== '' ? $email : null,
            'contactPhone'             => $in['phone']            ?? null,
            'contactPassword'          => $hash,
            'salt'                     => $salt,
            'role'                     => $in['roleId']           ?? null,
            'outletId'                 => $in['outletId']         ?? null,
            'lockPass'                 => $lockPass !== '' ? $lockPass : null,
            'contactInCalendar'        => !empty($in['inCalendar']) ? true : false,
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
            $rec['contactPhone'] = $in['phone'] ?: null;
        }
        if (!empty($in['password'])) {
            [$hash, $salt] = self::hashPassword((string) $in['password']);
            $rec['contactPassword'] = $hash;
            $rec['salt']            = $salt;
        }
        if (array_key_exists('roleId', $in)) {
            $rec['role']    = $in['roleId'] ?: null;
        }
        if (array_key_exists('outletId', $in)) {
            $rec['outletId'] = $in['outletId'] ?: null;
        }
        if (array_key_exists('lockPass', $in)) {
            $lockPass = trim((string) ($in['lockPass'] ?? ''));
            if ($lockPass !== '' && !preg_match('/^\d{4}$/', $lockPass)) {
                throw new \InvalidArgumentException('El código POS debe tener 4 dígitos numéricos');
            }
            if ($this->pinIsTaken($lockPass, $companyId, $id)) {
                throw new \InvalidArgumentException('El código POS ya está en uso por otro usuario');
            }
            $rec['lockPass'] = $lockPass !== '' ? $lockPass : null;
        }
        if (array_key_exists('inCalendar', $in)) {
            $rec['contactInCalendar'] = !empty($in['inCalendar']) ? true : false;
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

        global $db;
        $ok = $db->AutoExecute('contact', $rec, 'UPDATE', "contactId='{$id}' AND companyId='{$companyId}'");
        return $ok !== false;
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
        return [
            'id'               => $row['id']               ?? $row['contactid']               ?? null,
            'name'             => $row['name']              ?? $row['contactname']              ?? null,
            'email'            => $row['email']             ?? $row['contactemail']             ?? null,
            'phone'            => $row['phone']             ?? $row['contactphone']             ?? null,
            'status'           => (int) ($row['status']    ?? $row['contactstatus']            ?? 1),
            'color'            => $row['color']             ?? $row['contactcolor']             ?? null,
            'lockPass'         => $row['lockpass']          ?? $row['lockPass']                 ?? null,
            'inCalendar'       => (bool) ($row['incalendar'] ?? $row['contactincalendar']      ?? false),
            'calendarPosition' => (int) ($row['calendarposition'] ?? $row['contactcalendarposition'] ?? 0),
            'roleId'           => $roleKey,
            'roleName'         => $roleKey !== null ? (self::ROLES[$roleKey] ?? null) : null,
            'outletId'         => $row['outletid']          ?? $row['outletId']                 ?? null,
            'outletName'       => $row['outletname']        ?? $row['outletName']               ?? null,
            'createdAt'        => $row['createdat']         ?? $row['createdAt']                ?? null,
            'updatedAt'        => $row['updatedat']         ?? $row['updatedAt']                ?? null,
        ];
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

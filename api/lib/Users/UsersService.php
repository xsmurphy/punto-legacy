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
                c.contactColor    AS color,
                c.lockPass,
                c.contactInCalendar AS inCalendar,
                c.contactCalendarPosition AS calendarPosition,
                c.role            AS roleId,
                r.name            AS roleName,
                c.outletId,
                o.outletName,
                c.contactDate     AS createdAt,
                c.updated_at      AS updatedAt
            FROM contact c
            LEFT JOIN role r  ON r.roleId = c.role        AND r.companyId = c.companyId
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
                c.contactColor    AS color,
                c.lockPass,
                c.contactInCalendar AS inCalendar,
                c.contactCalendarPosition AS calendarPosition,
                c.role            AS roleId,
                r.name            AS roleName,
                c.outletId,
                o.outletName,
                c.contactDate     AS createdAt,
                c.updated_at      AS updatedAt
            FROM contact c
            LEFT JOIN role r  ON r.roleId = c.role        AND r.companyId = c.companyId
            LEFT JOIN outlet o ON o.outletId = c.outletId AND o.companyId = c.companyId
            WHERE c.contactId = ? AND c.companyId = ? AND c.type = ?
        ";
        $row = ncmExecute($sql, [$id, $companyId, self::TYPE_USER]);
        return $row ? $this->shape($row) : null;
    }

    /** Roles disponibles para esta empresa (tabla `role`). */
    public function roles(string $companyId): array
    {
        $res  = ncmExecute(
            "SELECT roleId AS id, name FROM role WHERE companyId = ? ORDER BY name ASC",
            [$companyId], false, true
        );
        $rows = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $rows[] = ['id' => $res->fields['id'], 'name' => $res->fields['name']];
                $res->MoveNext();
            }
            $res->Close();
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

        [$hash, $salt] = self::hashPassword((string) $password);

        $rec = [
            'contactName'              => $name,
            'contactEmail'             => $in['email']            ?? null,
            'contactPhone'             => $in['phone']            ?? null,
            'contactPassword'          => $hash,
            'salt'                     => $salt,
            'role'                     => $in['roleId']           ?? null,
            'outletId'                 => $in['outletId']         ?? null,
            'lockPass'                 => $in['lockPass']         ?? null,
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
            $rec['contactEmail'] = $in['email'] ?: null;
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
            $rec['lockPass'] = $in['lockPass'] ?: null;
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

    /** Shape canónico para respuestas. */
    private function shape(array $row): array
    {
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
            'roleId'           => $row['roleid']            ?? $row['roleId']                   ?? null,
            'roleName'         => $row['rolename']          ?? $row['roleName']                 ?? null,
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

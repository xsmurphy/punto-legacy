<?php

/**
 * AdminUserService.php — capa de datos del CRUD de super-admins (realm /admin).
 *
 * Realm aislado del tenant: admin_user NO tiene companyId. Password bcrypt.
 * Las reglas de negocio (último admin activo, no auto-desactivarse, email único)
 * viven acá — fuente única (convención §15). La API solo orquesta.
 *
 * Sin JSONB → queries crudas parametrizadas (igual que admin_auth.php), no ncm*.
 *
 * Ver ADR-002 + context/02-arquitectura.md § Admin realm + 10-roadmap.md (F2).
 */

class AdminUserService
{
    /** Roles válidos, jerarquía ascendente — única fuente de verdad (ver AdminAuth::ADMIN_ROLE_LEVELS). */
    public const ROLES = ['sales', 'support', 'owner'];

    /** Lista todos los admins (activos primero, luego por email). */
    public function listAll(): array
    {
        global $db;
        $r = $db->Execute(
            "SELECT adminId, email, name, role, status, lastLoginAt, created_at
               FROM admin_user
              ORDER BY status DESC, lower(email) ASC"
        );
        $rows = [];
        if ($r) {
            while (!$r->EOF) {
                $rows[] = $this->shape($r->fields);
                $r->MoveNext();
            }
        }
        return $rows;
    }

    /** Un admin por id, o null. */
    public function get(string $id): ?array
    {
        global $db;
        $r = $db->Execute(
            "SELECT adminId, email, name, role, status, lastLoginAt, created_at
               FROM admin_user WHERE adminId = ? LIMIT 1",
            [$id]
        );
        if (!$r || $r->EOF) {
            return null;
        }
        return $this->shape($r->fields);
    }

    /**
     * Crea un admin. Devuelve ['ok'=>true,'id'=>uuid] o ['ok'=>false,'error','code'].
     * $role: 'owner'|'support'|'sales' — si viene vacío/ inválido, cae a 'support'
     * (mismo default que la columna, ver mig 112).
     */
    public function create(string $email, string $name, string $password, ?string $createdBy, string $role = 'support'): array
    {
        global $db;
        $email = trim($email);
        $name  = trim($name);
        $role  = in_array($role, self::ROLES, true) ? $role : 'support';

        $bad = $this->validate($email, $password);
        if ($bad !== null) {
            return ['ok' => false, 'error' => $bad, 'code' => 422];
        }

        if ($this->emailExists($email, null)) {
            return ['ok' => false, 'error' => 'Ya existe un admin con ese email', 'code' => 422];
        }

        // DB::Execute() solo materializa filas para SELECT/WITH → un INSERT…RETURNING
        // vuelve vacío. Insert() usa AutoExecute (RETURNING * interno) → Insert_ID().
        $ok = $db->Insert('admin_user', [
            'email'        => $email,
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'name'         => $name,
            'role'         => $role,
            'status'       => 1,
            'createdBy'    => $createdBy,
        ]);
        $id = $db->Insert_ID();
        if (!$ok || !$id) {
            return ['ok' => false, 'error' => 'No se pudo crear el admin', 'code' => 500];
        }
        return ['ok' => true, 'id' => (string) $id];
    }

    /**
     * Actualiza nombre/email/rol (y password si se pasa uno no vacío).
     * $role null → no toca el rol actual (permite que un caller que no gestiona roles
     * — no debería existir, pero por las dudas — no lo pise sin querer).
     * Guard: no se puede degradar al último 'owner' activo (la plataforma se quedaría
     * sin nadie que pueda tocar planes/módulos/llaves/admins).
     * Devuelve ['ok'=>true] o ['ok'=>false,'error','code'].
     */
    public function update(string $id, string $email, string $name, string $password = '', ?string $role = null): array
    {
        global $db;
        $email = trim($email);
        $name  = trim($name);

        $current = $this->get($id);
        if (!$current) {
            return ['ok' => false, 'error' => 'Admin no encontrado', 'code' => 404];
        }

        if ($email === '') {
            return ['ok' => false, 'error' => 'El email es obligatorio', 'code' => 422];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email inválido', 'code' => 422];
        }
        if ($this->emailExists($email, $id)) {
            return ['ok' => false, 'error' => 'Ya existe otro admin con ese email', 'code' => 422];
        }
        if ($password !== '' && strlen($password) < 8) {
            return ['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres', 'code' => 422];
        }

        $newRole = $current['role'];
        if ($role !== null) {
            if (!in_array($role, self::ROLES, true)) {
                return ['ok' => false, 'error' => 'Rol inválido', 'code' => 422];
            }
            if ($current['role'] === 'owner' && $role !== 'owner' && $this->activeOwnerCount() <= 1) {
                return ['ok' => false, 'error' => 'No podés degradar al último owner activo', 'code' => 422];
            }
            $newRole = $role;
        }

        if ($password !== '') {
            $db->Execute(
                "UPDATE admin_user SET email = ?, name = ?, role = ?, passwordHash = ?, updated_at = now() WHERE adminId = ?",
                [$email, $name, $newRole, password_hash($password, PASSWORD_DEFAULT), $id]
            );
        } else {
            $db->Execute(
                "UPDATE admin_user SET email = ?, name = ?, role = ?, updated_at = now() WHERE adminId = ?",
                [$email, $name, $newRole, $id]
            );
        }
        return ['ok' => true];
    }

    /**
     * Activa (1) o desactiva (0) un admin.
     * Guard: no se puede desactivar el último admin activo ni a uno mismo.
     */
    public function setStatus(string $id, int $status, string $actingAdminId): array
    {
        global $db;
        $target = $this->get($id);
        if (!$target) {
            return ['ok' => false, 'error' => 'Admin no encontrado', 'code' => 404];
        }

        $status = $status === 1 ? 1 : 0;

        if ($status === 0) {
            if ($id === $actingAdminId) {
                return ['ok' => false, 'error' => 'No podés desactivarte a vos mismo', 'code' => 422];
            }
            if ((int) $target['status'] === 1 && $this->activeCount() <= 1) {
                return ['ok' => false, 'error' => 'No podés desactivar el último admin activo', 'code' => 422];
            }
            if ($target['role'] === 'owner' && (int) $target['status'] === 1 && $this->activeOwnerCount() <= 1) {
                return ['ok' => false, 'error' => 'No podés desactivar el último owner activo', 'code' => 422];
            }
        }

        $db->Execute(
            "UPDATE admin_user SET status = ?, updated_at = now() WHERE adminId = ?",
            [$status, $id]
        );
        return ['ok' => true];
    }

    // --- internos -----------------------------------------------------------

    private function activeCount(): int
    {
        global $db;
        $r = $db->Execute("SELECT count(*) AS n FROM admin_user WHERE status = 1");
        return ($r && !$r->EOF) ? (int) $r->fields['n'] : 0;
    }

    private function activeOwnerCount(): int
    {
        global $db;
        $r = $db->Execute("SELECT count(*) AS n FROM admin_user WHERE status = 1 AND role = 'owner'");
        return ($r && !$r->EOF) ? (int) $r->fields['n'] : 0;
    }

    private function emailExists(string $email, ?string $exceptId): bool
    {
        global $db;
        if ($exceptId !== null) {
            $r = $db->Execute(
                "SELECT adminId FROM admin_user WHERE lower(email) = lower(?) AND adminId <> ? LIMIT 1",
                [$email, $exceptId]
            );
        } else {
            $r = $db->Execute(
                "SELECT adminId FROM admin_user WHERE lower(email) = lower(?) LIMIT 1",
                [$email]
            );
        }
        return $r && !$r->EOF;
    }

    private function validate(string $email, string $password): ?string
    {
        if ($email === '') {
            return 'El email es obligatorio';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Email inválido';
        }
        if (strlen($password) < 8) {
            return 'La contraseña debe tener al menos 8 caracteres';
        }
        return null;
    }

    private function shape($row): array
    {
        return [
            // 'adminId' (no 'id'): matchea la columna admin_user.adminId y lo
            // que el frontend (AdminUserRow) ya leía — corregido junto con F6
            // porque este archivo se tocaba de todos modos para sumar 'role'.
            'adminId'     => (string) $row['adminid'],
            'email'       => (string) $row['email'],
            'name'        => (string) $row['name'],
            'role'        => (string) ($row['role'] ?? 'support'),
            'status'      => (int) $row['status'],
            'lastLoginAt' => $row['lastloginat'] ?? null,
            'createdAt'   => $row['created_at'] ?? null,
        ];
    }
}

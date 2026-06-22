<?php
declare(strict_types=1);
namespace Punto\Api\Services;

/**
 * RegisterAdminService — CRUD de cajas desde el panel de administración.
 *
 * El servicio existente RegisterService maneja la sesión de caja del POS
 * (setSession, docNumbers, hotkeys). Este servicio cubre la gestión admin:
 * listar, crear, editar y eliminar cajas.
 */
final class RegisterAdminService
{
    public function __construct(
        private readonly string $companyId,
    ) {}

    /**
     * Lista todas las cajas del tenant con JOIN a outlet para outletName.
     */
    public function listAll(): array
    {
        $rs = ncmExecute(
            'SELECT r.registerId, r.registerName, r.outletId, o.outletName, r.registerStatus
               FROM register r
               JOIN outlet o ON o.outletId = r.outletId AND o.companyId = r.companyId
              WHERE r.companyId = ?
              ORDER BY o.outletName ASC, r.registerName ASC',
            [$this->companyId],
            false,
            true  // forceObj → recordset
        );
        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $out[] = [
                    'id'         => (string)($f['registerId']    ?? $f['registerid']    ?? ''),
                    'name'       => (string)($f['registerName']  ?? $f['registername']  ?? ''),
                    'outletId'   => (string)($f['outletId']      ?? $f['outletid']      ?? ''),
                    'outletName' => (string)($f['outletName']    ?? $f['outletname']    ?? ''),
                    'status'     => (bool)($f['registerStatus']  ?? $f['registerstatus'] ?? false),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /**
     * Crea una caja.
     */
    public function create(string $outletId, string $name): array
    {
        $name     = trim($name);
        $outletId = trim($outletId);

        if ($name === '' || $outletId === '') {
            apiError('outletId y name son requeridos', 422);
        }

        // Guard: outlet pertenece al tenant
        $outlet = ncmExecute(
            'SELECT 1 FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',
            [$outletId, $this->companyId]
        );
        if (!$outlet) {
            apiError('Sucursal no encontrada', 422);
        }

        // Guard: nombre único en el outlet (solo entre activas)
        $dup = ncmExecute(
            'SELECT 1 FROM register
              WHERE companyId = ? AND outletId = ?
                AND LOWER(registerName) = LOWER(?) AND registerStatus = TRUE
              LIMIT 1',
            [$this->companyId, $outletId, $name]
        );
        if ($dup) {
            apiError('Ya existe una caja con ese nombre en la sucursal', 409);
        }

        $row = ncmExecute(
            'INSERT INTO register (registerId, companyId, outletId, registerName, registerStatus)
             VALUES (gen_random_uuid(), ?, ?, ?, TRUE)
             RETURNING registerId',
            [$this->companyId, $outletId, $name]
        );

        $id = (string)($row['registerId'] ?? $row['registerid'] ?? '');
        realtimePublish('register', 'create', $id);
        return ['id' => $id, 'name' => $name];
    }

    /**
     * Actualiza nombre y/o status de una caja.
     */
    public function update(string $id, array $fields): array
    {
        // Guard: caja existe y pertenece al tenant
        $reg = ncmExecute(
            'SELECT registerId, outletId, registerName FROM register
              WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$id, $this->companyId]
        );
        if (!$reg) {
            apiError('Caja no encontrada', 404);
        }

        $setParts  = [];
        $params    = [];

        if (array_key_exists('name', $fields)) {
            $name = trim((string)$fields['name']);
            if ($name === '') {
                apiError('El nombre no puede estar vacío', 422);
            }
            // Guard: nombre único en el outlet (excluyendo la caja actual)
            $dup = ncmExecute(
                'SELECT 1 FROM register
                  WHERE companyId = ? AND outletId = ?
                    AND LOWER(registerName) = LOWER(?) AND registerStatus = TRUE
                    AND registerId != ?
                  LIMIT 1',
                [$this->companyId, $reg['outletId'] ?? $reg['outletid'], $name, $id]
            );
            if ($dup) {
                apiError('Ya existe una caja con ese nombre en la sucursal', 409);
            }
            $setParts[] = 'registerName = ?';
            $params[]   = $name;
        }

        if (array_key_exists('status', $fields)) {
            $setParts[] = 'registerStatus = ?';
            $params[]   = (bool)$fields['status'];
        }

        if (empty($setParts)) {
            return ['ok' => true];
        }

        $params[] = $id;
        $params[] = $this->companyId;

        global $db;
        $db->Execute(
            'UPDATE register SET ' . implode(', ', $setParts) .
            ' WHERE registerId = ? AND companyId = ?',
            $params
        );

        realtimePublish('register', 'update', $id);
        return ['ok' => true];
    }

    /**
     * Elimina una caja.
     * - Bloquea si hay devices activos apuntando a esta caja.
     * - Soft delete si tiene transacciones; hard delete si no.
     */
    public function delete(string $id): array
    {
        // Guard: caja existe y pertenece al tenant
        $reg = ncmExecute(
            'SELECT registerId FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$id, $this->companyId]
        );
        if (!$reg) {
            apiError('Caja no encontrada', 404);
        }

        // Guard: devices activos — status=1 en tabla device
        $devRow = ncmExecute(
            'SELECT COUNT(*)::int AS cnt FROM device WHERE registerId = ? AND status = 1',
            [$id]
        );
        $devCount = (int)($devRow['cnt'] ?? 0);
        if ($devCount > 0) {
            // apiError solo acepta 2 args — respuesta manual para incluir payload extra
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => "Hay {$devCount} dispositivo(s) usando esta caja como caja activa", 'devices' => $devCount]);
            exit;
        }

        // ¿Tiene transacciones?
        $txRow = ncmExecute(
            'SELECT 1 FROM transaction WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$id, $this->companyId]
        );

        if ($txRow) {
            // Soft delete: preserva históricos
            global $db;
            $db->Execute(
                'UPDATE register SET registerStatus = FALSE WHERE registerId = ? AND companyId = ?',
                [$id, $this->companyId]
            );
            realtimePublish('register', 'update', $id);
            return ['deleted' => 'soft', 'reason' => 'has_transactions'];
        }

        // Hard delete
        global $db;
        $db->Execute(
            'DELETE FROM register WHERE registerId = ? AND companyId = ?',
            [$id, $this->companyId]
        );
        realtimePublish('register', 'delete', $id);
        return ['deleted' => 'hard'];
    }
}

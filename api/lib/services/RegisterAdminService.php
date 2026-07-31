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
            'SELECT r.registerId, r.registerName, r.outletId, o.outletName, r.registerStatus, r.data
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
                // Timbrado de la caja (mig 26 lo demoteó a `data` JSONB): la
                // caja ES el punto de expedición (context/29 §1), así que su
                // timbrado se administra acá — facturación electrónica lo LEE
                // de la caja, nunca lo pide de nuevo.
                $data = json_decode((string)($f['data'] ?? '{}'), true);
                $data = is_array($data) ? $data : [];
                $out[] = [
                    'id'         => (string)($f['registerId']    ?? $f['registerid']    ?? ''),
                    'name'       => (string)($f['registerName']  ?? $f['registername']  ?? ''),
                    'outletId'   => (string)($f['outletId']      ?? $f['outletid']      ?? ''),
                    'outletName' => (string)($f['outletName']    ?? $f['outletname']    ?? ''),
                    'status'     => (bool)($f['registerStatus']  ?? $f['registerstatus'] ?? false),
                    'fiscal'     => [
                        'invoiceAuth'           => isset($data['registerInvoiceAuth']) && $data['registerInvoiceAuth'] !== null
                            ? (string) $data['registerInvoiceAuth'] : '',
                        // "EEE-PPP" — establecimiento y punto de expedición.
                        'invoicePrefix'         => (string) ($data['registerInvoicePrefix'] ?? ''),
                        'invoiceAuthStart'      => (string) ($data['registerInvoiceAuthStart'] ?? ''),
                        'invoiceAuthExpiration' => (string) ($data['registerInvoiceAuthExpiration'] ?? ''),
                    ],
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

        // Timbrado de la caja — merge sobre `data` JSONB (mig 26). La caja es
        // el punto de expedición: este es EL lugar donde vive el timbrado
        // (número, EEE-PPP, vigencia); facturación electrónica y la
        // numeración fiscal (context/29 §4.2) lo leen de acá.
        $fiscalPatch = [];
        if (is_array($fields['fiscal'] ?? null)) {
            $fc = $fields['fiscal'];
            if (array_key_exists('invoiceAuth', $fc)) {
                $auth = trim((string) $fc['invoiceAuth']);
                if ($auth !== '' && !preg_match('/^\d+$/', $auth)) {
                    apiError('El número de timbrado debe ser numérico', 422);
                }
                $fiscalPatch['registerInvoiceAuth'] = $auth === '' ? null : (int) $auth;
            }
            if (array_key_exists('invoicePrefix', $fc)) {
                $prefix = trim((string) $fc['invoicePrefix']);
                if ($prefix !== '' && !preg_match('/^\d{3}-\d{3}$/', $prefix)) {
                    apiError('Establecimiento y punto de expedición van como EEE-PPP (ej. 001-001)', 422);
                }
                $fiscalPatch['registerInvoicePrefix'] = $prefix === '' ? null : $prefix;
            }
            foreach (['invoiceAuthStart' => 'registerInvoiceAuthStart', 'invoiceAuthExpiration' => 'registerInvoiceAuthExpiration'] as $in => $key) {
                if (array_key_exists($in, $fc)) {
                    $v = trim((string) $fc[$in]);
                    if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        apiError('Las fechas del timbrado van como YYYY-MM-DD', 422);
                    }
                    $fiscalPatch[$key] = $v === '' ? null : $v;
                }
            }
        }

        if (empty($setParts) && empty($fiscalPatch)) {
            return ['ok' => true];
        }

        global $db;
        if (!empty($setParts)) {
            $colParams   = $params;
            $colParams[] = $id;
            $colParams[] = $this->companyId;
            $db->Execute(
                'UPDATE register SET ' . implode(', ', $setParts) .
                ' WHERE registerId = ? AND companyId = ?',
                $colParams
            );
        }
        if (!empty($fiscalPatch)) {
            // `||` mergea claves; las que vienen null quedan null adentro del
            // JSONB (semántica "borrado" para estos campos — los lectores
            // hacen `?? ''`).
            $db->Execute(
                "UPDATE register SET data = COALESCE(data, '{}'::jsonb) || ?::jsonb
                  WHERE registerId = ? AND companyId = ?",
                [json_encode($fiscalPatch), $id, $this->companyId]
            );
        }

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

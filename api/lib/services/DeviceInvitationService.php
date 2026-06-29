<?php
namespace Punto\Api\Services;

use Punto\Api\Auth\DeviceAuth;

class DeviceInvitationService
{
    private const VALID_MODULES = ['pos', 'screen', 'kds', 'display'];
    // Alfabeto sin I ni O para evitar confusión visual
    private const ALPHA = 'ABCDEFGHJKLMNPQRSTUVWXYZ';         // 24 chars
    private const ALNUM = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 chars

    public function create(
        string $companyId,
        string $createdByUserId,
        string $module,
        ?string $outletId,
        ?string $registerId,
        ?string $deviceName,
        int $ttlHours = 24
    ): array {
        if (!in_array($module, self::VALID_MODULES, true)) {
            throw new \RuntimeException('module inválido', 422);
        }
        if ($outletId !== null && $outletId !== '') {
            $exists = ncmExecute(
                'SELECT 1 FROM outlet WHERE outletId = ?::uuid AND companyId = ?::uuid',
                [$outletId, $companyId]
            );
            if (!$exists) {
                throw new \RuntimeException('outletId no pertenece al tenant', 422);
            }
        }
        if ($registerId !== null && $registerId !== '') {
            $exists = ncmExecute(
                'SELECT 1 FROM register WHERE registerId = ?::uuid AND companyId = ?::uuid',
                [$registerId, $companyId]
            );
            if (!$exists) {
                throw new \RuntimeException('registerId no pertenece al tenant', 422);
            }
        }
        $row = ncmExecute(
            'INSERT INTO device_invitation (company_id, created_by, module, outlet_id, register_id, device_name, expires_at)
             VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, ?, now() + ?::interval)
             RETURNING id, expires_at',
            [
                $companyId,
                $createdByUserId,
                $module,
                ($outletId !== null && $outletId !== '') ? $outletId : null,
                ($registerId !== null && $registerId !== '') ? $registerId : null,
                ($deviceName !== null && $deviceName !== '') ? $deviceName : null,
                $ttlHours . ' hours',
            ]
        );
        if (!$row) {
            throw new \RuntimeException('No se pudo crear la invitación', 500);
        }
        $id     = (string) ($row['id'] ?? '');
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'https://app.punto.la', '/');
        return [
            'id'        => $id,
            'url'       => $appUrl . '/connect/' . $id,
            'expiresAt' => (string) ($row['expires_at'] ?? ''),
        ];
    }

    public function open(string $id, string $userAgent, string $ip): array
    {
        $row = ncmExecute(
            'SELECT id, company_id, module, status, opened_at, device_ua, device_ip, expires_at, user_code, auto_approve, device_id
             FROM device_invitation WHERE id = ?::uuid',
            [$id]
        );
        if (!$row) {
            throw new \RuntimeException('Invitación no encontrada', 404);
        }

        $status    = (string) ($row['status'] ?? '');
        $expiresAt = (string) ($row['expires_at'] ?? '');

        // Idempotente por id, no por UA/IP: si la invitation ya está opened y
        // tiene un user_code asignado, devolvemos SIEMPRE ese mismo code. El
        // chequeo previo (UA+IP match) era demasiado estricto — cualquier
        // reload del browser, hot-reload del Next page server-side, NAT change
        // o header forwarding diferente regeneraba el code y desincronizaba
        // la pantalla del listado del admin (incidente 2026-06-28).
        // El user_code es PÚBLICO (lo muestra la pantalla a quien la vea),
        // regenerarlo no suma seguridad. Actualizamos UA/IP best-effort para
        // mantener telemetría pero sin tocar el code.
        if ($status === 'opened' && !empty($row['user_code'])) {
            $persistedCode = (string) $row['user_code'];
            try {
                ncmExecute(
                    'UPDATE device_invitation SET device_ua=?, device_ip=?::inet WHERE id=?::uuid',
                    [$userAgent, ($ip !== '') ? $ip : null, $id]
                );
            } catch (\Throwable) {
                // best-effort — el code ya está bien
            }
            return [
                'userCode'  => $persistedCode,
                'module'    => (string) ($row['module'] ?? ''),
                'status'    => 'opened',
                'expiresAt' => $expiresAt,
            ];
        }

        if (!in_array($status, ['pending', 'opened'], true)) {
            throw new \RuntimeException('Invitación en estado inválido: ' . $status, 410);
        }
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            throw new \RuntimeException('Invitación expirada', 410);
        }

        // Generar userCode con reintentos ante colisión en unique index
        $userCode   = null;
        $maxRetries = 3;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $candidate = $this->generateUserCode();
            try {
                ncmExecute(
                    "UPDATE device_invitation
                     SET status='opened', opened_at=now(), user_code=?, device_ua=?, device_ip=?::inet
                     WHERE id=?::uuid",
                    [$candidate, $userAgent, ($ip !== '') ? $ip : null, $id]
                );
                $userCode = $candidate;
                break;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'uq_di_user_code_active')) {
                    continue;
                }
                throw $e;
            }
        }
        if ($userCode === null) {
            throw new \RuntimeException('No se pudo generar un código único', 500);
        }

        // Auto-approve: reconnect invitations se resuelven inmediatamente sin
        // mostrar user_code ni esperar aprobación del admin.
        $autoApprove = ($row['auto_approve'] ?? false) === true || ($row['auto_approve'] ?? '') === 't';
        if ($autoApprove) {
            $targetDeviceId = (string)($row['device_id'] ?? '');
            if ($targetDeviceId === '') {
                throw new \RuntimeException('Invitación auto-aprobada sin device target', 500);
            }
            $issued = \Punto\Api\Auth\DeviceAuth::issueTokenForExistingDevice($targetDeviceId);
            ncmExecute(
                "UPDATE device_invitation
                 SET status='approved', approved_at=now(), approved_by=created_by
                 WHERE id=?::uuid",
                [$id]
            );
            return [
                'id'          => $id,
                'status'      => 'approved',
                'userCode'    => null,
                'autoApprove' => true,
                'token'       => $issued['token'],
                'deviceId'    => $issued['deviceId'],
                'module'      => (string)($row['module'] ?? ''),
                'companyId'   => (string) ($issued['companyId']  ?? ''),
                'registerId'  => (string) ($issued['registerId'] ?? ''),
            ];
        }

        return [
            'userCode'  => $userCode,
            'module'    => (string) ($row['module'] ?? ''),
            'status'    => 'opened',
            'expiresAt' => $expiresAt,
        ];
    }

    public function status(string $id): array
    {
        $row = ncmExecute(
            'SELECT id, company_id, module, status, expires_at, device_id, user_code
             FROM device_invitation WHERE id = ?::uuid',
            [$id]
        );
        if (!$row) {
            throw new \RuntimeException('Invitación no encontrada', 404);
        }

        $status    = (string) ($row['status'] ?? '');
        $expiresAt = (string) ($row['expires_at'] ?? '');

        // Expiración silenciosa best-effort
        if (in_array($status, ['pending', 'opened'], true)
            && $expiresAt !== ''
            && strtotime($expiresAt) < time()
        ) {
            try {
                ncmExecute(
                    "UPDATE device_invitation SET status='expired' WHERE id=?::uuid AND status IN ('pending','opened')",
                    [$id]
                );
            } catch (\Throwable) {
                // best-effort
            }
            $status = 'expired';
        }

        $result = ['status' => $status];

        if ($status === 'approved') {
            $deviceId  = (string) ($row['device_id'] ?? '');
            $companyId = (string) ($row['company_id'] ?? '');
            if ($deviceId !== '' && $companyId !== '') {
                $jwt                     = DeviceAuth::issueTokenForExistingDevice($deviceId, $companyId);
                $result['token']         = $jwt['token'];
                $result['deviceId']      = $deviceId;
                $result['cookieExpires'] = time() + (int) ($jwt['expiresIn'] ?? 0);
                $result['module']        = (string) ($row['module'] ?? 'pos');
                $result['companyId']     = (string) ($jwt['companyId']  ?? '');
                $result['registerId']    = (string) ($jwt['registerId'] ?? '');
            }
        }

        return $result;
    }

    public function approve(
        string $id,
        string $companyIdOfAdmin,
        string $approvedByUserId,
        string $userCodeConfirm
    ): array {
        $row = ncmExecute(
            'SELECT id, company_id, module, outlet_id, register_id, device_name, status, user_code, expires_at
             FROM device_invitation WHERE id = ?::uuid',
            [$id]
        );
        if (!$row) {
            throw new \RuntimeException('Invitación no encontrada', 404);
        }

        if ((string) ($row['company_id'] ?? '') !== $companyIdOfAdmin) {
            throw new \RuntimeException('No autorizado', 403);
        }
        if ((string) ($row['status'] ?? '') !== 'opened') {
            throw new \RuntimeException('La invitación no está en estado abierto', 409);
        }
        if ((string) ($row['user_code'] ?? '') !== $userCodeConfirm) {
            throw new \RuntimeException('Código incorrecto', 422);
        }
        $expiresAt = (string) ($row['expires_at'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            throw new \RuntimeException('Invitación expirada', 410);
        }

        $outletId   = (string) ($row['outlet_id'] ?? '');
        $registerId = (string) ($row['register_id'] ?? '');
        $deviceName = (string) ($row['device_name'] ?? '');
        $module     = (string) ($row['module'] ?? '');

        // Crear device sin setear cookie — el dispositivo obtiene el token via polling en /status.
        // El module se persiste en la fila device y en el claim 'mdl' del token desde la creación.
        $issued = DeviceAuth::createDeviceAndIssueToken(
            $companyIdOfAdmin,
            $outletId,
            $registerId,
            $approvedByUserId,
            $deviceName !== '' ? $deviceName : null,
            null,    // userAgent no disponible aquí
            null,    // browserLocalId no aplica en device flow
            $module, // persiste en device.module y en JWT claim 'mdl'
        );

        $deviceId = $issued['deviceId'];

        ncmExecute(
            "UPDATE device_invitation
             SET status='approved', approved_at=now(), approved_by=?::uuid, device_id=?::uuid
             WHERE id=?::uuid",
            [$approvedByUserId, $deviceId, $id]
        );

        return ['deviceId' => $deviceId, 'token' => $issued['token']];
    }

    public function createReconnect(string $deviceId, string $companyId, string $userId): array
    {
        $device = ncmExecute(
            'SELECT deviceid, companyid, outletid, registerid, module, devicename
             FROM device
             WHERE deviceid = ?::uuid AND status = 1',
            [$deviceId]
        );
        if (!$device) {
            throw new \RuntimeException('Device no encontrado o revocado', 404);
        }
        if ((string)($device['companyid'] ?? '') !== $companyId) {
            throw new \RuntimeException('No autorizado', 403);
        }
        $row = ncmExecute(
            "INSERT INTO device_invitation
               (company_id, created_by, module, outlet_id, register_id, device_name, device_id, auto_approve, expires_at)
             VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, ?, ?::uuid, true, now() + interval '10 minutes')
             RETURNING id, expires_at",
            [
                $companyId, $userId,
                (string)($device['module'] ?? 'pos'),
                $device['outletid'] !== null ? (string)$device['outletid'] : null,
                $device['registerid'] !== null ? (string)$device['registerid'] : null,
                (string)($device['devicename'] ?? ''),
                $deviceId,
            ]
        );
        if (!$row) {
            throw new \RuntimeException('No se pudo crear la invitación de reconexión', 500);
        }
        $id     = (string)($row['id'] ?? '');
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'https://app.punto.la', '/');
        return [
            'id'          => $id,
            'url'         => $appUrl . '/connect/' . $id,
            'expiresAt'   => (string)($row['expires_at'] ?? ''),
            'autoApprove' => true,
        ];
    }

    public function deny(string $id, string $companyIdOfAdmin): void
    {
        $row = ncmExecute(
            'SELECT company_id, status FROM device_invitation WHERE id = ?::uuid',
            [$id]
        );
        if (!$row) {
            throw new \RuntimeException('Invitación no encontrada', 404);
        }
        if ((string) ($row['company_id'] ?? '') !== $companyIdOfAdmin) {
            throw new \RuntimeException('No autorizado', 403);
        }
        if (!in_array((string) ($row['status'] ?? ''), ['pending', 'opened'], true)) {
            throw new \RuntimeException('No se puede denegar en este estado', 409);
        }
        ncmExecute(
            "UPDATE device_invitation SET status='denied' WHERE id=?::uuid",
            [$id]
        );
    }

    public function list(string $companyId): array
    {
        $rs = ncmExecute(
            "SELECT id, user_code, module, outlet_id, register_id, device_name, device_ua, device_ip,
                    status, opened_at, created_at, expires_at
             FROM device_invitation
             WHERE company_id = ?::uuid
               AND status IN ('pending','opened')
               AND expires_at > now()
             ORDER BY created_at DESC",
            [$companyId],
            false,
            true  // forceObj=true -> recordset, iterar con while(!$rs->EOF)
        );
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f      = $rs->fields;
                $rows[] = [
                    'id'         => (string) ($f['id'] ?? ''),
                    'userCode'   => $f['user_code'] !== null ? (string) $f['user_code'] : null,
                    'module'     => (string) ($f['module'] ?? ''),
                    'outletId'   => $f['outlet_id'] !== null ? (string) $f['outlet_id'] : null,
                    'registerId' => $f['register_id'] !== null ? (string) $f['register_id'] : null,
                    'deviceName' => $f['device_name'] !== null ? (string) $f['device_name'] : null,
                    'deviceUa'   => $f['device_ua'] !== null ? (string) $f['device_ua'] : null,
                    'deviceIp'   => $f['device_ip'] !== null ? (string) $f['device_ip'] : null,
                    'status'     => (string) ($f['status'] ?? ''),
                    'openedAt'   => $f['opened_at'] !== null ? (string) $f['opened_at'] : null,
                    'createdAt'  => (string) ($f['created_at'] ?? ''),
                    'expiresAt'  => (string) ($f['expires_at'] ?? ''),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $rows;
    }

    private function generateUserCode(): string
    {
        $alpha = self::ALPHA;
        $alnum = self::ALNUM;
        $code  = '';
        for ($i = 0; $i < 3; $i++) {
            $code .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        $code .= '-';
        for ($i = 0; $i < 4; $i++) {
            $code .= $alnum[random_int(0, strlen($alnum) - 1)];
        }
        return $code;
    }
}

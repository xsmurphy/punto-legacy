<?php
/**
 * Auth del realm `pos-app` -- dispositivos POS pareados.
 *
 * Cookie `_jwt` (device pairing):
 *   - TTL 10 anyos (JWT lib requiere exp finita).
 *   - Sin expiracion practica: el cajero opera continuamente.
 *   - Revocacion explicita via tabla `device` (status=0).
 *
 * Coexiste con `_jwt_panel` (panel admin, 24h). Los dos cookies
 * pueden estar presentes en el mismo browser (operador que tambien
 * usa el POS en el mismo dispositivo).
 */

declare(strict_types=1);

namespace Punto\Api\Auth;

final class DeviceAuth
{
    private const TTL = 315360000; // 10 anyos en segundos

    /**
     * Emite JWT de device, inserta en tabla `device`, setea cookie `_jwt`.
     *
     * Si `$browserLocalId` no es null/vacío y ya existe un device activo para
     * (companyId, registerId, browserLocalId), re-emite el token del device
     * existente en lugar de insertar uno nuevo (idempotente).
     *
     * @return array{deviceId: string, token: string, expiresIn: int, reused: bool}
     */
    public static function issueJwt(
        string $companyId,
        string $outletId,
        string $registerId,
        string $pairedByContactId,
        ?string $deviceName     = null,
        ?string $userAgent      = null,
        ?string $browserLocalId = null,
    ): array {
        // jwt.php no se autocarga -- cargarlo explicito (mismo patron que PanelAuth)
        require_once dirname(__DIR__, 2) . '/../app/includes/jwt.php';

        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET no configurado');
        }

        // Idempotency check: si ya existe un device activo para este browser, reusar.
        if ($browserLocalId !== null && $browserLocalId !== '') {
            $existing = ncmExecute(
                'SELECT deviceid FROM device WHERE companyid=?::uuid AND registerid=?::uuid AND browserlocalid=? AND status=1',
                [$companyId, $registerId, $browserLocalId]
            );
            if ($existing) {
                $deviceId = (string) ($existing['deviceid'] ?? '');
                $token    = self::issueTokenAndCookie($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret);
                return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => true];
            }
        }

        // INSERT con RETURNING para obtener el UUID generado por PG
        try {
            $row = ncmExecute(
                'INSERT INTO device (companyid,outletid,registerid,userid,devicename,useragent,ipfirst,browserlocalid,status)
                 VALUES (?::uuid,?::uuid,?::uuid,?::uuid,?,?,?::inet,?,1)
                 RETURNING deviceid',
                [
                    $companyId,
                    $outletId   !== '' ? $outletId   : null,
                    $registerId !== '' ? $registerId : null,
                    $pairedByContactId,
                    $deviceName ?? '',
                    $userAgent,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $browserLocalId !== '' ? $browserLocalId : null,
                ]
            );

            // ncmExecute sin forceObj retorna la primera fila. PG lowercase -> deviceid.
            $deviceId = (string) ($row['deviceid'] ?? '');
            if ($deviceId === '') {
                throw new \RuntimeException('No se pudo crear el registro de device');
            }

            $token = self::issueTokenAndCookie($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret);
            return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => false];
        } catch (\Throwable $e) {
            // Race condition: otro request ganó el INSERT con el mismo browserLocalId.
            if ($browserLocalId !== null && str_contains($e->getMessage(), 'uq_device_browser_active')) {
                $winner = ncmExecute(
                    'SELECT deviceid FROM device WHERE companyid=?::uuid AND registerid=?::uuid AND browserlocalid=? AND status=1',
                    [$companyId, $registerId, $browserLocalId]
                );
                if ($winner) {
                    $deviceId = (string) ($winner['deviceid'] ?? '');
                    $token    = self::issueTokenAndCookie($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret);
                    return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => true];
                }
            }
            throw $e;
        }
    }

    /**
     * Construye el JWT del device sin efectos secundarios (sin cookie).
     * Usado tanto por issueTokenAndCookie() como por createDeviceAndIssueJwt().
     */
    private static function buildToken(
        string $companyId,
        string $outletId,
        string $registerId,
        string $deviceId,
        string $pairedByContactId,
        string $secret,
    ): string {
        $now = time();
        // oid/rid se omiten del token: se resuelven desde la fila device en cada request.
        return jwtEncode([
            'iss'  => 'pos-app',
            'cid'  => $companyId,
            'did'  => $deviceId,
            'pby'  => $pairedByContactId,
            'iat'  => $now,
            'exp'  => $now + self::TTL,
        ], $secret);
    }

    /**
     * Emite el JWT y setea la cookie `_jwt`. Reutilizable desde los paths
     * "nuevo device" y "device reusado" sin duplicar lógica.
     *
     * Si jwtEncode lanza, el device queda activo sin cookie -- el caller
     * es responsable de decidir si revocar (solo aplica al INSERT nuevo).
     */
    private static function issueTokenAndCookie(
        string $companyId,
        string $outletId,
        string $registerId,
        string $deviceId,
        string $pairedByContactId,
        string $secret,
    ): string {
        $token = self::buildToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret);

        $now     = time();
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $cookieOpts = [
            'expires'  => $now + self::TTL,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $isHttps,
        ];
        $cookieDomain = $_ENV['COOKIE_DOMAIN'] ?? '';
        if ($cookieDomain !== '') {
            $cookieOpts['domain'] = $cookieDomain;
        }
        setcookie('_jwt', $token, $cookieOpts);

        return $token;
    }

    /**
     * Crea un device en BD y emite un JWT sin setear cookie.
     * Usado por el Device Authorization Grant: el admin aprueba la invitación
     * y el dispositivo recibe el token via polling en /status (no vía setcookie
     * del request del admin).
     *
     * Misma lógica de idempotencia que issueJwt() pero sin el efecto secundario
     * de la cookie.
     *
     * @return array{deviceId: string, token: string, expiresIn: int, reused: bool}
     */
    public static function createDeviceAndIssueJwt(
        string $companyId,
        string $outletId,
        string $registerId,
        string $pairedByContactId,
        ?string $deviceName     = null,
        ?string $userAgent      = null,
        ?string $browserLocalId = null,
    ): array {
        require_once dirname(__DIR__, 2) . '/../app/includes/jwt.php';

        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET no configurado');
        }

        if ($browserLocalId !== null && $browserLocalId !== '') {
            $existing = ncmExecute(
                'SELECT deviceid FROM device WHERE companyid=?::uuid AND registerid=?::uuid AND browserlocalid=? AND status=1',
                [$companyId, $registerId, $browserLocalId]
            );
            if ($existing) {
                $deviceId = (string) ($existing['deviceid'] ?? '');
                $token    = self::buildToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret);
                return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => true];
            }
        }

        try {
            $row = ncmExecute(
                'INSERT INTO device (companyid,outletid,registerid,userid,devicename,useragent,ipfirst,browserlocalid,status)
                 VALUES (?::uuid,?::uuid,?::uuid,?::uuid,?,?,?::inet,?,1)
                 RETURNING deviceid',
                [
                    $companyId,
                    $outletId   !== '' ? $outletId   : null,
                    $registerId !== '' ? $registerId : null,
                    $pairedByContactId,
                    $deviceName ?? '',
                    $userAgent,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $browserLocalId !== '' ? $browserLocalId : null,
                ]
            );

            $deviceId = (string) ($row['deviceid'] ?? '');
            if ($deviceId === '') {
                throw new \RuntimeException('No se pudo crear el registro de device');
            }

            $token = self::buildToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret);
            return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => false];
        } catch (\Throwable $e) {
            if ($browserLocalId !== null && str_contains($e->getMessage(), 'uq_device_browser_active')) {
                $winner = ncmExecute(
                    'SELECT deviceid FROM device WHERE companyid=?::uuid AND registerid=?::uuid AND browserlocalid=? AND status=1',
                    [$companyId, $registerId, $browserLocalId]
                );
                if ($winner) {
                    $deviceId = (string) ($winner['deviceid'] ?? '');
                    $token    = self::buildToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret);
                    return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => true];
                }
            }
            throw $e;
        }
    }

    /**
     * Emite un JWT para un device ya existente en BD (sin crear uno nuevo).
     * Usado por DeviceInvitationService::status() cuando la invitación fue aprobada:
     * el dispositivo hace polling y recibe su token directamente.
     *
     * @return array{token: string, expiresIn: int}
     */
    public static function issueJwtForExistingDevice(string $deviceId, string $companyId): array
    {
        require_once dirname(__DIR__, 2) . '/../app/includes/jwt.php';

        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET no configurado');
        }

        $device = ncmExecute(
            'SELECT companyid, outletid, registerid, userid FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid AND status = 1',
            [$deviceId, $companyId]
        );
        if (!$device) {
            throw new \RuntimeException('Device no encontrado', 404);
        }

        $token = self::buildToken(
            (string) ($device['companyid'] ?? $companyId),
            (string) ($device['outletid']  ?? ''),
            (string) ($device['registerid'] ?? ''),
            $deviceId,
            (string) ($device['userid'] ?? ''),
            $secret,
        );

        return ['token' => $token, 'expiresIn' => self::TTL];
    }

    /**
     * Valida la cookie `_jwt` y retorna el ctx del device, o null si invalido/revocado.
     *
     * @return array{companyId:string,outletId:string,registerId:string,deviceId:string,userId:string,roleId:string,isDevice:bool}|null
     */
    public static function validateJwt(string $cookieValue): ?array
    {
        require_once dirname(__DIR__, 2) . '/../app/includes/jwt.php';

        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            return null;
        }

        $payload = jwtDecode($cookieValue, $secret);
        if (!is_array($payload) || ($payload['iss'] ?? '') !== 'pos-app') {
            return null;
        }

        $deviceId = (string) ($payload['did'] ?? '');
        if ($deviceId === '') {
            return null;
        }

        // Verificar que el device no esta revocado y que cid del JWT coincide con BD
        $device = ncmExecute(
            'SELECT deviceid, companyid, outletid, registerid, userid FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid AND status = 1',
            [$deviceId, (string) ($payload['cid'] ?? '')]
        );
        if (!$device) {
            return null;
        }

        // Actualizar lastSeenAt + iplast best-effort
        try {
            ncmExecute(
                'UPDATE device SET lastseenat = now(), iplast = ?::inet WHERE deviceid = ?::uuid',
                [$_SERVER['REMOTE_ADDR'] ?? null, $deviceId]
            );
        } catch (\Throwable) {
            // best-effort
        }

        return [
            'companyId'  => (string) ($device['companyid']  ?? $payload['cid'] ?? ''),
            'outletId'   => (string) ($device['outletid']   ?? $payload['oid'] ?? ''),
            'registerId' => (string) ($device['registerid'] ?? $payload['rid'] ?? ''),
            'deviceId'   => $deviceId,
            'userId'     => (string) ($device['userid']     ?? $payload['pby'] ?? ''),
            'roleId'     => '1',
            'isDevice'   => true,
        ];
    }

    /**
     * Revoca un device (soft delete: status=0).
     * companyId es obligatorio para evitar TOCTOU cross-tenant.
     */
    public static function revoke(string $deviceId, string $companyId): void
    {
        ncmExecute(
            'UPDATE device SET status = 0, revokedat = now() WHERE deviceid = ?::uuid AND companyid = ?::uuid',
            [$deviceId, $companyId]
        );
    }
}

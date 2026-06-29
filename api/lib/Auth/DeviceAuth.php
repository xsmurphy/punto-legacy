<?php
/**
 * Auth del realm `pos-app` -- dispositivos POS pareados.
 *
 * JWT del device (Bearer token, storage en localStorage del browser):
 *   - TTL 10 anyos (JWT lib requiere exp finita).
 *   - Sin expiracion practica: el cajero opera continuamente.
 *   - Revocacion explicita via tabla `device` (status=0).
 *   - Viaja como `Authorization: Bearer <token>` (no como cookie HttpOnly).
 *
 * Coexiste con `_jwt_panel` (panel admin, cookie HttpOnly 24h). El device
 * puede estar autenticado en ambos realms al mismo tiempo en el mismo browser.
 */

declare(strict_types=1);

namespace Punto\Api\Auth;

final class DeviceAuth
{
    private const TTL = 315360000; // 10 anyos en segundos

    /**
     * Emite JWT de device, inserta en tabla `device`.
     *
     * Si `$browserLocalId` no es null/vacío y ya existe un device activo para
     * (companyId, registerId, browserLocalId), re-emite el token del device
     * existente en lugar de insertar uno nuevo (idempotente).
     *
     * El token se retorna en el body — el cliente lo persiste en localStorage
     * y lo envía como `Authorization: Bearer <token>` en cada request.
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
        string $module          = 'pos',
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
                $token    = self::issueToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret, $module);
                return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => true];
            }
        }

        // INSERT con RETURNING para obtener el UUID generado por PG
        try {
            $row = ncmExecute(
                'INSERT INTO device (companyid,outletid,registerid,userid,devicename,useragent,ipfirst,browserlocalid,module,status)
                 VALUES (?::uuid,?::uuid,?::uuid,?::uuid,?,?,?::inet,?,?,1)
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
                    $module,
                ]
            );

            // ncmExecute sin forceObj retorna la primera fila. PG lowercase -> deviceid.
            $deviceId = (string) ($row['deviceid'] ?? '');
            if ($deviceId === '') {
                throw new \RuntimeException('No se pudo crear el registro de device');
            }

            $token = self::issueToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret, $module);
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
                    $token    = self::issueToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret, $module);
                    return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => true];
                }
            }
            throw $e;
        }
    }

    /**
     * Construye el JWT del device sin efectos secundarios (sin cookie).
     * Usado tanto por issueToken() como por createDeviceAndIssueJwt().
     *
     * @param string $module Tipo de dispositivo: 'pos' | 'screen' | 'kds' | 'display'.
     *                       Se incluye en el claim 'mdl'. Default 'pos' para back-compat.
     */
    private static function buildToken(
        string $companyId,
        string $outletId,
        string $registerId,
        string $deviceId,
        string $pairedByContactId,
        string $secret,
        string $module = 'pos',
    ): string {
        $now = time();
        // oid/rid se INCLUYEN como info-only para el cliente (ej. la pantalla
        // cliente necesita el `rid` en el front para suscribirse al canal WS
        // `${cid}:checkout:${rid}` al que el POS publica el cart en vivo).
        // El backend NO los lee para auth scope: sigue resolviendo desde la
        // fila `device` en cada request (memoria: jerarquia_dominio, bootstrap
        // pos-app branch). Si la fila cambia, este claim queda stale pero el
        // backend siempre gana.
        return jwtEncode([
            'iss'  => 'pos-app',
            'cid'  => $companyId,
            'did'  => $deviceId,
            'oid'  => $outletId,
            'rid'  => $registerId,
            'pby'  => $pairedByContactId,
            'mdl'  => $module,
            'iat'  => $now,
            'exp'  => $now + self::TTL,
        ], $secret);
    }

    /**
     * Construye el JWT del device y lo retorna. Reutilizable desde los paths
     * "nuevo device" y "device reusado" sin duplicar lógica.
     *
     * No setea cookie — el token viaja como Bearer en cada request del device.
     * Si jwtEncode lanza, el caller decide si revocar el device recién insertado.
     */
    private static function issueToken(
        string $companyId,
        string $outletId,
        string $registerId,
        string $deviceId,
        string $pairedByContactId,
        string $secret,
        string $module = 'pos',
    ): string {
        return self::buildToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret, $module);
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
        string $module          = 'pos',
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
                $token    = self::buildToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret, $module);
                return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => true];
            }
        }

        try {
            $row = ncmExecute(
                'INSERT INTO device (companyid,outletid,registerid,userid,devicename,useragent,ipfirst,browserlocalid,module,status)
                 VALUES (?::uuid,?::uuid,?::uuid,?::uuid,?,?,?::inet,?,?,1)
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
                    $module,
                ]
            );

            $deviceId = (string) ($row['deviceid'] ?? '');
            if ($deviceId === '') {
                throw new \RuntimeException('No se pudo crear el registro de device');
            }

            $token = self::buildToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret, $module);
            return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => false];
        } catch (\Throwable $e) {
            if ($browserLocalId !== null && str_contains($e->getMessage(), 'uq_device_browser_active')) {
                $winner = ncmExecute(
                    'SELECT deviceid FROM device WHERE companyid=?::uuid AND registerid=?::uuid AND browserlocalid=? AND status=1',
                    [$companyId, $registerId, $browserLocalId]
                );
                if ($winner) {
                    $deviceId = (string) ($winner['deviceid'] ?? '');
                    $token    = self::buildToken($companyId, $outletId, $registerId, $deviceId, $pairedByContactId, $secret, $module);
                    return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL, 'reused' => true];
                }
            }
            throw $e;
        }
    }

    /**
     * Emite un JWT para un device ya existente en BD (sin crear uno nuevo).
     * Usado por DeviceInvitationService::status() y createReconnect().
     *
     * Si $companyId se provee, se usa como filtro adicional (scoped al tenant).
     * Si es null, sólo se filtra por deviceid + status=1 (reconnect flow donde
     * ya validamos la pertenencia al tenant en el Service).
     *
     * @return array{deviceId: string, token: string, expiresIn: int}
     */
    public static function issueJwtForExistingDevice(string $deviceId, ?string $companyId = null): array
    {
        require_once dirname(__DIR__, 2) . '/../app/includes/jwt.php';

        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET no configurado');
        }

        if ($companyId !== null && $companyId !== '') {
            $device = ncmExecute(
                'SELECT companyid, outletid, registerid, userid, module FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid AND status = 1',
                [$deviceId, $companyId]
            );
        } else {
            $device = ncmExecute(
                'SELECT companyid, outletid, registerid, userid, module FROM device WHERE deviceid = ?::uuid AND status = 1',
                [$deviceId]
            );
        }
        if (!$device) {
            throw new \RuntimeException('Device no encontrado o revocado', 404);
        }

        $token = self::buildToken(
            (string) ($device['companyid'] ?? $companyId ?? ''),
            (string) ($device['outletid']  ?? ''),
            (string) ($device['registerid'] ?? ''),
            $deviceId,
            (string) ($device['userid'] ?? ''),
            $secret,
            (string) ($device['module'] ?? 'pos'),
        );

        return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL];
    }

    /**
     * Valida el Bearer token del device y retorna el ctx, o null si invalido/revocado.
     *
     * @return array{companyId:string,outletId:string,registerId:string,deviceId:string,userId:string,roleId:string,isDevice:bool}|null
     */
    public static function validateJwt(string $bearerToken): ?array
    {
        require_once dirname(__DIR__, 2) . '/../app/includes/jwt.php';

        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            return null;
        }

        $payload = jwtDecode($bearerToken, $secret);
        if (!is_array($payload) || ($payload['iss'] ?? '') !== 'pos-app') {
            return null;
        }

        $deviceId = (string) ($payload['did'] ?? '');
        if ($deviceId === '') {
            return null;
        }

        // Verificar que el device no esta revocado y que cid del JWT coincide con BD
        $device = ncmExecute(
            'SELECT deviceid, companyid, outletid, registerid, userid, module FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid AND status = 1',
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

        // module: prioridad BD (fuente de verdad), fallback al claim del JWT, default 'pos'.
        $module = (string) ($device['module'] ?? $payload['mdl'] ?? 'pos');

        return [
            'companyId'  => (string) ($device['companyid']  ?? $payload['cid'] ?? ''),
            'outletId'   => (string) ($device['outletid']   ?? $payload['oid'] ?? ''),
            'registerId' => (string) ($device['registerid'] ?? $payload['rid'] ?? ''),
            'deviceId'   => $deviceId,
            'userId'     => (string) ($device['userid']     ?? $payload['pby'] ?? ''),
            'roleId'     => '1',
            'isDevice'   => true,
            'module'     => $module,
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

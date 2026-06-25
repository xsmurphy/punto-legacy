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
     * @return array{deviceId: string, token: string, expiresIn: int}
     */
    public static function issueJwt(
        string $companyId,
        string $outletId,
        string $registerId,
        string $pairedByContactId,
        ?string $deviceName = null,
        ?string $userAgent  = null,
    ): array {
        // jwt.php no se autocarga -- cargarlo explicito (mismo patron que PanelAuth)
        require_once dirname(__DIR__, 2) . '/../app/includes/jwt.php';

        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET no configurado');
        }

        // INSERT con RETURNING para obtener el UUID generado por PG
        $row = ncmExecute(
            'INSERT INTO device (companyid,outletid,registerid,userid,devicename,useragent,ipfirst,status)
             VALUES (?::uuid,?::uuid,?::uuid,?::uuid,?,?,?::inet,1)
             RETURNING deviceid',
            [
                $companyId,
                $outletId   !== '' ? $outletId   : null,
                $registerId !== '' ? $registerId : null,
                $pairedByContactId,
                $deviceName ?? '',
                $userAgent,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );

        // ncmExecute sin forceObj retorna la primera fila. PG lowercase -> deviceid.
        $deviceId = (string) ($row['deviceid'] ?? '');
        if ($deviceId === '') {
            throw new \RuntimeException('No se pudo crear el registro de device');
        }

        $now = time();

        // Si jwtEncode lanza, el device queda activo sin cookie -- lo revocamos.
        try {
            $token = jwtEncode([
                'iss'  => 'pos-app',
                'cid'  => $companyId,
                'oid'  => $outletId,
                'rid'  => $registerId,
                'did'  => $deviceId,
                'pby'  => $pairedByContactId,
                'iat'  => $now,
                'exp'  => $now + self::TTL,
            ], $secret);
        } catch (\Throwable $e) {
            ncmExecute(
                'UPDATE device SET status = 0, revokedat = now() WHERE deviceid = ?::uuid AND companyid = ?::uuid',
                [$deviceId, $companyId]
            );
            throw $e;
        }

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

        return ['deviceId' => $deviceId, 'token' => $token, 'expiresIn' => self::TTL];
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

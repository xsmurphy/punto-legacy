<?php
/**
 * Servicios de autenticación del realm `panel` para la API compartida.
 *
 * Port mínimo de los helpers del legacy `panel/includes/functions.php`:
 *   - checkPassword()  ← checkForPassword() (linea 412)
 *   - issueJwt()       ← issueJwtPanel()    (linea 8988)
 *
 * Razón del port en lugar de include: los helpers viven enredados con el
 * resto de panel/includes (10k líneas) que arrastraría sesiones PHP,
 * MutationObservers de Alpine y CSS — no aplican en /api. Acá quedan
 * aislados, namespaced y testeables.
 *
 * Cualquier cambio a la lógica de auth del panel debe replicarse aquí
 * Y en panel/includes/functions.php hasta que el legacy desaparezca.
 */

declare(strict_types=1);

namespace Punto\Api\Auth;

final class PanelAuth
{
    /**
     * Replica `checkForPassword()` del legacy:
     *
     *   hash = SHA-256(password . salt)
     *   loop 65646 veces: hash = SHA-256(hash . salt)
     *   return hex(hash)
     *
     * El caller compara con `rtrim($contact['contactPassword'])` —
     * PostgreSQL CHAR(68) pads con espacios; los SHA-256 hex nunca
     * terminan en espacio, así que rtrim es seguro.
     */
    public static function checkPassword(string $password, string $salt): string
    {
        $hash = hash('sha256', $password . $salt);
        $rounds = defined('HASH_TIMES') ? (int) constant('HASH_TIMES') : 65646;
        for ($i = 0; $i < $rounds; $i++) {
            $hash = hash('sha256', $hash . $salt);
        }
        return $hash;
    }

    /**
     * Emite el JWT del realm `panel` y setea la cookie `_jwt_panel` con
     * el scope correcto para coexistencia panel legacy + panel-next.
     *
     * Cookie domain: `COOKIE_DOMAIN` env var (ej. ".punto.la"). Si no
     * está seteado, default = sin domain (cookie atada al host actual —
     * comportamiento histórico para local dev).
     *
     * Devuelve `['token' => string, 'expiresIn' => int]`.
     */
    public static function issueJwt(array $user): array
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            return ['token' => null, 'expiresIn' => 0];
        }

        // jwt.php define jwtEncode() pero no se autocarga en /api/bootstrap.
        require_once dirname(__DIR__, 2) . '/../app/includes/jwt.php';

        // Resolver primer outlet activo del tenant para el claim `oid`.
        // Mismo SQL que issueJwtPanel del legacy.
        $outlet = ncmExecute(
            'SELECT outletId FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletId ASC LIMIT 1',
            [$user['companyId']]
        );

        $ttl = (int) ($_ENV['PANEL_JWT_TTL'] ?? 86400);
        $now = time();

        $token = jwtEncode([
            'iss'  => 'panel',
            'sub'  => (string) $user['contactId'],
            'cid'  => (string) $user['companyId'],
            'oid'  => (string) ($outlet['outletId'] ?? ''),
            'rid'  => '',
            'role' => (int) $user['role'],
            'iat'  => $now,
            'exp'  => $now + $ttl,
        ], $secret);

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $cookieOpts = [
            'expires'  => $now + $ttl,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $isHttps,
        ];
        $cookieDomain = $_ENV['COOKIE_DOMAIN'] ?? '';
        if ($cookieDomain !== '') {
            $cookieOpts['domain'] = $cookieDomain;
        }
        setcookie('_jwt_panel', $token, $cookieOpts);

        return [
            'token'     => $token,
            'expiresIn' => $ttl,
        ];
    }
}

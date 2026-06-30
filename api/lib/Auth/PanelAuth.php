<?php
/**
 * Servicios de autenticación del realm `panel` para la API compartida.
 *
 * Port mínimo de los helpers del legacy `panel/includes/functions.php`:
 *   - checkPassword()      ← checkForPassword() (linea 412)
 *   - issuePanelSession()  ← issueJwtPanel()    (linea 8988)
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
     * Emite la sesión opaca del realm `panel` y setea la cookie `_jwt_panel` con
     * el scope correcto para coexistencia panel legacy + frontend.
     *
     * Cookie domain: `COOKIE_DOMAIN` env var (ej. ".punto.la"). Si no
     * está seteado, default = sin domain (cookie atada al host actual —
     * comportamiento histórico para local dev).
     *
     * Devuelve `['token' => string, 'expiresIn' => int]`.
     *
     * Acepta `array|\ArrayAccess` porque `findPhoneLogin()` y `ncmExecute()`
     * devuelven `CaseInsensitiveArray` (objeto del wrapper con ArrayAccess), no un
     * array plano. Matchea la firma de `issueJwtPanel()` del legacy.
     *
     * `$outletIdOverride` (NUEVO 2026-06-12) — cuando el caller ya validó la
     * pertenencia del outlet al tenant (ej. `/v1/auth/active-outlet` para
     * cambiar de sucursal sin re-loguear), saltea la resolución por SQL.
     * Default `null` → comportamiento original (primer outlet activo).
     *
     * `$registerIdOverride` (NUEVO 2026-06-16, A7) — reservado para firma;
     * el rid del POS ya NO vive en el token panel (se resuelve desde la fila
     * device en realm pos-app). Default `null` → sin efecto.
     */
    public static function issuePanelSession(
        array|\ArrayAccess $user,
        ?string $outletIdOverride = null,
        ?string $registerIdOverride = null,
    ): array {
        require_once dirname(__DIR__, 2) . '/includes/auth_session.php';

        if ($outletIdOverride !== null) {
            $resolvedOutletId = $outletIdOverride;
        } else {
            $outlet = ncmExecute(
                'SELECT outletId FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletId ASC LIMIT 1',
                [$user['companyId']]
            );
            $resolvedOutletId = (string) ($outlet['outletId'] ?? ''); // CIA wrapper resuelve case-insensitive
        }

        $ttl = (int) ($_ENV['PANEL_JWT_TTL'] ?? 86400);

        $raw = authSessionCreate('panel', [
            'companyId' => (string) $user['companyId'],
            'userId'    => (string) $user['contactId'],
            'outletId'  => $resolvedOutletId,
            'roleId'    => (string) ($user['role'] ?? ''),
            'module'    => 'panel',
            'expiresAt' => date('Y-m-d H:i:s', time() + $ttl),
        ]);

        authSetOpaqueCookie('_jwt_panel', $raw, $ttl, 'Lax');

        return ['token' => $raw, 'expiresIn' => $ttl];
    }
}

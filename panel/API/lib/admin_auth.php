<?php
/**
 * Auth del ADMIN REALM (/admin) — aislado del realm tenant.
 *
 * Realm separado criptográficamente del de tenants:
 *   - secret propio  : $_ENV['ADMIN_JWT_SECRET']  (≠ JWT_SECRET)
 *   - cookie propia  : _jwt_admin                 (≠ _jwt_panel)
 *   - audience       : claim `aud = "admin"`
 * Un `_jwt_panel` de tenant NUNCA valida acá (secret + aud distintos) y viceversa.
 *
 * Provee:
 *   adminVerifyPassword($email, $pass) → fila admin_user (CaseInsensitiveArray) | false
 *   adminIssueSession($admin)          → string token (la cookie la setea el BFF, no la API)
 *   adminMiddleware()                  → valida _jwt_admin; define ADMIN_AUTHED_ID/EMAIL o corta 401
 *
 * Password con bcrypt (password_hash/password_verify) — NO el sha256+salt de `contact`.
 */

require_once __DIR__ . '/response.php';

/** Verifica credenciales contra admin_user (activo). Devuelve la fila o false. */
function adminVerifyPassword(string $email, string $pass)
{
    global $db;
    $email = trim($email);
    if ($email === '' || $pass === '') {
        return false;
    }

    $r = $db->Execute(
        "SELECT adminId, email, name, passwordHash, status FROM admin_user WHERE lower(email) = lower(?) LIMIT 1",
        [$email]
    );
    if (!$r || $r->EOF) {
        return false;
    }
    $row = $r->fields;   // CaseInsensitiveArray
    if ((int) $row['status'] !== 1) {
        return false;
    }
    if (!password_verify($pass, (string) $row['passwordHash'])) {
        return false;
    }
    return $row;
}

/** Crea la sesion opaca del admin (sin setear cookie — la API es stateless; el BFF setea _jwt_admin). */
function adminIssueSession($admin): string
{
    require_once __DIR__ . '/../../../api/includes/auth_session.php';

    $ttl = (int) ($_ENV['ADMIN_JWT_TTL'] ?? 28800);

    return authSessionCreate('admin', [
        'companyId' => null,
        'userId'    => (string) $admin['adminId'],
        'roleId'    => 'admin',
        'module'    => 'admin',
        'meta'      => ['email' => (string) $admin['email']],
        'expiresAt' => date('Y-m-d H:i:s', time() + $ttl),
    ]);
}

/**
 * Registra una acción del super-admin en admin_audit (best-effort, nunca lanza).
 *
 * Debe llamarse DESPUÉS de adminMiddleware() (necesita ADMIN_AUTHED_ID / ADMIN_AUTHED_EMAIL)
 * y DESPUÉS de que la acción principal haya tenido éxito.
 *
 * @param string      $action      Identificador de la acción (ej. 'grantAiCredits').
 * @param string      $targetType  Tipo de entidad afectada ('company', 'admin', …).
 * @param string|null $targetId    ID de la entidad afectada (UUID o similar).
 * @param string|null $targetName  Nombre legible de la entidad (para el historial).
 * @param array       $meta        Datos adicionales (campos cambiados, montos, etc.).
 */
function adminAudit(
    string  $action,
    string  $targetType,
    ?string $targetId   = null,
    ?string $targetName = null,
    array   $meta       = []
): void {
    global $db;

    // Guard: si la conexión no está lista (tests, CLI) no hay nada que hacer.
    if (!isset($db) || !is_object($db)) {
        return;
    }

    try {
        $adminId    = defined('ADMIN_AUTHED_ID')    ? ADMIN_AUTHED_ID    : null;
        $adminEmail = defined('ADMIN_AUTHED_EMAIL') ? ADMIN_AUTHED_EMAIL : null;
        $ip         = $_SERVER['REMOTE_ADDR'] ?? null;

        $db->Execute(
            'INSERT INTO admin_audit
               ("adminId", "adminEmail", action, "targetType", "targetId", "targetName", meta, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?)',
            [
                $adminId,
                $adminEmail ? substr($adminEmail, 0, 180) : null,
                substr($action, 0, 40),
                $targetType  ? substr($targetType,  0, 20)  : null,
                $targetId    ? substr($targetId,    0, 64)  : null,
                $targetName  ? substr($targetName,  0, 200) : null,
                json_encode($meta, JSON_UNESCAPED_UNICODE),
                $ip ? substr($ip, 0, 64) : null,
            ]
        );
    } catch (\Throwable $e) {
        // Best-effort: nunca interrumpir la acción principal.
        error_log('[adminAudit] Error insertando en admin_audit: ' . $e->getMessage());
    }
}

/**
 * Gate de los endpoints del admin realm. Valida la sesión opaca (realm admin).
 * Define ADMIN_AUTHED_ID / ADMIN_AUTHED_EMAIL. Corta 401 si falla.
 */
function adminMiddleware(): void
{
    if (empty($_POST)) {
        $body = file_get_contents('php://input');
        if ($body) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) { $_POST = $decoded; }
        }
    }

    global $db;
    include_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../../api/includes/auth_session.php';

    if (!authResolve(['admin'])) {
        apiUnauthorized('No autorizado (admin)');
    }

    define('ADMIN_AUTHED_ID', AUTHED_USER_ID);

    // Email para auditoría (el token opaco no lo lleva). Lookup barato (tráfico admin mínimo).
    $email = '';
    try {
        $r = $db->Execute('SELECT email FROM admin_user WHERE adminId = ? LIMIT 1', [AUTHED_USER_ID]);
        if ($r && !$r->EOF) { $email = (string)($r->fields['email'] ?? ''); }
    } catch (\Throwable $e) {
        error_log('[adminMiddleware] email lookup falló: ' . $e->getMessage());
    }
    define('ADMIN_AUTHED_EMAIL', $email);
}

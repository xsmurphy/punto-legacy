<?php
/**
 * Auth del ADMIN REALM (/admin) — aislado del realm tenant.
 *
 * El admin NO usa JWT firmado con secret propio. Usa sesión opaca almacenada en la
 * tabla auth_session con realm = 'admin' (via authSessionCreate / authResolve).
 * El token que se emite es un handle opaco, no un JWT — ADMIN_JWT_SECRET está deprecado
 * y ya no se usa en ninguna parte del auth flow.
 *
 * Aislamiento de realms:
 *   - cookie propia  : _jwt_admin                 (≠ _jwt_panel)
 *   - realm          : 'admin' en auth_session     (≠ 'panel')
 * Un token de tenant NUNCA valida acá (realm distinto) y viceversa.
 *
 * Provee:
 *   adminVerifyPassword($email, $pass) → fila admin_user (CaseInsensitiveArray) | false
 *   adminIssueSession($admin)          → string token opaco (la cookie la setea el BFF, no la API)
 *   adminMiddleware()                  → valida _jwt_admin; define ADMIN_AUTHED_ID/EMAIL/ROLE o corta 401
 *   adminRequireRole($minRole)         → 403 si el rol del admin autenticado no alcanza $minRole
 *
 * Password con bcrypt (password_hash/password_verify) — NO el sha256+salt de `contact`.
 *
 * Roles (F6, context/34-admin-saas-plan.md §1) — jerarquía plana, NO capabilities
 * granulares: 'sales'(1) < 'support'(2) < 'owner'(3). Cada nivel incluye todo lo del
 * anterior. ADMIN_AUTHED_ROLE se resuelve DESDE LA DB en cada request (mismo query que
 * ya hacía el lookup de email) — nunca desde el token/sesión, así un cambio de rol
 * aplica de inmediato sin esperar a que expire/reemita la sesión del admin afectado.
 */

/** Jerarquía de roles admin — único lugar donde se define el orden (§1 del plan). */
const ADMIN_ROLE_LEVELS = ['sales' => 1, 'support' => 2, 'owner' => 3];

require_once __DIR__ . '/../response.php';

/** Verifica credenciales contra admin_user (activo). Devuelve la fila o false. */
function adminVerifyPassword(string $email, string $pass)
{
    global $db;
    $email = trim($email);
    if ($email === '' || $pass === '') {
        return false;
    }

    $r = $db->Execute(
        "SELECT adminId, email, name, passwordHash, status, role FROM admin_user WHERE lower(email) = lower(?) LIMIT 1",
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
    require_once __DIR__ . '/../../includes/auth_session.php';

    $ttl = (int) ($_ENV['ADMIN_JWT_TTL'] ?? 28800);

    // roleId acá es solo diagnóstico/observabilidad (auth_session.roleId visible en
    // queries de soporte) — la autorización real SIEMPRE resuelve el rol fresco desde
    // admin_user en adminMiddleware(), nunca desde este valor de sesión.
    return authSessionCreate('admin', [
        'companyId' => null,
        'userId'    => (string) $admin['adminId'],
        'roleId'    => isset($admin['role']) && $admin['role'] !== '' ? (string) $admin['role'] : 'support',
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
               (adminid, adminemail, action, targettype, targetid, targetname, meta, ip)
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
 * Define ADMIN_AUTHED_ID / ADMIN_AUTHED_EMAIL / ADMIN_AUTHED_ROLE. Corta 401 si falla.
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
    require_once __DIR__ . '/../../includes/auth_session.php';

    if (!authResolve(['admin'])) {
        apiUnauthorized('No autorizado (admin)');
    }

    define('ADMIN_AUTHED_ID', AUTHED_USER_ID);

    // Email + rol para auditoría/autorización (el token opaco no los lleva). Una sola
    // query, lookup barato (tráfico admin mínimo). El rol se resuelve SIEMPRE desde acá
    // (no desde la sesión) — ver docblock de arriba.
    $email = '';
    $role  = 'sales'; // piso más restrictivo si algo falla en el lookup — nunca fail-open.
    try {
        $r = $db->Execute('SELECT email, role FROM admin_user WHERE adminId = ? AND status = 1 LIMIT 1', [AUTHED_USER_ID]);
        if ($r && !$r->EOF) {
            $email = (string) ($r->fields['email'] ?? '');
            $dbRole = (string) ($r->fields['role'] ?? 'sales');
            $role = array_key_exists($dbRole, ADMIN_ROLE_LEVELS) ? $dbRole : 'sales';
        } else {
            // Admin desactivado o borrado pero con sesión aún viva: no confiar en nada.
            apiUnauthorized('Sesión admin inválida');
        }
    } catch (\Throwable $e) {
        error_log('[adminMiddleware] email/role lookup falló: ' . $e->getMessage());
    }
    define('ADMIN_AUTHED_EMAIL', $email);
    define('ADMIN_AUTHED_ROLE', $role);
}

/**
 * Gate de autorización por rol — ÚNICO lugar donde se compara el rol del admin
 * autenticado contra un mínimo requerido. Debe llamarse DESPUÉS de adminMiddleware().
 * Corta 403 si el rol no alcanza (nunca silencioso, nunca un `if` suelto en el endpoint).
 */
function adminRequireRole(string $minRole): void
{
    $required = ADMIN_ROLE_LEVELS[$minRole] ?? PHP_INT_MAX; // rol desconocido → nadie pasa
    $mine     = ADMIN_ROLE_LEVELS[defined('ADMIN_AUTHED_ROLE') ? ADMIN_AUTHED_ROLE : ''] ?? 0;
    if ($mine < $required) {
        apiError('No autorizado — se requiere rol ' . $minRole . ' o superior', 403);
    }
}

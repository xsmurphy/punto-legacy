<?php
/**
 * REST canónico (API compartida /api) — Login del realm `panel`.
 *
 *   POST /v1/login { phone: "E.164", password: "..." }
 *       → { ok: true, data: { token, expiresIn, user: { id, role, companyId } } }
 *       → cookie `_jwt_panel` HttpOnly seteada vía PanelAuth::issuePanelSession()
 *
 * Endpoint PÚBLICO (no `apiAuthTenant`) — esto es lo que produce la sesión.
 *
 * Port FIEL de panel/login.php (líneas 117-156). Cambios:
 *   - Acepta JSON body en vez de form-encoded.
 *   - No abre sesión de PHP: la API es stateless (ya no existe $_SESSION en /api).
 *   - Devuelve JSON envelope canónico (apiOk / apiError).
 *   - Cookie con scope `.punto.la` para compartir sesión con frontend.
 *
 * Validación de password: replica EXACTA del legacy:
 *   PanelAuth::checkPassword($pass, $row['salt']) === rtrim($row['contactPassword'])
 */

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

// Body JSON o form-encoded — apiBootstrap normaliza JSON a $_POST, pero solo
// para PUT/DELETE/PATCH. POST con application/json no entra en esa rama:
// parseamos acá manualmente.
$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $_POST = array_merge($_POST, $json);
    }
}

$phoneInput = trim((string) ($_POST['phone'] ?? ''));
$password   = (string) ($_POST['password'] ?? '');
// Sin default de país: en el login todavía no hay tenant del cual sacarlo, y
// asumir 'PY' hacía que el número de un comercio de otro país se normalizara
// como paraguayo y no matcheara nunca contra el guardado. Si el cliente no
// manda `country`, el teléfono tiene que venir en E.164 — que es exactamente
// lo que manda el frontend (ver comentario de phoneToE164 más abajo).
$iso        = strtoupper(trim((string) ($_POST['country'] ?? ''))) ?: null;

if ($phoneInput === '' || $password === '') {
    apiError('Teléfono y contraseña requeridos', 400);
}

// Normalizar a E.164. El front YA lo manda en E.164 (ver
// frontend/components/forms/phone-input.tsx que emite `e164` antes
// del submit), pero validamos por las dudas y para no atar el contract
// al frontend.
$phoneE164 = phoneToE164($phoneInput, $iso);
if ($phoneE164 === null) {
    apiError('Número de teléfono inválido', 400);
}

// findPhoneLogin: SELECT contact WHERE contactPhone = ? AND type = 0
// AND role = 1 (super-admin tenant). Ver app/includes/functions.php:2534.
// Para super-admins del SaaS hay otro realm (admin/login + admin_user).
$result = findPhoneLogin($phoneE164);
if (!$result) {
    apiError('Usuario o contraseña incorrectos o no posee permisos', 401);
}

// rtrim contactPassword: PostgreSQL CHAR(68) pads con espacios, SHA-256
// hashes nunca terminan en espacios.
$computed = \Punto\Api\Auth\PanelAuth::checkPassword($password, $result['salt']);
if ($computed !== rtrim((string) $result['contactPassword'])) {
    apiError('Usuario o contraseña incorrectos', 401);
}

// Reutilizar loginPart() del legacy: chequea status de cuenta, resuelve outlet,
// limpia datos sensibles del row. Lo SÍ usamos pero descartamos su output
// (envía HTML al body). Solo nos importa que valide companyStatus + resolve
// outlet para que issuePanelSession arme la sesion con el `oid` correcto.
// NO llamamos loginPart() porque su output mezcla HTML — duplicamos solo el
// company status check inline.
$company = ncmExecute('SELECT status FROM company WHERE companyId = ? LIMIT 1', [$result['companyId']]);
if (!$company || ((string) $company['status']) !== 'active') {
    apiError('Cuenta inhabilitada', 403);
}

$jwt = \Punto\Api\Auth\PanelAuth::issuePanelSession($result);
if ($jwt['token'] === null) {
    apiError('JWT_SECRET no configurado', 500);
}

apiOk([
    'token'     => $jwt['token'],
    'expiresIn' => $jwt['expiresIn'],
    'user'      => [
        'id'        => (string) $result['contactId'],
        'role'      => (int) $result['role'],
        'companyId' => (string) $result['companyId'],
    ],
]);

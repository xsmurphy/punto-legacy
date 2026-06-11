<?php
/**
 * REST canónico — Crea la empresa + admin user al final del flujo de signup.
 *
 *   POST /v1/signup {
 *     phone:    "+595...",        // E.164 ya verificado en /v1/signup/verify
 *     code:     "1234",            // OTP verificado (re-chequeado server-side)
 *     storename: "Panadería ...",
 *     category: "1.7",
 *     username: "Ana García",
 *     password: "...",
 *     country:  "PY"
 *   }
 *   → { ok: true, data: { token, expiresIn, companyId, user: { id, role } } }
 *   → cookie `_jwt_panel` seteada
 *
 * Endpoint PÚBLICO. Re-verifica el OTP server-side antes de crear la cuenta
 * para que no sea bypasseable saltando el paso 2 del front. Llama a
 * SignupService::create() y emite el JWT vía PanelAuth::issueJwt().
 */

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $_POST = array_merge($_POST, $json);
    }
}

$phone     = trim((string) ($_POST['phone']     ?? ''));
$code      = trim((string) ($_POST['code']      ?? ''));
$storename = trim((string) ($_POST['storename'] ?? ''));
$category  = trim((string) ($_POST['category']  ?? ''));
$username  = trim((string) ($_POST['username']  ?? ''));
$password  = (string)        ($_POST['password'] ?? '');
$country   = strtoupper(trim((string) ($_POST['country'] ?? 'PY')));

if ($phone === '' || $code === '' || $storename === '' || $category === ''
    || $username === '' || $password === '') {
    apiError('Faltan campos requeridos', 400);
}
if (strlen($password) < 6) {
    apiError('La contraseña debe tener al menos 6 caracteres', 400);
}

// Re-verificar OTP server-side. Sin esto, un atacante podría POSTear directo
// a /v1/signup saltando /v1/signup/start + /v1/signup/verify.
$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
if ($isDebug) {
    if ($code !== '0000') {
        apiError('Código inválido o expirado', 401);
    }
} else {
    $apiUrl = defined('API_URL') ? rtrim(API_URL, '/') : '';
    $pinResponse = json_decode((string) getFileContent(
        $apiUrl . '/2fapin.php?phone=' . rawurlencode($phone)
    ), true);
    if (!is_array($pinResponse) || ((string) ($pinResponse['code'] ?? '')) !== $code) {
        apiError('Código inválido o expirado', 401);
    }
}

$result = \Punto\Api\Auth\SignupService::create([
    'storename' => $storename,
    'username'  => $username,
    'password'  => $password,
    'category'  => $category,
    'country'   => $country,
    'phone'     => $phone,
]);

if (!$result['ok']) {
    // 409 cuando el conflicto es de duplicado (mensaje del service); 400 default.
    // $httpCode (no $code) — $code arriba es el OTP, no pisarlo.
    $httpCode = (str_contains((string) ($result['error'] ?? ''), 'Ya existe')) ? 409 : 400;
    apiError((string) $result['error'], $httpCode);
}

$contact = $result['contact'];
$jwt = \Punto\Api\Auth\PanelAuth::issueJwt($contact);
if ($jwt['token'] === null) {
    apiError('JWT_SECRET no configurado', 500);
}

apiOk([
    'token'     => $jwt['token'],
    'expiresIn' => $jwt['expiresIn'],
    'companyId' => $result['companyId'],
    'user'      => [
        'id'   => (string) ($contact['contactId'] ?? ''),
        'role' => (int) ($contact['role'] ?? 1),
    ],
]);

<?php
/**
 * REST canónico — Verifica el código OTP del paso 2 del signup.
 *
 *   POST /v1/signup/verify { phone: "+595...", code: "1234" }
 *       → { ok: true, data: { phone } }
 *       → 401 { error: 'invalid' } si el código no matchea
 *
 * Endpoint PÚBLICO. El chequeo real pasa por SignupOtp::check() (única
 * fuente de verdad, ver api/lib/Auth/SignupOtp.php) — en modo 'off'
 * siempre válido, en modo 'on' valida contra el hash guardado en
 * `signup_otp`. `APP_DEBUG=true` sigue aceptando el código fijo '0000'
 * como antes, en cualquier modo.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\Api\Auth\SignupOtp;

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

$phone = trim((string) ($_POST['phone'] ?? ''));
$code  = trim((string) ($_POST['code']  ?? ''));

if ($phone === '' || $code === '') {
    apiError('phone y code requeridos', 400);
}

$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
if ($isDebug) {
    if ($code === '0000') {
        apiOk(['phone' => $phone]);
    }
    apiError('Código inválido o expirado', 401);
}

if (!SignupOtp::check($phone, $code)) {
    apiError('Código inválido o expirado', 401);
}

apiOk(['phone' => $phone]);

<?php
/**
 * REST canónico — Verifica el código OTP del paso 2 del signup.
 *
 *   POST /v1/signup/verify { phone: "+595...", code: "1234" }
 *       → { ok: true, data: { phone } }
 *       → 401 { error: 'invalid' } si el código no matchea
 *
 * Endpoint PÚBLICO. Port FIEL de panel/API/check_verification.php.
 */

require_once __DIR__ . '/../../bootstrap.php';

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

$apiUrl = defined('API_URL') ? rtrim(API_URL, '/') : '';
if ($apiUrl === '') {
    apiError('API_URL no configurada', 500);
}

$pinResponse = json_decode((string) getFileContent(
    $apiUrl . '/2fapin.php?phone=' . rawurlencode($phone)
), true);

if (!is_array($pinResponse) || ((string) ($pinResponse['code'] ?? '')) !== $code) {
    apiError('Código inválido o expirado', 401);
}

apiOk(['phone' => $phone]);

<?php
/**
 * Genera y envía un código de verificación por WhatsApp.
 *
 * GET params:
 *   phone   — número sin código de país
 *   country — código ISO2 (ej: PY)
 *   new     — 1 para generar código nuevo
 *
 * Response:
 *   { success: true }                  — producción (código enviado por WA)
 *   { success: true, code: "0000" }    — debug mode (código retornado para autocomplete)
 *   { error: "..." }                   — error
 */

require_once __DIR__ . '/lib/api_middleware.php';
// No aplicar auth de empresa aquí, es un endpoint público de registro
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/simple.config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
$phone   = validateHttp('phone');
$country = validateHttp('country') ?: 'PY';

if (!$phone) {
    http_response_code(400);
    die(json_encode(['error' => 'phone requerido']));
}

// Validar y formatear el número
$valid = json_decode(getFileContent(API_URL . '/phonevalidator.php?phone=' . rawurlencode($phone) . '&country=' . $country . '&format=international'), true);

if (!empty($valid['error']) || empty($valid['phone'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Número de teléfono inválido']));
}

if (isset($valid['type']) && $valid['type'] < 1) {
    http_response_code(400);
    die(json_encode(['error' => 'El número no es un celular válido']));
}

$formattedPhone = $valid['phone'];

if ($isDebug) {
    die(json_encode(['success' => true, 'phone' => $formattedPhone, 'code' => '0000']));
}

// Generar código vía 2fapin
$pinResponse = json_decode(getFileContent(API_URL . '/2fapin.php?new=1&phone=' . rawurlencode($formattedPhone)), true);

if (!empty($pinResponse['error'])) {
    http_response_code(500);
    die(json_encode(['error' => 'No se pudo generar el código']));
}

$code = $pinResponse['code'];

// Enviar por WhatsApp via Evolution API
$msg     = '[' . APP_NAME . '] ' . $code . ' es tu código de verificación. Válido por 4 minutos.';
$phone_e = ltrim($formattedPhone, '+');
$payload = json_encode(['number' => $phone_e, 'text' => $msg]);
$baseUrl = rtrim(EVOLUTION_API_URL, '/');

$ch = curl_init($baseUrl . '/message/sendText/' . EVOLUTION_INSTANCE);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . EVOLUTION_API_KEY],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    die(json_encode(['success' => true, 'phone' => $formattedPhone]));
} else {
    http_response_code(500);
    die(json_encode(['error' => 'No se pudo enviar el código. Verificá el número e intentá de nuevo.']));
}
?>

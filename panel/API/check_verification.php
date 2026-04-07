<?php
/**
 * Verifica el código PIN ingresado por el usuario.
 *
 * GET params:
 *   phone   — número internacional formateado (ej: +595981234567)
 *   code    — PIN de 4 dígitos ingresado por el usuario
 *
 * Response:
 *   { success: true, phone: "+595..." }
 *   { error: "invalid" }
 */

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/simple.config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
$phone   = validateHttp('phone');
$code    = validateHttp('code');

if (!$phone || !$code) {
    http_response_code(400);
    die(json_encode(['error' => 'phone y code requeridos']));
}

if ($isDebug) {
    $valid = ($code === '0000');
    if ($valid) {
        die(json_encode(['success' => true, 'phone' => $phone]));
    }
    http_response_code(400);
    die(json_encode(['error' => 'invalid']));
}

// Consultar el código almacenado
$pinResponse = json_decode(getFileContent(API_URL . '/2fapin.php?phone=' . rawurlencode($phone)), true);

if ($code == $pinResponse['code']) {
    die(json_encode(['success' => true, 'phone' => $phone]));
} else {
    http_response_code(400);
    die(json_encode(['error' => 'invalid']));
}
?>

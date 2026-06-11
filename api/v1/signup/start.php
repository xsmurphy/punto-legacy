<?php
/**
 * REST canónico — Envía OTP por WhatsApp al inicio del flujo de signup.
 *
 *   POST /v1/signup/start { phone: "...", country: "PY" }
 *       → { ok: true, data: { phone: "+595...", code?: "0000" (solo debug) } }
 *
 * Endpoint PÚBLICO. Port FIEL de panel/API/send_verification.php.
 * Cambios: POST con JSON en vez de GET con query, envelope canónico.
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

$phone   = trim((string) ($_POST['phone'] ?? ''));
$country = strtoupper(trim((string) ($_POST['country'] ?? 'PY')));

if ($phone === '') {
    apiError('phone requerido', 400);
}

$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

if ($isDebug) {
    // En debug evitamos self-HTTP y devolvemos código fijo '0000' para
    // que el front pueda autocompletar (matchea check_verification.php).
    $e164 = phoneToE164($phone, $country);
    if ($e164 === null) {
        apiError('Número de teléfono inválido', 400);
    }
    apiOk(['phone' => $e164, 'code' => '0000']);
}

// PROD: validar via /phonevalidator.php + generar code via /2fapin.php + enviar
// por WhatsApp via Evolution API. URLs absolutas a la API URL (configurada en env).
$apiUrl = defined('API_URL') ? rtrim(API_URL, '/') : '';
if ($apiUrl === '') {
    apiError('API_URL no configurada', 500);
}

$valid = json_decode((string) getFileContent(
    $apiUrl . '/phonevalidator.php?phone=' . rawurlencode($phone)
    . '&country=' . rawurlencode($country) . '&format=international'
), true);

if (!is_array($valid) || !empty($valid['error']) || empty($valid['phone'])) {
    apiError('Número de teléfono inválido', 400);
}
if (isset($valid['type']) && (int) $valid['type'] < 1) {
    apiError('El número no es un celular válido', 400);
}

$formattedPhone = (string) $valid['phone'];

$pinResponse = json_decode((string) getFileContent(
    $apiUrl . '/2fapin.php?new=1&phone=' . rawurlencode($formattedPhone)
), true);
if (!is_array($pinResponse) || !empty($pinResponse['error'])) {
    apiError('No se pudo generar el código', 500);
}
$code = (string) ($pinResponse['code'] ?? '');

$msg     = '[' . (defined('APP_NAME') ? APP_NAME : 'Punto') . '] '
         . $code . ' es tu código de verificación. Válido por 4 minutos.';
$phoneE  = ltrim($formattedPhone, '+');
$payload = json_encode(['number' => $phoneE, 'text' => $msg]);

$evolutionUrl  = defined('EVOLUTION_API_URL') ? rtrim(EVOLUTION_API_URL, '/') : '';
$evolutionInst = defined('EVOLUTION_INSTANCE') ? EVOLUTION_INSTANCE : '';
$evolutionKey  = defined('EVOLUTION_API_KEY')  ? EVOLUTION_API_KEY  : '';
if ($evolutionUrl === '' || $evolutionInst === '' || $evolutionKey === '') {
    apiError('Evolution API no configurada', 500);
}

$ch = curl_init($evolutionUrl . '/message/sendText/' . $evolutionInst);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $evolutionKey],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    apiOk(['phone' => $formattedPhone]);
}
apiError('No se pudo enviar el código. Verificá el número e intentá de nuevo.', 500);

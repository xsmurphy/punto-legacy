<?php
/**
 * REST canónico — Envía OTP por WhatsApp al inicio del flujo de signup.
 *
 *   POST /v1/signup/start { phone: "...", country: "PY" }
 *       → { ok: true, data: { phone: "+595...", code?: "0000" (solo debug) } }
 *
 * Endpoint PÚBLICO.
 *
 * Modo de OTP (env `SIGNUP_OTP`, ver api/lib/Auth/SignupOtp.php):
 *   - 'off' (default): no se genera ni envía código real — el registro
 *     funciona sin OTP verdadero mientras Evolution no esté configurada.
 *   - 'on': genera código real (SignupOtp::issue) y lo envía por WhatsApp
 *     vía Evolution API.
 * `APP_DEBUG=true` sigue devolviendo el código fijo '0000' en la respuesta
 * para autocompletar en dev, en CUALQUIER modo.
 *
 * La validación de teléfono usa `phoneToE164` para todos los modos — los
 * scripts legacy `phonevalidator.php`/`2fapin.php` fueron borrados en la
 * limpieza 2026-06-29 y ya no existen.
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

$phone   = trim((string) ($_POST['phone'] ?? ''));
$country = strtoupper(trim((string) ($_POST['country'] ?? 'PY')));

if ($phone === '') {
    apiError('phone requerido', 400);
}

$e164 = phoneToE164($phone, $country);
if ($e164 === null) {
    apiError('Número de teléfono inválido', 400);
}

$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
if ($isDebug) {
    // En debug evitamos el envío real y devolvemos código fijo '0000' para
    // que el front pueda autocompletar (matchea la rama debug de verify.php).
    apiOk(['phone' => $e164, 'code' => '0000']);
}

if (SignupOtp::mode() === 'off') {
    // Registro funcionando sin validación real de OTP (decisión del owner,
    // reactivable con SIGNUP_OTP=on + Evolution). No se genera ni envía nada.
    apiOk(['phone' => $e164]);
}

$code = SignupOtp::issue($e164);

$msg     = '[' . (defined('APP_NAME') ? APP_NAME : 'Punto') . '] '
         . $code . ' es tu código de verificación. Válido por 4 minutos.';
$phoneE  = ltrim($e164, '+');
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
    apiOk(['phone' => $e164]);
}
apiError('No se pudo enviar el código. Verificá el número e intentá de nuevo.', 500);

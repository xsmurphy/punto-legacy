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
require_once __DIR__ . '/../../lib/Admin/PlatformConfig.php';

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
// Sin default de país: es el primer paso del alta y el país es justamente lo
// que el usuario está eligiendo. Asumir 'PY' mandaba el OTP a un +595
// inventado cuando el número era de otro país. Sin `country`, el teléfono
// tiene que venir en E.164 o se rechaza.
$country = strtoupper(trim((string) ($_POST['country'] ?? ''))) ?: null;

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

// Precedencia (context/34-admin-saas-plan.md F6 §3): si el admin configuró
// 'integration.evolution' en platform_config, gana entero sobre las env vars.
$evolutionCfg = PlatformConfig::get('integration.evolution', [
    'url'      => defined('EVOLUTION_API_URL') ? EVOLUTION_API_URL : '',
    'instance' => defined('EVOLUTION_INSTANCE') ? EVOLUTION_INSTANCE : '',
    'key'      => defined('EVOLUTION_API_KEY') ? EVOLUTION_API_KEY : '',
]);
$evolutionUrl  = rtrim((string) ($evolutionCfg['url'] ?? ''), '/');
$evolutionInst = (string) ($evolutionCfg['instance'] ?? '');
$evolutionKey  = (string) ($evolutionCfg['key'] ?? '');
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

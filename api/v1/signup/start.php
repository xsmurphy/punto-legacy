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
use Punto\Api\Notify\WhatsAppSender;

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

$msg = '[' . (defined('APP_NAME') ? APP_NAME : 'Punto') . '] '
     . $code . ' es tu código de verificación. Válido por 4 minutos.';

// El envío por Evolution (credenciales con precedencia platform_config→env,
// normalización del teléfono, timeout) vive en WhatsAppSender: era código
// inline acá hasta que apareció el segundo caller —los avisos de vencimiento
// de plan del job `plan-lifecycle`— y copiarlo habría dejado dos lugares
// donde arreglar lo mismo.
if (!WhatsAppSender::isConfigured()) {
    apiError('Evolution API no configurada', 500);
}

$sent = WhatsAppSender::send($e164, $msg);
if ($sent['ok']) {
    apiOk(['phone' => $e164]);
}

error_log('[signup/start] envío de OTP falló: ' . (string) ($sent['error'] ?? 'desconocido'));
apiError('No se pudo enviar el código. Verificá el número e intentá de nuevo.', 500);

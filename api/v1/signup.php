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
 *   → el `token` ES la credencial (Bearer, context/54 F1). Ya NO se setea cookie:
 *     el front tiene que guardarlo o el usuario recién registrado queda sin sesión.
 *
 * Endpoint PÚBLICO. Re-verifica el OTP server-side antes de crear la cuenta
 * para que no sea bypasseable saltando el paso 2 del front. Llama a
 * SignupService::create() y emite la sesión opaca vía PanelAuth::issuePanelSession().
 */

require_once __DIR__ . '/../bootstrap.php';

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

$phone     = trim((string) ($_POST['phone']     ?? ''));
$code      = trim((string) ($_POST['code']      ?? ''));
$storename = trim((string) ($_POST['storename'] ?? ''));
$category  = trim((string) ($_POST['category']  ?? ''));
$username  = trim((string) ($_POST['username']  ?? ''));
$password  = (string)        ($_POST['password'] ?? '');
// El país es OBLIGATORIO en el alta, no un default: de él salen la moneda, el
// IVA, el nombre del identificador tributario, el idioma y la zona horaria del
// comercio. Cuando caía a 'PY', un comercio de otro país nacía con guaraníes,
// IVA paraguayo y el reloj de Asunción — y esos valores quedaban como
// permanentes porque nada volvía a preguntarlos. El form de alta siempre lo
// manda; si no llega, es un cliente roto y conviene fallar acá.
$country   = strtoupper(trim((string) ($_POST['country'] ?? '')));
// Aceptación de términos: la EXIGE `SignupService::create()` y la manda el form
// (`app/(auth)/signup/page.tsx`), pero hasta el 2026-09-08 este archivo NO la
// reenviaba al servicio — armaba el array con seis campos y `termsAccepted`
// quedaba afuera, así que el guard del servicio veía `empty()` SIEMPRE y NADIE
// podía registrarse ("tenés que aceptar los términos" con el checkbox tildado).
// La versión viaja junto: es la evidencia de QUÉ texto se aceptó, y perderla
// acá dejaba el `termsVersion` de la evidencia legal en null aun con el front
// mandándolo.
$termsAccepted = $_POST['termsAccepted'] ?? null;
$termsVersion  = trim((string) ($_POST['termsVersion'] ?? ''));

if ($phone === '' || $code === '' || $storename === '' || $category === ''
    || $username === '' || $password === '') {
    apiError('Faltan campos requeridos', 400);
}
// Se valida ACÁ además del servicio: el endpoint es la frontera con el cliente
// y un 400 explícito es más honesto que dejar que el servicio devuelva su
// `['ok' => false]` genérico. El guard del servicio NO se saca — es el que
// protege a cualquier otro caller.
if (empty($termsAccepted)) {
    apiError('Tenés que aceptar los términos y condiciones', 400);
}
if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
    apiError('País requerido (código ISO de 2 letras)', 400);
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
} elseif (!SignupOtp::check($phone, $code)) {
    apiError('Código inválido o expirado', 401);
}

try {
    $result = \Punto\Api\Auth\SignupService::create([
        'storename' => $storename,
        'username'  => $username,
        'password'  => $password,
        'category'  => $category,
        'country'   => $country,
        'phone'     => $phone,
        'termsAccepted' => $termsAccepted,
        'termsVersion'  => $termsVersion,
    ]);
} catch (\Punto\Api\Support\DbQueryException $e) {
    // El alta corre entera dentro de una transacción y devolvía
    // `['ok'=>false,'error'=>ErrorMsg()]` cuando el wrapper devolvía `false`.
    // Ahora el wrapper LANZA, así que ese `return` ya no se alcanza y sin este
    // catch el alta respondería un 500 genérico. Se mantiene el 400 con
    // mensaje de usuario; la causa real de PG va al log (nunca al cliente:
    // filtraría nombres de tablas/columnas a un endpoint SIN autenticar).
    // El wrapper ya rollbackeó la transacción antes de propagar.
    error_log('[signup] DbQueryException: ' . $e->getMessage()
        . ' | SQLSTATE ' . $e->sqlState() . ' | SQL: ' . $e->sql());
    apiError('No se pudo crear la cuenta. Intentá de nuevo en unos minutos.', 400);
}

if (!$result['ok']) {
    // 409 cuando el conflicto es de duplicado (mensaje del service); 400 default.
    // $httpCode (no $code) — $code arriba es el OTP, no pisarlo.
    $httpCode = (str_contains((string) ($result['error'] ?? ''), 'Ya existe')) ? 409 : 400;
    apiError((string) $result['error'], $httpCode);
}

$contact = $result['contact'];
$jwt = \Punto\Api\Auth\PanelAuth::issuePanelSession($contact);
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

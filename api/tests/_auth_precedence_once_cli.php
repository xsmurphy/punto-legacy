<?php
declare(strict_types=1);

/**
 * Helper de subproceso para `pos_token_only_precedence_test.php`.
 *
 * `apiAuthTenant()` termina la ejecución con `die()` en el path de error, así
 * que cada caso corre en su propio proceso (mismo patrón que
 * `_pos_auth_once_cli.php`).
 *
 * Lo que este helper agrega sobre aquel: arma una request con las DOS
 * credenciales a la vez —Bearer y cookie— que es justo la situación que no se
 * puede montar desde el proceso del test (las constantes AUTHED_* se definen
 * una sola vez por proceso) y es la que produjo los tres incidentes.
 *
 * Uso: php _auth_precedence_once_cli.php <bearerToken> <cookieToken> <realms>
 *   <bearerToken>  token crudo para `Authorization: Bearer`, o '' para omitir
 *   <cookieToken>  token crudo para la cookie `_jwt_panel`, o '' para omitir
 *   <realms>       lista separada por comas, ej. 'panel,pos-app'
 *
 * Imprime a stdout:
 *   REALM:<realm resuelto>   (solo si NO murió)
 *   HTTP_STATUS:<code>       (siempre, vía shutdown function)
 *
 * ── Por qué llama a authResolve() y no a apiAuthTenant() ────────────────────
 * La precedencia vive en `authResolve()`, y ahí es donde queda definido
 * AUTHED_REALM. `apiAuthTenant()` hace eso y DESPUÉS carga `api/data.php` para
 * armar el contexto del tenant; en el path de ÉXITO eso muere en el fixture por
 * un motivo ajeno a la auth: `data.php:98` hace `sha1($company['accountId'])` y
 * la columna `accountid` NO existe en el schema, así que el valor es null. En
 * HTTP eso es solo un E_DEPRECATED silencioso, pero en CLI
 * `api/includes/error_handlers.php` lo escala y el proceso sale 500.
 *
 * O sea: pasar por `apiAuthTenant()` haría que los casos de éxito fallaran por
 * una deuda de `data.php` sin relación con lo que este arnés verifica —y los
 * casos de éxito son justo el regression guard del PANEL, el que NO puede
 * quedar sin correr. Los casos de 401 igual ejercitan el camino completo: mueren
 * dentro de `authResolve()`, antes de que `data.php` entre en juego.
 */

$bearerToken = $argv[1] ?? '';
$cookieToken = $argv[2] ?? '';
$realmsArg   = $argv[3] ?? 'panel,pos-app';

$realms = array_values(array_filter(array_map('trim', explode(',', $realmsArg))));

if ($bearerToken !== '') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
} else {
    unset($_SERVER['HTTP_AUTHORIZATION']);
}
if ($cookieToken !== '') {
    $_COOKIE['_jwt_panel'] = $cookieToken;
} else {
    unset($_COOKIE['_jwt_panel']);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/v1/bootstrap';

register_shutdown_function(function (): void {
    echo "\nHTTP_STATUS:" . http_response_code() . "\n";
});

require_once dirname(__DIR__) . '/bootstrap.php';
// bootstrap.php NO deja `authResolve()` disponible: `jwt_middleware.php` hace el
// `require_once` de `auth_session.php` DENTRO de `jwtAuthenticate()`, así que en
// un proceso que nunca llama a esa función la definición no existe.
require_once dirname(__DIR__) . '/includes/auth_session.php';

// authResolve() mata la request con 401 si ninguna credencial sobrevive, y
// devuelve false si NO había ninguna credencial (ahí decide el caller).
if (!authResolve($realms)) {
    http_response_code(401);
    echo "REALM_NONE:sin credenciales\n";
    exit;
}

echo 'REALM:' . AUTHED_REALM . "\n";
echo 'COMPANY:' . AUTHED_COMPANY_ID . "\n";

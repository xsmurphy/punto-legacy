<?php
declare(strict_types=1);

/**
 * Helper de subproceso para `permission_enforcement_test.php`.
 *
 * Ejecuta UN endpoint de `/v1` con una sesión de auth real y reporta con qué
 * status terminó. Corre en subproceso porque `apiError()` hace `exit` — un 403
 * dentro del proceso del test lo mataría entero. Mismo patrón que
 * `_pos_auth_once_cli.php` / `_void_once_cli.php`.
 *
 * Uso:
 *   php _permission_once_cli.php <endpointRelPath> <METHOD> <queryString> <bodyJson> <panelToken> [deviceBearer] [operatorToken]
 *
 * `<panelToken>` va como Bearer desde context/54 F4 (el realm panel dejó la cookie).
 *
 * `operatorToken` es la afirmación de operador (`X-Operator-Token`,
 * api/lib/Auth/OperatorAssertion.php): bajo realm `pos-app` es lo ÚNICO que le
 * dice al backend qué PERSONA está operando la caja, y sin ella no se puede
 * probar la exclusividad de mesas. Opcional — su AUSENCIA también es un caso
 * de prueba ("operador no identificado no toca mesa ajena").
 *
 * El body llega por argv Y por stdin (ver abajo por qué las dos).
 *
 * ── php://input bajo CLI ───────────────────────────────────────────────────
 * Varios endpoints leen el body con `file_get_contents('php://input')`
 * (users.php, drawer.php, returns.php, contacts.php). Bajo el SAPI cli ese
 * stream devuelve SIEMPRE cadena vacía —comprobado en el PHP 8.4 de la imagen
 * de la app—, así que sin hacer nada esos endpoints reciben un body vacío y
 * fallan con 400/422 ANTES de llegar al gate que queremos probar. El arnés
 * daría verde por el motivo equivocado.
 *
 * Por eso se reemplaza el wrapper `php://` por uno que sirve `php://input`
 * desde stdin. LIMITACIÓN CONSCIENTE: este wrapper NO implementa el resto de
 * los `php://` (temp, memory, output). Ningún endpoint de la matriz de casos
 * los usa; el único del repo que sí es `ItemImporter` (`php://temp`, detrás de
 * `/v1/items?resource=import`). Si algún día se agrega un caso que pase por
 * ahí, hay que extender este wrapper — de lo contrario va a fallar con un
 * error de stream y no con el 403 que se está probando.
 */

$endpointRel = $argv[1] ?? '';
$method      = strtoupper($argv[2] ?? 'GET');
$queryString = $argv[3] ?? '';
$bodyJson    = $argv[4] ?? '';
$cookieToken   = $argv[5] ?? '';
$bearerToken   = $argv[6] ?? '';
$operatorToken = $argv[7] ?? '';

if ($endpointRel === '') {
    fwrite(STDERR, "uso: _permission_once_cli.php <endpointRelPath> <METHOD> <query> <bodyJson> <cookieToken> [bearer]\n");
    exit(2);
}

$endpointPath = dirname(__DIR__) . '/' . $endpointRel;
if (!is_file($endpointPath)) {
    fwrite(STDERR, "endpoint no encontrado: $endpointPath\n");
    exit(2);
}

// Body crudo: stdin si vino, si no el argv (para poder invocar el helper a mano).
$rawBody = stream_get_contents(STDIN);
if (!is_string($rawBody) || trim($rawBody) === '') {
    $rawBody = $bodyJson;
}
$rawBody = trim($rawBody);
if ($rawBody === '{}' || $rawBody === 'null') {
    $rawBody = '';
}

/** Wrapper mínimo que sirve `php://input` desde una cadena. Ver docblock. */
final class PermTestPhpStream
{
    /** @var resource|null */
    public $context;
    public static string $input = '';
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        if (strtolower($path) !== 'php://input') {
            fwrite(STDERR, "[_permission_once_cli] stream no soportado por el wrapper de test: $path\n");
            return false;
        }
        $this->pos = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$input, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_write(string $data): int { return 0; }
    public function stream_eof(): bool { return $this->pos >= strlen(self::$input); }
    public function stream_tell(): int { return $this->pos; }
    public function stream_close(): void {}
    public function stream_flush(): bool { return true; }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $len = strlen(self::$input);
        $new = match ($whence) {
            SEEK_CUR => $this->pos + $offset,
            SEEK_END => $len + $offset,
            default  => $offset,
        };
        if ($new < 0 || $new > $len) return false;
        $this->pos = $new;
        return true;
    }

    public function stream_stat(): array { return ['size' => strlen(self::$input)]; }
    public function url_stat(string $path, int $flags): array { return ['size' => strlen(self::$input)]; }
}

PermTestPhpStream::$input = $rawBody;
stream_wrapper_unregister('php');
stream_wrapper_register('php', PermTestPhpStream::class);

$_SERVER['REQUEST_METHOD']  = $method;
$_SERVER['REQUEST_URI']     = '/' . $endpointRel . ($queryString !== '' ? '?' . $queryString : '');
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'permission-enforcement-test';
$_SERVER['CONTENT_TYPE']    = 'application/json';

// El token de PANEL viaja como Bearer, NO como cookie (context/54 F4,
// 2026-08-27): `_authAmbientTokens()` dejó de aceptar `_jwt_panel`, así que
// setearla acá haría que TODO respondiera 401 — y peor, en silencio: los casos
// "rol CON la clave → pasa el gate" tratan cualquier respuesta que no sea 403
// como éxito, así que el arnés seguiría en verde sin ejercitar un solo gate de
// permiso. El slot sigue llamándose `$cookieToken` por compatibilidad con el
// orden de argumentos; lo que transporta es la credencial del realm panel.
//
// Nunca llegan los dos a la vez (el caller pasa uno u otro: panel por este slot,
// device por `$bearerToken`), así que no se pierde ningún caso multi-credencial.
if ($cookieToken !== '') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $cookieToken;
}
if ($bearerToken !== '') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
}
if ($operatorToken !== '') {
    // Mismo nombre que produce PHP para el header `X-Operator-Token` en una
    // request real (OperatorAssertion::fromRequest lo lee de acá).
    $_SERVER['HTTP_X_OPERATOR_TOKEN'] = $operatorToken;
}

$_GET = [];
if ($queryString !== '') {
    parse_str($queryString, $_GET);
}

// $_POST se deja VACÍO a propósito: así bootstrap.php hace su parseo normal de
// `php://input` (que ahora funciona) y los endpoints ven exactamente el mismo
// estado que en una request HTTP real.
$_POST    = [];
$_REQUEST = $_GET;

register_shutdown_function(static function (): void {
    // Bajo cli el getter de http_response_code() no siempre devuelve lo que
    // seteó apiError(); el test toma el status del envelope canónico y usa
    // esta línea solo como pista de diagnóstico.
    echo "\nHTTP_STATUS:" . var_export(http_response_code(), true) . "\n";
});

echo "BODY:";
require $endpointPath;

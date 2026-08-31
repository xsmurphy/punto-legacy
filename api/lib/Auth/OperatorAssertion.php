<?php
declare(strict_types=1);

namespace Punto\Api\Auth;

/**
 * OperatorAssertion — prueba de QUIÉN está operando una caja POS.
 *
 * ── El agujero que cierra ───────────────────────────────────────────────────
 *
 * El token del realm `pos-app` identifica una TERMINAL, no a una persona: se
 * emite al parear el dispositivo, es eterno, y `apiAuthTenant()` resuelve su
 * `userId` como el contacto que hizo el pareo (api/bootstrap.php:169-171) y su
 * rol como el rol `device` del tenant. Los tres turnos de mozos que pasan por
 * esa tablet mandan requests idénticas. Para el backend, hoy, son la misma
 * entidad.
 *
 * Eso alcanza mientras la autorización dependa solo de la caja. No alcanza
 * para la exclusividad de espacios (context/15, pedido del owner 2026-08-23):
 * "el espacio de un mozo no lo puede tocar otro" es una regla sobre PERSONAS, y
 * el backend no tenía forma de distinguirlas.
 *
 * ── Por qué no alcanzaba con mandar el userId en el body ────────────────────
 *
 * Porque un dato que el cliente elige no autoriza nada: cualquiera que quiera
 * saltarse la regla manda el `userId` del dueño del espacio y pasa. Sería el
 * mismo botón escondido que el owner pidió explícitamente NO hacer, con un
 * `if` en el server para disimular.
 *
 * ── Cómo funciona ───────────────────────────────────────────────────────────
 *
 * El lockscreen del POS ya pide el PIN del operador, y `/v1/unlock-pin.php` ya
 * lo valida SERVER-SIDE contra `contact.pinhash`. Ahí, y solo ahí, el backend
 * sabe con certeza quién se paró frente a la caja. Este helper convierte ese
 * momento en una credencial verificable:
 *
 *   unlock-pin (PIN correcto) → issue() → token firmado con HMAC-SHA256
 *   el POS lo guarda junto al operador activo y lo manda en `X-Operator-Token`
 *   el backend → verify() → identidad probada, no declarada
 *
 * La firma la hace el server con `JWT_SECRET`, así que el token no se puede
 * fabricar desde el browser: para tener uno hay que haber acertado un PIN.
 *
 * ── Qué NO es ───────────────────────────────────────────────────────────────
 *
 * No es una sesión: no hay fila en `auth_session`, no se puede revocar de a
 * una, y no reemplaza al token del device (que sigue siendo lo que autentica
 * la request). Es una AFIRMACIÓN acotada — "quien manda esto conocía el PIN de
 * este contacto hace menos de `TTL_SECONDS`" — y se usa solo para decidir
 * autoría/propiedad, nunca para autenticar por sí sola. Un `X-Operator-Token`
 * sin Bearer válido no vale nada, porque `apiAuthTenant()` corta antes.
 *
 * Esto es deliberadamente lo mínimo para cerrar la regla de exclusividad SIN
 * tocar el modelo de auth. La sesión de operador de verdad (re-emitir la
 * credencial por persona, revocable, con su propio rol en el token) es el
 * rewrite de `context/21-auth-rewrite.md`, y colgarla de este cambio sería
 * meter el modelo de auth entero dentro de un feature de espacios.
 */
final class OperatorAssertion
{
    /**
     * Vida del token. Cubre un turno largo sin obligar a re-tipear el PIN, y
     * caduca solo para que una tablet olvidada desbloqueada no quede
     * afirmando para siempre que la opera el mozo del turno mañana.
     *
     * No es una ventana de seguridad crítica: el token no da acceso a nada por
     * sí mismo (ver docblock de la clase). Es la frescura de la afirmación.
     */
    public const TTL_SECONDS = 16 * 3600;

    /** Header por el que viaja. */
    public const HEADER = 'X-Operator-Token';

    /**
     * Emite la afirmación. SOLO debe llamarse desde un punto que YA verificó
     * al operador contra la BD (hoy: `/v1/unlock-pin.php`, tras el match del
     * PIN). Llamarla en cualquier otro lado convierte el token en lo que este
     * archivo existe para evitar: un dato que el cliente eligió.
     */
    public static function issue(string $companyId, string $contactId): string
    {
        $payload = [
            'c'   => $companyId,
            'u'   => $contactId,
            'exp' => time() + self::TTL_SECONDS,
        ];
        $body = self::b64UrlEncode((string) json_encode($payload));
        return $body . '.' . self::b64UrlEncode(self::sign($body));
    }

    /**
     * Verifica la afirmación y devuelve el contactId del operador, o null si
     * el token falta, está mal formado, no valida la firma, venció, o fue
     * emitido para OTRO tenant.
     *
     * Devuelve null en vez de lanzar: "no sé quién sos" es un estado normal y
     * esperado (device recién pareado, token vencido, request de un módulo que
     * no pasa por el lockscreen). Quien llama decide qué implica esa ausencia
     * — y para la exclusividad implica "no podés tocar el espacio de otro", que
     * es el fail-closed correcto.
     */
    public static function verify(?string $token, string $companyId): ?string
    {
        if ($token === null || $token === '') return null;

        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        [$body, $sig] = $parts;

        $expected = self::sign($body);
        $given    = self::b64UrlDecode($sig);
        // hash_equals: comparación en tiempo constante. La firma es un secreto
        // verificable — compararla con === filtra, byte a byte, cuánto acertó
        // quien la está adivinando.
        if ($given === null || !hash_equals($expected, $given)) return null;

        $json = self::b64UrlDecode($body);
        if ($json === null) return null;
        $payload = json_decode($json, true);
        if (!is_array($payload)) return null;

        // Scope de tenant DENTRO de la firma: sin este check, un token
        // legítimo de la empresa A sería válido contra la empresa B (la firma
        // es del server, no del tenant). Es el mismo aislamiento multi-tenant
        // que se exige en cualquier otra credencial del sistema.
        if (($payload['c'] ?? null) !== $companyId) return null;
        if ((int) ($payload['exp'] ?? 0) < time()) return null;

        $contactId = (string) ($payload['u'] ?? '');
        return $contactId !== '' ? $contactId : null;
    }

    /** Lee el header de la request actual (case-insensitive vía $_SERVER). */
    public static function fromRequest(): ?string
    {
        $raw = $_SERVER['HTTP_X_OPERATOR_TOKEN'] ?? null;
        if (!is_string($raw)) return null;
        $raw = trim($raw);
        return $raw === '' ? null : $raw;
    }

    // ── Internals ──────────────────────────────────────────────────────────

    private static function sign(string $body): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET no configurado');
        }
        return hash_hmac('sha256', $body, (string) $secret, true);
    }

    private static function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $enc): ?string
    {
        $padded = strtr($enc, '-_', '+/');
        $rem    = strlen($padded) % 4;
        if ($rem > 0) $padded .= str_repeat('=', 4 - $rem);
        $out = base64_decode($padded, true);
        return $out === false ? null : $out;
    }
}

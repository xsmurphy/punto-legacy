<?php
/**
 * Implementación minimal de JWT HS256.
 * Sin dependencias externas — solo usa hash_hmac() nativo de PHP.
 *
 * Uso:
 *   $token   = jwtEncode(['sub' => 1, 'exp' => time() + 3600], $secret);
 *   $payload = jwtDecode($token, $secret); // null si inválido o expirado
 */

function jwtEncode(array $payload, string $secret): string
{
    $header  = _jwtB64Encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body    = _jwtB64Encode(json_encode($payload));
    $sig     = _jwtB64Encode(hash_hmac('sha256', "$header.$body", $secret, true));
    return "$header.$body.$sig";
}

/**
 * Decodifica y valida un JWT.
 * Retorna el payload como array, o null si la firma es inválida o el token expiró.
 */
function jwtDecode(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $body, $sig] = $parts;

    // Verificar firma con comparación en tiempo constante
    $expected = _jwtB64Encode(hash_hmac('sha256', "$header.$body", $secret, true));
    if (!hash_equals($expected, $sig)) return null;

    $payload = json_decode(_jwtB64Decode($body), true);
    if (!is_array($payload)) return null;

    // Verificar expiración
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;

    return $payload;
}

function _jwtB64Encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _jwtB64Decode(string $data): string
{
    $pad = (4 - strlen($data) % 4) % 4;
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
}

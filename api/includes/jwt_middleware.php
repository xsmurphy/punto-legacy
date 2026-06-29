<?php
/**
 * Wrapper de autenticación para el módulo /app (POS).
 *
 * Solo expone jwtAuthenticate(), que delega a authResolve() del sistema de
 * sesiones opacas (auth_session.php). Las funciones JWT puras
 * (jwtIsDeviceRevoked, jwtInvalidateDeviceCache, jwtSetCookie,
 * _jwtExtractTokens, _jwtExtractToken) fueron eliminadas en F6 — no tenían
 * callers vivos fuera de este archivo.
 */

function jwtAuthenticate(array $allowedRealms = ['pos-app']): bool
{
    require_once __DIR__ . '/auth_session.php';
    return authResolve($allowedRealms);
}

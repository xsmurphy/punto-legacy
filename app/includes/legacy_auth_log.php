<?php
/**
 * Logging para autenticación legacy (?l= base64 / POST companyId).
 *
 * Cuando un cliente cae al fallback legacy (sin JWT) o cuando el JWT es válido
 * pero el cliente envió IDs distintos en `?l=`, se loggea con detalles útiles
 * para planificar el deprecation.
 *
 * Destino: error_log() del servidor (PHP) — captado por la stack standard.
 * No bloquea: si error_log() falla, la request sigue.
 *
 * Uso:
 *   logLegacyFallback($entryPoint, $claimedCompanyId);
 *   logJwtMismatch($entryPoint, $jwtCompanyId, $claimedCompanyId);
 */

function _legacyAuthContext(): array
{
    return [
        'ip'  => $_SERVER['REMOTE_ADDR']      ?? '?',
        'ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '?', 0, 120),
        'ref' => $_SERVER['HTTP_REFERER']     ?? '',
        'm'   => $_SERVER['REQUEST_METHOD']   ?? '?',
    ];
}

function logLegacyFallback(string $entryPoint, ?string $claimedCompanyId, ?string $claimedOutletId = null): void
{
    $ctx = _legacyAuthContext();
    @error_log(sprintf(
        '[LEGACY_AUTH] entry=%s company=%s outlet=%s ip=%s ua=%s ref=%s',
        $entryPoint,
        $claimedCompanyId ?? '?',
        $claimedOutletId  ?? '?',
        $ctx['ip'],
        $ctx['ua'],
        $ctx['ref']
    ));
}

function logJwtMismatch(string $entryPoint, string $jwtCompanyId, ?string $claimedCompanyId): void
{
    if ($jwtCompanyId === $claimedCompanyId) return;

    $ctx = _legacyAuthContext();
    @error_log(sprintf(
        '[JWT_MISMATCH] entry=%s jwt_company=%s claimed_company=%s ip=%s ua=%s',
        $entryPoint,
        $jwtCompanyId,
        $claimedCompanyId ?? '?',
        $ctx['ip'],
        $ctx['ua']
    ));
}

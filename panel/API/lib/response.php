<?php
/**
 * Envelope canónico para la API del panel.
 *
 * Formato de éxito:
 *   { "ok": true, "data": { ... }, "meta": { "ts": 1234567890, "v": "1" } }
 *
 * Formato de error:
 *   { "ok": false, "error": { "message": "...", "code": 422, "details": [] } }
 *
 * Funciones de alto nivel:
 *   apiOk($data, $code)    → responde 200 (o el código indicado) con envelope de éxito
 *   apiError($msg, $code)  → responde con envelope de error y muere
 *   apiNotFound($msg)      → wrapper para 404
 *   apiUnauthorized($msg)  → wrapper para 401
 *   apiForbidden($msg)     → wrapper para 403
 */

function _apiMeta(): array
{
    return ['ts' => time(), 'v' => '1'];
}

/**
 * Construye el envelope de éxito pero NO lo envía (útil para tests o composición).
 */
function apiEnvelope(mixed $data): array
{
    return ['ok' => true, 'data' => $data, 'meta' => _apiMeta()];
}

/**
 * Construye el envelope de error pero NO lo envía.
 */
function apiErrorEnvelope(string $message, int $code = 400, array $details = []): array
{
    return ['ok' => false, 'error' => ['message' => $message, 'code' => $code, 'details' => $details]];
}

/**
 * Envía respuesta de éxito con envelope y termina.
 */
function apiOk(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(apiEnvelope($data));
    exit;
}

/**
 * Envía respuesta de error con envelope y termina.
 */
function apiError(string $message, int $code = 400, array $details = []): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(apiErrorEnvelope($message, $code, $details));
    exit;
}

function apiNotFound(string $message = 'No se encontraron registros'): never
{
    apiError($message, 404);
}

function apiUnauthorized(string $message = 'No autorizado'): never
{
    apiError($message, 401);
}

function apiForbidden(string $message = 'Acceso denegado'): never
{
    apiError($message, 403);
}

function apiUnprocessable(string $message, array $details = []): never
{
    apiError($message, 422, $details);
}

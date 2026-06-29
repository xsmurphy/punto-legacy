<?php
/**
 * Envelope canónico de las respuestas de la API de /app (mismo contrato que el panel).
 *
 *   éxito → { "ok": true,  "data": <mixed> }
 *   error → { "ok": false, "error": { "message", "code" } }
 *
 * Ambas funciones terminan la ejecución (never). Ver context/02-arquitectura.md.
 */

function apiOk($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(string $message, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => ['message' => $message, 'code' => $code]], JSON_UNESCAPED_UNICODE);
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
    http_response_code(422);
    header('Content-Type: application/json');
    $error = ['message' => $message, 'code' => 422];
    if ($details !== []) {
        $error['details'] = $details;
    }
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

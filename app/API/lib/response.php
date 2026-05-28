<?php
/**
 * Envelope canónico de las respuestas de la API de /app (mismo contrato que el panel).
 *
 *   éxito → { "ok": true,  "data": <mixed> }
 *   error → { "ok": false, "error": { "message", "code" } }
 *
 * Ambas funciones terminan la ejecución (never). Ver context/02-arquitectura.md.
 */

function apiOk($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => ['message' => $message, 'code' => $code]], JSON_UNESCAPED_UNICODE);
    exit;
}

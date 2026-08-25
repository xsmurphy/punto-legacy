<?php
/**
 * Envelope canónico de las respuestas de la API de /app (mismo contrato que el panel).
 *
 *   éxito → { "ok": true,  "data": <mixed> }
 *   error → { "ok": false, "error": { "message", "code", "details"? } }
 *
 * Ambas funciones terminan la ejecución (never). Ver context/02-arquitectura.md.
 *
 * `details` es OPCIONAL y solo aparece cuando el error tiene estructura que el
 * cliente necesita pintar (no un texto). Primer caso: el gate de cierre de
 * turno (`ShiftCloseGate`) manda la lista de órdenes y espacios abiertos, para
 * que el POS diga QUÉ falta cerrar en vez de repetir un mensaje. Va en el
 * wrapper compartido y no inline en el endpoint a propósito: el segundo error
 * estructurado que aparezca no tiene que reinventar el envelope.
 */

function apiOk($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(string $message, int $code = 400, ?array $details = null): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    $error = ['message' => $message, 'code' => $code];
    if ($details !== null) {
        $error['details'] = $details;
    }
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
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

/**
 * 409 con `error.details` estructurado — mismo patrón que apiUnprocessable(),
 * para conflictos de ESTADO donde el cliente necesita más que un mensaje para
 * decidir qué hacer (ej. quién tiene la caja tomada y hasta cuándo, en vez de
 * solo "está ocupada"). apiError() no acepta `details`; agregar el campo ahí
 * cambiaría el contrato de TODOS sus callers, así que este helper vive aparte,
 * igual que apiUnprocessable().
 */
function apiConflict(string $message, array $details = []): never
{
    http_response_code(409);
    header('Content-Type: application/json');
    $error = ['message' => $message, 'code' => 409];
    if ($details !== []) {
        $error['details'] = $details;
    }
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

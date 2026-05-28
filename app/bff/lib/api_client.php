<?php
/**
 * Cliente HTTP fino del BFF → API para el módulo /app.
 *
 * El BFF NUNCA toca la BD ni `lib/`: obtiene su data llamando a la API por HTTP,
 * reenviando el JWT del usuario (cookie _jwt) para preservar el tenant.
 * Análogo a panel/bff/lib/api_client.php. Ver context/02-arquitectura.md § BFF.
 */

function bffApiBase(): string
{
    $base = getenv('PUNTO_APP_API_BASE');
    if (!$base) {
        // Mismo host que el BFF (dev :8002 / prod). El server corre con
        // PHP_CLI_SERVER_WORKERS>1, así que el self-HTTP no deadlockea.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost:8002';
        $base   = $scheme . '://' . $host . '/API';
    }
    return rtrim($base, '/');
}

function bffApiGet(string $path, array $query = [], string $cookieName = '_jwt'): array
{
    $url = bffApiBase() . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return bffApiSend($url, null, $cookieName);
}

function bffApiPost(string $path, array $data = [], string $cookieName = '_jwt'): array
{
    $url = bffApiBase() . '/' . ltrim($path, '/');
    return bffApiSend($url, http_build_query($data), $cookieName);
}

/** Hace la request curl y desempaqueta el envelope { ok, data } / { ok:false, error }. */
function bffApiSend(string $url, ?string $postBody, string $cookieName): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($postBody !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $postBody;
    }
    curl_setopt_array($ch, $opts);

    $jwt = $_COOKIE[$cookieName] ?? '';
    if ($jwt !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookieName . '=' . rawurlencode($jwt));
    }

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlEr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlEr) {
        return ['status' => 0, 'ok' => false, 'data' => null, 'error' => $curlEr ?: 'transport error'];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['status' => $status, 'ok' => false, 'data' => null, 'error' => 'respuesta no-JSON de la API'];
    }

    $err = $json['error'] ?? null;
    if (is_array($err)) {
        $err = $err['message'] ?? 'error';
    }

    return [
        'status' => $status,
        'ok'     => !empty($json['ok']),
        'data'   => $json['data'] ?? null,
        'error'  => $err,
    ];
}

/** Emite un JSON al front y termina. */
function bffJson($payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Propaga al front un fallo de la API (auth/transport/negocio). */
function bffFailFromApi(array $res): void
{
    $code = ($res['status'] === 401 || $res['status'] === 403) ? 401 : 502;
    bffJson(['ok' => false, 'error' => $res['error'] ?: 'error llamando a la API'], $code);
}

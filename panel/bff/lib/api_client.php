<?php
/**
 * Cliente HTTP fino del BFF → API.
 *
 * El BFF NUNCA toca la BD ni `lib/`: obtiene TODA su data llamando a la API por HTTP.
 * Hoy la API vive en el mismo host (localhost); mañana en otro server → solo cambia
 * PUNTO_API_BASE, sin tocar lógica. Ver context/02-arquitectura.md § BFF de 3 niveles.
 *
 * Reenvía el JWT del usuario (cookie _jwt_panel) a la API para preservar el tenant.
 */

function bffApiBase()
{
    $base = getenv('PUNTO_API_BASE');
    if (!$base) {
        // Dev: el server panel corre con PHP_CLI_SERVER_WORKERS>1, el self-HTTP no deadlockea.
        $base = 'http://localhost:8001/API';
    }
    return rtrim($base, '/');
}

/**
 * GET a la API. Devuelve ['status'=>int, 'ok'=>bool, 'data'=>mixed, 'error'=>mixed].
 * Desempaqueta el envelope canónico { ok, data } / { ok:false, error }.
 */
function bffApiGet($path, array $query = [])
{
    $url = bffApiBase() . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $jwt = $_COOKIE['_jwt_panel'] ?? '';
    if ($jwt !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, '_jwt_panel=' . rawurlencode($jwt));
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

    return [
        'status' => $status,
        'ok'     => !empty($json['ok']),
        'data'   => $json['data'] ?? null,
        'error'  => $json['error'] ?? null,
    ];
}

/**
 * POST a la API (escrituras). Mismo desempaquetado del envelope que bffApiGet y mismo
 * forward del JWT del usuario. El cuerpo va como form-urlencoded.
 */
function bffApiPost($path, array $data = [])
{
    $url = bffApiBase() . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $jwt = $_COOKIE['_jwt_panel'] ?? '';
    if ($jwt !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, '_jwt_panel=' . rawurlencode($jwt));
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

    return [
        'status' => $status,
        'ok'     => !empty($json['ok']),
        'data'   => $json['data'] ?? null,
        'error'  => $json['error'] ?? null,
    ];
}

/** Emite un JSON al front y termina. */
function bffJson($payload, $httpCode = 200)
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/** Propaga al front un fallo de la API (auth/transport/error de negocio). */
function bffFailFromApi(array $res)
{
    $code = ($res['status'] === 401 || $res['status'] === 403) ? 401 : 502;
    bffJson(['ok' => false, 'error' => $res['error'] ?: 'error llamando a la API'], $code);
}

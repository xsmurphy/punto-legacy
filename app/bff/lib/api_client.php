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
    // API compartida del sistema (/api), backend único que /panel y /app consumen.
    //
    // OJO con la nomenclatura: el panel BFF apunta a SU PROPIA API local (/panel/API/v1)
    // usando PUNTO_API_BASE. El app BFF apunta a la API compartida (/api/v1) — son dos
    // backends distintos. En single-container deploy ambas comparten el mismo port pero
    // se sirven con paths distintos según el Host header.
    //
    // PUNTO_SHARED_API_BASE — opcional, recomendado en deploys single-container — apunta
    // a la API compartida (ej. https://api.punto.la o http://localhost:3000 + Host header).
    // Si no está, fallback a PUNTO_API_BASE (legacy) y luego a localhost:8000 (dev).
    $base = getenv('PUNTO_SHARED_API_BASE') ?: (getenv('PUNTO_API_BASE') ?: 'http://localhost:8000');
    return rtrim($base, '/');
}

function bffApiGet(string $path, array $query = [], string $cookieName = '_jwt'): array
{
    $url = bffApiBase() . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    // IMPORTANTE: pasar 'GET' explícito. Sin esto bffApiSend defaultea a 'POST'
    // (CURLOPT_POST) → la API recibe POST y todos los endpoints que gatean
    // `$method === 'GET'` (§22.7: reads) responden "Operación no reconocida".
    return bffApiSend($url, null, $cookieName, 'GET');
}

function bffApiPost(string $path, array $data = [], string $cookieName = '_jwt'): array
{
    $url = bffApiBase() . '/' . ltrim($path, '/');
    return bffApiSend($url, http_build_query($data), $cookieName, 'POST');
}

/** PUT a la API (updates / transiciones de estado). Body form-encoded; lo parsea el shim de bootstrap. */
function bffApiPut(string $path, array $query = [], array $data = [], string $cookieName = '_jwt'): array
{
    $url = bffApiBase() . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return bffApiSend($url, http_build_query($data), $cookieName, 'PUT');
}

/** DELETE a la API (borrados). El id/scope va en query; body opcional. */
function bffApiDelete(string $path, array $query = [], array $data = [], string $cookieName = '_jwt'): array
{
    $url = bffApiBase() . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return bffApiSend($url, $data ? http_build_query($data) : null, $cookieName, 'DELETE');
}

/** Hace la request curl y desempaqueta el envelope { ok, data } / { ok:false, error }. */
function bffApiSend(string $url, ?string $body, string $cookieName, string $method = 'POST'): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
    } elseif (in_array($method, ['PUT', 'DELETE', 'PATCH'], true)) {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
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

    return bffDecodeEnvelope($body, $status, $curlEr);
}

/** Desempaqueta el envelope canónico { ok, data } / { ok:false, error } a un shape plano. */
function bffDecodeEnvelope($body, int $status, string $curlEr = ''): array
{
    if ($body === false || $curlEr) {
        return ['status' => 0, 'ok' => false, 'data' => null, 'error' => $curlEr ?: 'transport error'];
    }
    $json = json_decode((string) $body, true);
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

/**
 * GET en PARALELO a varios endpoints de la API (curl_multi). Permite que el BFF
 * componga un set de datos desde recursos GRANULARES de la API sin pagar N
 * round-trips secuenciales: el wall-clock es el del recurso más lento, no la suma.
 *
 * Patrón: la API expone recursos reusables (?resource=profile, debt, …) y el BFF
 * los compone en la forma que el front necesita. Ver context/10-roadmap.md
 * (piloto customerInfo) y §22.x.
 *
 * @param array<string,array{path:string,query?:array}> $requests  key → {path, query}
 * @return array<string,array>  key → shape plano { status, ok, data, error } (igual que bffApiSend)
 */
function bffApiGetMulti(array $requests, string $cookieName = '_jwt'): array
{
    $mh   = curl_multi_init();
    $jwt  = $_COOKIE[$cookieName] ?? '';
    $handles = [];

    foreach ($requests as $key => $req) {
        $url = bffApiBase() . '/' . ltrim($req['path'], '/');
        if (!empty($req['query'])) {
            $url .= '?' . http_build_query($req['query']);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_HTTPGET        => true,
        ]);
        if ($jwt !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookieName . '=' . rawurlencode($jwt));
        }
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    // Ejecutar todas concurrentemente. Loop hasta que no queden transfers activos.
    // (No cortar por $status: en libcurl viejo curl_multi_exec puede devolver
    // CURLM_CALL_MULTI_PERFORM, que no es CURLM_OK, y cortaría antes de terminar.)
    do {
        $running = null;
        $status  = curl_multi_exec($mh, $running);
        if ($status > CURLM_OK) {
            break; // error duro del multi-handle
        }
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0);

    $out = [];
    foreach ($handles as $key => $ch) {
        $body   = curl_multi_getcontent($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlEr = curl_error($ch);
        $out[$key] = bffDecodeEnvelope($body, $code, $curlEr);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $out;
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

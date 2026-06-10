<?php
/**
 * Cliente HTTP fino del BFF → API.
 *
 * El BFF NUNCA toca la BD ni `lib/`: obtiene TODA su data llamando a la API por HTTP.
 * Ver context/02-arquitectura.md § BFF de 3 niveles.
 *
 * DOS bases coexisten durante la migración (docs/PLAN_panel_desacople.md):
 *   - 'panel'  (default) → panel/API local (PUNTO_API_BASE). Auth: reenvía la cookie
 *     del realm (_jwt_panel | _jwt_admin). Los BFFs no migrados siguen acá sin cambios.
 *   - 'shared' → API compartida /api (PUNTO_SHARED_API_BASE). Auth: Authorization
 *     Bearer con el token de la cookie — NO cookie, para que la `_jwt` de un POS
 *     logueado en el mismo browser jamás colisione con el token del panel.
 * Los BFFs migrados pasan ['base' => 'shared']. Cuando el último migre, el default
 * flipea a 'shared' y la base panel se borra.
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

function bffSharedApiBase()
{
    // SIN fallback a PUNTO_API_BASE: en el panel esa var apunta a la API LOCAL —
    // usarla acá mandaría silenciosamente las requests migradas al backend equivocado.
    $base = getenv('PUNTO_SHARED_API_BASE');
    if (!$base) {
        $base = 'http://localhost:8000'; // dev: api/router.php en :8000
    }
    return rtrim($base, '/');
}

/** Resuelve base URL según $opts['base'] ('panel' default | 'shared'). */
function _bffResolveBase(array $opts)
{
    return (($opts['base'] ?? 'panel') === 'shared') ? bffSharedApiBase() : bffApiBase();
}

/**
 * GET a la API. Devuelve ['status'=>int, 'ok'=>bool, 'data'=>mixed, 'error'=>mixed].
 * Desempaqueta el envelope canónico { ok, data } / { ok:false, error }.
 */
function bffApiGet($path, array $query = [], $cookieName = '_jwt_panel', array $opts = [])
{
    $url = _bffResolveBase($opts) . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    // 'GET' explícito: sin método, bffApiSend defaultearía a POST y los endpoints
    // que gatean por $_SERVER['REQUEST_METHOD'] === 'GET' rechazarían la lectura.
    return bffApiSend($url, null, $cookieName, 'GET', $opts);
}

/**
 * POST a la API (escrituras). Mismo desempaquetado del envelope que bffApiGet.
 * El cuerpo va como form-urlencoded.
 */
function bffApiPost($path, array $data = [], $cookieName = '_jwt_panel', array $opts = [])
{
    $url = _bffResolveBase($opts) . '/' . ltrim($path, '/');
    return bffApiSend($url, http_build_query($data), $cookieName, 'POST', $opts);
}

/** PUT a la API (updates / transiciones de estado). Body form-encoded; lo parsea el shim del bootstrap de /api. */
function bffApiPut($path, array $query = [], array $data = [], $cookieName = '_jwt_panel', array $opts = [])
{
    $url = _bffResolveBase($opts) . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return bffApiSend($url, http_build_query($data), $cookieName, 'PUT', $opts);
}

/** DELETE a la API (borrados). El id/scope va en query; body opcional. */
function bffApiDelete($path, array $query = [], array $data = [], $cookieName = '_jwt_panel', array $opts = [])
{
    $url = _bffResolveBase($opts) . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return bffApiSend($url, $data ? http_build_query($data) : null, $cookieName, 'DELETE', $opts);
}

/** Hace la request curl y desempaqueta el envelope. */
function bffApiSend($url, $body, $cookieName, $method = 'POST', array $opts = [])
{
    $ch = curl_init($url);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($method === 'POST') {
        $curlOpts[CURLOPT_POST] = true;
        if ($body !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = $body;
        }
    } elseif (in_array($method, ['PUT', 'DELETE', 'PATCH'], true)) {
        $curlOpts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = $body;
        }
    }
    curl_setopt_array($ch, $curlOpts);

    _bffAttachAuth($ch, $cookieName, $opts, $curlOpts[CURLOPT_HTTPHEADER]);

    $respBody = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlEr   = curl_error($ch);
    curl_close($ch);

    return bffDecodeEnvelope($respBody, $status, $curlEr);
}

/**
 * Adjunta la credencial del usuario a la request según la base:
 *   - panel  → reenvía la cookie del realm (_jwt_panel | _jwt_admin), comportamiento histórico.
 *   - shared → Authorization: Bearer (prioridad 1 de _jwtExtractToken en /api); nunca cookie.
 */
function _bffAttachAuth($ch, $cookieName, array $opts, array $baseHeaders)
{
    $jwt = $_COOKIE[$cookieName] ?? '';
    if ($jwt === '') {
        return;
    }
    if (($opts['base'] ?? 'panel') === 'shared') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($baseHeaders, ['Authorization: Bearer ' . $jwt]));
    } else {
        curl_setopt($ch, CURLOPT_COOKIE, $cookieName . '=' . rawurlencode($jwt));
    }
}

/** Desempaqueta el envelope canónico { ok, data } / { ok:false, error } a un shape plano. */
function bffDecodeEnvelope($body, $status, $curlEr = '')
{
    if ($body === false || $curlEr) {
        return ['status' => 0, 'ok' => false, 'data' => null, 'error' => $curlEr ?: 'transport error'];
    }
    $json = json_decode((string) $body, true);
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
 * GET en PARALELO a varios endpoints de la API (curl_multi). El BFF compone un
 * view-model desde recursos GRANULARES sin pagar N round-trips secuenciales:
 * el wall-clock es el del recurso más lento. Mismo patrón que app/bff (§22.12).
 *
 * @param array<string,array{path:string,query?:array}> $requests  key → {path, query}
 * @return array<string,array>  key → shape plano { status, ok, data, error }
 */
function bffApiGetMulti(array $requests, $cookieName = '_jwt_panel', array $opts = [])
{
    $mh       = curl_multi_init();
    $jwt      = $_COOKIE[$cookieName] ?? '';
    $isShared = ($opts['base'] ?? 'panel') === 'shared';
    $base     = _bffResolveBase($opts);
    $handles  = [];

    foreach ($requests as $key => $req) {
        $url = $base . '/' . ltrim($req['path'], '/');
        if (!empty($req['query'])) {
            $url .= '?' . http_build_query($req['query']);
        }
        $headers = ['Accept: application/json'];
        if ($jwt !== '' && $isShared) {
            $headers[] = 'Authorization: Bearer ' . $jwt;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HTTPGET        => true,
        ]);
        if ($jwt !== '' && !$isShared) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookieName . '=' . rawurlencode($jwt));
        }
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    // No cortar por $status !== CURLM_OK: libcurl viejo puede devolver
    // CURLM_CALL_MULTI_PERFORM sin que sea un error.
    do {
        $running = null;
        $status  = curl_multi_exec($mh, $running);
        if ($status > CURLM_OK) {
            break;
        }
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0);

    $out = [];
    foreach ($handles as $key => $ch) {
        $respBody  = curl_multi_getcontent($ch);
        $code      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlEr    = curl_error($ch);
        $out[$key] = bffDecodeEnvelope($respBody, $code, $curlEr);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $out;
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

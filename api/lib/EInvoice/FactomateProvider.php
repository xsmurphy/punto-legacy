<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Cliente HTTP de Factomate — motor real de facturación electrónica para
 * Paraguay/SIFEN (el proveedor correcto, no Automate — ver pivot en
 * context/28-facturacion-electronica-plan.md). Mismo estilo que
 * api/lib/Billing/Payments/DlocalGoProvider.php (cURL directo, sin librería
 * HTTP externa, timeouts explícitos).
 *
 * Reglas de la guía real de integración (fuente:
 * /Users/xstian/Dropbox/Automate/Agent/context/09-integracion-factomate.md,
 * escrita desde una implementación en producción) que NO se pueden violar
 * — cada una costó horas de debugging ajeno:
 *
 *   a) El header `phonenumber` va en TODAS las llamadas autenticadas. Sin
 *      él, `/api/sincro/config` revienta con "The given header was not
 *      found". Este cliente lo agrega también en `token()` (el primer
 *      paso, antes de tener bearer) porque la guía dice "todas" sin
 *      excepción explícita y no hay downside en mandarlo de más — pero OJO:
 *      esto es una EXTRAPOLACIÓN nuestra, la guía solo confirma la falla
 *      concreta en sincro/config. Sin verificar contra la API real.
 *
 *   b) NUNCA Content-Type en un GET, ni en un POST sin body. Varios
 *      endpoints — especialmente los que devuelven binario — responden
 *      "500 An error has occurred" si lo reciben: ASP.NET Web API intenta
 *      deserializar un body inexistente y explota.
 *
 * Auth en dos pasos, ninguno de los dos es el bearer final:
 *   POST /Token                    usuario+contraseña        → bearer 15 min, 1 uso
 *   POST /api/account/PhoneLogin   bearer de /Token + phonenumber → bearer 24 h (éste se cachea)
 *
 * El spec de Factomate no tipa las respuestas más allá de lo que documenta
 * la guía, así que el parseo es defensivo (variantes de casing/nesting) y
 * se devuelve el payload crudo (`raw`) para que el caller pueda ajustar sin
 * tener que volver a pegarle a la API para adivinar.
 *
 * Logging (guía §7 — es la única forma práctica de destrabar un rechazo de
 * Factomate, cuyos mensajes de error son engañosos): el payload saliente y
 * el body de error completo van a error_log. La contraseña SIEMPRE se
 * redacta antes de loguear el payload de /Token; el bearer nunca se loguea
 * en ningún punto (no forma parte del body, solo del header Authorization,
 * que este cliente no serializa a los logs).
 */
final class FactomateProvider implements EInvoiceProvider
{
    private const CONNECT_TIMEOUT = 5;
    private const TOTAL_TIMEOUT   = 20;

    /**
     * Host de test hardcodeado como último fallback (el de la guía) si ni
     * siquiera la constante de simple.config.php está definida — no debería
     * pasar en un boot normal, pero evita un warning de PHP en vez de un
     * error claro si alguna vez falta el include.
     */
    private const FALLBACK_TEST_URL = 'https://factomatedev.tech-precision.com';

    private function baseUrl(string $environment): string
    {
        $constant = $environment === 'prod' ? 'FACTOMATE_BASE_URL_PROD' : 'FACTOMATE_BASE_URL_TEST';
        $url = defined($constant) ? (string) constant($constant) : '';

        if ($url === '') {
            if ($environment === 'prod') {
                // Nunca fallback silencioso de prod a test (ni viceversa):
                // mandar facturas de prueba a producción, o al revés, es
                // exactamente el tipo de bug que no se detecta hasta que ya
                // es tarde.
                throw new \RuntimeException(
                    'FACTOMATE_BASE_URL_PROD no está configurada — no se puede operar en entorno de producción.'
                );
            }
            $url = self::FALLBACK_TEST_URL;
        }

        return rtrim($url, '/');
    }

    // ── F0 ───────────────────────────────────────────────────────────────

    public function token(string $environment, string $phone, string $username, string $password): array
    {
        // SIN VERIFICAR contra la API real: la guía documenta el endpoint
        // (`POST /Token`, usuario+contraseña → bearer 15 min) pero no su
        // content-type ni el shape exacto del body. Es un endpoint llamado
        // literalmente "/Token" en un backend ASP.NET Web API — el patrón
        // clásico de ASP.NET Identity/OWIN para ese path es un grant de
        // password OAuth2 con `application/x-www-form-urlencoded` y campos
        // `grant_type=password&username=...&password=...`. Se implementa
        // así porque es la hipótesis más probable dado el nombre del
        // endpoint, pero es una suposición — FLAGEADO en el reporte de esta
        // tarea. Si falla contra la API real, este es el primer sospechoso
        // (probar JSON con Content-Type: application/json como alternativa).
        $raw = $this->requestForm('/Token', [
            'grant_type' => 'password',
            'username'   => $username,
            'password'   => $password,
        ], $phone, $environment);

        $token = $this->extractToken($raw);
        if ($token === null) {
            $keys = implode(', ', array_keys($raw));
            throw new \RuntimeException("Factomate /Token no devolvió un token reconocible (claves recibidas: $keys)");
        }

        return ['token' => $token, 'expiresAt' => $this->extractExpiry($raw), 'raw' => $raw];
    }

    public function phoneLogin(string $environment, string $phone, string $tokenStep1): array
    {
        // Sin body: toda la información de este paso va en headers
        // (Authorization: Bearer <tokenStep1> + phonenumber). La guía no
        // menciona ningún campo de body para PhoneLogin.
        $raw = $this->request('POST', '/api/account/PhoneLogin', null, $tokenStep1, $phone, $environment);

        $token = $this->extractToken($raw);
        if ($token === null) {
            $keys = implode(', ', array_keys($raw));
            throw new \RuntimeException("Factomate PhoneLogin no devolvió un token reconocible (claves recibidas: $keys)");
        }

        return ['token' => $token, 'expiresAt' => $this->extractExpiry($raw), 'raw' => $raw];
    }

    public function userInfo(string $environment, string $phone, string $bearer): array
    {
        return $this->request('GET', '/api/account/GetUserInfo', null, $bearer, $phone, $environment);
    }

    public function sincroConfig(string $environment, string $phone, string $bearer): array
    {
        // POST con body vacío `{}`: la guía no documenta campos de entrada
        // para este endpoint (es un "traeme mi config vigente", no un
        // formulario), pero mandarlo como POST-sin-body se arriesga al
        // mismo 500 de deserialización que un GET con Content-Type — un
        // body `{}` explícito es la forma más segura de cumplir "es un
        // POST" sin violar la regla (b).
        return $this->request('POST', '/api/sincro/config', [], $bearer, $phone, $environment);
    }

    public function paymentMethods(string $environment, string $phone, string $bearer): array
    {
        return $this->request('GET', '/api/PaymentMethod/get', null, $bearer, $phone, $environment);
    }

    // ── F1/F2/F3 — sin implementar en F0 ────────────────────────────────

    public function issue(string $environment, string $phone, string $bearer, array $payload): array
    {
        throw new \LogicException('FactomateProvider::issue — pendiente de F1 (POST /api/electronicDocument/Bulk)');
    }

    public function cancel(string $environment, string $phone, string $bearer, string $cdc, string $reason): array
    {
        throw new \LogicException('FactomateProvider::cancel — pendiente de F1/F2 (POST /api/electronicDocument/event)');
    }

    public function kude(string $environment, string $phone, string $bearer, string $cdc): string
    {
        throw new \LogicException('FactomateProvider::kude — pendiente de F1/F2 (GET /api/electronicDocument/getkude/{cdc})');
    }

    public function clientByRuc(string $environment, string $phone, string $bearer, string $ruc): array
    {
        throw new \LogicException('FactomateProvider::clientByRuc — pendiente de F3 (GET /api/Client/getbyruc/{ruc})');
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Busca el token en las claves más probables de la respuesta. Cubre
     * tanto el shape típico de un endpoint OAuth clásico de ASP.NET
     * (`access_token`) como el de un endpoint custom más simple
     * (`token`/`accessToken`/`jwt`), con y sin envoltorio `data`.
     */
    private function extractToken(array $raw): ?string
    {
        foreach (['access_token', 'token', 'accessToken', 'jwt'] as $key) {
            if (!empty($raw[$key]) && is_string($raw[$key])) {
                return $raw[$key];
            }
        }
        $data = $raw['data'] ?? null;
        if (is_array($data)) {
            foreach (['access_token', 'token', 'accessToken', 'jwt'] as $key) {
                if (!empty($data[$key]) && is_string($data[$key])) {
                    return $data[$key];
                }
            }
        }
        return null;
    }

    private function extractExpiry(array $raw): ?string
    {
        foreach (['expiresAt', 'expires_at', 'expiration'] as $key) {
            if (!empty($raw[$key]) && is_string($raw[$key])) {
                return $raw[$key];
            }
        }
        // El endpoint OAuth clásico de ASP.NET Identity (que asumimos para
        // /Token, ver token()) devuelve `expires_in` en SEGUNDOS relativos,
        // no un timestamp absoluto — se convierte acá a ISO 8601 absoluto
        // para que FactomateSession no tenga que distinguir los dos shapes.
        if (isset($raw['expires_in']) && is_numeric($raw['expires_in'])) {
            return date('c', time() + (int) $raw['expires_in']);
        }
        // Sin campo de expiración reconocible: FactomateSession asume el
        // default de 24 h documentado en la guía cuando esto es null.
        return null;
    }

    /**
     * POST /Token — único endpoint que usa form-urlencoded en vez de JSON
     * (ver comentario de suposición en token()).
     */
    private function requestForm(string $path, array $formFields, string $phone, string $environment): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'phonenumber: ' . $phone,
        ];

        // Payload saliente a error_log (guía §7) — password SIEMPRE redactado.
        $redacted = $formFields;
        if (isset($redacted['password'])) {
            $redacted['password'] = '***';
        }
        error_log('[Factomate] POST ' . $path . ' body=' . json_encode($redacted, JSON_UNESCAPED_UNICODE));

        return $this->exec($path, 'POST', $headers, http_build_query($formFields), $environment);
    }

    /**
     * Request JSON genérico. `$jsonBody === null` → sin Content-Type y sin
     * body (GET, o POST sin campos como phoneLogin). `$jsonBody !== null`
     * → Content-Type + body JSON.
     *
     * El array vacío se serializa a `{}` a mano y NO con JSON_FORCE_OBJECT:
     * ese flag convierte TODA lista en objeto con claves numéricas, lo que
     * destruiría los payloads de F1 (`items[]`, `payments[]` de /Bulk) de
     * una forma que Factomate reportaría como un error de negocio sin
     * relación con la causa real (guía §7).
     */
    private function request(string $method, string $path, ?array $jsonBody, ?string $bearer, ?string $phone, string $environment): array
    {
        $headers = ['Accept: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        if ($phone !== null) {
            $headers[] = 'phonenumber: ' . $phone;
        }

        $body = null;
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = $jsonBody === [] ? '{}' : json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            error_log("[Factomate] $method $path body=$body");
        }

        return $this->exec($path, $method, $headers, $body, $environment);
    }

    private function exec(string $path, string $method, array $headers, ?string $body, string $environment): array
    {
        $ch = curl_init($this->baseUrl($environment) . $path);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException("Factomate $method $path — error de red: $err");
        }

        $json = json_decode((string) $resp, true);
        $json = is_array($json) ? $json : [];

        if ($code < 200 || $code >= 300) {
            // Body de error COMPLETO a error_log (guía §7): es la única
            // forma práctica de destrabar un rechazo, porque los mensajes
            // de error de Factomate son engañosos (un FormatException
            // numérico aparece como un mensaje de negocio que no tiene
            // nada que ver). Esto es la RESPUESTA de Factomate, no nuestro
            // request — nunca contiene la contraseña ni el bearer que
            // mandamos, así que loguearla entera acá es seguro.
            error_log("[Factomate] $method $path failed HTTP $code: $resp");

            // El mensaje que SÍ persiste en einvoice_account.last_error (y
            // es visible en el panel) se sanea: solo whitelist de claves
            // conocidas, truncado a 300 — nunca el body crudo, por si algún
            // endpoint llegara a eco-ear el request.
            $msg = '';
            foreach (['message', 'error', 'detail'] as $key) {
                if (is_string($json[$key] ?? null) && $json[$key] !== '') {
                    $msg = mb_substr($json[$key], 0, 300);
                    break;
                }
            }
            if ($msg === '') {
                $msg = "HTTP $code sin mensaje reconocible";
            }
            throw new \RuntimeException("Factomate $method $path falló (HTTP $code): $msg");
        }

        return $json;
    }
}

<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

require_once __DIR__ . '/EncomSource.php';
require_once __DIR__ . '/EncomMigrationException.php';

/**
 * Cliente HTTP del panel legacy, para el migrador (context/77).
 *
 * ── La password no se persiste (D2) ─────────────────────────────────────
 * `login()` es el ÚNICO punto del sistema que ve la password del cliente, y
 * corre dentro de la request de /admin: se usa para obtener las cookies y se
 * descarta con la request. Lo que queda guardado en `migration_job` son las
 * cookies, que el worker vuelve a montar con `fromCookies()`.
 *
 * Por eso el constructor es privado y solo hay dos formas de construir un
 * cliente: haciendo login (endpoint) o con cookies ya obtenidas (worker). No
 * existe ninguna en la que la password llegue al worker.
 *
 * ── Pacing ──────────────────────────────────────────────────────────────
 * El legacy limita a 60 req/min. El cliente espacía CADA llamada al menos
 * `MIN_INTERVAL_US`, esperando solo lo que falte desde la anterior: si el
 * import gastó 900 ms procesando, duerme los 200 que faltan.
 *
 * ── Reintento ───────────────────────────────────────────────────────────
 * UNA vez, y solo ante fallo TRANSITORIO (red, timeout, 429, 5xx). Un
 * 401/403/302 es la sesión caída: reintentar no la arregla y solo retrasa el
 * error real.
 *
 * ── OJO: qué devuelve REALMENTE el legacy ───────────────────────────────
 * El mapa del brief decía que todo salía por `/API/*.php` con envelope
 * `{ok,data}`. Verificado contra el código legacy, NO es así, y estas tres
 * diferencias son las que gobiernan el diseño de esta clase:
 *
 *   1. **`get_registers.php` NO EXISTE.** Las cajas salen anidadas dentro de
 *      `get_company.php` (`outlets[].registers[]`), que además es la única
 *      fuente que las trae CON su sucursal y sin depender de cuál esté
 *      activa en la sesión. Ver `registers()`.
 *   2. **`get_tags.php` no usa envelope** y devuelve un OBJETO indexado por
 *      id (no una lista), con un tag fijo inyectado por código.
 *   3. **Solo `get_items.php` pagina.** Categorías (LIMIT 500), clientes
 *      (LIMIT 1000), marcas y bancos (LIMIT 100) tienen el tope cableado en
 *      la SQL e IGNORAN `offset`. Pedirles una segunda página devolvería la
 *      misma primera para siempre.
 *   4. **El punto de expedición YA viene como `EEE-PPP`** en
 *      `registerInvoicePrefix` — el propio legacy hace
 *      `explode("-", registerInvoicePrefix)` para mandarle
 *      establecimiento/puntoExpedicion a la SET
 *      (`API/send_fe_invoices.php`). El campo `sufix` es OTRA cosa y no
 *      entra en el punto de expedición.
 */
final class EncomClient implements EncomSource
{
    private const CONNECT_TIMEOUT = 10;
    private const TOTAL_TIMEOUT   = 60;

    /** 60 req/min = 1 req/s, con margen: el legacy cuenta por ventana fija. */
    private const MIN_INTERVAL_US = 1_100_000;

    /** Espera antes del reintento de un fallo transitorio. */
    private const RETRY_SLEEP_US = 3_000_000;

    /**
     * Página de `get_items.php`. 500 y no 1000 a propósito: el legacy IGNORA
     * el `limit` cuando es `>= 1000` y lo baja a 1000 por su cuenta, así que
     * pedir 1000 deja la última página indistinguible de una página llena.
     */
    private const PAGE_SIZE = 500;

    /** Corta el bucle si el legacy ignora el offset y repite la página. */
    private const MAX_PAGES = 200;

    private float $lastCallAt = 0.0;

    /** Respuesta memoizada de `get_company.php` (sucursales + cajas). */
    private ?array $companyCache = null;

    /** @var array<string,string> */
    private array $cookies;

    private function __construct(
        private readonly string $baseUrl,
        array $cookies,
    ) {
        $this->cookies = $cookies;
    }

    /**
     * Autentica contra el legacy y devuelve un cliente con las cookies vivas.
     *
     * El legacy contesta 200 con el texto plano "true" en éxito — no un JSON
     * ni un 302. Por eso el éxito se decide por el cuerpo Y por haber
     * recibido `_jwt_panel`: un 200 con "false" es un login fallido y de otra
     * forma pasaría por bueno.
     */
    public static function login(string $baseUrl, string $phone, string $iso, string $password): self
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            throw new EncomMigrationException(
                'Falta configurar ENCOM_MIGRATION_URL: sin la dirección del panel legacy no se puede migrar.',
                503
            );
        }

        $client = new self($baseUrl, []);

        $res = $client->raw(
            'POST',
            '/login?login=true',
            http_build_query([
                'phone'    => $phone,
                'iso'      => $iso !== '' ? $iso : 'PY',
                'password' => $password,
            ]),
            'application/x-www-form-urlencoded'
        );

        if ($res['error'] !== '') {
            throw new EncomMigrationException('No se pudo contactar al panel legacy: ' . $res['error'], 502);
        }

        $body = strtolower(trim((string) $res['body']));

        if ($res['status'] !== 200 || $body !== 'true' || !isset($client->cookies['_jwt_panel'])) {
            throw new EncomMigrationException(
                'El panel legacy rechazó las credenciales. Verificá el teléfono (con código de país) y la contraseña.',
                401
            );
        }

        return $client;
    }

    /**
     * Cliente para un job ya creado: las cookies salen de `migration_job`.
     *
     * @param array<string,string> $cookies
     */
    public static function fromCookies(string $baseUrl, array $cookies): self
    {
        $clean = [];
        foreach ($cookies as $k => $v) {
            if (is_string($k) && is_string($v) && $k !== '') {
                $clean[$k] = $v;
            }
        }
        if (!isset($clean['_jwt_panel'])) {
            throw new EncomMigrationException(
                'El job no tiene la sesión del legacy (caducó o ya se consumió). Creá el job de nuevo.',
                422
            );
        }
        return new self(rtrim(trim($baseUrl), '/'), $clean);
    }

    /** Cookies vivas. Solo las lee el endpoint que crea el job. */
    public function cookies(): array
    {
        return $this->cookies;
    }

    // ═══════════════════════════════════════════════════════════════════
    // EncomSource
    // ═══════════════════════════════════════════════════════════════════

    public function settings(): array
    {
        $data = $this->json('POST', '/API/get_settings.php', []);
        if (!is_array($data)) {
            return [];
        }
        // `get_settings` devuelve UN objeto. Sin fila `company` el legacy
        // devuelve `[]`, que en PHP es indistinguible de un objeto vacío.
        return array_is_list($data) ? (is_array($data[0] ?? null) ? $data[0] : []) : $data;
    }

    /**
     * Sucursales — salen de `get_company.php`, NO de `bff/outlets.php`.
     *
     * `bff/outlets.php` es un proxy a la "shared API" (otro servicio, fuera
     * del snapshot legacy): su shape no es verificable y encima depende de un
     * despliegue distinto. `get_company.php` está en el propio legacy, tiene
     * shape leído del código, y trae las sucursales CON SUS CAJAS adentro.
     */
    public function outlets(): array
    {
        return $this->rows($this->company()['outlets'] ?? []);
    }

    /**
     * Cajas, con la sucursal a la que pertenecen.
     *
     * Sale del mismo `get_company.php` que las sucursales — que las anida en
     * `outlets[].registers[]` y recorre TODAS las sucursales activas, no solo
     * la de la sesión.
     *
     * Eso es lo que descarta la otra fuente posible, `a_registers.php?list=true`:
     * devuelve HTML (habría que parsear una tabla para leer un TIMBRADO) y
     * corre `SELECT ... <roc>`, o sea acotado a la sucursal ACTIVA de la
     * sesión, sin forma de cambiarla. Habría migrado las cajas de una sola
     * sucursal y sin saber de cuál.
     *
     * Se aplana a lista y cada fila se queda con su `outletLegacyId`: el
     * importador NO puede adivinar la sucursal de una caja (memoria
     * "prohibido inventar la dimensión faltante") y acá no hace falta.
     */
    public function registers(): array
    {
        $out = [];
        foreach ($this->rows($this->company()['outlets'] ?? []) as $outlet) {
            $outletId = (string) ($outlet['ID'] ?? '');
            foreach ($this->rows($outlet['registers'] ?? []) as $register) {
                $register['outletLegacyId'] = $outletId;
                $out[] = $register;
            }
        }
        return $out;
    }

    /**
     * `get_company.php`, memoizado: sucursales y cajas salen de la MISMA
     * respuesta y pedirla dos veces gastaría dos slots del límite de 60/min
     * para recibir lo mismo.
     */
    private function company(): array
    {
        if ($this->companyCache === null) {
            $data = $this->json('POST', '/API/get_company.php', []);
            $this->companyCache = is_array($data) ? $data : [];
        }
        return $this->companyCache;
    }

    public function items(): array
    {
        return $this->paged('/API/get_items.php', ['archived' => 0, 'children' => 'all']);
    }

    /** Sin paginación: el legacy cablea `LIMIT 500`. */
    public function categories(): array
    {
        return $this->rows($this->json('POST', '/API/get_categories.php', []));
    }

    /** Sin paginación: el legacy no pone LIMIT explícito y no acepta offset. */
    public function brands(): array
    {
        return $this->rows($this->json('POST', '/API/get_brands.php', []));
    }

    /**
     * Etiquetas — sin envelope y como OBJETO `{id: {name}}`, no lista.
     *
     * El legacy inyecta además un tag fijo por código (id 166227, "INTERNO")
     * que no sale de la tabla. Se filtra: migrarlo crearía en Punto una
     * etiqueta que el cliente nunca creó.
     */
    public function tags(): array
    {
        $data = $this->json('GET', '/API/get_tags.php', [], false);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $id => $row) {
            $id = (string) $id;
            if ($id === self::HARDCODED_TAG_ID) {
                continue;
            }
            $name = is_array($row) ? (string) ($row['name'] ?? '') : (is_string($row) ? $row : '');
            if ($name === '') {
                continue;
            }
            $out[] = ['ID' => $id, 'name' => $name];
        }
        return $out;
    }

    /** El tag que `getAllTags()` agrega por código, no por dato del cliente. */
    private const HARDCODED_TAG_ID = '166227';

    /** Sin paginación: el legacy cablea `type = 1` y `LIMIT 1000`. */
    public function customers(): array
    {
        return $this->rows($this->json('POST', '/API/get_customers.php', []));
    }

    /** Sin paginación: el legacy cablea `LIMIT 100`. */
    public function banks(): array
    {
        return $this->rows($this->json('POST', '/API/get_banks.php', []));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Transporte
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Recorre un endpoint paginado hasta que devuelve menos de una página.
     *
     * El corte por `MAX_PAGES` no es decorativo: si el legacy ignora el
     * `offset` cada página devuelve lo mismo y el bucle no termina nunca —
     * con pacing de 1 req/s, un worker colgado por horas.
     */
    private function paged(string $path, array $baseParams): array
    {
        $out    = [];
        $offset = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $rows = $this->rows($this->json('POST', $path, $baseParams + [
                'offset' => $offset,
                'limit'  => self::PAGE_SIZE,
            ]));

            $out = array_merge($out, $rows);

            if (count($rows) < self::PAGE_SIZE) {
                return $out;
            }
            $offset += self::PAGE_SIZE;
        }

        throw new EncomMigrationException(
            'El legacy devolvió más de ' . (self::MAX_PAGES * self::PAGE_SIZE) . ' filas en ' . $path .
            ': se corta por seguridad (posible paginación ignorada).',
            502
        );
    }

    /** Se queda solo con las filas que son arrays. */
    private function rows(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $rows = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Request JSON con pacing y un reintento transitorio.
     *
     * @param bool $envelope false para los endpoints legacy que devuelven el
     *                       JSON crudo, sin `{ok,data}` (get_tags.php).
     * @return mixed El contenido de `data`, o el JSON entero si no hay envelope.
     */
    private function json(string $method, string $path, array $params, bool $envelope = true): mixed
    {
        $res = $this->attempt($method, $path, $params);

        $json = json_decode((string) $res, true);
        if (!is_array($json)) {
            throw new EncomMigrationException('El legacy devolvió una respuesta que no es JSON en ' . $path . '.', 502);
        }

        if (!$envelope) {
            return $json;
        }

        if (array_key_exists('ok', $json) && !$json['ok']) {
            $err = $json['error'] ?? null;
            $msg = is_array($err) ? (string) ($err['message'] ?? 'sin detalle') : (is_string($err) ? $err : 'sin detalle');
            throw new EncomMigrationException('El legacy rechazó ' . $path . ': ' . $msg, 502);
        }

        return array_key_exists('data', $json) ? $json['data'] : $json;
    }

    /**
     * Ejecuta la request respetando el pacing, con UN reintento transitorio.
     * Devuelve el cuerpo; lanza con un mensaje accionable si no se pudo.
     */
    private function attempt(string $method, string $path, array $params): string
    {
        for ($try = 0; ; $try++) {
            $this->pace();

            $res = $method === 'GET'
                ? $this->raw('GET', $path . ($params !== [] ? (str_contains($path, '?') ? '&' : '?') . http_build_query($params) : ''), null, null)
                : $this->raw('POST', $path, http_build_query($params), 'application/x-www-form-urlencoded');

            $transient = $res['error'] !== '' || $res['status'] === 429 || $res['status'] >= 500;

            if ($transient && $try === 0) {
                usleep(self::RETRY_SLEEP_US);
                continue;
            }

            if ($res['error'] !== '') {
                throw new EncomMigrationException('Error de red contra el legacy en ' . $path . ': ' . $res['error'], 502);
            }

            if ($res['status'] === 401 || $res['status'] === 403) {
                throw new EncomMigrationException(
                    'La sesión del panel legacy caducó (dura 24 h). Creá el job de nuevo para volver a autenticarte.',
                    401
                );
            }

            if ($res['status'] < 200 || $res['status'] >= 300) {
                throw new EncomMigrationException('El legacy respondió ' . $res['status'] . ' en ' . $path . '.', 502);
            }

            return (string) $res['body'];
        }
    }

    /** Espera lo que falte para respetar el límite de 60 req/min. */
    private function pace(): void
    {
        if ($this->lastCallAt > 0.0) {
            $elapsed = (int) ((microtime(true) - $this->lastCallAt) * 1_000_000);
            if ($elapsed < self::MIN_INTERVAL_US) {
                usleep(self::MIN_INTERVAL_US - $elapsed);
            }
        }
        $this->lastCallAt = microtime(true);
    }

    /**
     * curl crudo. Acumula las cookies que el legacy devuelve y reenvía las
     * que ya tiene. No se usa `CURLOPT_COOKIEJAR`: eso escribiría la sesión
     * viva del cliente a un archivo en el disco del servidor.
     *
     * @return array{status:int,body:?string,error:string}
     */
    private function raw(string $method, string $path, ?string $body, ?string $contentType): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            return ['status' => 0, 'body' => null, 'error' => 'no se pudo inicializar curl'];
        }

        $headers = ['Accept: application/json, text/html'];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            // Sin seguir redirects: el legacy manda 302 al login cuando la
            // sesión se cayó. Siguiéndolo se recibiría un 200 con el HTML del
            // login, que el parser leería como "respuesta que no es JSON" —
            // un error que no dice nada. Sin seguirlo, el 302 se ve como lo
            // que es y se traduce a "la sesión caducó".
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header): int {
                $this->captureCookie($header);
                return strlen($header);
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $resBody = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_error($ch);
        curl_close($ch);

        if ($status === 301 || $status === 302) {
            return ['status' => 401, 'body' => null, 'error' => ''];
        }

        return [
            'status' => $status,
            'body'   => is_string($resBody) ? $resBody : null,
            'error'  => $err,
        ];
    }

    /** Guarda las cookies de `Set-Cookie` (solo nombre=valor, sin atributos). */
    private function captureCookie(string $header): void
    {
        if (stripos($header, 'Set-Cookie:') !== 0) {
            return;
        }
        $value = trim(substr($header, strlen('Set-Cookie:')));
        $pair  = explode(';', $value, 2)[0] ?? '';
        $eq    = strpos($pair, '=');
        if ($eq === false || $eq === 0) {
            return;
        }
        $name = trim(substr($pair, 0, $eq));
        $val  = trim(substr($pair, $eq + 1));
        if ($name !== '' && $val !== '' && $val !== 'deleted') {
            $this->cookies[$name] = $val;
        }
    }
}

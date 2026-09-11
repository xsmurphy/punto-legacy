<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

require_once __DIR__ . '/EncomSource.php';
require_once __DIR__ . '/EncomParse.php';
require_once __DIR__ . '/EncomMigrationException.php';
require_once __DIR__ . '/EncomExportTruncatedException.php';

/**
 * Cliente HTTP del sistema legacy, para el migrador (context/77).
 *
 * ── Contra qué habla: el bootstrap del POS, no las pantallas ─────────────
 * El export sale de `POST /fetchs?load=<dominio>&gtoken=`, el endpoint que el
 * POS legacy usa para bajarse el catálogo entero al arrancar. Devuelve JSON
 * limpio y tipado.
 *
 * Hasta 2026-09-11 esto era scraping: se pedían `a_items?action=showTable`,
 * `a_contacts?action=download` (CSV), `a_registers?list=true` (HTML) y se
 * parseaban tablas y forms. Ese camino **se eliminó entero**, no quedó como
 * fallback: convivir con dos fuentes para el mismo dominio deja la pregunta
 * "¿de cuál salió este dato?" sin respuesta, y la que sobrevive es
 * estrictamente mejor. `/fetchs` no solo es más robusto (no depende del orden
 * de las columnas de una tabla ni de qué atributo lleva el valor crudo): trae
 * cosas que las pantallas NO exponían y que por eso la F1 declaraba
 * imposibles —la composición de combos y recetas, los usuarios con su PIN y su
 * rol, los medios de pago, el último correlativo por tipo de documento—.
 *
 * **Quedan DOS excepciones, las dos por la misma razón: `/fetchs` no lo trae.**
 * Por eso la sesión del panel y el transporte `get()` siguen vivos, y por eso
 * `EncomParse` no se borró del todo:
 *
 *   1. **El histórico (F2)** — `/fetchs` no lo expone por ningún `load`: es el
 *      bootstrap de una caja, no un reporte. Son DOS LOGS independientes,
 *      cada uno con su pantalla: `salesHistory()` (transacciones) e
 *      `itemsSoldHistory()` (ítems vendidos).
 *   2. **El COSTO de los artículos** — el POS no lo necesita para vender, así
 *      que el bootstrap no lo manda. `itemCosts()`.
 *
 * La (2) NO es "volver al scraping". El catálogo entero —nombre, precio, IVA,
 * categoría, marca, SKU, código de barras, composición— sigue saliendo de
 * `/fetchs`, y del panel se lee UN campo que esa fuente no tiene. No hay dos
 * fuentes para el mismo dato, que era lo que se quería evitar: hay una fuente
 * por dato. Y es un ENRIQUECIMIENTO, no un insumo: si el panel falla, cambia
 * de columnas o contesta vacío, el catálogo se importa igual y sin costos —
 * nunca se cae por esto.
 *
 * ── La password no se persiste (D2) ─────────────────────────────────────
 * `login()` es el único punto que la ve, y corre dentro de la request de
 * /admin. Lo que se guarda en `migration_job` son las cookies y el par
 * (companyId, outletId) del legacy, que el worker remonta con
 * `fromCookies()`. Por eso el constructor es privado: no hay forma de
 * construir un cliente en la que la password llegue al worker.
 *
 * ── DOS hosts, no uno (incidente 2026-09-11) ────────────────────────────
 * El sistema legacy está partido en dos aplicaciones con dominios distintos:
 *
 *   · el **PANEL** (`panel.encom.com.py`, `ENCOM_MIGRATION_URL`) — el login y
 *     las pantallas de reporte de las que sale el histórico y el costo;
 *   · el **POS** (`app.encom.com.py`) — donde vive `/fetchs`.
 *
 * Hasta este arreglo había UNA sola base y todos los `/fetchs` salían contra
 * el panel, que contesta **404**: un job real terminó con cero mapeos y 512
 * errores derivados ("la sucursal X no está migrada") que enterraban la causa.
 * Por eso son dos propiedades con nombre —`panelUrl` y `posUrl`— y no una
 * `baseUrl` ambigua: cada request dice contra cuál de las dos va.
 *
 * El host del POS **no se configura aparte**: se DERIVA de la misma URL de la
 * que ya salía el alcance (`?i=<base64>` del redirect de `/bff/pos-redirect.php`,
 * que es absoluto al POS). Una env var nueva sería un segundo lugar donde
 * equivocarse, y el dato ya estaba en la respuesta.
 *
 * ── Pacing ──────────────────────────────────────────────────────────────
 * 60 req/min del otro lado. Se espacia CADA llamada, esperando solo lo que
 * falte desde la anterior. Con `/fetchs` el export pasó de decenas de
 * requests (una por sucursal, otra por caja) a SIETE en total, así que el
 * pacing dejó de ser el factor que domina la duración del job.
 */
class EncomClient implements EncomSource
{
    private const CONNECT_TIMEOUT = 10;

    /**
     * `/fetchs?load=items` de un catálogo grande es una sola respuesta con
     * TODO adentro: tarda más que cualquier pantalla del panel. El timeout
     * viejo (60 s) estaba dimensionado para tablas paginadas.
     */
    private const TOTAL_TIMEOUT = 120;

    /** 60 req/min = 1 req/s, con margen (el legacy cuenta por ventana fija). */
    private const MIN_INTERVAL_US = 1_100_000;

    private const RETRY_SLEEP_US = 3_000_000;

    /**
     * Filas que el legacy contesta SIN parámetros de paginación.
     *
     * No es una elección nuestra: es el techo del otro lado, medido en la
     * primera corrida real (2026-09-11). Tres meses seguidos de ventas
     * volvieron con exactamente 100 filas —y las compras de dos meses con 93 y
     * 14, o sea por debajo—, que es la firma de un tope y no del volumen. Con
     * 6.927 ventas en el rango, ese tope se comió el 96% del histórico y el
     * job lo reportó como `imported 300, failed 0`.
     *
     * Acá se usa como FIRMA, no como tamaño: una respuesta de exactamente
     * estas filas es sospechosa y dispara la paginación.
     */
    private const CAP_SIZE = 100;

    /**
     * Filas que se piden por página cuando sí se pagina.
     *
     * 1000 está VERIFICADO contra el sistema vivo (2026-09-11), y no se sube
     * sin motivo: es el valor probado. Con este tamaño el listado de 6.927
     * ventas son 7 requests en vez de 70.
     */
    private const PAGE_SIZE = 1000;

    /**
     * Tamaño de la sonda que demuestra que el parámetro de tamaño se RESPETA.
     *
     * Un legacy que ignora los parámetros de paginación contesta su página
     * completa igual, así que pedir 5 y recibir 100 es la prueba directa de
     * que la convención no está soportada — y cuesta una sola request.
     */
    private const PROBE_SIZE = 5;

    /** Techo de páginas por listado: un rango que no corta es un error. */
    private const MAX_PAGES = 200;

    private float $lastCallAt = 0.0;

    /**
     * Código HTTP de la última respuesta, para las SONDAS de diagnóstico.
     *
     * `send()` traduce todo lo que no sea 2xx en una excepción con un mensaje
     * para el operador, que es lo correcto para el flujo normal y lo que deja
     * sin evidencia a quien tiene que averiguar POR QUÉ un cuerpo vino mal
     * formado con status 200 — el caso de las 300 ventas que volvieron sin
     * líneas. Esto lo conserva sin cambiar ese contrato.
     */
    private int $lastStatus = 0;

    /** @var array<string,string> */
    private array $cookies;

    /** Último header `Location` recibido. Lo lee `resolveScope()`. */
    private ?string $lastLocation = null;

    /**
     * Origen (esquema + host) de la app del POS legacy, donde vive `/fetchs`.
     *
     * NO es el panel. Se deriva de la URL absoluta que lleva el `?i=` del
     * alcance — la misma de la que ya salían companyId y outletId — y se
     * persiste en el job junto a ellos, porque tiene exactamente la misma
     * procedencia y la misma vida útil.
     */
    private string $posUrl = '';

    /**
     * Identificadores del comercio EN EL LEGACY (hashids cortos tipo `QE22`,
     * no UUID). Son el cuerpo de todo `/fetchs`.
     */
    private string $companyHash = '';
    private string $outletHash  = '';

    /** Memo por `load`: `categories()`/`brands()` derivan del mismo payload. */
    private array $cache = [];

    /**
     * `protected`, no público: las dos formas legítimas de obtener un cliente
     * son `login()` (endpoint, ve la password) y `fromCookies()` (worker, no
     * la ve). El arnés lo usa desde su subclase de fixtures.
     */
    protected function __construct(
        private readonly string $panelUrl,
        array $cookies,
        string $companyHash = '',
        string $outletHash = '',
        string $posUrl = '',
    ) {
        $this->cookies     = $cookies;
        $this->companyHash = $companyHash;
        $this->outletHash  = $outletHash;
        // Se guarda como ORIGEN: si viniera con path o query (la URL del POS
        // trae el `?i=`), lo que sirve de base para `/fetchs` es solo
        // esquema + host.
        $this->posUrl      = self::originOf($posUrl);
    }

    /**
     * Autentica, resuelve el alcance y devuelve un cliente listo para exportar.
     *
     * El legacy contesta 200 con el texto plano "true" en éxito — no un JSON
     * ni un 302. El éxito se decide por el cuerpo Y por haber recibido la
     * cookie de sesión: un 200 con "false" es un login fallido y de otra forma
     * pasaría por bueno.
     *
     * La cookie que importa es **PHPSESSID**. `_jwt_panel` puede venir o no
     * según la versión desplegada y no se exige — exigirla rompía el login
     * contra el deploy viejo, que es justamente el que hay que migrar.
     *
     * ── Un solo campo: `email` ──────────────────────────────────────────
     * Verificado contra el sistema VIVO (2026-09-11): el form del login
     * deployado tiene `name="email"` y `name="password"`, y NINGÚN
     * `phone`/`iso` — eso es de una versión de código más nueva que la que
     * corre. En ese campo el cliente tipea su email O su teléfono, y el
     * backend legacy resuelve cuál es. Por eso el identificador viaja TAL CUAL
     * lo tipeó el operador: normalizarlo a E.164 le cambiaría el valor a quien
     * entra con email y también a quien entra con el teléfono como lo tiene
     * guardado el legacy.
     */
    public static function login(string $baseUrl, string $identifier, string $password): self
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
            $baseUrl . '/login?login=true',
            static::loginBody($identifier, $password),
            'application/x-www-form-urlencoded',
            true
        );

        if ($res['error'] !== '') {
            throw new EncomMigrationException('No se pudo contactar al panel legacy: ' . $res['error'], 502);
        }

        $body = strtolower(trim((string) $res['body']));

        if ($body !== 'true' || !isset($client->cookies['PHPSESSID'])) {
            throw new EncomMigrationException(
                'El panel legacy rechazó las credenciales. Verificá el usuario (el email o el celular con el '
                . 'que el cliente entra al panel legacy) y la contraseña.',
                401
            );
        }

        // El alcance se resuelve ACÁ, con la sesión recién abierta y el
        // operador mirando: si el legacy cambió y no se puede obtener, el job
        // NO se crea y el mensaje sale en la pantalla del alta. Descubrirlo
        // dentro del worker significaría un job fallido media hora después.
        $client->resolveScope();

        return $client;
    }

    /**
     * Cuerpo del POST de login.
     *
     * Está separado —y `protected`— por una sola razón: los `name` del form
     * legacy son justamente lo que se puede equivocar (mandábamos
     * `phone`/`iso`, que esa versión del deploy no tiene, y el login fallaba
     * sin explicación posible desde este lado). Acá el arnés los verifica sin
     * red.
     */
    protected static function loginBody(string $identifier, string $password): string
    {
        return http_build_query([
            'email'    => $identifier,
            'password' => $password,
        ]);
    }

    /**
     * @param array<string,string> $cookies
     * @param array{companyId?:string,outletId?:string,posUrl?:string}|null $scope
     */
    public static function fromCookies(string $baseUrl, array $cookies, ?array $scope = null): self
    {
        $clean = [];
        foreach ($cookies as $k => $v) {
            if (is_string($k) && is_string($v) && $k !== '') {
                $clean[$k] = $v;
            }
        }
        if (!isset($clean['PHPSESSID'])) {
            throw new EncomMigrationException(
                'El job no tiene la sesión del legacy (caducó o ya se consumió). Creá el job de nuevo.',
                422
            );
        }

        $client = new self(
            rtrim(trim($baseUrl), '/'),
            $clean,
            trim((string) ($scope['companyId'] ?? '')),
            trim((string) ($scope['outletId'] ?? '')),
            trim((string) ($scope['posUrl'] ?? '')),
        );

        // Un job creado antes de que el alcance se guardara —o al que le
        // faltara una de sus partes— lo resuelve de nuevo con la misma sesión,
        // en vez de fallar. Es la misma llamada que hace `login()`.
        //
        // `posUrl` cuenta como parte faltante: los jobs creados antes del
        // arreglo del host tienen scope con solo dos campos, y sin el origen
        // del POS todo `/fetchs` iría contra el panel (404) — que es
        // exactamente el incidente que esto cierra.
        if ($client->companyHash === '' || $client->outletHash === '' || $client->posUrl === '') {
            $client->resolveScope();
        }

        return $client;
    }

    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * El alcance del legacy. Se persiste en el job junto a las cookies: sin él,
     * `/fetchs` no sabe de qué comercio hablar —ni contra qué host preguntar—.
     *
     * `posUrl` viaja acá y no en una env var propia porque sale de la MISMA
     * respuesta que el par (companyId, outletId) y caduca con la misma sesión.
     *
     * @return array{companyId:string,outletId:string,posUrl:string}
     */
    public function scope(): array
    {
        return [
            'companyId' => $this->companyHash,
            'outletId'  => $this->outletHash,
            'posUrl'    => $this->posUrl,
        ];
    }

    /**
     * Resuelve el comercio y la sucursal del legacy SIN pedírselos al operador.
     *
     * El cliente no conoce esos identificadores —son hashids internos que
     * nunca ve— así que el migrador los deduce de la sesión recién abierta:
     *
     *   1. `GET /bff/pos-redirect.php` **sin seguir el redirect**. El legacy
     *      contesta 302 hacia la app del POS con `?i=<base64>`, y ese base64
     *      es literalmente `"companyId,outletId"`.
     *   2. Si ese endpoint no existe en el deploy (el vivo es más viejo que el
     *      snapshot), se cae al HOME del panel y se busca el mismo `?i=` en el
     *      href del botón "Caja", que es el que abre la caja.
     *
     * Si ninguna de las dos vías da un par completo, LANZA. Es deliberado:
     * seguir con un companyId vacío haría que `/fetchs` devolviera el catálogo
     * de nadie —o, peor, uno que no es del cliente— y el job importaría eso
     * sin una sola señal de que algo salió mal.
     *
     * `protected` por lo mismo que `fetch()`: es la otra pieza que no se puede
     * probar contra el legacy real y que, si se rompe, importa el comercio
     * equivocado. El arnés la ejercita con las dos vías y con el caso en que
     * ninguna responde.
     */
    protected function resolveScope(): void
    {
        $sinOrigen = null;

        foreach (['/bff/pos-redirect.php', '/'] as $path) {
            $scope = $this->scopeFrom($path);
            if ($scope === null) {
                continue;
            }

            [$companyHash, $outletHash, $origin] = $scope;

            // El par SIN el host del POS no alcanza: `/fetchs` no vive en el
            // panel. Se recuerda y se prueba la vía siguiente, porque una de
            // las dos puede traer la URL absoluta aunque la otra no.
            if ($origin === '') {
                $sinOrigen ??= [$companyHash, $outletHash];
                continue;
            }

            $this->companyHash = $companyHash;
            $this->outletHash  = $outletHash;
            $this->posUrl      = $origin;
            return;
        }

        // ── Fail-closed, y ACÁ, no seis 404 más tarde ────────────────────
        // Este es el caso del incidente: el alcance se resolvía bien y el
        // host del POS no se miraba, así que el job seguía adelante pidiendo
        // `/fetchs` contra el panel. Seis 404 silenciosos después, cero
        // mapeos, y 512 errores derivados que enterraban la causa.
        if ($sinOrigen !== null) {
            throw new EncomMigrationException(
                'Se obtuvo el identificador del comercio en el sistema legacy, pero NO la dirección de la '
                . 'app del POS, que es donde vive /fetchs (la URL que traía el identificador no es '
                . 'absoluta). El panel y el POS son dos hosts distintos y el panel responde 404 a /fetchs: '
                . 'no se migra nada para no importar un catálogo vacío.',
                502
            );
        }

        throw new EncomMigrationException(
            'No se pudo determinar el comercio y la sucursal del cliente en el sistema legacy: ni '
            . '/bff/pos-redirect.php ni el botón "Caja" del panel devolvieron el identificador esperado. '
            . 'El panel legacy cambió: no se migra nada para no importar datos de otro comercio.',
            502
        );
    }

    /**
     * Busca el `?i=<base64>` en el redirect de `$path` y, si no está ahí, en su
     * cuerpo.
     *
     * Devuelve TRES cosas, no dos: el par del alcance y el ORIGEN de la URL que
     * lo llevaba, que es la dirección de la app del POS. El origen sale gratis
     * de la misma respuesta —el `Location` del 302 es
     * `https://app.encom.com.py/?i=<base64>`, y el href del botón "Caja" del
     * home también es absoluto al POS—, así que pedirlo aparte (una env var
     * más) sería inventar un segundo lugar donde equivocarse.
     *
     * @return array{0:string,1:string,2:string}|null
     */
    private function scopeFrom(string $path): ?array
    {
        $this->lastLocation = null;

        // `allowRedirect`: el 302 es la respuesta ESPERADA de pos-redirect, no
        // una sesión caída.
        $body = '';
        try {
            $body = $this->get($path, [], true);
        } catch (\Throwable $e) {
            // Un 404 en `/bff/pos-redirect.php` es el caso normal del deploy
            // viejo: se prueba la vía siguiente, no se aborta.
            return null;
        }

        foreach ([(string) $this->lastLocation(), $body] as $haystack) {
            if (trim($haystack) === '') {
                continue;
            }
            $scope = self::decodeScope($haystack);
            if ($scope !== null) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * Extrae y decodifica el parámetro `i` de una URL o de un HTML con enlaces,
     * junto con el ORIGEN de la URL que lo contenía.
     *
     * @return array{0:string,1:string,2:string}|null
     */
    private static function decodeScope(string $haystack): ?array
    {
        // Todas las apariciones, no la primera: el home del panel tiene varios
        // enlaces y solo el de la caja lleva un `i` que decodifica a un par.
        // El prefijo `https?://host` se captura en el mismo match para saber a
        // qué app apuntaba ESE enlace; es opcional porque un deploy podría
        // servir el enlace relativo, y en ese caso el par sirve pero el origen
        // no se puede derivar (lo resuelve `resolveScope()`, fallando).
        $re = '~(https?://[^\s"\'<>]*?)?[?&]i=([A-Za-z0-9+/=%_-]+)~i';
        if (!preg_match_all($re, $haystack, $matches, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($matches as $m) {
            $decoded = base64_decode(urldecode((string) ($m[2] ?? '')), true);
            if ($decoded === false || !str_contains($decoded, ',')) {
                continue;
            }
            [$companyId, $outletId] = array_map('trim', explode(',', $decoded, 2));
            // Los dos tienen que venir: con la sucursal vacía `/fetchs`
            // respondería el bootstrap de otra y las cajas saldrían mal.
            if ($companyId !== '' && $outletId !== '') {
                return [$companyId, $outletId, self::originOf((string) ($m[1] ?? ''))];
            }
        }

        return null;
    }

    /**
     * Esquema + host (+ puerto) de una URL absoluta. '' si no lo es.
     *
     * Se descarta el path a propósito: la URL del POS viene con su `?i=` y lo
     * único que sirve como base de `/fetchs` es el origen.
     */
    private static function originOf(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return '';
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    // ═══════════════════════════════════════════════════════════════════
    // EncomSource — un `load` por dominio
    // ═══════════════════════════════════════════════════════════════════

    /** Configuración del comercio. `load=settings` devuelve UN objeto en una lista. */
    public function settings(): array
    {
        $rows = $this->fetch('settings');
        $s    = is_array($rows[0] ?? null) ? $rows[0] : $rows;

        return [
            'name'         => self::str($s['companyName'] ?? ''),
            'billingName'  => self::str($s['companyBillingName'] ?? ''),
            'tin'          => self::str($s['companyTIN'] ?? ''),
            'address'      => self::str($s['companyAddress'] ?? ''),
            'phone'        => self::str($s['companyPhone'] ?? ''),
            'email'        => self::str($s['companyEmail'] ?? ''),
            'website'      => self::str($s['companyWebsite'] ?? ''),
            'currency'     => self::str($s['currency'] ?? ''),
            'currencyISO'  => self::str($s['currencyISO'] ?? ''),
            'country'      => self::str($s['country'] ?? ''),
            'countryISO'   => self::str($s['countryISO'] ?? ''),
            'taxName'      => self::str($s['taxName'] ?? ''),
            'decimal'      => $s['decimal'] ?? null,
            'thousand'     => self::str($s['thousandSeparator'] ?? ''),
        ];
    }

    /** Sucursales. */
    public function outlets(): array
    {
        $out = [];
        foreach ($this->fetch('outlets') as $o) {
            if (!is_array($o)) {
                continue;
            }
            $row = [
                'ID'          => self::str($o['outletId'] ?? ''),
                'name'        => self::str($o['name'] ?? ''),
                'address'     => self::str($o['outletAddress'] ?? ''),
                'billingName' => self::str($o['outletRazon'] ?? ''),
                'tin'         => self::str($o['outletRuc'] ?? ''),
                'phone'       => self::str($o['outletPhone'] ?? ''),
                'email'       => self::str($o['outletEmail'] ?? ''),
            ];

            // `latLng` viaja como "lat,lng" en un solo campo.
            $latLng = self::str($o['outletLatLng'] ?? '');
            if (str_contains($latLng, ',')) {
                [$lat, $lng] = array_map('trim', explode(',', $latLng, 2));
                $row['lat'] = $lat;
                $row['lng'] = $lng;
            }

            $out[] = $row;
        }
        return $out;
    }

    /**
     * Cajas con su timbrado y su ÚLTIMO correlativo por tipo de documento.
     *
     * `load=registers` es el único que NO devuelve una lista: contesta
     * `{registers: [...], docsNum: [...]}`. `docsNum` es la mitad que importa
     * —el último número EMITIDO de cada documento— y viene indexada por
     * `registerId`, así que se junta acá y el importador recibe una sola fila
     * por caja con todo adentro.
     *
     * Esto reemplaza al recorrido por sucursal con el switch `?o=` más un
     * `?action=edit` por caja (decenas de requests, y el número salía de un
     * campo de texto de un form). Ahora es UNA request y el correlativo es un
     * entero que el propio POS usa para numerar.
     */
    public function registers(): array
    {
        $payload = $this->fetch('registers');

        $registers = is_array($payload['registers'] ?? null) ? $payload['registers'] : [];
        $docsNum   = [];
        foreach ((is_array($payload['docsNum'] ?? null) ? $payload['docsNum'] : []) as $d) {
            if (is_array($d) && self::str($d['registerId'] ?? '') !== '') {
                $docsNum[self::str($d['registerId'])] = $d;
            }
        }

        $out = [];
        foreach ($registers as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id   = self::str($r['registerId'] ?? '');
            $nums = $docsNum[$id] ?? [];

            $out[] = [
                'ID'             => $id,
                'outletLegacyId' => self::str($r['outletId'] ?? ''),
                'name'           => self::str($r['name'] ?? ''),
                'invoiceAuth'    => self::digits($r['invoiceAuthNo'] ?? ($r['invoiceAuth'] ?? '')),
                'prefix'         => self::normalizePrefix(self::str($r['invoicePrefix'] ?? '')),
                'sufix'          => self::str($r['invoiceSufix'] ?? ''),
                'invoiceAuthExp' => self::str($r['invoiceAuthExpiration'] ?? ''),
                'invoiceNoMax'   => self::str($r['invoiceNoMax'] ?? ''),
                'docsZeros'      => $r['leadingZero'] ?? null,
                // ÚLTIMO emitido de cada documento. El +1 lo aplica el
                // importador, que es donde vive la regla de continuación.
                'invoiceNo'      => self::intOrZero($nums['invoiceNo'] ?? 0),
                'quoteNo'        => self::intOrZero($nums['quoteNo'] ?? 0),
                'returnNo'       => self::intOrZero($nums['returnNo'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Artículos, con su composición cruda.
     *
     * `compound` viaja como STRING con un JSON adentro
     * (`[{"id":"6KNgR","units":"1.000","select":"0"}]`) y se pasa tal cual: el
     * importador lo decodifica en su segunda pasada, cuando los componentes ya
     * tienen id de Punto. Interpretarlo acá obligaría a que el cliente HTTP
     * conociera el modelo de combos.
     */
    public function items(): array
    {
        $out = [];
        foreach ($this->fetch('items') as $it) {
            if (!is_array($it)) {
                continue;
            }
            $out[] = [
                'ID'          => self::str($it['itemId'] ?? ''),
                'name'        => self::str($it['name'] ?? ''),
                'sku'         => self::str($it['sku'] ?? ''),
                // `barcode` NO aparece en el relevamiento de `/fetchs` (el POS
                // legacy no lo bajaba). Se lee igual por si el deploy del
                // cliente lo manda: la columna `item.barcode` existe desde la
                // mig 220 y el costo de intentarlo es cero. Ausente = el
                // artículo entra sin código, no con uno inventado.
                'barcode'     => self::str($it['barcode'] ?? ($it['itemBarcode'] ?? '')),
                'price'       => $it['price'] ?? null,
                // El bootstrap del POS no manda el COSTO (no lo necesita para
                // vender). Se lee si viniera, pero lo normal es que el artículo
                // entre sin costo — ver context/77 §8.
                'cost'        => $it['cogs'] ?? ($it['cost'] ?? null),
                'tax'         => self::str($it['tax'] ?? ''),
                'kind'        => self::str($it['kind'] ?? ''),
                'type'        => self::str($it['type'] ?? ''),
                'categoryId'  => self::str($it['categoryId'] ?? ''),
                'category'    => self::str($it['category'] ?? ''),
                'brand'       => self::str($it['brand'] ?? ''),
                'uom'         => self::str($it['uom'] ?? ''),
                'description' => self::str($it['description'] ?? ''),
                'trackStock'  => $it['trackInventory'] ?? null,
                'compound'    => is_string($it['compound'] ?? null) ? $it['compound'] : '',
            ];
        }
        return $out;
    }

    /**
     * SALDO de cada artículo EN UNA SUCURSAL.
     *
     * Es el mismo `load=items`, pedido con OTRO `outletId` en el cuerpo: el
     * bootstrap del POS legacy devuelve el stock de la caja que arranca, así
     * que el saldo de cada sucursal solo se consigue preguntando por ella.
     *
     * `inventory` viaja como lista (`[{"count": 24}]`) y puede venir vacía —un
     * artículo que no lleva stock, o que nunca tuvo movimientos—. Un artículo
     * SIN entrada de inventario no es lo mismo que uno con saldo 0, así que se
     * devuelve `hasCount` además del número: quién decide qué hacer con cada
     * caso es el importador, no este cliente.
     *
     * @return array<int,array{ID:string,count:float,hasCount:bool,trackStock:mixed}>
     */
    public function itemStock(string $outletLegacyId): array
    {
        $out = [];
        foreach ($this->fetch('items', $outletLegacyId) as $it) {
            if (!is_array($it)) {
                continue;
            }

            $id = self::str($it['itemId'] ?? '');
            if ($id === '') {
                continue;
            }

            $count    = 0.0;
            $hasCount = false;
            foreach ((is_array($it['inventory'] ?? null) ? $it['inventory'] : []) as $bucket) {
                if (is_array($bucket) && is_numeric($bucket['count'] ?? null)) {
                    $count   += (float) $bucket['count'];
                    $hasCount = true;
                }
            }

            $out[] = [
                'ID'         => $id,
                'count'      => $count,
                'hasCount'   => $hasCount,
                // Se devuelve por completitud, pero el importador NO lo usa
                // para decidir: quien manda es `item.itemTrackInventory` del
                // artículo YA migrado, que es lo que el ledger mira.
                'trackStock' => $it['trackInventory'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * COSTO de cada artículo, leído de la tabla del PANEL.
     *
     * ── Por qué este dato viene de otra superficie ───────────────────────
     * `/fetchs` es el bootstrap del POS y el POS no necesita el costo para
     * vender, así que no lo manda. Es el único campo del catálogo que el
     * scraping viejo daba y este no: perderlo deja en cero todo reporte de
     * margen del comercio migrado.
     *
     * El catálogo NO vuelve al panel por eso: sigue saliendo entero de
     * `/fetchs` y de acá sale un solo campo, que se cruza por SKU o por nombre
     * (`EncomImportService::costFor()`).
     *
     * ── Por qué `showTable` y no `exportCSV` ─────────────────────────────
     * `a_items?action=exportCSV` está descartado desde la F1 y sigue estándolo
     * (context/77 §15): exige `ids` —no tiene "todos"— y su header declara 18
     * columnas mientras las filas traen 7 claves con otros nombres. Está
     * desalineado en el propio legacy.
     *
     * ── Las columnas se resuelven por ENCABEZADO ─────────────────────────
     * La tabla de artículos era lo ÚNICO que en la F1 seguía leyéndose por
     * índice fijo, y era el supuesto #4 del plan. Ahora que vuelve a tener un
     * lector se hace por encabezado: si el legacy agrega una columna, el costo
     * se sigue leyendo de la columna del costo y no del precio. El fallback
     * posicional es el orden conocido del sistema vivo.
     *
     * Si NO hay columna de costo, lanza: el importador lo traduce en "se
     * importa sin costos" con su nota en la bitácora. Devolver una lista vacía
     * en silencio haría indistinguible "el comercio no carga costos" de "ya no
     * sé leer esta tabla".
     *
     * @return array<int,array{sku:string,name:string,cost:float|null}>
     */
    public function itemCosts(): array
    {
        // Por el MISMO lector paginado que el histórico: esta tabla del panel
        // tiene el mismo techo de filas, así que un catálogo de más de 100
        // artículos traía 100 costos y ninguna señal sobre el resto. Si el
        // deploy no deja paginar, lanza —y el importador lo traduce en "se
        // importa sin costos"—, que es mucho mejor que costos a medias
        // indistinguibles de "el comercio no los carga".
        $tabla   = $this->pagedTable('los costos de los artículos', '/a_items', ['action' => 'showTable']);
        $headers = $tabla['headers'];

        // Orden conocido del sistema vivo como respaldo: 0 imagen · 1 nombre ·
        // 2 tipo · 3 fecha · 4 UOM · 5 SKU · … · 14 costo · 15 precio.
        $cName = EncomParse::columnIndex($headers, ['NOMBRE']) ?? 1;
        $cSku  = EncomParse::columnIndex($headers, ['SKU', 'CODIGO']) ?? 5;
        $cCost = EncomParse::columnIndex($headers, ['COSTO']);

        if ($cCost === null && $headers !== []) {
            throw new EncomMigrationException(
                'La tabla de artículos del panel legacy ya no tiene columna de costo: los artículos se '
                . 'importan sin costo.',
                502
            );
        }
        $cCost ??= 14;

        $out = [];
        foreach ($tabla['rows'] as $row) {
            $cells = $row['cells'];

            $name = trim((string) ($cells[$cName] ?? ''));
            $sku  = trim((string) ($cells[$cSku] ?? ''));
            // El legacy pinta "-" cuando el artículo no tiene SKU.
            if ($sku === '-') {
                $sku = '';
            }
            if ($name === '' && $sku === '') {
                continue;
            }

            // El valor crudo viaja en `data-sort`; el texto visible trae los
            // separadores de miles del comercio ("5.000"), que NO se deshacen
            // acá — un costo mal parseado es peor que ninguno.
            $raw = trim((string) ($cells[$cCost] ?? ''));

            $out[] = [
                'sku'  => $sku,
                'name' => $name,
                'cost' => is_numeric($raw) ? (float) $raw : null,
            ];
        }

        return $out;
    }

    /**
     * Categorías — DERIVADAS de los artículos.
     *
     * A diferencia del export viejo (que solo traía el NOMBRE y obligaba a
     * usarlo como clave), `/fetchs` manda `categoryId` junto al nombre, así que
     * la identidad es el id real del legacy: dos categorías homónimas dejan de
     * fusionarse y renombrar una en el legacy no crea una segunda en Punto.
     */
    public function categories(): array
    {
        $seen = [];
        foreach ($this->items() as $item) {
            $name = trim((string) $item['category']);
            $id   = trim((string) $item['categoryId']);
            if ($name === '' || $name === '-') {
                continue;
            }
            // Sin id del otro lado, el nombre ES la clave (mismo criterio que
            // las marcas).
            $seen[$id !== '' ? $id : $name] = $name;
        }

        $out = [];
        foreach ($seen as $id => $name) {
            $out[] = ['ID' => (string) $id, 'name' => $name];
        }
        return $out;
    }

    /** Marcas — derivadas de los artículos; el legacy no les da id. */
    public function brands(): array
    {
        $seen = [];
        foreach ($this->items() as $item) {
            $name = trim((string) $item['brand']);
            if ($name === '' || $name === '-') {
                continue;
            }
            $seen[mb_strtolower($name, 'UTF-8')] = $name;
        }

        $out = [];
        foreach ($seen as $name) {
            $out[] = ['ID' => $name, 'name' => $name];
        }
        return $out;
    }

    /**
     * Etiquetas, de `settings.tags`.
     *
     * ⚠ El shape exacto NO está verificado contra el sistema vivo: el
     * relevamiento confirma que la clave existe, no cómo viene adentro. Se
     * aceptan las dos formas plausibles (lista de nombres, o lista de objetos
     * con id+nombre) y se ignora en silencio cualquier otra: una etiqueta mal
     * leída es cosmética, y abortar el catálogo entero por ella no se paga.
     */
    public function tags(): array
    {
        $raw = $this->settingsRaw()['tags'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $tag) {
            if (is_string($tag)) {
                $name = trim($tag);
                $id   = is_string($key) ? $key : $name;
            } elseif (is_array($tag)) {
                $name = self::str($tag['name'] ?? ($tag['tagName'] ?? ($tag['title'] ?? '')));
                $id   = self::str($tag['id'] ?? ($tag['tagId'] ?? '')) ?: $name;
            } else {
                continue;
            }
            if ($name !== '') {
                $out[] = ['ID' => (string) $id, 'name' => $name];
            }
        }
        return $out;
    }

    /**
     * Clientes.
     *
     * Mucho más rico que el CSV del panel: trae el id del contacto (así que la
     * idempotencia deja de depender de una clave natural), el documento con su
     * TIPO, el saldo a favor, la línea de crédito y las coordenadas.
     */
    public function customers(): array
    {
        $out = [];
        foreach ($this->fetch('customers') as $c) {
            if (!is_array($c)) {
                continue;
            }

            // `name` es el nombre con el que el POS lo muestra (razón social en
            // un contribuyente) y `fullName` el de la persona. Cuando coinciden
            // se manda uno solo — `ContactService` ya replica el que reciba.
            $name     = self::str($c['name'] ?? '');
            $fullName = self::str($c['fullName'] ?? '');

            $row = [
                'ID'         => self::str($c['customerId'] ?? ''),
                'fiscalName' => $name,
                'name'       => $fullName !== '' ? $fullName : $name,
                'tin'        => self::str($c['ruc'] ?? ''),
                'ci'         => self::str($c['ci'] ?? ''),
                'idType'     => $c['typeIdentifier'] ?? null,
                'phone'      => self::str($c['phone'] ?? ''),
                'email'      => self::str($c['email'] ?? ''),
                'address'    => self::str($c['address'] ?? ''),
                'city'       => self::str($c['city'] ?? ''),
                'location'   => self::str($c['location'] ?? ''),
                'country'    => self::str($c['country'] ?? ''),
                'note'       => self::str($c['note'] ?? ''),
                'bday'       => self::str($c['birthDay'] ?? ''),
                'storeCredit' => $c['storeCredit'] ?? null,
                'creditLine'  => $c['creditLine'] ?? null,
                'loyalty'     => $c['loyalty'] ?? null,
            ];

            $latLng = self::str($c['latLng'] ?? '');
            if (str_contains($latLng, ',')) {
                [$lat, $lng] = array_map('trim', explode(',', $latLng, 2));
                $row['lat'] = $lat;
                $row['lng'] = $lng;
            }

            $out[] = $row;
        }
        return $out;
    }

    /**
     * Usuarios del comercio, con su PIN de caja y el nombre de su rol.
     *
     * El objeto `permissions` del legacy NO se mapea permiso por permiso: su
     * forma no tiene nada que ver con las permission keys de Punto y traducirla
     * a ciegas es cómo se le da a un cajero un permiso que nunca tuvo. El
     * importador asigna el ROL más cercano por nombre y deja que el rol traiga
     * sus permisos — ver `EncomImportService::users()`.
     */
    public function users(): array
    {
        $out = [];
        foreach ($this->fetch('users') as $u) {
            if (!is_array($u)) {
                continue;
            }
            $out[] = [
                'ID'             => self::str($u['userId'] ?? ''),
                'name'           => self::str($u['name'] ?? ''),
                'email'          => self::str($u['email'] ?? ''),
                'phone'          => self::str($u['phone'] ?? ''),
                // PIN de la pantalla de bloqueo. Punto exige 4 dígitos: lo
                // valida `UsersService`, no este cliente.
                'lockPass'       => self::str($u['lockPass'] ?? ''),
                'roleName'       => self::str($u['roleName'] ?? ''),
                'role'           => self::str($u['role'] ?? ''),
                'outletLegacyId' => self::str($u['outlet'] ?? ''),
                'color'          => self::str($u['color'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Medios de pago, de `settings.paymentMethods`.
     *
     * ⚠ Igual que `tags()`: el relevamiento confirma la CLAVE, no el shape de
     * adentro. Se aceptan las formas plausibles (lista de nombres, o de objetos
     * con nombre y código) y se descarta lo que no tenga nombre — un medio de
     * pago sin nombre no es importable.
     */
    public function paymentMethods(): array
    {
        $raw = $this->settingsRaw()['paymentMethods'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $pm) {
            if (is_string($pm)) {
                $name = trim($pm);
                $id   = is_string($key) ? $key : $name;
                $code = '';
            } elseif (is_array($pm)) {
                $name = self::str($pm['name'] ?? ($pm['paymentMethodName'] ?? ($pm['title'] ?? ($pm['label'] ?? ''))));
                $id   = self::str($pm['id'] ?? ($pm['paymentMethodId'] ?? '')) ?: $name;
                $code = self::str($pm['code'] ?? ($pm['shortcut'] ?? ''));
            } else {
                continue;
            }
            if ($name !== '') {
                $out[] = ['ID' => (string) $id, 'name' => $name, 'code' => $code];
            }
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Ventas — PREPARADO PARA F2, sigue saliendo del PANEL
    // ═══════════════════════════════════════════════════════════════════

    /**
     * El LOG DE ÍTEMS VENDIDOS de un rango — el segundo log del histórico.
     *
     * ── Por qué existe, y qué reemplaza ──────────────────────────────────
     * En el legacy los ítems vendidos NO cuelgan de la transacción: son una
     * tabla aparte con su propio reporte. Hasta 2026-09-11 el migrador los
     * sacaba del form de edición de cada venta
     * (`a_report_transactions?action=edit&id=`), UNA request por venta —
     * 6.927 en el primer cliente real, más de dos horas paceadas contra el
     * servidor donde el comercio factura—. Este log trae lo mismo en unas
     * pocas páginas de 1000.
     *
     * Los tres filtros vacíos (`itmId`, `cusId`, `usrId`) son los de la
     * pantalla: van explícitos porque así está verificada la URL contra el
     * sistema vivo.
     *
     * ⚠ SUPUESTO declarado: que este reporte acepta `from`/`to` como los
     * otros. No está verificado. Si los ignorara, la única consecuencia es que
     * vendrían filas de fuera del mes; el importador NO las asienta a ciegas
     * —cada fila se pega a una venta ya importada o se cuenta como huérfana—,
     * así que el modo de falla es ruidoso y no una fila de más.
     */
    public function itemsSoldHistory(string $from, string $to): array
    {
        $tabla = $this->pagedTable('las líneas de venta', '/a_report_products', [
            'action' => 'detailTable',
            'itmId'  => '',
            'cusId'  => '',
            'usrId'  => '',
            'from'   => $from,
            'to'     => $to,
        ]);

        $headers = $tabla['headers'];

        // La referencia a la VENTA es lo único sin lo cual esto no sirve: una
        // línea que no se puede pegar a su transacción no se puede asentar
        // (`itemsold.transactionid` es NOT NULL con FK). Se buscan las dos
        // formas posibles y se prefiere el ID sobre el número de documento,
        // que es una clave natural y puede repetirse entre timbrados.
        $cSale = EncomParse::columnIndex($headers, ['#TRANSACCION', 'TRANSACCION', '#VENTA', 'ID VENTA'])
            ?? EncomParse::columnIndexExact($headers, ['VENTA']);
        $cDoc  = EncomParse::columnIndex($headers, ['#DOCUMENTO']) ?? EncomParse::columnIndex($headers, ['DOCUMENTO']);

        // `NOMBRE` va PRIMERO y no es un sinónimo de más: el selector de
        // columnas del legacy muestra la etiqueta "Artículo", pero la tabla
        // renderiza el encabezado `Nombre`. Verificado contra el sistema vivo
        // (2026-09-11). Buscar solo "ARTICULO" tiraba el dominio entero.
        $cItem  = EncomParse::columnIndex($headers, ['NOMBRE', 'ARTICULO', 'PRODUCTO', 'DESCRIPCION']);
        // El SKU resuelve el artículo contra el catálogo migrado cuando está.
        // ⚠ En la cuenta del primer cliente viene VACÍO, así que este camino
        // existe para otros comercios y acá cae al nombre.
        $cSku   = EncomParse::columnIndex($headers, ['CODIGO', 'SKU']);
        $cQty   = EncomParse::columnIndex($headers, ['CANTIDAD', 'CANT']);
        $cPrice = EncomParse::columnIndex($headers, ['PRECIO']);
        $cTax   = EncomParse::columnIndexExact($headers, ['IVA']) ?? EncomParse::columnIndex($headers, ['IVA', 'IMPUESTO']);
        $cTotal = EncomParse::columnIndexExact($headers, ['TOTAL']);
        $cDate  = EncomParse::columnIndexExact($headers, ['FECHA']) ?? EncomParse::columnIndex($headers, ['FECHA']);
        $cUser  = EncomParse::columnIndex($headers, ['USUARIO', 'VENDEDOR']);
        $cOutlet   = EncomParse::columnIndex($headers, ['SUCURSAL']);
        $cRegister = EncomParse::columnIndexExact($headers, ['CAJA']);
        // Este reporte SÍ trae el costo con el que se vendió —el form de la
        // venta no lo traía, que es de donde salía el supuesto viejo—, y
        // además la utilidad, que es con lo que el importador despeja si ese
        // costo es unitario o de la línea.
        $cCost     = EncomParse::columnIndex($headers, ['COSTO']);
        $cProfit   = EncomParse::columnIndex($headers, ['UTILIDAD']);
        $cDiscount = EncomParse::columnIndex($headers, ['DESCUENTO']);
        $cComision = EncomParse::columnIndex($headers, ['COMISION']);
        $cCategory = EncomParse::columnIndex($headers, ['CATEGORIA']);

        if ($tabla['rows'] === [] && $headers === []) {
            return [];
        }

        // Falla NOMBRANDO lo que vino, igual que el detalle de compras: si el
        // legacy renombró una columna, eso se ve en el mensaje en vez de
        // deducirse de un silencio.
        if (($cSale === null && $cDoc === null) || $cItem === null) {
            throw new EncomMigrationException(
                'El log de ítems vendidos del sistema legacy no tiene las columnas con las que se identifica la '
                . 'venta y el artículo, así que no hay forma de pegar cada línea a su transacción. Encabezados '
                . 'recibidos: ' . ($headers === [] ? '(ninguno)' : implode(' | ', $headers)) . '.',
                502
            );
        }

        $out = [];
        foreach ($tabla['rows'] as $row) {
            $cells = $row['cells'];
            $out[] = [
                // El id de la FILA es el de la línea, no el de la venta: sirve
                // para no volver a asentarla en una corrida posterior.
                'ID'           => $row['id'],
                'saleRef'      => $cSale !== null ? trim((string) ($cells[$cSale] ?? '')) : '',
                'docNumber'    => $cDoc !== null ? trim((string) ($cells[$cDoc] ?? '')) : '',
                'date'         => $cDate !== null ? trim((string) ($cells[$cDate] ?? '')) : '',
                'legacyItemId' => '',
                'sku'          => $cSku !== null ? trim((string) ($cells[$cSku] ?? '')) : '',
                'itemName'     => trim((string) ($cells[$cItem] ?? '')),
                'qty'          => $cQty !== null ? self::numCell($cells[$cQty] ?? null) : null,
                'price'        => $cPrice !== null ? self::numCell($cells[$cPrice] ?? null) : null,
                'tax'          => $cTax !== null ? self::numCell($cells[$cTax] ?? null) : null,
                'total'        => $cTotal !== null ? self::numCell($cells[$cTotal] ?? null) : null,
                'cost'         => $cCost !== null ? self::numCell($cells[$cCost] ?? null) : null,
                'profit'       => $cProfit !== null ? self::numCell($cells[$cProfit] ?? null) : null,
                'discount'     => $cDiscount !== null ? self::numCell($cells[$cDiscount] ?? null) : null,
                'comission'    => $cComision !== null ? self::numCell($cells[$cComision] ?? null) : null,
                'category'     => $cCategory !== null ? trim((string) ($cells[$cCategory] ?? '')) : '',
                'user'         => $cUser !== null ? trim((string) ($cells[$cUser] ?? '')) : '',
                'outlet'       => $cOutlet !== null ? trim((string) ($cells[$cOutlet] ?? '')) : '',
                'register'     => $cRegister !== null ? trim((string) ($cells[$cRegister] ?? '')) : '',
            ];
        }

        return $out;
    }


    /**
     * Cabeceras de las ventas de un rango, con las columnas resueltas por
     * ENCABEZADO.
     *
     * Nunca por índice fijo: es la lección más cara de la F1 (el listado vivo
     * de cajas tenía una columna que el snapshot no tenía y, leído por
     * posición, el nombre de la sucursal se leía como TIMBRADO). Y acá hay dos
     * pares ambiguos —`Tipo Documento`/`Tipo` y `Total Gravado`/`Total`, en
     * los que el título más largo va PRIMERO— que se resuelven por igualdad
     * exacta (`columnIndexExact`), porque el match por substring elegiría en
     * los dos casos la columna equivocada sin decir nada.
     */
    public function salesHistory(string $from, string $to): array
    {
        $tabla   = $this->pagedTable('las ventas', '/a_report_transactions', [
            'action' => 'detailTable',
            'from'   => $from,
            'to'     => $to,
            'cusId'  => '',
        ]);
        $headers = $tabla['headers'];

        $cDoc      = EncomParse::columnIndex($headers, ['#DOCUMENTO']) ?? EncomParse::columnIndex($headers, ['DOCUMENTO']);
        $cAuth     = EncomParse::columnIndex($headers, ['AUTORIZACION', 'TIMBRADO']);
        $cDate     = EncomParse::columnIndexExact($headers, ['FECHA']) ?? EncomParse::columnIndex($headers, ['FECHA']);
        $cTime     = EncomParse::columnIndexExact($headers, ['HORA']);
        $cDue      = EncomParse::columnIndex($headers, ['VENCIMIENTO']);
        $cCustomer = EncomParse::columnIndex($headers, ['CLIENTE']);
        $cTin      = EncomParse::columnIndex($headers, ['RUC', 'TIN', 'NIT', 'CEDULA', 'CI']);
        $cUser     = EncomParse::columnIndex($headers, ['USUARIO', 'VENDEDOR']);
        $cOutlet   = EncomParse::columnIndex($headers, ['SUCURSAL']);
        $cRegister = EncomParse::columnIndexExact($headers, ['CAJA']);
        $cPayment  = EncomParse::columnIndex($headers, ['M.DE PAGO', 'PAGO']);
        $cNote     = EncomParse::columnIndex($headers, ['NOTA']);
        $cDocType  = EncomParse::columnIndexExact($headers, ['TIPO DOCUMENTO']);
        $cType     = EncomParse::columnIndexExact($headers, ['TIPO']);
        $cDiscount = EncomParse::columnIndex($headers, ['DESCUENTO']);
        $cTax      = EncomParse::columnIndexExact($headers, ['IVA']) ?? EncomParse::columnIndex($headers, ['IVA', 'IMPUESTO']);
        $cTotal    = EncomParse::columnIndexExact($headers, ['TOTAL']);

        // Sin la fecha o sin el total no hay asiento contable posible, y
        // adivinar la posición de un MONTO es exactamente lo que no se hace.
        if ($cDate === null || $cTotal === null) {
            throw new EncomMigrationException(
                'El listado de ventas del sistema legacy no tiene las columnas de fecha y total donde se '
                . 'esperaban: no se puede importar el histórico sin leerlas mal.',
                502
            );
        }

        $out = [];
        foreach ($tabla['rows'] as $row) {
            $cells = $row['cells'];

            $fecha = trim((string) ($cells[$cDate] ?? ''));
            if ($cTime !== null) {
                $hora = trim((string) ($cells[$cTime] ?? ''));
                // `data-order` de la fecha suele traer ya el timestamp
                // completo; la hora se pega solo si falta.
                if ($hora !== '' && strlen($fecha) <= 10) {
                    $fecha = $fecha . ' ' . $hora;
                }
            }

            $out[] = [
                'ID'            => $row['id'],
                'docNumber'     => $cDoc !== null ? trim((string) ($cells[$cDoc] ?? '')) : '',
                'authNo'        => $cAuth !== null ? trim((string) ($cells[$cAuth] ?? '')) : '',
                'date'          => $fecha,
                'dueDate'       => $cDue !== null ? trim((string) ($cells[$cDue] ?? '')) : '',
                'customer'      => $cCustomer !== null ? trim((string) ($cells[$cCustomer] ?? '')) : '',
                'customerTin'   => $cTin !== null ? trim((string) ($cells[$cTin] ?? '')) : '',
                'user'          => $cUser !== null ? trim((string) ($cells[$cUser] ?? '')) : '',
                'outlet'        => $cOutlet !== null ? trim((string) ($cells[$cOutlet] ?? '')) : '',
                'register'      => $cRegister !== null ? trim((string) ($cells[$cRegister] ?? '')) : '',
                'paymentMethod' => $cPayment !== null ? trim((string) ($cells[$cPayment] ?? '')) : '',
                'note'          => $cNote !== null ? trim((string) ($cells[$cNote] ?? '')) : '',
                'docType'       => $cDocType !== null ? trim((string) ($cells[$cDocType] ?? '')) : '',
                'type'          => $cType !== null ? trim((string) ($cells[$cType] ?? '')) : '',
                'discount'      => $cDiscount !== null ? self::numCell($cells[$cDiscount] ?? null) : null,
                'tax'           => $cTax !== null ? self::numCell($cells[$cTax] ?? null) : null,
                'total'         => self::numCell($cells[$cTotal] ?? null),
            ];
        }

        return $out;
    }

    /** Cabeceras de las compras de un rango. Columnas por ENCABEZADO. */
    public function purchasesHistory(string $from, string $to): array
    {
        $tabla = $this->pagedTable('las compras', '/a_report_purchases', [
            'action' => 'general',
            'from'   => $from,
            'to'     => $to,
        ]);

        $headers = $tabla['headers'];

        $cDoc      = EncomParse::columnIndex($headers, ['#DOCUMENTO']) ?? EncomParse::columnIndex($headers, ['DOCUMENTO']);
        $cAuth     = EncomParse::columnIndex($headers, ['TIMBRADO', 'AUTORIZACION']);
        $cDate     = EncomParse::columnIndexExact($headers, ['FECHA']) ?? EncomParse::columnIndex($headers, ['FECHA']);
        $cDue      = EncomParse::columnIndex($headers, ['VENCIMIENTO']);
        $cSupplier = EncomParse::columnIndex($headers, ['PROVEEDOR']);
        $cOutlet   = EncomParse::columnIndex($headers, ['SUCURSAL']);
        $cUser     = EncomParse::columnIndex($headers, ['USUARIO']);
        $cType     = EncomParse::columnIndexExact($headers, ['TIPO']);
        $cTax      = EncomParse::columnIndexExact($headers, ['IVA']) ?? EncomParse::columnIndex($headers, ['IVA', 'IMPUESTO']);
        $cTotal    = EncomParse::columnIndexExact($headers, ['TOTAL']);

        if ($cDate === null || $cTotal === null) {
            throw new EncomMigrationException(
                'El listado de compras del sistema legacy no tiene las columnas de fecha y total donde se '
                . 'esperaban: no se puede importar el histórico sin leerlas mal.',
                502
            );
        }

        $out = [];
        foreach ($tabla['rows'] as $row) {
            $cells = $row['cells'];
            $out[] = [
                'ID'        => $row['id'],
                'docNumber' => $cDoc !== null ? trim((string) ($cells[$cDoc] ?? '')) : '',
                'authNo'    => $cAuth !== null ? trim((string) ($cells[$cAuth] ?? '')) : '',
                'date'      => trim((string) ($cells[$cDate] ?? '')),
                'dueDate'   => $cDue !== null ? trim((string) ($cells[$cDue] ?? '')) : '',
                'supplier'  => $cSupplier !== null ? trim((string) ($cells[$cSupplier] ?? '')) : '',
                'outlet'    => $cOutlet !== null ? trim((string) ($cells[$cOutlet] ?? '')) : '',
                'user'      => $cUser !== null ? trim((string) ($cells[$cUser] ?? '')) : '',
                'type'      => $cType !== null ? trim((string) ($cells[$cType] ?? '')) : '',
                'tax'       => $cTax !== null ? self::numCell($cells[$cTax] ?? null) : null,
                'total'     => self::numCell($cells[$cTotal] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Líneas de TODAS las compras del rango, en UNA request.
     *
     * A diferencia de las ventas, el legacy sí tiene un detalle por rango. Se
     * juntan con su cabecera por `#Documento`: el listado de detalle no trae
     * el id de la compra.
     */
    public function purchaseLines(string $from, string $to): array
    {
        $tabla = $this->pagedTable('el detalle de las compras', '/a_report_purchases', [
            'action' => 'detailTable',
            'from'   => $from,
            'to'     => $to,
        ]);

        $headers = $tabla['headers'];

        $cDoc      = EncomParse::columnIndex($headers, ['#DOCUMENTO']) ?? EncomParse::columnIndex($headers, ['DOCUMENTO']);
        $cSupplier = EncomParse::columnIndex($headers, ['PROVEEDOR']);
        $cOutlet   = EncomParse::columnIndex($headers, ['SUCURSAL']);
        // `NOMBRE` primero: en las tablas de reporte del legacy el encabezado
        // que se renderiza es ese, aunque el selector de columnas diga
        // "Artículo" (verificado en vivo en el log de ítems vendidos).
        $cItem     = EncomParse::columnIndex($headers, ['NOMBRE', 'ARTICULO', 'PRODUCTO', 'DESCRIPCION']);
        $cQty      = EncomParse::columnIndex($headers, ['CANTIDAD', 'CANT']);
        $cPrice    = EncomParse::columnIndex($headers, ['PRECIO', 'COSTO']);
        $cTax      = EncomParse::columnIndexExact($headers, ['IVA']) ?? EncomParse::columnIndex($headers, ['IVA', 'IMPUESTO']);
        $cTotal    = EncomParse::columnIndexExact($headers, ['TOTAL']);

        if ($cDoc === null || $cItem === null) {
            // Sin documento no hay a qué compra pegar la línea, y sin artículo
            // no hay línea. Las cabeceras entran igual (el total de la compra
            // es correcto), pero este camino NO puede ser mudo: en la primera
            // corrida real 207 compras entraron sin una sola línea y el job no
            // dijo nada, porque el `note()` del importador solo se dispara ante
            // una EXCEPCIÓN y acá había un `return []`.
            //
            // Se lanza diciendo qué encabezados vinieron DE VERDAD, que es el
            // dato con el que se ve si el legacy renombró una columna.
            if ($tabla['rows'] === [] && $headers === []) {
                // Ni tabla ni filas: el mes no tuvo compras. No hay nada que
                // reportar y avisar por cada mes vacío sería ruido.
                return [];
            }

            throw new EncomMigrationException(
                'El detalle de compras del sistema legacy no tiene las columnas de documento y artículo: '
                . 'las compras entran sin sus líneas. Encabezados recibidos: '
                . ($headers === [] ? '(ninguno)' : implode(' | ', $headers)) . '.',
                502
            );
        }

        $out = [];
        foreach ($tabla['rows'] as $row) {
            $cells = $row['cells'];
            $out[] = [
                'docNumber' => trim((string) ($cells[$cDoc] ?? '')),
                'supplier'  => $cSupplier !== null ? trim((string) ($cells[$cSupplier] ?? '')) : '',
                'outlet'    => $cOutlet !== null ? trim((string) ($cells[$cOutlet] ?? '')) : '',
                'itemName'  => trim((string) ($cells[$cItem] ?? '')),
                'qty'       => $cQty !== null ? self::numCell($cells[$cQty] ?? null) : null,
                'price'     => $cPrice !== null ? self::numCell($cells[$cPrice] ?? null) : null,
                'tax'       => $cTax !== null ? self::numCell($cells[$cTax] ?? null) : null,
                'total'     => $cTotal !== null ? self::numCell($cells[$cTotal] ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * Movimientos de caja de un rango.
     *
     * ⚠ El `action` correcto es `generalTable`. Con `general`, `detailTable`,
     * `showTable` o `table` el archivo IGNORA el parámetro y devuelve la
     * PÁGINA HTML ENTERA (los cuatro dan exactamente los mismos 15312 bytes),
     * que parsea a cero filas sin ningún error — o sea "el comercio no tuvo
     * gastos" en vez de "pedí mal". Verificado en vivo el 2026-09-11.
     */
    public function expensesHistory(string $from, string $to): array
    {
        $tabla = $this->pagedTable('los movimientos de caja', '/a_report_expenses', [
            'action' => 'generalTable',
            'from'   => $from,
            'to'     => $to,
        ]);

        $headers = $tabla['headers'];

        $cDate     = EncomParse::columnIndexExact($headers, ['FECHA']) ?? EncomParse::columnIndex($headers, ['FECHA']);
        $cOutlet   = EncomParse::columnIndex($headers, ['SUCURSAL']);
        $cRegister = EncomParse::columnIndexExact($headers, ['CAJA']);
        $cUser     = EncomParse::columnIndex($headers, ['USUARIO']);
        $cNote     = EncomParse::columnIndex($headers, ['NOTA', 'DESCRIPCION', 'CONCEPTO']);
        $cType     = EncomParse::columnIndexExact($headers, ['TIPO']);
        $cTotal    = EncomParse::columnIndexExact($headers, ['TOTAL']) ?? EncomParse::columnIndex($headers, ['MONTO', 'IMPORTE']);

        if ($cDate === null || $cTotal === null) {
            throw new EncomMigrationException(
                'El listado de movimientos de caja del sistema legacy no tiene las columnas de fecha y '
                . 'monto donde se esperaban: no se puede importar el histórico sin leerlas mal.',
                502
            );
        }

        $out = [];
        foreach ($tabla['rows'] as $row) {
            $cells = $row['cells'];
            $out[] = [
                'ID'       => $row['id'],
                'date'     => trim((string) ($cells[$cDate] ?? '')),
                'outlet'   => $cOutlet !== null ? trim((string) ($cells[$cOutlet] ?? '')) : '',
                'register' => $cRegister !== null ? trim((string) ($cells[$cRegister] ?? '')) : '',
                'user'     => $cUser !== null ? trim((string) ($cells[$cUser] ?? '')) : '',
                'note'     => $cNote !== null ? trim((string) ($cells[$cNote] ?? '')) : '',
                'type'     => $cType !== null ? trim((string) ($cells[$cType] ?? '')) : '',
                'total'    => self::numCell($cells[$cTotal] ?? null),
            ];
        }

        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════
    // El lector de listados: completo, o ruidoso
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Un listado del panel ENTERO — o una excepción diciendo que no lo está.
     *
     * ── El problema que resuelve (primera corrida real, 2026-09-11) ──────
     * El legacy CAPA sus listados en 100 filas por request y no lo dice de
     * ninguna forma: contesta 200, con una tabla bien formada, de exactamente
     * 100 filas. El job importó "300 ventas, 0 errores" en tres meses que
     * tenían 100 cada uno — el techo, no el volumen — y el resto del año se
     * perdió sin una sola señal.
     *
     * ── Por qué UN lector y no un arreglo por método ─────────────────────
     * Los cinco listados que se leen del panel (ventas, compras, detalle de
     * compras, movimientos de caja y los costos del catálogo) tienen todos el
     * mismo techo. Paginar en cada uno sería el mismo bug esperando en cuatro
     * lugares más, y el próximo lector que alguien agregue nacería capado
     * otra vez. Acá la única forma de leer una tabla del panel es esta.
     *
     * ── Dos mitades, las dos obligatorias ────────────────────────────────
     *   1. **Intentar paginar Y DEMOSTRAR que funcionó.** El parámetro no está
     *      documentado y no se puede sondear sin las credenciales de un
     *      cliente, así que no alcanza con mandarlo: un legacy que lo ignora
     *      contesta las mismas 100 filas con cara de éxito. Se prueban las
     *      convenciones conocidas y cada una tiene que probar que respeta el
     *      TAMAÑO y el OFFSET (ver `paginar()`).
     *   2. **Si ninguna funciona y la respuesta vino justo en el tope, fallar
     *      fuerte.** Es la mitad que no se puede negociar: el mes está
     *      truncado y no hay forma de saber cuánto falta. Importarlo sería
     *      repetir el incidente con más código.
     *
     * Ojo con la condición: se paginan los listados que devuelven EXACTAMENTE
     * el tope. Menos es todo lo que había; MÁS es la prueba de que este deploy
     * no tiene ese tope, y ahí tampoco hay nada recortado. En los dos casos no
     * se gasta una sola request de más, que es el caso normal.
     *
     * @param string $que cómo nombrar estas filas en el mensaje de error
     * @return array{headers:array<int,string>,rows:array<int,array{id:string,cells:array<int,string>}>}
     */
    private function pagedTable(string $que, string $path, array $params): array
    {
        $html    = EncomParse::tableHtml($this->get($path, $params));
        $headers = EncomParse::htmlHeaders($html);
        $filas   = EncomParse::htmlRows($html);

        if (count($filas) !== self::CAP_SIZE) {
            return ['headers' => $headers, 'rows' => $filas];
        }

        $probadas = [];
        foreach (self::paginadores() as $convencion => $armar) {
            $probadas[] = $convencion;

            $todas = $this->paginar($path, $params, $armar);
            if ($todas !== null) {
                return ['headers' => $headers, 'rows' => $todas];
            }
        }

        throw new EncomExportTruncatedException(
            'El sistema legacy devolvió exactamente ' . self::CAP_SIZE . ' filas de ' . $que . ' para este mes, '
            . 'que es su tope por pedido, y no aceptó ninguna forma de pedirle el resto (se probaron: '
            . implode('; ', $probadas) . '). O sea que el mes está TRUNCADO: hay más ' . $que . ' de las que se '
            . 'pueden leer, y no se sabe cuántas. El export se corta acá en vez de seguir: un mes incompleto '
            . 'asentado como completo deja reportes que no cuadran y que nadie vuelve a revisar. Lo que este job '
            . 'ya haya asentado de meses anteriores queda marcado en la migración, así que relanzarlo no duplica '
            . 'nada y completa lo que falte.',
            502
        );
    }

    /**
     * Lee el listado entero con UNA convención de paginación, o dice que no.
     *
     * Verifica las DOS propiedades por separado, porque son preguntas
     * distintas y un legacy puede cumplir una sola:
     *
     *   · **¿respeta el tamaño?** Se le piden 5 filas. Si devuelve 100, está
     *     ignorando los parámetros. Es la prueba más barata que hay: una
     *     request, y descarta la convención sin pedir una página entera.
     *   · **¿respeta el offset?** Se le pide la página 2 y sus ids tienen que
     *     ser DISTINTOS de los de la primera. Sin este chequeo, un legacy que
     *     recorta a lo que le piden pero siempre desde el principio devolvería
     *     las mismas 100 filas una y otra vez, y el import las iría apilando
     *     como si fueran nuevas.
     *
     * @param callable(int,int):array<string,int|string> $armar (OFFSET en filas, tamaño) → parámetros
     * @return array<int,array{id:string,cells:array<int,string>}>|null null = esta convención no está soportada
     */
    private function paginar(string $path, array $params, callable $armar): ?array
    {
        $sonda = $this->rowsOf($path, array_merge($params, $armar(0, self::PROBE_SIZE)));
        if (count($sonda) !== self::PROBE_SIZE) {
            return null;
        }

        $filas = $this->rowsOf($path, array_merge($params, $armar(0, self::PAGE_SIZE)));
        if ($filas === []) {
            return null;
        }

        $vistos = self::idsDe($filas);

        for ($n = 1; $n < self::MAX_PAGES; $n++) {
            // ── El offset avanza por filas LEÍDAS, no por página pedida ────
            // Un deploy puede respetar `limit` hasta un techo propio (pedimos
            // 1000 y contesta 100). Avanzando de a 1000 nos saltearíamos las
            // 900 del medio en silencio, que es el mismo modo de falla que
            // esto viene a cerrar, solo que más difícil de ver.
            //
            // Por lo mismo, la ÚNICA señal de fin es una página vacía: "vino
            // menos de lo que pedí" no prueba que no haya más.
            $pagina = $this->rowsOf($path, array_merge($params, $armar(count($filas), self::PAGE_SIZE)));

            if ($pagina === []) {
                return $filas;
            }

            $repetidas = 0;
            foreach ($pagina as $fila) {
                if (isset($vistos[$fila['id']])) {
                    $repetidas++;
                }
            }

            // Nunca se deduplica en silencio. Una fila repetida no es un
            // duplicado que se limpia: es la señal de que el listado no está
            // avanzando, y taparla devolvería el problema al estado en que
            // estaba —completo por fuera, incompleto por dentro—.
            if ($repetidas > 0) {
                if ($n === 1) {
                    return null;   // el offset no se respeta: probar la que sigue
                }

                throw new EncomExportTruncatedException(
                    'El listado del sistema legacy dejó de avanzar en la página ' . ($n + 1) . ': devolvió filas '
                    . 'que ya había devuelto antes. No hay forma de saber qué quedó afuera, así que no se importa '
                    . 'nada de este dominio en vez de asentar un período incompleto.',
                    502
                );
            }

            foreach ($pagina as $fila) {
                $vistos[$fila['id']] = true;
                $filas[] = $fila;
            }
        }

        throw new EncomExportTruncatedException(
            'El listado del sistema legacy no se terminó después de ' . self::MAX_PAGES . ' páginas de '
            . self::PAGE_SIZE . ' filas. Se corta acá a propósito —seguir sería pedirle sin fin— y no se importa '
            . 'nada de este dominio.',
            502
        );
    }

    /**
     * Las convenciones de paginación que se prueban, en orden.
     *
     * Se prueban de a UNA y con verificación, en vez de mandar todos los
     * parámetros juntos: mezclar `start` con `page` en el mismo pedido puede
     * darle al legacy dos órdenes contradictorias, y el resultado sería
     * imposible de interpretar. Además, así el mensaje de error dice
     * exactamente qué se intentó.
     *
     * @return array<string,callable(int,int):array<string,int|string>> (offset, tamaño) → parámetros
     */
    private static function paginadores(): array
    {
        return [
            // ── La vía REAL, verificada contra el sistema vivo (2026-09-11) ──
            // `part=true` es lo que ENCIENDE el modo paginado; sin él, `offset`
            // y `limit` se ignoran y vuelve la página con el tope. Los tres van
            // juntos o no va ninguno.
            'part/offset/limit' => static fn (int $offset, int $tam): array => [
                'part'   => 'true',
                'offset' => $offset,
                'limit'  => $tam,
            ],
            // Respaldo para otro deploy: estas pantallas son DataTables
            // (`action=*Table`, valor crudo en `data-order`, id en `data-id`),
            // así que su convención nativa es la única otra que vale la pena
            // probar. No se prueban convenciones inventadas: si ninguna de
            // estas dos anda, el corte ruidoso es la respuesta correcta.
            'start/length (DataTables)' => static fn (int $offset, int $tam): array => [
                'draw'   => intdiv($offset, max(1, $tam)) + 1,
                'start'  => $offset,
                'length' => $tam,
            ],
        ];
    }

    /** Filas de una tabla del panel, en una request. */
    private function rowsOf(string $path, array $params): array
    {
        return EncomParse::htmlRows(EncomParse::tableHtml($this->get($path, $params)));
    }

    /**
     * Ids de fila como claves, para preguntar por pertenencia.
     *
     * @param array<int,array{id:string,cells:array<int,string>}> $filas
     * @return array<string,true>
     */
    private static function idsDe(array $filas): array
    {
        $out = [];
        foreach ($filas as $fila) {
            $out[(string) $fila['id']] = true;
        }
        return $out;
    }

    /**
     * Valor numérico de una celda o de un input.
     *
     * `null` cuando no es numérico, NUNCA 0: en un asiento contable un 0
     * inventado es un monto falso que nadie vuelve a mirar. Los valores crudos
     * del legacy vienen sin formatear (`data-order`), así que no se deshacen
     * separadores de miles acá — un monto mal parseado es peor que ninguno.
     */
    private static function numCell(mixed $v): ?float
    {
        $s = trim((string) ($v ?? ''));
        return is_numeric($s) ? (float) $s : null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Transporte
    // ═══════════════════════════════════════════════════════════════════

    /**
     * UN dominio del bootstrap del POS legacy.
     *
     * ── `protected` a propósito: es LA costura del diseño ────────────────
     * Todo lo de arriba —qué `load` se pide, cómo se juntan `registers` con
     * `docsNum`, de qué campo sale cada dato— es la parte que se puede
     * equivocar, y no se puede probar contra el legacy real. Con este único
     * método sobreescribible, el arnés sirve los payloads CRUDOS que devuelve
     * el sistema vivo y ejercita el mapeo de verdad, no una copia paralela que
     * se desincroniza. Es el motivo por el que la clase no es `final`.
     *
     * @return array<mixed>
     */
    protected function fetch(string $load, ?string $outletHash = null): array
    {
        // `$outletHash` existe por el STOCK: `/fetchs` contesta el bootstrap de
        // UNA sucursal, así que el mismo `load=items` trae `inventory[].count`
        // distinto según qué `outletId` viaje en el cuerpo. El resto de los
        // dominios no lo pasa y sigue saliendo de la sucursal de la sesión.
        //
        // La caché va por (load, sucursal) y no por `load` a secas: con una
        // sola clave, el saldo de la PRIMERA sucursal se le habría servido a
        // todas las demás — cada sucursal habría abierto su inventario con el
        // stock de la otra.
        $outlet   = ($outletHash !== null && trim($outletHash) !== '') ? trim($outletHash) : $this->outletHash;
        $cacheKey = $load . '@' . $outlet;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        if ($this->companyHash === '' || $outlet === '') {
            throw new EncomMigrationException(
                'No se sabe qué comercio del sistema legacy exportar (falta el identificador de la sesión). '
                . 'Creá la migración de nuevo.',
                422
            );
        }

        // ── Fail-closed: `/fetchs` NO vive en el panel ───────────────────
        // Sin el origen del POS, la alternativa sería pedirlo contra
        // `panelUrl`, que es exactamente lo que devolvía 404 seis veces
        // seguidas sin que el job se detuviera. Nunca se cae para atrás al
        // panel: son dos aplicaciones distintas.
        if ($this->posUrl === '') {
            throw new EncomMigrationException(
                'No se sabe contra qué dirección pedir el bootstrap del POS legacy: /fetchs vive en la app '
                . 'del POS, no en el panel, y el origen no se pudo derivar de la sesión. Creá la migración '
                . 'de nuevo.',
                502
            );
        }

        $body = $this->post(
            $this->posUrl . '/fetchs?load=' . rawurlencode($load) . '&gtoken=',
            http_build_query([
                'companyId'  => $this->companyHash,
                'outletId'   => $outlet,
                // El bootstrap completo, no el incremental: `lastUpdate=false`
                // es lo que hace que devuelva TODO y no solo lo cambiado.
                'updateData' => 'true',
                'lastUpdate' => 'false',
            ])
        );

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new EncomMigrationException(
                'El sistema legacy no devolvió datos válidos para "' . $load . '". Puede haber caducado la '
                . 'sesión: creá la migración de nuevo.',
                502
            );
        }

        // Tolerancia al envoltorio: algunas versiones contestan `{ok, data}` y
        // otras la lista pelada. Desenvolver acá evita que cada dominio tenga
        // que acordarse.
        if (isset($json['data']) && is_array($json['data']) && !isset($json['registers'])) {
            $json = $json['data'];
        }

        return $this->cache[$cacheKey] = $json;
    }

    /**
     * Header `Location` de la última respuesta.
     *
     * Existe como método y no como lectura directa del campo para que el arnés
     * pueda simular el redirect de `pos-redirect` sin levantar un servidor: es
     * la única parte del alcance que llega por un header y no por el cuerpo.
     */
    protected function lastLocation(): ?string
    {
        return $this->lastLocation;
    }

    /**
     * Código HTTP de la última respuesta. 0 si todavía no hubo ninguna.
     *
     * `protected` por lo mismo que `lastLocation()`: el arnés corre sin red y
     * necesita poder decir qué contestó "el legacy" para ejercitar las sondas.
     */
    protected function lastStatus(): int
    {
        return $this->lastStatus;
    }

    /** `settings` crudo — lo comparten `tags()` y `paymentMethods()`. */
    private function settingsRaw(): array
    {
        $rows = $this->fetch('settings');
        return is_array($rows[0] ?? null) ? $rows[0] : $rows;
    }

    /**
     * POST con pacing y UN reintento transitorio.
     *
     * Recibe la URL **absoluta**, no un path: el único que lo usa es `fetch()`,
     * que va contra la app del POS y no contra el panel. Que la base viaje en
     * el argumento es lo que hace imposible repetir el bug — no hay una
     * `baseUrl` implícita que pueda ser la equivocada.
     */
    private function post(string $url, string $body): string
    {
        return $this->send('POST', $url, $body, 'application/x-www-form-urlencoded', false);
    }

    /**
     * GET contra el PANEL, con pacing y UN reintento transitorio.
     *
     * Toma un path porque todo lo que se lee por acá —el login, el home, el
     * costo, el histórico— es del panel. El POS se pide por `post()`, con su
     * URL absoluta.
     *
     * `$allowRedirect` existe para `resolveScope()`, donde el 302 ES la
     * respuesta. En cualquier otra llamada un 302 significa que la sesión se
     * cayó (el legacy redirige al login) y se traduce a un error accionable.
     */
    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        $qs = $params !== [] ? (str_contains($path, '?') ? '&' : '?') . http_build_query($params) : '';
        return $this->send('GET', $this->panelUrl . $path . $qs, null, null, $allowRedirect);
    }

    /**
     * Envía a una URL ABSOLUTA.
     *
     * `protected` por lo mismo que `fetch()`: contra qué host sale cada request
     * es justamente lo que se rompió, y el arnés lo verifica sondeando esta
     * costura con dos hosts distintos, que es el caso real.
     */
    protected function send(string $method, string $url, ?string $body, ?string $contentType, bool $allowRedirect): string
    {
        for ($try = 0; ; $try++) {
            $this->pace();

            $res = $this->raw($method, $url, $body, $contentType, $allowRedirect);

            $transient = $res['error'] !== '' || $res['status'] === 429 || $res['status'] >= 500;

            if ($transient && $try === 0) {
                usleep(self::RETRY_SLEEP_US);
                continue;
            }

            if ($res['error'] !== '') {
                throw new EncomMigrationException('Error de red contra el legacy en ' . $url . ': ' . $res['error'], 502);
            }

            if ($res['status'] === 401 || $res['status'] === 403) {
                throw new EncomMigrationException(
                    'La sesión del panel legacy caducó. Creá el job de nuevo para volver a autenticarte.',
                    401
                );
            }

            // La URL ENTERA, con su host: el 404 del incidente decía
            // "en /fetchs?load=outlets&gtoken=" y ocultaba lo único que
            // importaba, que era CONTRA QUÉ HOST se había pedido.
            if ($res['status'] < 200 || $res['status'] >= 400) {
                throw new EncomMigrationException('El legacy respondió ' . $res['status'] . ' en ' . $url . '.', 502);
            }

            return (string) $res['body'];
        }
    }

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
     * curl crudo. Acumula las cookies que el legacy devuelve y reenvía las que
     * ya tiene. No se usa `CURLOPT_COOKIEJAR`: eso escribiría la sesión viva de
     * un cliente a un archivo en el disco del servidor.
     *
     * @return array{status:int,body:?string,error:string}
     */
    private function raw(
        string $method,
        string $url,
        ?string $body,
        ?string $contentType,
        bool $allowRedirect = false,
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => null, 'error' => 'no se pudo inicializar curl'];
        }

        $headers = ['Accept: application/json, text/html, text/csv'];
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
            // Nunca se siguen los redirects: seguir el del login devolvería un
            // 200 con el HTML del login, que se leería como una respuesta
            // vacía — "cero artículos" en vez de "la sesión se cayó". Y el de
            // `pos-redirect` hay que LEERLO, no seguirlo: el dato está en el
            // header `Location`.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header): int {
                $this->captureHeader($header);
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

        // Se guarda el código CRUDO, antes de la traducción de abajo (un 302
        // se reporta como 401 porque para el resto del cliente eso ES la
        // sesión caída). Las sondas necesitan lo que el legacy contestó, no lo
        // que significa.
        $this->lastStatus = $status;

        if (($status === 301 || $status === 302) && !$allowRedirect) {
            return ['status' => 401, 'body' => null, 'error' => ''];
        }

        return [
            'status' => $status,
            'body'   => is_string($resBody) ? $resBody : null,
            'error'  => $err,
        ];
    }

    /** Guarda las cookies de sesión y el `Location` del último redirect. */
    private function captureHeader(string $header): void
    {
        if (stripos($header, 'Location:') === 0) {
            $this->lastLocation = trim(substr($header, strlen('Location:')));
            return;
        }

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

    // ═══════════════════════════════════════════════════════════════════
    // Normalizadores
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Normaliza el punto de expedición a `EEE-PPP`.
     *
     * El legacy lo guarda con un GUIÓN FINAL (`009-001-`), que es como se arma
     * el número completo al imprimirlo. Punto valida contra `^\d{3}-\d{3}$`,
     * así que sin esto TODAS las cajas serían rechazadas por formato y el
     * dominio abortaría entero.
     */
    private static function normalizePrefix(string $raw): string
    {
        return trim(trim($raw), '-');
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private static function digits(mixed $v): string
    {
        return preg_replace('/\D/', '', (string) (is_scalar($v) ? $v : '')) ?? '';
    }

    private static function intOrZero(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}

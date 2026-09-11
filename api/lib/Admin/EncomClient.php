<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

require_once __DIR__ . '/EncomSource.php';
require_once __DIR__ . '/EncomParse.php';
require_once __DIR__ . '/EncomMigrationException.php';

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
 *   1. **El histórico de VENTAS (F2)** — `/fetchs` no lo expone por ningún
 *      `load`: es el bootstrap de una caja, no un reporte. `salesRaw()` /
 *      `saleDetailRaw()`.
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

    private float $lastCallAt = 0.0;

    /** @var array<string,string> */
    private array $cookies;

    /** Último header `Location` recibido. Lo lee `resolveScope()`. */
    private ?string $lastLocation = null;

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
        private readonly string $baseUrl,
        array $cookies,
        string $companyHash = '',
        string $outletHash = '',
    ) {
        $this->cookies     = $cookies;
        $this->companyHash = $companyHash;
        $this->outletHash  = $outletHash;
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
            '/login?login=true',
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
     * @param array{companyId?:string,outletId?:string}|null $scope
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
        );

        // Un job creado antes de que el alcance se guardara —o al que le
        // faltara una de las dos mitades— lo resuelve de nuevo con la misma
        // sesión, en vez de fallar. Es la misma llamada que hace `login()`.
        if ($client->companyHash === '' || $client->outletHash === '') {
            $client->resolveScope();
        }

        return $client;
    }

    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * El par (companyId, outletId) del legacy. Se persiste en el job junto a
     * las cookies: sin él, `/fetchs` no sabe de qué comercio hablar.
     *
     * @return array{companyId:string,outletId:string}
     */
    public function scope(): array
    {
        return ['companyId' => $this->companyHash, 'outletId' => $this->outletHash];
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
        foreach (['/bff/pos-redirect.php', '/'] as $path) {
            $scope = $this->scopeFrom($path);
            if ($scope !== null) {
                [$this->companyHash, $this->outletHash] = $scope;
                return;
            }
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
     * @return array{0:string,1:string}|null
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
     * Extrae y decodifica el parámetro `i` de una URL o de un HTML con enlaces.
     *
     * @return array{0:string,1:string}|null
     */
    private static function decodeScope(string $haystack): ?array
    {
        // Todas las apariciones, no la primera: el home del panel tiene varios
        // enlaces y solo el de la caja lleva un `i` que decodifica a un par.
        if (!preg_match_all('/[?&]i=([A-Za-z0-9+\/=%_-]+)/', $haystack, $matches)) {
            return null;
        }

        foreach ($matches[1] as $raw) {
            $decoded = base64_decode(urldecode((string) $raw), true);
            if ($decoded === false || !str_contains($decoded, ',')) {
                continue;
            }
            [$companyId, $outletId] = array_map('trim', explode(',', $decoded, 2));
            // Los dos tienen que venir: con la sucursal vacía `/fetchs`
            // respondería el bootstrap de otra y las cajas saldrían mal.
            if ($companyId !== '' && $outletId !== '') {
                return [$companyId, $outletId];
            }
        }

        return null;
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
        $html    = EncomParse::tableHtml($this->get('/a_items', ['action' => 'showTable']));
        $headers = EncomParse::htmlHeaders($html);

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
        foreach (EncomParse::htmlRows($html) as $row) {
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
     * Ventas de un rango — PREPARADO PARA F2, no se usa en F1.
     *
     * `/fetchs` NO expone el histórico por ningún `load`: es el bootstrap de
     * una caja, no un reporte. Por eso este dominio —y SOLO este— sigue
     * saliendo de `a_report_transactions?action=detailTable`, con los valores
     * crudos en `data-order` y el id de la venta en `data-id`.
     *
     * Es la razón por la que el cliente conserva la sesión del panel y el
     * transporte `get()` después de que todo lo demás pasó a JSON.
     *
     * @return array<int,array{id:string,cells:array<int,string>}>
     */
    public function salesRaw(string $from, string $to): array
    {
        return EncomParse::htmlRows(EncomParse::tableHtml($this->get('/a_report_transactions', [
            'action' => 'detailTable',
            'from'   => $from,
            'to'     => $to,
            'cusId'  => '',
        ])));
    }

    /** Detalle de UNA venta (form con sus ítems). Preparado para F2. */
    public function saleDetailRaw(string $legacyId): string
    {
        return $this->get('/a_report_transactions', ['action' => 'edit', 'id' => $legacyId]);
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

        $body = $this->post(
            '/fetchs?load=' . rawurlencode($load) . '&gtoken=',
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

    /** `settings` crudo — lo comparten `tags()` y `paymentMethods()`. */
    private function settingsRaw(): array
    {
        $rows = $this->fetch('settings');
        return is_array($rows[0] ?? null) ? $rows[0] : $rows;
    }

    /** POST con pacing y UN reintento transitorio. */
    private function post(string $path, string $body): string
    {
        return $this->send('POST', $path, $body, 'application/x-www-form-urlencoded', false);
    }

    /**
     * GET con pacing y UN reintento transitorio.
     *
     * `$allowRedirect` existe para `resolveScope()`, donde el 302 ES la
     * respuesta. En cualquier otra llamada un 302 significa que la sesión se
     * cayó (el legacy redirige al login) y se traduce a un error accionable.
     */
    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        $qs = $params !== [] ? (str_contains($path, '?') ? '&' : '?') . http_build_query($params) : '';
        return $this->send('GET', $path . $qs, null, null, $allowRedirect);
    }

    private function send(string $method, string $path, ?string $body, ?string $contentType, bool $allowRedirect): string
    {
        for ($try = 0; ; $try++) {
            $this->pace();

            $res = $this->raw($method, $path, $body, $contentType, $allowRedirect);

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
                    'La sesión del panel legacy caducó. Creá el job de nuevo para volver a autenticarte.',
                    401
                );
            }

            if ($res['status'] < 200 || $res['status'] >= 400) {
                throw new EncomMigrationException('El legacy respondió ' . $res['status'] . ' en ' . $path . '.', 502);
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
        string $path,
        ?string $body,
        ?string $contentType,
        bool $allowRedirect = false,
    ): array {
        $ch = curl_init($this->baseUrl . $path);
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

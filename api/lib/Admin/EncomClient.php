<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

require_once __DIR__ . '/EncomSource.php';
require_once __DIR__ . '/EncomParse.php';
require_once __DIR__ . '/EncomMigrationException.php';

/**
 * Cliente HTTP del panel legacy, para el migrador (context/77).
 *
 * ── Contra qué habla, y por qué NO contra la API ────────────────────────
 * El deploy VIVO es más viejo que el código del snapshot y no tiene la
 * superficie JSON (verificado contra el sistema real 2026-09-11):
 *
 *   · `/bff/*.php`      → 404.
 *   · `/API/get_*.php`  → `{"error":"Acceso denegado"}` incluso con una
 *                         sesión de panel válida (usa el auth viejo por
 *                         api_key, que no tenemos).
 *
 * Lo que SÍ responde es la superficie de PÁGINAS, `a_*.php?action=…`, con la
 * cookie **PHPSESSID**. O sea: el export sale de las mismas pantallas que ve
 * el cliente. De ahí que esta clase parsee CSV y HTML en vez de consumir
 * JSON — no es una preferencia, es lo único que existe del otro lado.
 *
 * El parseo vive en `EncomParse`, que se prueba con fragmentos copiados
 * textualmente del sistema vivo; acá queda solo el transporte.
 *
 * ── La password no se persiste (D2) ─────────────────────────────────────
 * `login()` es el único punto que la ve, y corre dentro de la request de
 * /admin. Lo que se guarda en `migration_job` son las cookies, que el worker
 * remonta con `fromCookies()`. Por eso el constructor es privado: no hay
 * forma de construir un cliente en la que la password llegue al worker.
 *
 * ── Pacing ──────────────────────────────────────────────────────────────
 * 60 req/min del otro lado. Se espacia CADA llamada, esperando solo lo que
 * falte desde la anterior. Importa más que antes: este export hace una
 * request POR SUCURSAL y otra POR CAJA.
 */
class EncomClient implements EncomSource
{
    private const CONNECT_TIMEOUT = 10;
    private const TOTAL_TIMEOUT   = 60;

    /** 60 req/min = 1 req/s, con margen (el legacy cuenta por ventana fija). */
    private const MIN_INTERVAL_US = 1_100_000;

    private const RETRY_SLEEP_US = 3_000_000;

    /** Tope de sucursales/cajas a recorrer. Corta un listado absurdo. */
    private const MAX_ENTITIES = 300;

    private float $lastCallAt = 0.0;

    /** @var array<string,string> */
    private array $cookies;

    /** Memo del export de artículos: `categories()`/`brands()` derivan de él. */
    private ?array $itemsCache = null;

    /**
     * `protected`, no público: las dos formas legítimas de obtener un cliente
     * son `login()` (endpoint, ve la password) y `fromCookies()` (worker, no
     * la ve). El arnés lo usa desde su subclase de fixtures.
     */
    protected function __construct(
        private readonly string $baseUrl,
        array $cookies,
    ) {
        $this->cookies = $cookies;
    }

    /**
     * Autentica y devuelve un cliente con la sesión viva.
     *
     * El legacy contesta 200 con el texto plano "true" en éxito — no un JSON
     * ni un 302. El éxito se decide por el cuerpo Y por haber recibido la
     * cookie de sesión: un 200 con "false" es un login fallido y de otra
     * forma pasaría por bueno.
     *
     * La cookie que importa es **PHPSESSID**: es la que autoriza la
     * superficie `a_*.php`. `_jwt_panel` puede venir o no según la versión
     * desplegada y ya no se exige — exigirla rompía el login contra el
     * deploy viejo, que es justamente el que hay que migrar.
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
            'application/x-www-form-urlencoded',
            true
        );

        if ($res['error'] !== '') {
            throw new EncomMigrationException('No se pudo contactar al panel legacy: ' . $res['error'], 502);
        }

        $body = strtolower(trim((string) $res['body']));

        if ($body !== 'true' || !isset($client->cookies['PHPSESSID'])) {
            throw new EncomMigrationException(
                'El panel legacy rechazó las credenciales. Verificá el teléfono (con código de país) y la contraseña.',
                401
            );
        }

        return $client;
    }

    /** @param array<string,string> $cookies */
    public static function fromCookies(string $baseUrl, array $cookies): self
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
        return new self(rtrim(trim($baseUrl), '/'), $clean);
    }

    public function cookies(): array
    {
        return $this->cookies;
    }

    // ═══════════════════════════════════════════════════════════════════
    // EncomSource
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Configuración de la empresa.
     *
     * NO hay ningún `action=` que la devuelva en JSON: hay que pedir
     * `a_settings` entero y leer los `value` de sus inputs por `name`. Es la
     * página más pesada de las tres, pero el mapeo name→campo es estable.
     */
    public function settings(): array
    {
        $form = EncomParse::formValues($this->get('/a_settings'));

        return [
            'billingName' => $form['billingName'] ?? '',
            'tin'         => $form['ruc'] ?? '',
            'address'     => $form['address'] ?? '',
            'email'       => $form['email'] ?? '',
            'phone'       => $form['phone'] ?? '',
            'city'        => $form['city'] ?? '',
            'country'     => $form['country'] ?? '',
            'currency'    => $form['currency'] ?? '',
            'taxName'     => $form['taxName'] ?? '',
            'timeZone'    => $form['timeZone'] ?? '',
            'tinName'     => $form['tin'] ?? '',
        ];
    }

    /**
     * Sucursales. `a_outlets?showTable=true` devuelve `{"table": "<html>"}` y
     * —clave— filtra SOLO por empresa: trae todas, sin importar cuál esté
     * activa en la sesión.
     *
     * El listado trae poco (nombre, razón social, RUC, teléfono, dirección),
     * así que por cada sucursal se pide además su `action=edit`, que es el
     * form completo. Es una request más por sucursal, y son pocas.
     */
    public function outlets(): array
    {
        $table   = EncomParse::htmlTable(EncomParse::tableHtml($this->get('/a_outlets', ['showTable' => 'true'])));
        $headers = $table['headers'];

        // Por encabezado, igual que las cajas. La columna del documento
        // fiscal es `TIN_NAME` (dice "RUC" en PY y otra cosa en otro país),
        // así que esa se resuelve por posición conocida.
        $cName    = EncomParse::columnIndex($headers, ['NOMBRE']) ?? 0;
        $cBilling = EncomParse::columnIndex($headers, ['RAZON']) ?? 1;
        $cPhone   = EncomParse::columnIndex($headers, ['TELEFONO']) ?? 3;
        $cAddress = EncomParse::columnIndex($headers, ['DIRECCION']) ?? 4;

        $out = [];
        foreach (array_slice($table['rows'], 0, self::MAX_ENTITIES) as $row) {
            $cells = $row['cells'];

            $outlet = [
                'ID'          => $row['id'],
                'name'        => trim((string) ($cells[$cName] ?? '')),
                'billingName' => trim((string) ($cells[$cBilling] ?? '')),
                'tin'         => trim((string) ($cells[2] ?? '')),
                'phone'       => trim((string) ($cells[$cPhone] ?? '')),
                'address'     => trim((string) ($cells[$cAddress] ?? '')),
            ];

            // El form trae lo que la tabla no: email, descripción, lat/lng.
            $form = EncomParse::formValues($this->get('/a_outlets', ['action' => 'edit', 'id' => $row['id']]));
            if ($form !== []) {
                $outlet['name']        = $form['name'] ?: $outlet['name'];
                $outlet['address']     = $form['address'] ?? $outlet['address'];
                $outlet['phone']       = $form['phone'] ?? $outlet['phone'];
                $outlet['email']       = $form['email'] ?? '';
                $outlet['billingName'] = $form['billingName'] ?? $outlet['billingName'];
                $outlet['tin']         = $form['ruc'] ?? $outlet['tin'];
                $outlet['description'] = $form['description'] ?? '';

                // `latLng` viaja como "lat,lng" en un solo campo.
                $latLng = trim((string) ($form['latLng'] ?? ''));
                if (str_contains($latLng, ',')) {
                    [$lat, $lng] = array_map('trim', explode(',', $latLng, 2));
                    $outlet['lat'] = $lat;
                    $outlet['lng'] = $lng;
                }
            }

            $out[] = $outlet;
        }

        return $out;
    }

    /**
     * Cajas con su numeración fiscal, de TODAS las sucursales.
     *
     * ── El problema y cómo se resuelve ───────────────────────────────────
     * `a_registers?list=true` corre `... WHERE <roc>`, donde `<roc>` es
     * `getROC(1)` = "empresa + la sucursal ACTIVA de la sesión", y ese archivo
     * NO lee ningún parámetro de sucursal del request. O sea: por sí solo
     * solo puede ver las cajas de una sucursal.
     *
     * La salida es un switch GLOBAL que procesa `includes/functions.php` en
     * CUALQUIER página del panel: `?o=<outletId>` escribe la sucursal activa
     * en la sesión. Dos detalles que obligan a hacerlo en dos requests:
     *
     *   1. el switch responde con `header('location: …')` **sin el query
     *      string**, así que `?o=X&list=true` perdería el `list`;
     *   2. la constante `OUTLET_ID` se define ANTES de que el switch corra,
     *      así que el cambio recién se ve en el request SIGUIENTE.
     *
     * Por eso: por cada sucursal, un request que cambia la sucursal activa
     * (se ignora el cuerpo) y otro que pide el listado.
     *
     * El listado tampoco trae el vencimiento del timbrado ni la numeración
     * máxima, así que por cada caja se pide su `action=edit`.
     */
    public function registers(): array
    {
        // Indexado por id del legacy, NO una lista: ver la deduplicación de
        // más abajo.
        $out         = [];
        $outletNames = $this->outletNamesById();

        foreach (array_keys($outletNames) as $outletId) {
            // (1) Fijar la sucursal activa. Responde 302 a propósito —
            // `allowRedirect` evita que se lea como "sesión caída".
            $this->get('/a_registers', ['o' => $outletId], true);

            // (2) Ahora sí, el listado. OJO: viene SIN el wrapper `{"table":…}`
            // (a diferencia del de sucursales); `tableHtml()` tolera las dos.
            $table   = EncomParse::htmlTable(EncomParse::tableHtml($this->get('/a_registers', ['list' => 'true'])));
            $headers = $table['headers'];

            // Columnas POR ENCABEZADO. El sistema vivo tiene una columna
            // `Sucursal` que el snapshot no tiene, así que por índice fijo el
            // nombre de la sucursal se leería como TIMBRADO.
            $cName   = EncomParse::columnIndex($headers, ['NOMBRE']) ?? 0;
            $cOutlet = EncomParse::columnIndex($headers, ['SUCURSAL']);
            $cAuth   = EncomParse::columnIndex($headers, ['TIMBRADO', 'AUTORIZACION']) ?? 2;
            $cPrefix = EncomParse::columnIndex($headers, ['PREFIJO']) ?? 3;
            $cNumber = EncomParse::columnIndex($headers, ['FACTURA']) ?? 4;
            $cSufix  = EncomParse::columnIndex($headers, ['SUFIJO']) ?? 5;

            foreach (array_slice($table['rows'], 0, self::MAX_ENTITIES) as $row) {
                // ── Deduplicación ────────────────────────────────────────
                // No está garantizado que el listado esté acotado a la
                // sucursal activa: el vivo trae una columna `Sucursal`, que es
                // justamente lo que tendría una lista que abarca varias. Si
                // fuera así, el recorrido devolvería cada caja una vez POR
                // SUCURSAL, y dos copias de la misma caja se verían como dos
                // cajas con el mismo (timbrado, punto) — o sea, el import
                // abortaría por un choque que no existe.
                if (isset($out[$row['id']])) {
                    continue;
                }

                $cells = $row['cells'];

                // El número viene pasado por `leadingZeros()` ("0006848"), así
                // que esa cadena da el correlativo Y el ancho de impresión.
                $paddedNo = preg_replace('/\D/', '', (string) ($cells[$cNumber] ?? '')) ?? '';

                $reg = [
                    'ID'             => $row['id'],
                    'outletLegacyId' => $outletId,
                    'name'           => trim((string) ($cells[$cName] ?? '')),
                    'invoiceAuth'    => preg_replace('/\D/', '', (string) ($cells[$cAuth] ?? '')) ?? '',
                    'prefix'         => self::normalizePrefix((string) ($cells[$cPrefix] ?? '')),
                    'sufix'          => trim((string) ($cells[$cSufix] ?? '')),
                    'invoiceNo'      => $paddedNo === '' ? 0 : (int) $paddedNo,
                    'docsZeros'      => $paddedNo === '' ? null : strlen($paddedNo),
                ];

                // Si el listado dice a qué sucursal pertenece, ESO manda sobre
                // la sucursal que se activó para pedirlo.
                if ($cOutlet !== null) {
                    $byName = $this->outletIdByName($outletNames, (string) ($cells[$cOutlet] ?? ''));
                    if ($byName !== null) {
                        $reg['outletLegacyId'] = $byName;
                    }
                }

                $out[$row['id']] = $this->enrichRegisterFromForm($reg);
            }
        }

        return array_values($out);
    }

    /**
     * Completa la caja con el form de `?action=edit`, que es el único lugar
     * donde están el vencimiento del timbrado y la numeración máxima.
     *
     * **Falla fuerte, nunca en silencio**: si el legacy devolvió un form pero
     * no trae NINGUNO de los campos esperados, significa que los `name`
     * cambiaron y que el timbrado que se está por importar no es confiable.
     * Importar igual dejaría una caja fiscal con datos de una tabla que
     * tampoco sabemos leer. Preferimos que el dominio falle con un mensaje
     * que diga qué caja y qué pasó.
     */
    private function enrichRegisterFromForm(array $reg): array
    {
        $body = $this->get('/a_registers', ['action' => 'edit', 'id' => $reg['ID']]);

        // Sin cuerpo no hay form que leer (el legacy puede no exponerlo para
        // esa caja). Se sigue con lo que dio el listado, que ya trae timbrado,
        // punto y número.
        if (trim($body) === '') {
            return $reg;
        }

        $form = EncomParse::formValues($body);

        $esperados = ['auth', 'prefix', 'invoice', 'expiration', 'leadingZero', 'name'];
        if (array_intersect($esperados, array_keys($form)) === []) {
            throw new EncomMigrationException(
                'El formulario de la caja "' . $reg['name'] . '" no trae ninguno de los campos de timbrado '
                . 'esperados (auth/prefix/invoice/expiration). El panel legacy cambió sus campos: no se importa '
                . 'ninguna caja para no cargar una numeración fiscal incorrecta.',
                502
            );
        }

        $reg['name']        = trim((string) ($form['name'] ?? '')) ?: $reg['name'];
        $reg['invoiceAuth'] = (preg_replace('/\D/', '', (string) ($form['auth'] ?? '')) ?: '') ?: $reg['invoiceAuth'];
        $reg['prefix']      = self::normalizePrefix((string) ($form['prefix'] ?? '')) ?: $reg['prefix'];
        $reg['sufix']       = trim((string) ($form['sufix'] ?? ''));

        // Estos dos SOLO existen en el form.
        $reg['invoiceAuthExp'] = trim((string) ($form['expiration'] ?? ''));
        $reg['invoiceNoMax']   = trim((string) ($form['registerInvoiceNoMax'] ?? ''));

        if (trim((string) ($form['invoice'] ?? '')) !== '') {
            $reg['invoiceNo'] = (int) (preg_replace('/\D/', '', (string) $form['invoice']) ?: '0');
        }
        if (trim((string) ($form['leadingZero'] ?? '')) !== '') {
            $reg['docsZeros'] = (int) $form['leadingZero'];
        }

        return $reg;
    }

    /**
     * Normaliza el punto de expedición a `EEE-PPP`.
     *
     * El sistema vivo lo muestra con un GUIÓN FINAL (`009-001-`), que es como
     * se arma el número completo al imprimirlo. Punto valida contra
     * `^\d{3}-\d{3}$`, así que sin esto TODAS las cajas serían rechazadas por
     * formato y el dominio abortaría entero.
     */
    private static function normalizePrefix(string $raw): string
    {
        return trim(trim($raw), '-');
    }

    /** Nombre de cada sucursal por su id, para el recorrido y el match. */
    private function outletNamesById(): array
    {
        $table   = EncomParse::htmlTable(EncomParse::tableHtml($this->get('/a_outlets', ['showTable' => 'true'])));
        $cName   = EncomParse::columnIndex($table['headers'], ['NOMBRE']) ?? 0;

        $out = [];
        foreach (array_slice($table['rows'], 0, self::MAX_ENTITIES) as $row) {
            $out[$row['id']] = trim((string) ($row['cells'][$cName] ?? ''));
        }
        return $out;
    }

    /** Id de la sucursal cuyo nombre coincide, o null. */
    private function outletIdByName(array $outletNames, string $name): ?string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        if ($name === '') {
            return null;
        }
        foreach ($outletNames as $id => $outletName) {
            if (mb_strtolower(trim($outletName), 'UTF-8') === $name) {
                return (string) $id;
            }
        }
        return null;
    }

    /**
     * Artículos.
     *
     * Se pide `format=json` primero: el snapshot tiene un modo que devuelve
     * los valores crudos y evita parsear nada. El deploy vivo es más viejo y
     * puede ignorarlo y contestar la tabla igual, así que hay fallback al
     * HTML. Los dos caminos producen el MISMO shape hacia arriba.
     */
    public function items(): array
    {
        if ($this->itemsCache !== null) {
            return $this->itemsCache;
        }

        $body = $this->get('/a_items', ['action' => 'showTable', 'format' => 'json']);

        $json = json_decode($body, true);
        if (is_array($json) && is_array($json['data']['items'] ?? null)) {
            $rows = [];
            foreach ($json['data']['items'] as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $rows[] = [
                    'ID'          => (string) ($it['itemId'] ?? ''),
                    'name'        => (string) ($it['name'] ?? ''),
                    'sku'         => (string) ($it['sku'] ?? ''),
                    'uom'         => (string) ($it['uom'] ?? ''),
                    'brand'       => (string) ($it['brand'] ?? ''),
                    'category'    => (string) ($it['category'] ?? ''),
                    'cost'        => $it['cogs'] ?? null,
                    'price'       => $it['priceStored'] ?? ($it['price'] ?? null),
                    'discount'    => $it['discount'] ?? 0,
                    'type'        => (string) ($it['type'] ?? ''),
                    'canSell'     => $it['canSell'] ?? 1,
                    'trackStock'  => $it['trackInventory'] ?? 1,
                ];
            }
            return $this->itemsCache = $rows;
        }

        // Fallback: la tabla HTML. Orden de columnas fijado por el legacy —
        // 0 imagen · 1 nombre · 2 tipo · 3 fecha · 4 UOM · 5 SKU · 6 marca ·
        // 7 categoría · 8 sucursal · 9 sesiones · 10 duración · 11 merma ·
        // 12 comisión · 13 descuento · 14 costo · 15 precio · 16 valor ·
        // 17 impuesto · 18 stock · 19 online.
        $rows = EncomParse::htmlRows(EncomParse::tableHtml($body));

        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $out[] = [
                'ID'         => $row['id'],
                'name'       => $c[1] ?? '',
                'sku'        => ($c[5] ?? '') === '-' ? '' : ($c[5] ?? ''),
                'uom'        => ($c[4] ?? '') === '-' ? '' : ($c[4] ?? ''),
                'brand'      => $c[6] ?? '',
                'category'   => $c[7] ?? '',
                'discount'   => $c[13] ?? 0,
                'cost'       => $c[14] ?? null,
                'price'      => $c[15] ?? null,
                'type'       => $c[2] ?? '',
                'canSell'    => 1,
                'trackStock' => 1,
            ];
        }

        return $this->itemsCache = $out;
    }

    /**
     * Categorías — DERIVADAS de los artículos, no de un endpoint propio.
     *
     * El export de artículos trae la categoría por NOMBRE, no por id (tanto
     * en JSON como en HTML), así que un listado de categorías con ids no
     * serviría para vincular: habría que casar por nombre igual. Se deriva el
     * conjunto de nombres distintos y el nombre ES la clave natural.
     */
    public function categories(): array
    {
        return $this->distinctNames('category');
    }

    /** Marcas — derivadas de los artículos, misma razón que las categorías. */
    public function brands(): array
    {
        return $this->distinctNames('brand');
    }

    /**
     * Etiquetas — NO se migran en F1.
     *
     * La superficie viva no expone las etiquetas de un artículo por ninguna
     * de las dos vías (la tabla no tiene columna y el JSON no trae el campo).
     * Devolver vacío es honesto; inventar etiquetas a partir de otra cosa,
     * no. Queda anotado en context/77 §8.
     */
    public function tags(): array
    {
        return [];
    }

    /**
     * Clientes, del CSV de `a_contacts?action=download`.
     *
     * El CSV NO trae id, así que el mapa de idempotencia usa una clave
     * natural (documento, o el nombre normalizado) — ver
     * `EncomImportService::customerKey()`.
     *
     * La columna ROL separa Cliente / Proveedor / nombre-de-rol (el personal
     * del comercio). Acá se filtra a Cliente: proveedores y usuarios quedan
     * fuera de F1 por D5.
     */
    public function customers(): array
    {
        $rows = EncomParse::csvRows($this->get('/a_contacts', ['action' => 'download']));

        $out = [];
        foreach ($rows as $row) {
            if (strcasecmp(trim((string) ($row['ROL'] ?? '')), 'Cliente') !== 0) {
                continue;
            }

            $fiscalName = trim((string) ($row['RAZON SOCIAL'] ?? ''));
            $personName = trim((string) ($row['NOMBRE Y APELLIDO'] ?? ''));

            $out[] = [
                'fiscalName' => $fiscalName,
                'name'       => $personName,
                'tin'        => EncomParse::tinOf($row),
                'phone'      => trim((string) ($row['TELEFONO'] ?? '')),
                'email'      => trim((string) ($row['EMAIL'] ?? '')),
                'address'    => trim((string) ($row['DIRECCION'] ?? '')),
                'address2'   => trim((string) ($row['DIRECCION 2'] ?? '')),
                'note'       => trim((string) ($row['NOTA'] ?? '')),
            ];
        }

        return $out;
    }

    /** Medios de pago — sin fuente en la superficie viva. Ver context/77 §8. */
    public function banks(): array
    {
        return [];
    }

    /**
     * Ventas de un rango — PREPARADO PARA F2, no se usa en F1.
     *
     * `a_report_transactions?action=detailTable` devuelve la tabla con los
     * valores crudos en `data-order` y el id de la venta en `data-id`. El
     * detalle con los ítems de una venta es `?action=edit&id=<id>`, que
     * devuelve el form — lo que F2 va a necesitar.
     *
     * Queda acá para que F2 no tenga que redescubrir la superficie, pero
     * NINGÚN dominio de F1 lo llama: importar una venta histórica necesita
     * decisiones que no están tomadas (context/77 §8).
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
    // Internos
    // ═══════════════════════════════════════════════════════════════════
    /** Nombres distintos de un campo de los artículos, como filas `{ID,name}`. */
    private function distinctNames(string $field): array
    {
        $seen = [];
        foreach ($this->items() as $item) {
            $name = trim((string) ($item[$field] ?? ''));
            // El legacy pinta "-" cuando el artículo no tiene marca/categoría.
            if ($name === '' || $name === '-') {
                continue;
            }
            $seen[mb_strtolower($name, 'UTF-8')] = $name;
        }

        $out = [];
        foreach ($seen as $name) {
            // El nombre ES el id: no hay otro identificador del otro lado.
            $out[] = ['ID' => $name, 'name' => $name];
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Transporte
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET con pacing y UN reintento transitorio.
     *
     * `$allowRedirect` existe para el switch de sucursal, que contesta 302 a
     * propósito. En cualquier otra llamada un 302 ES la sesión caída (el
     * legacy redirige al login) y se traduce a un error accionable.
     *
     * ── `protected` a propósito: es LA costura del diseño ────────────────
     * Todo lo de arriba —qué `action` se pide, el orden de las columnas de
     * cada tabla, el recorrido de sucursales— es la parte que se puede
     * equivocar, y no se puede probar contra el legacy real. Con este único
     * método sobreescribible, el arnés sirve los payloads CRUDOS que devuelve
     * el sistema vivo y ejercita el mapeo de verdad, no una copia paralela
     * que se desincroniza. Es el motivo por el que la clase no es `final`.
     */
    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        for ($try = 0; ; $try++) {
            $this->pace();

            $qs  = $params !== [] ? (str_contains($path, '?') ? '&' : '?') . http_build_query($params) : '';
            $res = $this->raw('GET', $path . $qs, null, null, $allowRedirect);

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
     * curl crudo. Acumula las cookies que el legacy devuelve y reenvía las
     * que ya tiene. No se usa `CURLOPT_COOKIEJAR`: eso escribiría la sesión
     * viva de un cliente a un archivo en el disco del servidor.
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

        $headers = ['Accept: text/html, application/json, text/csv'];
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
            // 200 con el HTML del login, que el parser leería como una tabla
            // vacía — "cero cajas" en vez de "la sesión se cayó".
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

        if (($status === 301 || $status === 302) && !$allowRedirect) {
            return ['status' => 401, 'body' => null, 'error' => ''];
        }

        return [
            'status' => $status,
            'body'   => is_string($resBody) ? $resBody : null,
            'error'  => $err,
        ];
    }

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

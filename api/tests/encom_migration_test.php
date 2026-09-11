<?php
declare(strict_types=1);

/**
 * Arnés del migrador ENCOM → Punto (context/77).
 *
 * Corre contra Postgres REAL (descartable, lo levanta run_encom_migration_test.sh),
 * contra los SERVICIOS REALES de import y contra el CLIENTE REAL del legacy.
 * Lo único que se reemplaza es el TRANSPORTE: `FixtureEncomClient` sobreescribe
 * `fetch()` y sirve los payloads de `/fetchs` con el shape EXACTO que devuelve
 * el sistema vivo (`api/tests/fixtures/encom/fetchs-*.json`).
 *
 * Por qué así y no con un `EncomSource` de JSON ya normalizado: lo que más se
 * puede equivocar es justamente el mapeo —de qué campo sale cada dato, cómo se
 * junta `registers` con `docsNum`, cómo se lee el `compound` inline— y un
 * fixture normalizado lo saltea entero. Acá se ejercita.
 *
 * Casos:
 *   S. ALCANCE — companyId/outletId salen del `?i=` en base64, por el redirect
 *      y por el fallback del home; si no sale por ninguna vía, LANZA.
 *   L. LOGIN — los `name` del form del deploy vivo (`email`/`password`).
 *   X. EXPORT — el mapeo de cada dominio de `/fetchs`.
 *   A. Import completo — conteos por dominio y entidades realmente creadas.
 *   R. COMBOS Y RECETAS — la composición inline se resuelve por el mapa; lo que
 *      no mapea limpio NO se inventa y queda anotado para revisar.
 *   C. MAPEO — `migration_map`, y el artículo apunta a la categoría importada.
 *   D. CONTINUACIÓN DE NUMERACIÓN (D5) — `document_sequence` con el timbrado y
 *      el punto del legacy y `nextnumber` = último emitido + 1, por doctype.
 *   U. USUARIOS — PIN, sucursal y el rol de Punto asignado por nombre.
 *   P. MEDIOS DE PAGO — se suman los del legacy sin duplicar los que ya existen.
 *   F. La caja placeholder de la sucursal se REUSA (no quedan fantasmas).
 *   B. IDEMPOTENCIA — re-correr no duplica NADA, recetas incluidas.
 *   E. RECHAZO por punto de expedición duplicado — aborta el dominio SIN
 *      importar ninguna caja.
 *   Z. Barrido de credenciales huérfanas (TTL 24 h).
 */

require_once __DIR__ . '/_harness.php';

$companyId = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d1122';
$companyB  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d3344';

define('COMPANY_ID', $companyId);
define('OUTLET_ID', '');
define('USER_ID', '');
define('REGISTER_ID', '');
define('ROLE_ID', '');
define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomClient.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomImportService.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomMigrationService.php';

use Punto\Api\Admin\EncomClient;
use Punto\Api\Admin\EncomImportService;
use Punto\Api\Admin\EncomMigrationService;

global $db;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

/**
 * Cliente real del legacy con el transporte cambiado por fixtures.
 *
 * Sobreescribe SOLO `fetch()`: todo lo de arriba —el mapeo campo a campo, la
 * unión de `registers` con `docsNum`, la derivación de categorías y marcas, la
 * lectura tolerante de `tags` y `paymentMethods`— es el código de producción.
 */
final class FixtureEncomClient extends EncomClient
{
    /** @var array<int,string> `load` pedidos, en orden. */
    public array $calls = [];

    public function __construct(private readonly string $dir)
    {
        // El par que devolvió el sistema vivo en el relevamiento.
        parent::__construct('https://legacy.test', ['PHPSESSID' => 'fixture'], 'QE22', '62Lm');
    }

    protected function fetch(string $load): array
    {
        $this->calls[] = $load;

        $file = $this->dir . '/fetchs-' . $load . '.json';
        $raw  = is_file($file) ? (string) file_get_contents($file) : '[]';
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }
}

/** Variante del caso E: dos cajas con el mismo (timbrado, punto). */
final class ClashEncomClient extends EncomClient
{
    public function __construct()
    {
        parent::__construct('https://legacy.test', ['PHPSESSID' => 'fixture'], 'QE22', '62Lm');
    }

    protected function fetch(string $load): array
    {
        if ($load === 'outlets') {
            return [[
                'outletId'    => 'out-1',
                'name'        => 'Casa Central',
                'outletRazon' => 'Con Choque SA',
            ]];
        }
        if ($load === 'registers') {
            return [
                'registers' => [
                    [
                        'registerId'    => 'dup-1',
                        'name'          => 'Caja Uno',
                        'outletId'      => 'out-1',
                        'invoicePrefix' => '001-001-',
                        'invoiceAuthNo' => '16543210',
                        'leadingZero'   => 7,
                    ],
                    [
                        'registerId'    => 'dup-2',
                        'name'          => 'Caja Dos',
                        'outletId'      => 'out-1',
                        'invoicePrefix' => '001-001-',
                        'invoiceAuthNo' => '16543210',
                        'leadingZero'   => 7,
                    ],
                ],
                'docsNum' => [
                    ['registerId' => 'dup-1', 'invoiceNo' => 100],
                    ['registerId' => 'dup-2', 'invoiceNo' => 250],
                ],
            ];
        }
        return [];
    }
}

/**
 * Sonda del ALCANCE: de dónde salen companyId y outletId del legacy.
 *
 * Es la pieza que, si se rompe, importa el comercio EQUIVOCADO — o ninguno. No
 * se puede probar contra el sistema real, así que se simulan las dos vías: el
 * header `Location` del redirect de `pos-redirect` y el HTML del home del panel.
 */
final class ScopeProbeClient extends EncomClient
{
    public function __construct(
        private readonly ?string $location,
        private readonly string $body,
    ) {
        parent::__construct('https://legacy.test', ['PHPSESSID' => 'fixture']);
    }

    protected function lastLocation(): ?string
    {
        return $this->location;
    }

    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        return $this->body;
    }

    /** @return array{companyId:string,outletId:string} */
    public function resolveNow(): array
    {
        $this->resolveScope();
        return $this->scope();
    }
}

/**
 * Sonda del cuerpo del LOGIN.
 *
 * El login no se puede ejercitar entero sin red, pero lo que se rompió —y de
 * forma invisible desde este lado, porque el legacy contesta 200 igual— fueron
 * los `name` del form: se mandaba `phone`/`iso`, que el deploy VIVO no tiene.
 */
final class LoginBodyProbe extends EncomClient
{
    public static function body(string $identifier, string $password): string
    {
        return parent::loginBody($identifier, $password);
    }
}

function seedCompany(string $companyId, string $name): void
{
    global $db;
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
         ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config",
        [
            $companyId,
            json_encode([
                'settingName'              => $name,
                'settingCountry'           => 'PY',
                'settingCurrency'          => 'PYG',
                'settingTimeZone'          => 'America/Asuncion',
                'settingDecimal'           => 0,
                'settingThousandSeparator' => '.',
            ], JSON_UNESCAPED_UNICODE),
        ]
    );
}

function scalar(string $sql, array $params): mixed
{
    global $db;
    return $db->GetOne($sql, $params);
}

function countOf(string $table, string $companyId): int
{
    return (int) scalar("SELECT count(*) FROM $table WHERE companyId = ?", [$companyId]);
}

function cleanup(string $companyId): void
{
    global $db;
    foreach ([
        'DELETE FROM migration_map WHERE companyid = ?',
        'DELETE FROM migration_job WHERE companyid = ?',
        'DELETE FROM document_sequence WHERE companyid = ?',
        'DELETE FROM item_compound WHERE companyId = ?',
        'DELETE FROM item_category WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item_brand    WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item_tag      WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item_outlet   WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item WHERE companyId = ?',
        'DELETE FROM tax WHERE companyId = ?',
        'DELETE FROM category WHERE companyId = ?',
        'DELETE FROM brand WHERE companyId = ?',
        'DELETE FROM tag WHERE companyId = ?',
        // Usuarios: `contact_outlet` cuelga del contacto, y los roles del
        // tenant viven en `taxonomy` (los borra la línea de más abajo).
        'DELETE FROM contact_outlet WHERE companyid = ?',
        // `customeraddress` cuelga de `contact` con FK dura.
        'DELETE FROM customeraddress WHERE customerId IN (SELECT contactId FROM contact WHERE companyId = ?)',
        'DELETE FROM contact WHERE companyId = ?',
        'DELETE FROM register WHERE companyId = ?',
        // Los roles del tenant NO tienen tabla propia: viven en `taxonomy`
        // (type='role') y sus permisos en el JSONB de esa misma fila, así que
        // la línea de abajo se los lleva.
        'DELETE FROM taxonomy WHERE companyId = ?',
        'DELETE FROM outlet WHERE companyId = ?',
        'DELETE FROM company WHERE companyId = ?',
    ] as $sql) {
        try {
            $db->Execute($sql, [$companyId]);
        } catch (\Throwable $e) {
            // Una tabla ausente en este schema no invalida el cleanup.
        }
    }
}

$fixtures = __DIR__ . '/fixtures/encom';

cleanup($companyId);
cleanup($companyB);

try {
    // ══════════════════════════════════════════════════════════════════
    // S. ALCANCE — de dónde salen companyId y outletId
    // ══════════════════════════════════════════════════════════════════
    // Literal tomado del sistema vivo: base64('PnXa,KLzV').
    $iParam = 'UG5YYSxLTHpW';

    $porRedirect = (new ScopeProbeClient('https://app.encom.com.py/?i=' . $iParam, ''))->resolveNow();

    check(
        'S1 · el alcance sale del ?i= del redirect de pos-redirect (base64 → "companyId,outletId")',
        $porRedirect === ['companyId' => 'PnXa', 'outletId' => 'KLzV'],
        'scope = ' . json_encode($porRedirect),
        $failures, $checks
    );

    // Fallback: el deploy viejo no tiene /bff/pos-redirect.php, así que el
    // mismo `?i=` se busca en el href del botón "Caja" del home del panel.
    $homeHtml = '<ul><li><a href="/a_items">Artículos</a></li>'
        . '<li><a id="mnPOSBtn" href="https://app.encom.com.py/?i=' . $iParam . '">Caja</a></li></ul>';

    $porHome = (new ScopeProbeClient(null, $homeHtml))->resolveNow();

    check(
        'S2 · sin pos-redirect, el alcance sale del href del botón "Caja" del panel',
        $porHome === ['companyId' => 'PnXa', 'outletId' => 'KLzV'],
        'scope = ' . json_encode($porHome),
        $failures, $checks
    );

    $scopeErr = '';
    try {
        (new ScopeProbeClient(null, '<html><body>nada que ver</body></html>'))->resolveNow();
    } catch (\Throwable $e) {
        $scopeErr = $e->getMessage();
    }

    check(
        'S3 · si NINGUNA vía da el alcance, LANZA (no exporta el comercio equivocado)',
        $scopeErr !== '' && str_contains($scopeErr, 'comercio'),
        'mensaje = ' . var_export($scopeErr, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // L. LOGIN — los campos del form del deploy VIVO
    // ══════════════════════════════════════════════════════════════════
    parse_str(LoginBodyProbe::body('cliente@example.com', 'secreta 1'), $loginFields);

    check(
        'L1 · el login manda email+password, NUNCA phone/iso',
        array_keys($loginFields) === ['email', 'password']
            && ($loginFields['password'] ?? '') === 'secreta 1',
        'campos = ' . json_encode($loginFields, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    parse_str(LoginBodyProbe::body('0981 123456', 'x'), $phoneFields);

    check(
        'L2 · el identificador viaja TAL CUAL (un celular no se pasa a E.164)',
        ($phoneFields['email'] ?? '') === '0981 123456',
        'email = ' . var_export($phoneFields['email'] ?? null, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // X. EXPORT — el mapeo de cada dominio de /fetchs
    // ══════════════════════════════════════════════════════════════════
    $client = new FixtureEncomClient($fixtures);

    $items = $client->items();
    check(
        'X1 · se leen los 9 artículos con su kind del legacy',
        count($items) === 9 && ($items[0]['name'] ?? '') === 'Café Espresso',
        'items = ' . json_encode(array_column($items, 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'X2 · la composición inline viaja cruda para la segunda pasada',
        str_contains((string) ($items[3]['compound'] ?? ''), 'itm-5')
            && ($items[3]['kind'] ?? '') === 'combo',
        'compound = ' . var_export($items[3]['compound'] ?? null, true),
        $failures, $checks
    );

    $cats = $client->categories();
    check(
        'X3 · las categorías se derivan de los artículos por categoryId y "-" no es categoría',
        count($cats) === 4,
        'categorías = ' . json_encode(array_column($cats, 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $regs = $client->registers();
    check(
        'X4 · registers junta cada caja con SU último correlativo de docsNum',
        count($regs) === 3
            && ($regs[0]['invoiceNo'] ?? 0) === 2128
            && ($regs[0]['quoteNo'] ?? 0) === 15
            && ($regs[0]['returnNo'] ?? 0) === 3,
        'caja = ' . json_encode($regs[0] ?? null, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'X5 · el punto de expedición se normaliza: "001-001-" → "001-001"',
        ($regs[0]['prefix'] ?? '') === '001-001' && ($regs[0]['invoiceAuth'] ?? '') === '16543210',
        'prefix = ' . var_export($regs[0]['prefix'] ?? null, true),
        $failures, $checks
    );

    $usuarios = $client->users();
    check(
        'X6 · los usuarios traen su PIN y el nombre de su rol legacy',
        count($usuarios) === 3
            && ($usuarios[0]['lockPass'] ?? '') === '1234'
            && ($usuarios[0]['roleName'] ?? '') === 'Administrador',
        'usuarios = ' . json_encode($usuarios[0] ?? null, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $pms = $client->paymentMethods();
    check(
        'X7 · los medios de pago salen de settings.paymentMethods',
        count($pms) === 3 && ($pms[1]['name'] ?? '') === 'Transferencia',
        'medios = ' . json_encode(array_column($pms, 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'X8 · las etiquetas salen de settings.tags (lista de nombres)',
        count($client->tags()) === 2,
        'tags = ' . json_encode(array_column($client->tags(), 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $clientes = $client->customers();
    check(
        'X9 · el cliente trae id propio, documento, saldo a favor y línea de crédito',
        ($clientes[0]['ID'] ?? '') === 'cus-1'
            && ($clientes[0]['creditLine'] ?? 0) == 1000000
            && ($clientes[0]['storeCredit'] ?? 0) == 50000,
        'cliente = ' . json_encode($clientes[0] ?? null, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // A. Import completo
    // ══════════════════════════════════════════════════════════════════
    seedCompany($companyId, 'Comercio Migrado SA');

    $run1 = (new EncomImportService($companyId, new FixtureEncomClient($fixtures), null))
        ->run(['catalog', 'customers', 'config', 'users', 'payments']);

    $p1 = $run1['progress'];

    check(
        'A1 · no hubo errores en el import',
        $run1['errors'] === [],
        'errores: ' . json_encode($run1['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'A2 · categorías (4), marcas (1) y etiquetas (2)',
        ($p1['category']['imported'] ?? 0) === 4
            && ($p1['brand']['imported'] ?? 0) === 1
            && ($p1['tag']['imported'] ?? 0) === 2,
        'progress = ' . json_encode([$p1['category'] ?? null, $p1['brand'] ?? null, $p1['tag'] ?? null]),
        $failures, $checks
    );

    check(
        'A3 · artículos importados (9)',
        ($p1['item']['imported'] ?? 0) === 9,
        'progress.item = ' . json_encode($p1['item'] ?? null),
        $failures, $checks
    );

    check(
        'A4 · clientes (2), sucursales (2) y cajas (3)',
        ($p1['customer']['imported'] ?? 0) === 2
            && ($p1['outlet']['imported'] ?? 0) === 2
            && ($p1['register']['imported'] ?? 0) === 3,
        'progress = ' . json_encode([$p1['customer'] ?? null, $p1['outlet'] ?? null, $p1['register'] ?? null]),
        $failures, $checks
    );

    check(
        'A5 · usuarios (3) y medios de pago (3)',
        ($p1['user']['imported'] ?? 0) === 3 && ($p1['payment']['imported'] ?? 0) === 3,
        'progress = ' . json_encode([$p1['user'] ?? null, $p1['payment'] ?? null]),
        $failures, $checks
    );

    $itemsInDb = countOf('item', $companyId);
    check(
        'A6 · los artículos están en la base (9)',
        $itemsInDb === 9,
        "item count = $itemsInDb",
        $failures, $checks
    );

    $contactsInDb = (int) scalar('SELECT count(*) FROM contact WHERE companyId = ? AND type = 1', [$companyId]);
    check(
        'A7 · los clientes están en la base (2)',
        $contactsInDb === 2,
        "contact count = $contactsInDb",
        $failures, $checks
    );

    // El IVA del legacy ("10") es el `name` de la tabla `tax` de Punto.
    $itm1 = EncomMigrationService::mapped($companyId, 'item', 'itm-1');
    $taxOfItem = $itm1 === null ? null : scalar(
        'SELECT t.name FROM item i JOIN tax t ON t.taxId = i.taxId WHERE i.itemId = ?',
        [$itm1]
    );
    check(
        'A8 · el artículo queda con el impuesto del legacy (10)',
        (string) $taxOfItem === '10',
        'tax.name = ' . var_export($taxOfItem, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // R. COMBOS Y RECETAS — la novedad de /fetchs
    // ══════════════════════════════════════════════════════════════════
    check(
        'R1 · se compusieron 2 artículos (el combo fijo y la receta), 3 quedaron sin componer',
        ($p1['compound']['total'] ?? 0) === 5
            && ($p1['compound']['imported'] ?? 0) === 2
            && ($p1['compound']['failed'] ?? 0) === 3,
        'progress.compound = ' . json_encode($p1['compound'] ?? null),
        $failures, $checks
    );

    $compoundRows = (int) scalar('SELECT count(*) FROM item_compound WHERE companyId = ?', [$companyId]);
    check(
        'R2 · quedaron 4 filas de receta (2 del combo + 1 de producción + 1 del mixto a medias)',
        $compoundRows === 4,
        "item_compound = $compoundRows",
        $failures, $checks
    );

    $combo = EncomMigrationService::mapped($companyId, 'item', 'itm-4');
    $masa  = EncomMigrationService::mapped($companyId, 'item', 'itm-5');
    $medialuna = EncomMigrationService::mapped($companyId, 'item', 'itm-2');

    $qty = ($combo === null || $medialuna === null) ? null : scalar(
        'SELECT quantity FROM item_compound WHERE parentItemId = ? AND childItemId = ?',
        [$combo, $medialuna]
    );
    check(
        'R3 · el combo resuelve sus componentes por el mapa, con la cantidad del legacy (2.000 → 2)',
        $qty !== null && abs((float) $qty - 2.0) < 0.0001,
        'quantity = ' . var_export($qty, true),
        $failures, $checks
    );

    $kindCombo = $combo === null ? null : scalar('SELECT itemKind FROM item WHERE itemId = ?', [$combo]);
    $kindMasa  = $masa === null ? null : scalar('SELECT itemKind FROM item WHERE itemId = ?', [$masa]);
    check(
        'R4 · los kinds del legacy se mapean: combo → combo_fijo, direct_production → produccion_directa',
        (string) $kindCombo === 'combo_fijo' && (string) $kindMasa === 'produccion_directa',
        'kinds = ' . var_export([$kindCombo, $kindMasa], true),
        $failures, $checks
    );

    $logText = json_encode($run1['log'], JSON_UNESCAPED_UNICODE);

    check(
        'R5 · el combo con un componente inexistente NO se inventa: queda anotado para revisar',
        str_contains($logText, 'Revisar a mano') && str_contains($logText, 'Combo Roto'),
        "log = $logText",
        $failures, $checks
    );

    check(
        'R6 · el combo con opciones elegibles tampoco se inventa (en Punto son grupos de add-ons)',
        str_contains($logText, 'Armá tu plato'),
        "log = $logText",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // C. Mapeo
    // ══════════════════════════════════════════════════════════════════
    $catId = EncomMigrationService::mapped($companyId, 'category', 'cat-100');
    check(
        'C1 · migration_map mapea la categoría por su id del legacy',
        $catId !== null,
        'mapped() devolvió null',
        $failures, $checks
    );

    if ($itm1 !== null && $catId !== null) {
        $itemCat = scalar('SELECT categoryId FROM item WHERE itemId = ? AND companyId = ?', [$itm1, $companyId]);
        check(
            'C2 · el artículo apunta a la categoría IMPORTADA',
            (string) $itemCat === (string) $catId,
            'item.categoryId = ' . var_export($itemCat, true) . " vs mapeada $catId",
            $failures, $checks
        );

        $m2m = (int) scalar(
            'SELECT count(*) FROM item_category WHERE itemId = ? AND categoryId = ?',
            [$itm1, $catId]
        );
        check(
            'C3 · la m2m item_category también quedó escrita (context/41)',
            $m2m === 1,
            "item_category count = $m2m",
            $failures, $checks
        );
    }

    check(
        'C4 · el cliente se mapea por su id del legacy (ya no por clave natural)',
        EncomMigrationService::mapped($companyId, 'customer', 'cus-1') !== null,
        'no hay mapeo para cus-1',
        $failures, $checks
    );

    $cus1 = EncomMigrationService::mapped($companyId, 'customer', 'cus-1');
    $creditLine = $cus1 === null ? null : scalar(
        "SELECT contactCreditLine FROM contact WHERE contactId = ?",
        [$cus1]
    );
    check(
        'C5 · el cliente conserva su línea de crédito (el CSV del panel no la traía)',
        $creditLine !== null && (float) $creditLine == 1000000.0,
        'contactCreditLine = ' . var_export($creditLine, true),
        $failures, $checks
    );

    // El legacy numera los tipos de documento con SU tabla (manda 1 y 2), que
    // no es la Tabla 3 de la SET que valida Punto (11..17). El código no se
    // traduce a ciegas —es un dato fiscal—, pero el NÚMERO del documento, que
    // es lo que identifica al cliente, tiene que llegar igual.
    $tin = $cus1 === null ? null : scalar('SELECT contactTIN FROM contact WHERE contactId = ?', [$cus1]);
    check(
        'C6 · el documento del cliente se migra aunque su TIPO use otra tabla de códigos',
        (string) $tin === '80099887-1',
        'contactTIN = ' . var_export($tin, true),
        $failures, $checks
    );

    // `contactCI` NO es columna: vive en el JSONB `data` desde la mig 25
    // (`contactTIN` sí es columna, de ahí que C6 la lea directo).
    $cus2 = EncomMigrationService::mapped($companyId, 'customer', 'cus-2');
    $ci   = $cus2 === null ? null : scalar("SELECT data->>'contactCI' FROM contact WHERE contactId = ?", [$cus2]);
    check(
        'C7 · el cliente con cédula (sin RUC) también entra, con su número',
        (string) $ci === '4567890',
        'contactCI = ' . var_export($ci, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // D. Continuación de numeración (D5)
    // ══════════════════════════════════════════════════════════════════
    $regId = EncomMigrationService::mapped($companyId, 'register', 'reg-1');
    check(
        'D1 · la caja reg-1 quedó mapeada',
        $regId !== null,
        'mapped() devolvió null',
        $failures, $checks
    );

    if ($regId !== null) {
        $auth = scalar("SELECT data ->> 'registerInvoiceAuth' FROM register WHERE registerId = ?", [$regId]);
        $prefix = scalar("SELECT data ->> 'registerInvoicePrefix' FROM register WHERE registerId = ?", [$regId]);
        check(
            'D2 · la caja conserva timbrado (16543210) y punto de expedición (001-001)',
            (string) $auth === '16543210' && (string) $prefix === '001-001',
            'auth = ' . var_export($auth, true) . ' / prefix = ' . var_export($prefix, true),
            $failures, $checks
        );

        $seqOf = static function (string $regId, string $docType, string $companyId, string $auth, string $prefix) {
            return scalar(
                "SELECT nextnumber FROM document_sequence
                  WHERE companyid = ? AND doctype = ? AND scopetype = 'register'
                    AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
                [$companyId, $docType, $regId, $auth, $prefix]
            );
        };

        check(
            'D3 · la FACTURA continúa la serie: nextnumber = 2129 (último emitido 2128 + 1)',
            (int) $seqOf($regId, 'factura', $companyId, '16543210', '001-001') === 2129,
            'nextnumber = ' . var_export($seqOf($regId, 'factura', $companyId, '16543210', '001-001'), true),
            $failures, $checks
        );

        // `docsNum` trae un contador POR TIPO: eso es lo que el scraping no
        // daba. La cotización no tiene serie fiscal (serie vacía) y la nota de
        // crédito hereda la de la factura (mig 215).
        check(
            'D4 · la COTIZACIÓN continúa su propio correlativo: 16 (último 15 + 1)',
            (int) $seqOf($regId, 'cotizacion', $companyId, '', '') === 16,
            'nextnumber = ' . var_export($seqOf($regId, 'cotizacion', $companyId, '', ''), true),
            $failures, $checks
        );

        check(
            'D5 · la NOTA DE CRÉDITO continúa su propio correlativo: 4 (último 3 + 1)',
            (int) $seqOf($regId, 'nota_credito', $companyId, '16543210', '001-001') === 4,
            'nextnumber = ' . var_export($seqOf($regId, 'nota_credito', $companyId, '16543210', '001-001'), true),
            $failures, $checks
        );

        $pad = scalar(
            "SELECT padwidth FROM document_sequence
              WHERE companyid = ? AND doctype = 'factura' AND scopetype = 'register'
                AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
            [$companyId, $regId, '16543210', '001-001']
        );
        check(
            'D6 · el ancho de impresión sale de leadingZero (7 dígitos)',
            (int) $pad === 7,
            'padwidth = ' . var_export($pad, true),
            $failures, $checks
        );

        $regId2 = EncomMigrationService::mapped($companyId, 'register', 'reg-2');
        if ($regId2 !== null) {
            check(
                'D7 · cada caja continúa SU serie: la segunda arranca en 3779',
                (int) $seqOf($regId2, 'factura', $companyId, '16543210', '001-002') === 3779,
                'nextnumber = ' . var_export($seqOf($regId2, 'factura', $companyId, '16543210', '001-002'), true),
                $failures, $checks
            );
        }

        $regId3 = EncomMigrationService::mapped($companyId, 'register', 'reg-3');
        if ($regId3 !== null) {
            check(
                'D8 · una caja sin facturas emitidas arranca en 1 (no en 0)',
                (int) $seqOf($regId3, 'factura', $companyId, '16543210', '002-001') === 1,
                'nextnumber = ' . var_export($seqOf($regId3, 'factura', $companyId, '16543210', '002-001'), true),
                $failures, $checks
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // U. Usuarios
    // ══════════════════════════════════════════════════════════════════
    $usersInDb = (int) scalar('SELECT count(*) FROM contact WHERE companyId = ? AND type = 0', [$companyId]);
    check(
        'U1 · los 3 usuarios están en la base como equipo (type = 0)',
        $usersInDb === 3,
        "usuarios = $usersInDb",
        $failures, $checks
    );

    $pedro = EncomMigrationService::mapped($companyId, 'user', 'usr-2');
    $rolPedro = $pedro === null ? null : scalar(
        "SELECT t.taxonomyname FROM contact c
           JOIN taxonomy t ON t.taxonomyid::text = c.role AND t.taxonomytype = 'role'
          WHERE c.contactId = ?",
        [$pedro]
    );
    check(
        'U2 · el "Cajero" del legacy cae en el rol Cajero de Punto (match por nombre)',
        (string) $rolPedro === 'Cajero',
        'rol = ' . var_export($rolPedro, true),
        $failures, $checks
    );

    $maria = EncomMigrationService::mapped($companyId, 'user', 'usr-1');
    $rolMaria = $maria === null ? null : scalar(
        "SELECT t.taxonomyname FROM contact c
           JOIN taxonomy t ON t.taxonomyid::text = c.role AND t.taxonomytype = 'role'
          WHERE c.contactId = ?",
        [$maria]
    );
    check(
        'U3 · el "Administrador" del legacy cae en Encargado, NUNCA en Dueño (nunca de más)',
        (string) $rolMaria === 'Encargado',
        'rol = ' . var_export($rolMaria, true),
        $failures, $checks
    );

    $pin = $maria === null ? null : scalar('SELECT lockPass FROM contact WHERE contactId = ?', [$maria]);
    $pinHash = $maria === null ? null : scalar('SELECT pinhash FROM contact WHERE contactId = ?', [$maria]);
    check(
        'U4 · el PIN de la caja se migra y queda hasheado para la pantalla de bloqueo',
        (string) $pin === '1234' && (string) $pinHash === hash('sha256', '1234'),
        'lockPass = ' . var_export($pin, true),
        $failures, $checks
    );

    $asignaciones = (int) scalar(
        'SELECT count(*) FROM contact_outlet WHERE companyid = ?',
        [$companyId]
    );
    check(
        'U5 · cada usuario va a SU sucursal; el que no tenía queda global (2 filas, no 3)',
        $asignaciones === 2,
        "contact_outlet = $asignaciones",
        $failures, $checks
    );

    check(
        'U6 · la bitácora dice qué rol se le asignó a cada usuario (para que soporte lo revise)',
        str_contains($logText, 'rol de Punto') && str_contains($logText, 'María Dueña'),
        "log = $logText",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // P. Medios de pago
    // ══════════════════════════════════════════════════════════════════
    $transferencia = (int) scalar(
        "SELECT count(*) FROM taxonomy WHERE companyId = ? AND taxonomyType = 'paymentMethod' AND taxonomyName = 'Transferencia'",
        [$companyId]
    );
    check(
        'P1 · el medio de pago que el comercio tenía y Punto no, se crea',
        $transferencia === 1,
        "Transferencia = $transferencia",
        $failures, $checks
    );

    $efectivo = (int) scalar(
        "SELECT count(*) FROM taxonomy WHERE companyId = ? AND taxonomyType = 'paymentMethod' AND taxonomyName ILIKE 'efectivo'",
        [$companyId]
    );
    check(
        'P2 · "Efectivo" NO se duplica: el que ya existe se reusa',
        $efectivo === 1,
        "Efectivo = $efectivo",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // F. La caja placeholder se reusa
    // ══════════════════════════════════════════════════════════════════
    $registersInDb = countOf('register', $companyId);
    check(
        'F1 · no quedan cajas fantasma: 3 cajas, no 5 (se reusa el placeholder de cada sucursal)',
        $registersInDb === 3,
        "register count = $registersInDb (esperado 3)",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // B. Idempotencia
    // ══════════════════════════════════════════════════════════════════
    $before = [
        'item'          => countOf('item', $companyId),
        'item_compound' => (int) scalar('SELECT count(*) FROM item_compound WHERE companyId = ?', [$companyId]),
        'category'      => countOf('category', $companyId),
        'brand'         => countOf('brand', $companyId),
        'contact'       => countOf('contact', $companyId),
        'outlet'        => countOf('outlet', $companyId),
        'register'      => countOf('register', $companyId),
        'tax'           => countOf('tax', $companyId),
    ];

    $run2 = (new EncomImportService($companyId, new FixtureEncomClient($fixtures), null))
        ->run(['catalog', 'customers', 'config', 'users', 'payments']);
    $p2 = $run2['progress'];

    check(
        'B1 · la segunda corrida no importa artículos ni clientes nuevos',
        ($p2['item']['imported'] ?? -1) === 0 && ($p2['item']['skipped'] ?? 0) === 9
            && ($p2['customer']['imported'] ?? -1) === 0 && ($p2['customer']['skipped'] ?? 0) === 2,
        'progress = ' . json_encode([$p2['item'] ?? null, $p2['customer'] ?? null]),
        $failures, $checks
    );

    check(
        'B2 · la segunda corrida no importa cajas, usuarios ni medios de pago nuevos',
        ($p2['register']['imported'] ?? -1) === 0 && ($p2['register']['skipped'] ?? 0) === 3
            && ($p2['user']['imported'] ?? -1) === 0 && ($p2['user']['skipped'] ?? 0) === 3
            && ($p2['payment']['imported'] ?? -1) === 0 && ($p2['payment']['skipped'] ?? 0) === 3,
        'progress = ' . json_encode([$p2['register'] ?? null, $p2['user'] ?? null, $p2['payment'] ?? null]),
        $failures, $checks
    );

    // La receta es el caso donde re-correr SIN marca duplicaría cantidades:
    // `ItemCompoundService::add()` suma cuando el ingrediente ya está.
    check(
        'B3 · la composición NO se vuelve a aplicar (si no, cada corrida sumaría la cantidad otra vez)',
        ($p2['compound']['imported'] ?? -1) === 0 && ($p2['compound']['skipped'] ?? 0) === 2,
        'progress.compound = ' . json_encode($p2['compound'] ?? null),
        $failures, $checks
    );

    $after = [
        'item'          => countOf('item', $companyId),
        'item_compound' => (int) scalar('SELECT count(*) FROM item_compound WHERE companyId = ?', [$companyId]),
        'category'      => countOf('category', $companyId),
        'brand'         => countOf('brand', $companyId),
        'contact'       => countOf('contact', $companyId),
        'outlet'        => countOf('outlet', $companyId),
        'register'      => countOf('register', $companyId),
        'tax'           => countOf('tax', $companyId),
    ];

    check(
        'B4 · los conteos de la base NO se movieron tras re-correr',
        $before === $after,
        'antes=' . json_encode($before) . ' después=' . json_encode($after),
        $failures, $checks
    );

    if ($combo !== null && $medialuna !== null) {
        $qtyAgain = scalar(
            'SELECT quantity FROM item_compound WHERE parentItemId = ? AND childItemId = ?',
            [$combo, $medialuna]
        );
        check(
            'B5 · la cantidad de la receta sigue siendo 2, no 4',
            $qtyAgain !== null && abs((float) $qtyAgain - 2.0) < 0.0001,
            'quantity = ' . var_export($qtyAgain, true),
            $failures, $checks
        );
    }

    if ($regId !== null) {
        $nextAgain = scalar(
            "SELECT nextnumber FROM document_sequence
              WHERE companyid = ? AND doctype = 'factura' AND scopetype = 'register'
                AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
            [$companyId, $regId, '16543210', '001-001']
        );
        check(
            'B6 · re-correr NO vuelve a mover la numeración fiscal (sigue en 2129)',
            (int) $nextAgain === 2129,
            'nextnumber = ' . var_export($nextAgain, true),
            $failures, $checks
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // M. La receta a medias se COMPLETA, no queda congelada
    // ══════════════════════════════════════════════════════════════════
    // "Combo Mixto" tiene dos componentes fijos: uno resuelve (itm-2) y el otro
    // no existe en el catálogo migrado (itm-777). Marcar al padre como
    // compuesto igual lo congelaría: la corrida siguiente lo saltearía por
    // idempotente y la receta quedaría incompleta PARA SIEMPRE, con
    // `explodeRecipe` descontando de menos en cada venta y en silencio.
    $mixto = EncomMigrationService::mapped($companyId, 'item', 'itm-9');

    check(
        'M1 · la receta con un componente sin resolver NO queda marcada como compuesta',
        EncomMigrationService::mapped($companyId, 'compound', 'itm-9') === null,
        'quedó marcada: la próxima corrida la saltearía y nunca se completaría',
        $failures, $checks
    );

    check(
        'M2 · el componente que SÍ resolvió quedó marcado por su cuenta',
        EncomMigrationService::mapped($companyId, 'compound', 'itm-9:itm-2') !== null,
        'sin marca por componente, reintentar volvería a SUMAR la cantidad',
        $failures, $checks
    );

    $filasMixto = $mixto === null ? -1 : (int) scalar(
        'SELECT count(*) FROM item_compound WHERE parentItemId = ?',
        [$mixto]
    );
    check(
        'M3 · por ahora la receta tiene UN solo componente',
        $filasMixto === 1,
        "item_compound del mixto = $filasMixto",
        $failures, $checks
    );

    // Soporte crea a mano el artículo que faltaba y queda mapeado. La corrida
    // siguiente tiene que TERMINAR la receta.
    $harina = EncomMigrationService::mapped($companyId, 'item', 'itm-6');
    if ($harina !== null) {
        EncomMigrationService::remember($companyId, 'item', 'itm-777', $harina, null);
    }

    $run3 = (new EncomImportService($companyId, new FixtureEncomClient($fixtures), null))->run(['catalog']);

    check(
        'M4 · la corrida siguiente COMPLETA la receta que había quedado a medias',
        ($run3['progress']['compound']['imported'] ?? 0) === 1,
        'progress.compound = ' . json_encode($run3['progress']['compound'] ?? null),
        $failures, $checks
    );

    check(
        'M5 · recién ahora queda marcada como compuesta',
        EncomMigrationService::mapped($companyId, 'compound', 'itm-9') !== null,
        'sigue sin marcar',
        $failures, $checks
    );

    $filasMixto2 = $mixto === null ? -1 : (int) scalar(
        'SELECT count(*) FROM item_compound WHERE parentItemId = ?',
        [$mixto]
    );
    check(
        'M6 · la receta quedó con sus DOS componentes',
        $filasMixto2 === 2,
        "item_compound del mixto = $filasMixto2",
        $failures, $checks
    );

    // Lo que protege la marca por componente: `ItemCompoundService::add()` SUMA
    // cuando el ingrediente ya está, así que completar la receta no puede
    // volver a contar el que ya se había escrito.
    $qtyMixto = ($mixto === null || $medialuna === null) ? null : scalar(
        'SELECT quantity FROM item_compound WHERE parentItemId = ? AND childItemId = ?',
        [$mixto, $medialuna]
    );
    check(
        'M7 · el componente que ya estaba sigue en 1, no en 2 (completar no duplica)',
        $qtyMixto !== null && abs((float) $qtyMixto - 1.0) < 0.0001,
        'quantity = ' . var_export($qtyMixto, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // E. Rechazo por punto de expedición duplicado (D5)
    // ══════════════════════════════════════════════════════════════════
    seedCompany($companyB, 'Comercio Con Choque SA');

    $runBad  = (new EncomImportService($companyB, new ClashEncomClient(), null))->run(['config']);
    $errText = json_encode($runBad['errors'], JSON_UNESCAPED_UNICODE);

    check(
        'E1 · el dominio config falla si dos cajas comparten timbrado + punto de expedición',
        $runBad['errors'] !== [],
        'no se registró ningún error',
        $failures, $checks
    );

    check(
        'E2 · el error nombra el punto de expedición en conflicto',
        str_contains($errText, '001-001') && str_contains($errText, '16543210'),
        "errores: $errText",
        $failures, $checks
    );

    $mappedBad = (int) scalar(
        "SELECT count(*) FROM migration_map WHERE companyid = ? AND domain = 'register'",
        [$companyB]
    );
    check(
        'E3 · NINGUNA caja se importó (no se importa a medias)',
        $mappedBad === 0,
        "migration_map tiene $mappedBad cajas mapeadas, esperado 0",
        $failures, $checks
    );

    $seqBad = (int) scalar(
        "SELECT count(*) FROM document_sequence WHERE companyid = ? AND invoiceauth = '16543210'",
        [$companyB]
    );
    check(
        'E4 · no quedó ninguna serie fiscal a medio crear',
        $seqBad === 0,
        "document_sequence tiene $seqBad filas con ese timbrado, esperado 0",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // Z. Barrido de credenciales huérfanas (TTL 24 h)
    // ══════════════════════════════════════════════════════════════════
    // Un job que nunca se ejecuta —falta ENCOM_MIGRATION_URL, cron caído—
    // retendría la sesión viva del panel de un cliente para siempre.
    seedCompany($companyId, 'Comercio Migrado SA');

    $db->Execute(
        "INSERT INTO migration_job (companyid, source, status, domains, credentials, created_at)
         VALUES (?, 'encom', 'pending', '[\"catalog\"]'::jsonb, ?::jsonb, now() - interval '30 hours')",
        [$companyId, json_encode(['cookies' => ['PHPSESSID' => 'viva'], 'legacyUrl' => 'https://legacy.test'])]
    );

    $antes = (int) scalar(
        'SELECT count(*) FROM migration_job WHERE companyid = ? AND credentials IS NOT NULL',
        [$companyId]
    );

    (new EncomMigrationService())->drain();

    $conCreds = (int) scalar(
        'SELECT count(*) FROM migration_job WHERE companyid = ? AND credentials IS NOT NULL',
        [$companyId]
    );

    check(
        'Z1 · el job viejo tenía credenciales guardadas antes del barrido',
        $antes === 1,
        "jobs con credenciales antes = $antes",
        $failures, $checks
    );

    check(
        'Z2 · el drain borra las cookies de un job pending de más de 24 h',
        $conCreds === 0,
        "jobs con credenciales después = $conCreds",
        $failures, $checks
    );

    $estado = scalar(
        'SELECT status FROM migration_job WHERE companyid = ? ORDER BY created_at DESC LIMIT 1',
        [$companyId]
    );
    check(
        'Z3 · además lo cierra como failed (si no, bloquearía toda migración futura de esa empresa)',
        (string) $estado === 'failed',
        'status = ' . var_export($estado, true),
        $failures, $checks
    );

    $motivo = (string) scalar(
        'SELECT errors::text FROM migration_job WHERE companyid = ? ORDER BY created_at DESC LIMIT 1',
        [$companyId]
    );
    check(
        'Z4 · el job dice por qué murió (la sesión caducó), no queda mudo',
        str_contains($motivo, 'caduc'),
        "errors = $motivo",
        $failures, $checks
    );
} finally {
    cleanup($companyId);
    cleanup($companyB);
}

harnessFinish($failures, $checks);

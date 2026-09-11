<?php
declare(strict_types=1);

/**
 * Arnés del migrador ENCOM → Punto (context/77).
 *
 * Corre contra Postgres REAL (descartable, lo levanta run_encom_migration_test.sh),
 * contra los SERVICIOS REALES de import y contra el CLIENTE REAL del legacy.
 * Lo único que se reemplaza es el TRANSPORTE HTTP: `FixtureEncomClient`
 * sobreescribe `get()` y sirve los payloads CRUDOS que devuelve el sistema
 * vivo (CSV con `\r` y comillas, `{"table": "<html>"}`, forms HTML).
 *
 * Por qué así y no con un `EncomSource` de JSON normalizado: lo que más se
 * puede equivocar es justamente el mapeo —qué `action` se pide, el orden de
 * las columnas de cada tabla, de qué atributo sale el valor crudo—, y un
 * fixture ya normalizado lo saltea entero. Acá se ejercita.
 *
 * Casos:
 *   A. Import completo — conteos por dominio y entidades realmente creadas.
 *   B. IDEMPOTENCIA — re-correr no duplica: todo `skipped`, conteos quietos.
 *   C. MAPEO — `migration_map`, y el artículo apunta a la categoría importada.
 *   D. CONTINUACIÓN DE NUMERACIÓN (D5) — `document_sequence` con el timbrado y
 *      el punto del legacy y `nextnumber` = último emitido + 1.
 *   E. RECHAZO por punto de expedición duplicado — aborta el dominio SIN
 *      importar ninguna caja.
 *   F. La caja placeholder de la sucursal se REUSA (no quedan fantasmas).
 *   G. PARSERS sobre los shapes exactos del sistema vivo.
 *   H. El export recorre TODAS las sucursales (switch `?o=`) y filtra por ROL.
 *   I. Fallback de artículos a la tabla HTML cuando no hay `format=json`.
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
require_once dirname(__DIR__) . '/lib/Admin/EncomParse.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomImportService.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomMigrationService.php';

use Punto\Api\Admin\EncomClient;
use Punto\Api\Admin\EncomImportService;
use Punto\Api\Admin\EncomMigrationService;
use Punto\Api\Admin\EncomParse;

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
 * Sobreescribe SOLO `get()`: todo lo de arriba —el recorrido de sucursales
 * con `?o=`, el orden de columnas, el fallback de artículos— es el código de
 * producción.
 */
final class FixtureEncomClient extends EncomClient
{
    /** @var array<int,array{path:string,params:array}> */
    public array $calls = [];

    public function __construct(private readonly string $dir, private readonly bool $itemsAsJson = true)
    {
        parent::__construct('https://legacy.test', ['PHPSESSID' => 'fixture']);
    }

    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        $this->calls[] = ['path' => $path, 'params' => $params];

        $action = (string) ($params['action'] ?? '');

        // Switch de sucursal: el legacy contesta 302 y cuerpo inútil.
        if (isset($params['o'])) {
            $this->activeOutlet = (string) $params['o'];
            return '';
        }

        if ($path === '/a_outlets' && isset($params['showTable'])) {
            return $this->read('outlets-showtable.json');
        }
        if ($path === '/a_outlets' && $action === 'edit') {
            // Sin form de sucursal en los fixtures: el cliente tiene que
            // seguir andando con lo que trajo la tabla.
            return '';
        }
        if ($path === '/a_registers' && isset($params['list'])) {
            return $this->read('registers-' . $this->activeOutlet . '.html');
        }
        if ($path === '/a_registers' && $action === 'edit') {
            return $this->read('register-edit-' . (string) $params['id'] . '.html');
        }
        if ($path === '/a_items') {
            return $this->itemsAsJson
                ? $this->read('items-showtable.json')
                : $this->read('items-showtable-html.json');
        }
        if ($path === '/a_contacts' && $action === 'download') {
            return $this->read('contacts-download.csv');
        }
        if ($path === '/a_settings') {
            return '';
        }

        return '';
    }

    private string $activeOutlet = 'out-1';

    private function read(string $file): string
    {
        $full = $this->dir . '/' . $file;
        return is_file($full) ? (string) file_get_contents($full) : '';
    }
}

/** Variante del caso E: dos cajas con el mismo (timbrado, punto). */
final class ClashingEncomClient extends EncomClient
{
    public function __construct(private readonly string $dir)
    {
        parent::__construct('https://legacy.test', ['PHPSESSID' => 'fixture']);
    }

    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        $action = (string) ($params['action'] ?? '');

        if (isset($params['o'])) {
            return '';
        }
        if ($path === '/a_outlets' && isset($params['showTable'])) {
            return '{"table":"<tbody><tr data-id=\"out-1\"><td>Casa Central</td><td></td><td></td><td></td><td></td><td></td><td></td></tr></tbody>"}';
        }
        if ($path === '/a_registers' && isset($params['list'])) {
            return '<tbody>'
                . '<tr data-id="dup-1"><td>Caja Uno</td><td>hoy</td><td class="text-right">16543210</td><td>001-001</td><td class="text-right">0000100</td><td></td><td></td></tr>'
                . '<tr data-id="dup-2"><td>Caja Dos</td><td>hoy</td><td class="text-right">16543210</td><td>001-001</td><td class="text-right">0000250</td><td></td><td></td></tr>'
                . '</tbody>';
        }
        if ($path === '/a_registers' && $action === 'edit') {
            $f = $this->dir . '/register-edit-' . (string) $params['id'] . '.html';
            return is_file($f) ? (string) file_get_contents($f) : '';
        }
        return '';
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
        'DELETE FROM item_category WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item_brand    WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item_tag      WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item_outlet   WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item WHERE companyId = ?',
        'DELETE FROM category WHERE companyId = ?',
        'DELETE FROM brand WHERE companyId = ?',
        'DELETE FROM tag WHERE companyId = ?',
        // `customeraddress` cuelga de `contact` con FK dura.
        'DELETE FROM customeraddress WHERE customerId IN (SELECT contactId FROM contact WHERE companyId = ?)',
        'DELETE FROM contact WHERE companyId = ?',
        'DELETE FROM register WHERE companyId = ?',
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
    // G. Parsers sobre los shapes EXACTOS del sistema vivo
    // ══════════════════════════════════════════════════════════════════
    $csvRows = EncomParse::csvRows((string) file_get_contents($fixtures . '/contacts-download.csv'));

    check(
        'G1 · el CSV con saltos \r y comillas se parsea (4 filas)',
        count($csvRows) === 4,
        'filas = ' . count($csvRows),
        $failures, $checks
    );

    check(
        'G2 · una coma DENTRO de comillas no parte la celda',
        ($csvRows[0]['RAZON SOCIAL'] ?? '') === 'Distribuidora del Este, SRL',
        'razón social = ' . var_export($csvRows[0]['RAZON SOCIAL'] ?? null, true),
        $failures, $checks
    );

    // El deploy vivo trae TELEFONO 2 y el snapshot la eliminó: si el parser
    // fuera posicional, el email caería en la columna del teléfono.
    check(
        'G3 · con la columna TELEFONO 2 presente, EMAIL sigue siendo EMAIL',
        ($csvRows[0]['EMAIL'] ?? '') === 'ana@example.com'
            && ($csvRows[0]['TELEFONO'] ?? '') === '0981123456',
        'email = ' . var_export($csvRows[0]['EMAIL'] ?? null, true)
            . ' / tel = ' . var_export($csvRows[0]['TELEFONO'] ?? null, true),
        $failures, $checks
    );

    check(
        'G4 · la columna del documento se resuelve aunque el header sea variable (TIN_NAME)',
        EncomParse::tinOf($csvRows[0]) === '80099887-1',
        'tin = ' . var_export(EncomParse::tinOf($csvRows[0]), true),
        $failures, $checks
    );

    // La tabla de transacciones usa data-order; la de artículos, data-sort.
    $rowsOrder = EncomParse::htmlRows(
        '<tbody><tr data-id="tx-1"><td data-order="2026-09-11 14:03:22">11 sep</td>'
        . '<td data-order="1250000" data-format="money">1.250.000</td></tr></tbody>'
    );
    check(
        'G5 · se lee el valor CRUDO de data-order, no el texto formateado',
        ($rowsOrder[0]['cells'][1] ?? '') === '1250000',
        'celda = ' . var_export($rowsOrder[0]['cells'][1] ?? null, true),
        $failures, $checks
    );

    $rowsSort = EncomParse::htmlRows(
        '<tbody><tr id="itm-9"><td>x</td><td data-sort="9900">9.900</td></tr></tbody>'
    );
    check(
        'G6 · la fila de artículos usa id= y data-sort= y también se parsea',
        ($rowsSort[0]['id'] ?? '') === 'itm-9' && ($rowsSort[0]['cells'][1] ?? '') === '9900',
        'fila = ' . json_encode($rowsSort[0] ?? null),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // H. El export: recorre sucursales y filtra por ROL
    // ══════════════════════════════════════════════════════════════════
    $client = new FixtureEncomClient($fixtures);

    $outlets = $client->outlets();
    check(
        'H1 · se listan las 2 sucursales desde {"table": ...}',
        count($outlets) === 2 && ($outlets[0]['name'] ?? '') === 'Casa Central',
        'outlets = ' . json_encode($outlets, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $registers = $client->registers();
    check(
        'H2 · se traen las cajas de TODAS las sucursales (switch ?o=), no solo la activa',
        count($registers) === 3,
        'cajas = ' . count($registers) . ' → ' . json_encode(array_column($registers, 'name')),
        $failures, $checks
    );

    check(
        'H3 · cada caja sabe a qué sucursal pertenece',
        ($registers[0]['outletLegacyId'] ?? '') === 'out-1'
            && ($registers[2]['outletLegacyId'] ?? '') === 'out-2',
        'outletLegacyId = ' . json_encode(array_column($registers, 'outletLegacyId')),
        $failures, $checks
    );

    check(
        'H4 · del form de la caja salen el timbrado, el punto y el vencimiento',
        ($registers[0]['invoiceAuth'] ?? '') === '16543210'
            && ($registers[0]['prefix'] ?? '') === '001-001'
            && ($registers[0]['invoiceNo'] ?? 0) === 2128
            && ($registers[0]['invoiceAuthExp'] ?? '') === '2027-12-31'
            && ($registers[0]['docsZeros'] ?? 0) === 7,
        'caja = ' . json_encode($registers[0], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $customers = $client->customers();
    check(
        'H5 · del CSV solo entran los ROL=Cliente (no el proveedor ni el usuario)',
        count($customers) === 2,
        'clientes = ' . json_encode(array_column($customers, 'fiscalName')),
        $failures, $checks
    );

    check(
        'H6 · razón social y nombre de persona quedan en campos distintos',
        ($customers[0]['fiscalName'] ?? '') === 'Distribuidora del Este, SRL'
            && ($customers[0]['name'] ?? '') === 'Ana Gómez',
        'cliente = ' . json_encode($customers[0], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $cats = $client->categories();
    check(
        'H7 · las categorías se derivan de los artículos y "-" no es una categoría',
        count($cats) === 2,
        'categorías = ' . json_encode(array_column($cats, 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // I. Fallback de artículos a la tabla HTML
    // ══════════════════════════════════════════════════════════════════
    $htmlClient = new FixtureEncomClient($fixtures, false);
    $htmlItems  = $htmlClient->items();

    check(
        'I1 · sin format=json, los artículos se leen igual de la tabla HTML',
        count($htmlItems) === 3 && ($htmlItems[0]['name'] ?? '') === 'Café Espresso',
        'items = ' . json_encode(array_column($htmlItems, 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'I2 · el fallback saca costo y precio del data-sort, no del texto con puntos',
        (string) ($htmlItems[0]['cost'] ?? '') === '5000'
            && (string) ($htmlItems[0]['price'] ?? '') === '12000',
        'item = ' . json_encode($htmlItems[0], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'I3 · los dos caminos (JSON y HTML) dan los mismos nombres de categoría',
        array_column($htmlClient->categories(), 'name') === array_column($cats, 'name'),
        'html = ' . json_encode(array_column($htmlClient->categories(), 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // A. Import completo
    // ══════════════════════════════════════════════════════════════════
    seedCompany($companyId, 'Comercio Migrado SA');

    $source = new FixtureEncomClient($fixtures);
    $run1   = (new EncomImportService($companyId, $source, null))
        ->run(['catalog', 'customers', 'config']);

    $p1 = $run1['progress'];

    check(
        'A1 · no hubo errores en el import',
        $run1['errors'] === [],
        'errores: ' . json_encode($run1['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'A2 · categorías importadas (2)',
        ($p1['category']['imported'] ?? 0) === 2,
        'progress.category = ' . json_encode($p1['category'] ?? null),
        $failures, $checks
    );

    check(
        'A3 · artículos importados (3)',
        ($p1['item']['imported'] ?? 0) === 3,
        'progress.item = ' . json_encode($p1['item'] ?? null),
        $failures, $checks
    );

    check(
        'A4 · clientes importados (2)',
        ($p1['customer']['imported'] ?? 0) === 2,
        'progress.customer = ' . json_encode($p1['customer'] ?? null),
        $failures, $checks
    );

    check(
        'A5 · sucursales importadas (2)',
        ($p1['outlet']['imported'] ?? 0) === 2,
        'progress.outlet = ' . json_encode($p1['outlet'] ?? null),
        $failures, $checks
    );

    check(
        'A6 · cajas importadas (3)',
        ($p1['register']['imported'] ?? 0) === 3,
        'progress.register = ' . json_encode($p1['register'] ?? null),
        $failures, $checks
    );

    $itemsInDb = countOf('item', $companyId);
    check(
        'A7 · los artículos están en la base (3)',
        $itemsInDb === 3,
        "item count = $itemsInDb",
        $failures, $checks
    );

    $contactsInDb = (int) scalar('SELECT count(*) FROM contact WHERE companyId = ? AND type = 1', [$companyId]);
    check(
        'A8 · los clientes están en la base (2)',
        $contactsInDb === 2,
        "contact count = $contactsInDb",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // C. Mapeo
    // ══════════════════════════════════════════════════════════════════
    // La clave del legacy para una categoría es su NOMBRE: el export de
    // artículos no manda ids de taxonomía.
    $catId = EncomMigrationService::mapped($companyId, 'category', 'Bebidas');
    check(
        'C1 · migration_map mapea la categoría por su nombre',
        $catId !== null,
        'mapped() devolvió null',
        $failures, $checks
    );

    $itemId = EncomMigrationService::mapped($companyId, 'item', 'itm-1');
    check(
        'C2 · migration_map tiene el artículo itm-1',
        $itemId !== null,
        'mapped() devolvió null',
        $failures, $checks
    );

    if ($itemId !== null && $catId !== null) {
        $itemCat = scalar('SELECT categoryId FROM item WHERE itemId = ? AND companyId = ?', [$itemId, $companyId]);
        check(
            'C3 · el artículo apunta a la categoría IMPORTADA',
            (string) $itemCat === (string) $catId,
            'item.categoryId = ' . var_export($itemCat, true) . " vs mapeada $catId",
            $failures, $checks
        );

        $m2m = (int) scalar(
            'SELECT count(*) FROM item_category WHERE itemId = ? AND categoryId = ?',
            [$itemId, $catId]
        );
        check(
            'C4 · la m2m item_category también quedó escrita (context/41)',
            $m2m === 1,
            "item_category count = $m2m",
            $failures, $checks
        );
    }

    // Un cliente sin id en el CSV se mapea por su documento.
    check(
        'C5 · el cliente sin id del CSV se mapea por clave natural (documento)',
        EncomMigrationService::mapped($companyId, 'customer', 'tin:800998871') !== null,
        'no hay mapeo para tin:800998871',
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
        check(
            'D2 · la caja conserva el timbrado del legacy (16543210)',
            (string) $auth === '16543210',
            'registerInvoiceAuth = ' . var_export($auth, true),
            $failures, $checks
        );

        $prefix = scalar("SELECT data ->> 'registerInvoicePrefix' FROM register WHERE registerId = ?", [$regId]);
        check(
            'D3 · la caja conserva el punto de expedición (001-001)',
            (string) $prefix === '001-001',
            'registerInvoicePrefix = ' . var_export($prefix, true),
            $failures, $checks
        );

        $next = scalar(
            "SELECT nextnumber FROM document_sequence
              WHERE companyid = ? AND doctype = 'factura' AND scopetype = 'register'
                AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
            [$companyId, $regId, '16543210', '001-001']
        );
        check(
            'D4 · document_sequence continúa la serie: nextnumber = 2129 (último 2128 + 1)',
            (int) $next === 2129,
            'nextnumber = ' . var_export($next, true),
            $failures, $checks
        );

        $pad = scalar(
            "SELECT padwidth FROM document_sequence
              WHERE companyid = ? AND doctype = 'factura' AND scopetype = 'register'
                AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
            [$companyId, $regId, '16543210', '001-001']
        );
        check(
            'D5 · el ancho de impresión sale del form del legacy (7 dígitos)',
            (int) $pad === 7,
            'padwidth = ' . var_export($pad, true),
            $failures, $checks
        );

        $regId3 = EncomMigrationService::mapped($companyId, 'register', 'reg-3');
        if ($regId3 !== null) {
            $next3 = scalar(
                "SELECT nextnumber FROM document_sequence
                  WHERE companyid = ? AND doctype = 'factura' AND scopetype = 'register'
                    AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
                [$companyId, $regId3, '16543210', '002-001']
            );
            check(
                'D6 · una caja sin facturas emitidas arranca en 1 (no en 0)',
                (int) $next3 === 1,
                'nextnumber = ' . var_export($next3, true),
                $failures, $checks
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // F. La caja placeholder se reusa
    // ══════════════════════════════════════════════════════════════════
    $registersInDb = (int) scalar('SELECT count(*) FROM register WHERE companyId = ?', [$companyId]);
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
        'item'     => countOf('item', $companyId),
        'category' => countOf('category', $companyId),
        'brand'    => countOf('brand', $companyId),
        'contact'  => countOf('contact', $companyId),
        'outlet'   => countOf('outlet', $companyId),
        'register' => countOf('register', $companyId),
    ];

    $run2 = (new EncomImportService($companyId, new FixtureEncomClient($fixtures), null))
        ->run(['catalog', 'customers', 'config']);
    $p2 = $run2['progress'];

    check(
        'B1 · la segunda corrida no importa artículos nuevos',
        ($p2['item']['imported'] ?? -1) === 0 && ($p2['item']['skipped'] ?? 0) === 3,
        'progress.item = ' . json_encode($p2['item'] ?? null),
        $failures, $checks
    );

    check(
        'B2 · la segunda corrida no importa clientes nuevos',
        ($p2['customer']['imported'] ?? -1) === 0 && ($p2['customer']['skipped'] ?? 0) === 2,
        'progress.customer = ' . json_encode($p2['customer'] ?? null),
        $failures, $checks
    );

    check(
        'B3 · la segunda corrida no importa cajas nuevas',
        ($p2['register']['imported'] ?? -1) === 0 && ($p2['register']['skipped'] ?? 0) === 3,
        'progress.register = ' . json_encode($p2['register'] ?? null),
        $failures, $checks
    );

    $after = [
        'item'     => countOf('item', $companyId),
        'category' => countOf('category', $companyId),
        'brand'    => countOf('brand', $companyId),
        'contact'  => countOf('contact', $companyId),
        'outlet'   => countOf('outlet', $companyId),
        'register' => countOf('register', $companyId),
    ];

    check(
        'B4 · los conteos de la base NO se movieron tras re-correr',
        $before === $after,
        'antes=' . json_encode($before) . ' después=' . json_encode($after),
        $failures, $checks
    );

    if ($regId !== null) {
        $nextAgain = scalar(
            "SELECT nextnumber FROM document_sequence
              WHERE companyid = ? AND doctype = 'factura' AND scopetype = 'register'
                AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
            [$companyId, $regId, '16543210', '001-001']
        );
        check(
            'B5 · re-correr NO vuelve a mover la numeración fiscal (sigue en 2129)',
            (int) $nextAgain === 2129,
            'nextnumber = ' . var_export($nextAgain, true),
            $failures, $checks
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // E. Rechazo por punto de expedición duplicado (D5)
    // ══════════════════════════════════════════════════════════════════
    seedCompany($companyB, 'Comercio Con Choque SA');

    $runBad  = (new EncomImportService($companyB, new ClashingEncomClient($fixtures), null))->run(['config']);
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
} finally {
    cleanup($companyId);
    cleanup($companyB);
}

harnessFinish($failures, $checks);

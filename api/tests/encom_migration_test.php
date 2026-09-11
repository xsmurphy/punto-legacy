<?php
declare(strict_types=1);

/**
 * Arnés del migrador ENCOM → Punto (context/77).
 *
 * Corre contra Postgres REAL (descartable, lo levanta run_encom_migration_test.sh)
 * y contra los SERVICIOS REALES de import. Lo único mockeado es el EXPORT: la
 * fuente es un `EncomSource` de fixtures con el shape exacto que devuelve cada
 * endpoint legacy. Así lo que se ejercita es el mismo código que corre en
 * producción, sin depender de que el panel legacy esté arriba ni de las
 * credenciales de un cliente real.
 *
 * Casos:
 *   A. Import completo — conteos por dominio, y las entidades existen de verdad.
 *   B. IDEMPOTENCIA — correr el MISMO job otra vez no duplica nada: todo
 *      `skipped` y los conteos de la base no se mueven.
 *   C. MAPEO — `migration_map` tiene la correspondencia, y el artículo quedó
 *      apuntando a la categoría/marca importadas (no a un nombre suelto).
 *   D. CONTINUACIÓN DE NUMERACIÓN (D5) — la caja de Punto sigue la serie del
 *      legacy: `document_sequence` con el timbrado y el punto de expedición
 *      del legacy y `nextnumber` = último emitido + 1.
 *   E. RECHAZO POR PUNTO DE EXPEDICIÓN DUPLICADO (D5) — dos cajas con el mismo
 *      (timbrado, EEE-PPP) abortan el dominio SIN importar ninguna.
 *   F. La caja placeholder de la sucursal se REUSA, no queda una caja fantasma.
 */

require_once __DIR__ . '/_harness.php';

$companyId = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d1122';
$companyB  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d3344';   // caso E, empresa aparte

define('COMPANY_ID', $companyId);
define('OUTLET_ID', '');
define('USER_ID', '');
define('REGISTER_ID', '');
define('ROLE_ID', '');
define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomSource.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomImportService.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomMigrationService.php';

use Punto\Api\Admin\EncomImportService;
use Punto\Api\Admin\EncomMigrationService;
use Punto\Api\Admin\EncomSource;

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
 * Fuente de fixtures: implementa la MISMA interfaz que `EncomClient`, así que
 * el importador no distingue este arnés de una migración real.
 */
final class FixtureEncomSource implements EncomSource
{
    private array $data;

    public function __construct(string $file)
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("no se pudo leer el fixture $file");
        }
        $this->data = json_decode($raw, true) ?: [];
    }

    private function listOf(string $key): array
    {
        $v = $this->data[$key] ?? [];
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }

    public function settings(): array   { return is_array($this->data['settings'] ?? null) ? $this->data['settings'] : []; }
    public function outlets(): array    { return $this->listOf('outlets'); }
    public function registers(): array  { return $this->listOf('registers'); }
    public function items(): array      { return $this->listOf('items'); }
    public function categories(): array { return $this->listOf('categories'); }
    public function brands(): array     { return $this->listOf('brands'); }
    public function tags(): array       { return $this->listOf('tags'); }
    public function customers(): array  { return $this->listOf('customers'); }
    public function banks(): array      { return $this->listOf('banks'); }
}

/** Empresa mínima de prueba. `config` lleva lo que el bootstrap del tenant lee. */
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

/** Borra en orden FK-safe todo lo que el arnés haya creado para esa empresa. */
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
        // `customeraddress` cuelga de `contact` con FK dura: `ContactService::create()`
        // siembra la dirección default, así que va primero o el DELETE del
        // contacto rebota.
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
            // Una tabla que no existe en este schema no invalida el cleanup.
        }
    }
}

$fixtures = __DIR__ . '/fixtures/encom';

cleanup($companyId);
cleanup($companyB);

try {
    // ══════════════════════════════════════════════════════════════════
    // A. Import completo
    // ══════════════════════════════════════════════════════════════════
    seedCompany($companyId, 'Comercio Migrado SA');

    $source = new FixtureEncomSource($fixtures . '/export.json');
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

    // Las entidades existen de verdad, no solo en el contador.
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
    $catId = EncomMigrationService::mapped($companyId, 'category', 'cat-100');
    check(
        'C1 · migration_map tiene la categoría cat-100',
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
            'C3 · el artículo apunta a la categoría IMPORTADA (no a un nombre suelto)',
            (string) $itemCat === (string) $catId,
            "item.categoryId = " . var_export($itemCat, true) . " vs mapeada $catId",
            $failures, $checks
        );

        $m2m = (int) scalar(
            'SELECT count(*) FROM item_category WHERE itemId = ? AND categoryId = ?',
            [$itemId, $catId]
        );
        check(
            'C4 · la m2m item_category también quedó escrita (context/41: la categoría vive en dos lados)',
            $m2m === 1,
            "item_category count = $m2m",
            $failures, $checks
        );
    }

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

        // El corazón de D5: el legacy iba por la 2128 (último EMITIDO), así
        // que Punto tiene que arrancar en la 2129 (PRÓXIMO a emitir), y en la
        // fila de ESA serie — la que lleva el timbrado y el punto, no la
        // genérica sin serie fiscal.
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
            'D5 · el ancho de impresión viene del legacy (7 dígitos)',
            (int) $pad === 7,
            'padwidth = ' . var_export($pad, true),
            $failures, $checks
        );

        // La caja que en el legacy nunca emitió arranca en 1, no en 0:
        // `document_sequence` tiene CHECK (nextnumber >= 1).
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
    // Dos sucursales × 1 placeholder + 3 cajas del legacy. Si el placeholder
    // NO se reusara habría 5 cajas y el comercio tendría que borrar 2 a mano.
    $registersInDb = (int) scalar('SELECT count(*) FROM register WHERE companyId = ?', [$companyId]);
    check(
        'F1 · no quedan cajas fantasma: 3 cajas, no 5 (el placeholder de cada sucursal se reusa)',
        $registersInDb === 3,
        "register count = $registersInDb (esperado 3)",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // B. Idempotencia — la MISMA corrida otra vez
    // ══════════════════════════════════════════════════════════════════
    $before = [
        'item'     => countOf('item', $companyId),
        'category' => countOf('category', $companyId),
        'brand'    => countOf('brand', $companyId),
        'contact'  => countOf('contact', $companyId),
        'outlet'   => countOf('outlet', $companyId),
        'register' => countOf('register', $companyId),
    ];

    $run2 = (new EncomImportService($companyId, $source, null))
        ->run(['catalog', 'customers', 'config']);
    $p2 = $run2['progress'];

    check(
        'B1 · la segunda corrida no importa nada nuevo (artículos)',
        ($p2['item']['imported'] ?? -1) === 0 && ($p2['item']['skipped'] ?? 0) === 3,
        'progress.item = ' . json_encode($p2['item'] ?? null),
        $failures, $checks
    );

    check(
        'B2 · la segunda corrida no importa nada nuevo (clientes)',
        ($p2['customer']['imported'] ?? -1) === 0 && ($p2['customer']['skipped'] ?? 0) === 2,
        'progress.customer = ' . json_encode($p2['customer'] ?? null),
        $failures, $checks
    );

    check(
        'B3 · la segunda corrida no importa nada nuevo (cajas)',
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

    // Y la serie no se movió: re-correr NO puede volver a mover el contador
    // de una caja fiscal (sería saltear números emitidos).
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

    $badSource = new FixtureEncomSource($fixtures . '/export-duplicado.json');
    $runBad    = (new EncomImportService($companyB, $badSource, null))->run(['config']);

    $errText = json_encode($runBad['errors'], JSON_UNESCAPED_UNICODE);

    check(
        'E1 · el dominio config falla cuando dos cajas comparten timbrado + punto de expedición',
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

    // Lo que D5 exige: NO se importa a medias. La sucursal sí entró (se
    // importa antes y no tiene nada que ver con el choque), así que la única
    // caja que puede existir es su placeholder — ninguna caja del legacy.
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
        "SELECT count(*) FROM document_sequence
          WHERE companyid = ? AND invoiceauth = '16543210'",
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

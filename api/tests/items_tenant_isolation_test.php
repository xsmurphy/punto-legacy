<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (DB real) del AISLAMIENTO MULTI-TENANT de los
 * sub-recursos de `/v1/items` — categorías, marcas, etiquetas y depósitos.
 *
 * ── La vulnerabilidad que cierra (auditoría 2026-08-26) ──────────────────────
 * `PUT /v1/items?id=<itemId>&resource=categories|brands|tags` ejecutaba
 * `DELETE FROM item_category WHERE itemId = ?` (y sus gemelas de brand/tag) con
 * el `itemId` crudo de `$_GET`, SIN validar que el ítem sea del tenant. Esas
 * tablas m2m no tienen columna `companyId`: su único scope legítimo es que el
 * ítem PADRE pertenezca a `COMPANY_ID`. Ese guard faltaba. El sub-recurso
 * `locations` tenía la misma clase de bug un nivel más abajo:
 * `LocationService::syncForItem/detach/listForItem/setDefault` filtraban solo
 * por `itemId`. Un operador del tenant A, con solo conocer el UUID de un ítem
 * del tenant B, borraba/reescribía la clasificación y los depósitos del ítem
 * ajeno (IDOR destructivo cross-tenant).
 *
 * ── Las dos mitades del fix ──────────────────────────────────────────────────
 *   1. Guard de pertenencia único en `api/v1/items.php`, justo tras parsear
 *      `$id`: si la request trae `id`, el ítem tiene que ser del tenant o corta
 *      404 ANTES de despachar a cualquier sub-recurso. Cubre categories/brands/
 *      tags (SQL inline) y locations de una.
 *   2. `LocationService` scopea por `companyId` en TODOS sus statements de
 *      escritura/lectura (defense-in-depth: el aislamiento no depende de que el
 *      caller se acuerde del guard).
 *
 * Este arnés verifica (2) ejercitando el service real contra dos tenants, y
 * verifica que (1) sigue presente en el endpoint (chequeo estático — el flujo
 * HTTP completo con sesión de panel + permisos se cubre en el guard estático,
 * el comportamiento cross-tenant se prueba vivo abajo).
 *
 * Uso (necesita Postgres migrado — Docker, ver run_items_tenant_isolation_test.sh):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/items_tenant_isolation_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Items\LocationService;

/** @var \Punto\Api\Database\Query $db */
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

/** ¿Cuántas filas de itemLocation tiene este item (a nivel DB, sin filtro de tenant)? */
function locCount(string $itemId): int
{
    global $db;
    $rs = $db->Execute('SELECT count(*) AS n FROM itemLocation WHERE itemId = ?', [$itemId]);
    return ($rs === false || $rs->EOF) ? -1 : (int) $rs->fields['n'];
}

// ── Tenant A: reusar la company del seed "Verify PY" ──────────────────────────
$companyA = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletA  = '1a282724-6073-49c3-8bc3-0114a132e349';

// ── IDs efímeros (todo lo que este arnés crea, lo borra en el finally) ────────
$companyB = _isoUuid();
$outletB  = _isoUuid();
$itemA    = _isoUuid();
$itemB    = _isoUuid();
$locA     = _isoUuid();
$locB     = _isoUuid();

function _isoUuid(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

$created = ['itemLocation' => [], 'item' => [], 'taxonomy' => [], 'outlet' => [], 'company' => []];

try {
    // ── Fixtures ──────────────────────────────────────────────────────────────
    // Company B (tenant víctima independiente del seed).
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"ISO Test B\"}'::jsonb)",
        [$companyB]
    );
    $created['company'][] = $companyB;

    $db->Execute(
        'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
        [$outletB, 'ISO Test B - Sucursal', $companyB]
    );
    $created['outlet'][] = $outletB;

    // Un ítem por tenant.
    $db->Execute('INSERT INTO item (itemId, itemName, companyId) VALUES (?, ?, ?)', [$itemA, 'ISO item A', $companyA]);
    $created['item'][] = $itemA;
    $db->Execute('INSERT INTO item (itemId, itemName, companyId) VALUES (?, ?, ?)', [$itemB, 'ISO item B', $companyB]);
    $created['item'][] = $itemB;

    // Un depósito (taxonomy location) por tenant.
    $db->Execute(
        "INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, outletId, taxonomyName)
         VALUES (?, ?, 'location', ?, ?)",
        [$locA, $companyA, $outletA, 'ISO loc A']
    );
    $created['taxonomy'][] = $locA;
    $db->Execute(
        "INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, outletId, taxonomyName)
         VALUES (?, ?, 'location', ?, ?)",
        [$locB, $companyB, $outletB, 'ISO loc B']
    );
    $created['taxonomy'][] = $locB;

    // Asignación item→depósito por tenant (insert directo, no vía attach).
    $db->Execute(
        'INSERT INTO itemLocation (itemId, locationId, outletId, isDefault, companyId) VALUES (?, ?, ?, TRUE, ?)',
        [$itemA, $locA, $outletA, $companyA]
    );
    $created['itemLocation'][] = $itemA;
    $db->Execute(
        'INSERT INTO itemLocation (itemId, locationId, outletId, isDefault, companyId) VALUES (?, ?, ?, TRUE, ?)',
        [$itemB, $locB, $outletB, $companyB]
    );
    $created['itemLocation'][] = $itemB;

    $svc = new LocationService($db);

    // ── (a) EXPLOIT destructivo: tenant A intenta vaciar los depósitos de B ────
    // Antes del fix: `syncForItem` leía y borraba por itemId a secas → wipeaba
    // la fila de B. Con el fix: el SELECT/detach scopean por companyId=A, no
    // matchea nada del ítem de B, y la fila de B sobrevive.
    $svc->syncForItem($itemB, $companyA, [], null);
    check(
        '(a) syncForItem(itemB, companyA, []) NO borra el depósito del tenant B',
        locCount($itemB) === 1,
        'itemLocation de itemB quedó en ' . locCount($itemB) . ' filas (esperado 1 — intacto)',
        $failures,
        $checks
    );

    // ── (b) Lectura cross-tenant: A no ve los depósitos de B ───────────────────
    $readCross = $svc->listForItem($itemB, $companyA);
    check(
        '(b) listForItem(itemB, companyA) NO devuelve los depósitos del tenant B',
        count($readCross) === 0,
        'devolvió ' . count($readCross) . ' filas (esperado 0)',
        $failures,
        $checks
    );

    // ── (c) Regresión: el tenant dueño SÍ lee sus depósitos ────────────────────
    $readOwn = $svc->listForItem($itemB, $companyB);
    check(
        '(c) listForItem(itemB, companyB) SÍ devuelve el depósito propio (no sobre-filtra)',
        count($readOwn) === 1,
        'devolvió ' . count($readOwn) . ' filas (esperado 1)',
        $failures,
        $checks
    );

    // ── (d) Regresión: el sync del propio tenant sigue funcionando ─────────────
    $svc->syncForItem($itemA, $companyA, [], null);
    check(
        '(d) syncForItem(itemA, companyA, []) SÍ vacía los depósitos del propio ítem',
        locCount($itemA) === 0,
        'itemLocation de itemA quedó en ' . locCount($itemA) . ' filas (esperado 0)',
        $failures,
        $checks
    );

    // ── (e) Guard estático del endpoint: cubre categories/brands/tags ──────────
    // Su exploit es SQL inline en items.php (DELETE FROM item_category ... por
    // itemId), no pasa por un service testeable acá. El guard de pertenencia
    // único, justo tras `$id = $_GET['id']`, es lo que los protege a los tres.
    $src = (string) @file_get_contents(dirname(__DIR__) . '/v1/items.php');
    // Buscamos los STATEMENTS ejecutables (`->Execute('...`), no la prosa: el
    // docblock del guard cita el mismo DELETE como ejemplo, y un str_contains
    // pelado matchearía ese comentario.
    $guardStmt  = "->Execute('SELECT 1 FROM item WHERE itemId = ? AND companyId = ?";
    $deleteStmt = "->Execute('DELETE FROM item_category WHERE itemId = ?'";
    $hasGuardSql = str_contains($src, $guardStmt);
    // El guard tiene que estar ANTES del DELETE del sub-recurso m2m.
    $guardPos  = strpos($src, $guardStmt);
    $deletePos = strpos($src, $deleteStmt);
    $guardBeforeDelete = $hasGuardSql && $deletePos !== false && $guardPos !== false && $guardPos < $deletePos;
    check(
        '(e) items.php tiene el guard de pertenencia ANTES del DELETE de item_category',
        $guardBeforeDelete,
        'guard presente=' . ($hasGuardSql ? 'si' : 'no') . ' posición-correcta=' . ($guardBeforeDelete ? 'si' : 'no'),
        $failures,
        $checks
    );

    // El guard cuelga del parseo de $id, no de una rama puntual.
    check(
        '(e2) el guard cuelga de `$id = $_GET[\'id\']` (choke point único)',
        (bool) preg_match('/\$id\s*=\s*\$_GET\[.id.\].*?SELECT 1 FROM item WHERE itemId = \? AND companyId = \?/s', $src),
        'no se encontró el guard inmediatamente tras el parseo de $id',
        $failures,
        $checks
    );

} finally {
    foreach ($created['itemLocation'] as $it) {
        try { $db->Execute('DELETE FROM itemLocation WHERE itemId = ?', [$it]); } catch (\Throwable) {}
    }
    foreach ($created['item'] as $it) {
        try { $db->Execute('DELETE FROM item WHERE itemId = ?', [$it]); } catch (\Throwable) {}
    }
    foreach ($created['taxonomy'] as $t) {
        try { $db->Execute('DELETE FROM taxonomy WHERE taxonomyId = ?', [$t]); } catch (\Throwable) {}
    }
    foreach ($created['outlet'] as $o) {
        try { $db->Execute('DELETE FROM outlet WHERE outletId = ?', [$o]); } catch (\Throwable) {}
    }
    foreach ($created['company'] as $c) {
        try { $db->Execute('DELETE FROM company WHERE companyId = ?', [$c]); } catch (\Throwable) {}
    }
}

harnessFinish($failures, $checks);

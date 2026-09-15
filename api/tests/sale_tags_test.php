<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de las ETIQUETAS DE LA VENTA (`toTag`) — regresión del incidente del
 * 2026-09-15.
 *
 * ── Qué pasó ────────────────────────────────────────────────────────────────
 * Un cajero escribió una etiqueta NUEVA ("Venta Whatsapp") en el campo de
 * etiquetas de la venta. El campo es de chips de texto libre, pero el bloque B7
 * de `SaleService` comparaba ese texto contra `taxonomy.taxonomyId`, que es
 * `uuid`. Postgres aborta esa comparación con
 *
 *     SQLSTATE[22P02] invalid input syntax for type uuid: "Venta Whatsapp"
 *
 * ANTES de poder devolver cero filas, así que el "tag inexistente → omitido, no
 * aborta la venta" que el propio bloque decía hacer NUNCA se ejecutaba. Dos
 * ventas ya COBRADAS de un tenant real quedaron trabadas para siempre en la
 * cola del POS, reintentándose solas, por una etiqueta decorativa.
 *
 * ── Por qué este arnés es de integración y no un unit test ──────────────────
 * El bug era un error de TIPO de Postgres. Con el `$db` mockeado la comparación
 * nunca falla y el test pasa en verde con el bug puesto. Sin PG real no se
 * cubre nada.
 *
 * Lo que verifica:
 *
 *   (A) EL CASO DEL INCIDENTE: una venta con una etiqueta que NO es uuid entra,
 *       y la etiqueta queda creada en el catálogo del tenant y linkeada.
 *   (B) El catálogo no se llena de variantes: la misma etiqueta con otro casing
 *       reusa el `tagId` que ya existe (mismo criterio que el índice único
 *       `uq_tag_company_name` de la mig 39).
 *   (C) `tag` y `taxonomy` quedan en sync (los triggers de la mig 39), que es
 *       lo que hace válida la FK `toTag.tagId → taxonomy(taxonomyId)`.
 *   (D) Dos casings de lo mismo en la MISMA venta → UN solo link (`toTag` no
 *       tiene constraint que lo impida).
 *   (E) Un uuid que no existe se omite y la venta entra igual.
 *   (F) Un uuid de OTRO tenant se omite: sigue habiendo scope por companyId.
 *   (G) El camino viejo no se rompió: un uuid VÁLIDO del propio tenant linkea.
 *
 * El que más importa es (A): es la venta cobrada que hoy no entra.
 *
 * No limpia lo que crea — `run_sale_tags_test.sh` levanta un Postgres
 * descartable y lo destruye al terminar.
 *
 * Uso (ver `run_sale_tags_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/sale_tags_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Tags\TagService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$roleId     = '1';
require API_APP_DIR . '/data.php';

// Segundo tenant del mismo seed — para el caso (F).
$otherCompanyId = 'fa8cf679-9003-417e-8726-5b772d3b6e88';

/** @var \DB $db */
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

$ctx     = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$service = new SaleService($ctx, $db);
$tags    = new TagService($db);

$itemRow = $db->Execute(
    'SELECT itemId FROM item WHERE itemSKU = ? AND companyId = ? LIMIT 1',
    ['VERIFY-10-INC', $companyId]
);
if (!$itemRow || $itemRow->EOF) {
    fwrite(STDERR, "No se encontró el item fixture VERIFY-10-INC — revisar seed.sql\n");
    exit(1);
}
$itemId = (string) $itemRow->fields['itemid'];

// Numeración bien arriba del último usado, para no chocar con
// uq_transaction_expedition_invoiceno si el arnés corre dos veces.
$nextNo = 800000 + random_int(1, 90000);

/**
 * Guarda una venta de contado con las etiquetas dadas.
 *
 * @param array<int,string> $saleTags
 * @return array{ok:bool, transId:?string, error:?string}
 */
function sell(SaleService $service, string $companyId, string $itemId, int $invoiceNo, array $saleTags): array
{
    $payload = [
        'transaction' => [
            'uid'       => 'sale-tags-test-' . bin2hex(random_bytes(8)),
            'type'      => 0,
            'invoiceno' => $invoiceNo,
            'sale'      => [[
                'itemId' => $itemId, 'count' => 1, 'name' => 'Test etiquetas',
                'uniPrice' => 11000, 'price' => 11000, 'total' => 11000,
                'tax' => 0, 'discount' => 0, 'totalDiscount' => 0,
                'user' => '', 'type' => '', 'date' => '', 'note' => '',
                'currency' => '', 'uId' => 0,
            ]],
            'subtotal'  => 11000,
            'tax'       => 0,
            'discount'  => 0,
            'payment'   => [['type' => 'cash', 'name' => 'Efectivo', 'total' => 11000]],
            'date'      => date('Y-m-d H:i:s'),
            'timestamp' => time(),
            'tags'      => $saleTags,
        ],
    ];

    try {
        $input  = SaleInput::fromPayload($payload, $companyId);
        $result = $service->save($input);
        return ['ok' => true, 'transId' => (string) $result->transactionId, 'error' => null];
    } catch (\Throwable $e) {
        return ['ok' => false, 'transId' => null, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
}

/** @return array<int,string> tagIds linkeados a la transacción. */
function linkedTagIds(string $transId): array
{
    global $db;
    $rs = $db->Execute('SELECT tagId FROM toTag WHERE parentId = ? AND toTagType = 0', [$transId]);
    if ($rs === false) return [];
    $out = [];
    foreach ($rs->GetRows() as $row) {
        $out[] = (string) ($row['tagid'] ?? $row['tagId']);
    }
    return $out;
}

function countTagsNamed(string $companyId, string $name): int
{
    global $db;
    $rs = $db->Execute(
        'SELECT COUNT(*) AS n FROM tag WHERE companyId = ? AND LOWER(name) = LOWER(?)',
        [$companyId, $name]
    );
    return ($rs === false || $rs->EOF) ? 0 : (int) $rs->fields['n'];
}

// Nombre único por corrida: el tenant fixture es compartido con otros arneses.
$nuevaEtiqueta = 'Venta Whatsapp ' . bin2hex(random_bytes(4));

// ═══════════════════════════════════════════════════════════════════════════
// (A) El caso del incidente: etiqueta escrita a mano, no-uuid
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (A) venta con una etiqueta NUEVA de texto libre ===\n";
$a = sell($service, $companyId, $itemId, $nextNo++, [$nuevaEtiqueta]);
check('(A1) la venta ENTRA (antes: 22P02 invalid input syntax for type uuid)',
    $a['ok'] === true, (string) $a['error'], $failures, $checks);

$tagId = null;
if ($a['ok']) {
    check('(A2) la etiqueta quedó creada en el catálogo del tenant',
        countTagsNamed($companyId, $nuevaEtiqueta) === 1,
        'filas en `tag` con ese nombre: ' . countTagsNamed($companyId, $nuevaEtiqueta), $failures, $checks);

    $linked = linkedTagIds((string) $a['transId']);
    check('(A3) y quedó linkeada a la venta', count($linked) === 1,
        'links en toTag: ' . json_encode($linked), $failures, $checks);
    $tagId = $linked[0] ?? null;

    // (C) — la FK de toTag apunta a taxonomy, no a tag: si el trigger no
    // replicara la fila, el INSERT de (A3) habría fallado y con él la venta.
    $tx = $db->Execute(
        "SELECT taxonomyName FROM taxonomy
          WHERE taxonomyId = ? AND taxonomyType = 'tag' AND companyId = ? LIMIT 1",
        [$tagId, $companyId]
    );
    check('(C1) `tag` y `taxonomy` quedaron en sync (triggers mig 39)',
        $tx && !$tx->EOF && (string) $tx->fields['taxonomyname'] === $nuevaEtiqueta,
        'taxonomy no tiene la fila espejo del tag ' . (string) $tagId, $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// (B) La misma etiqueta con otro casing NO crea una segunda
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (B) la misma etiqueta con otro casing, en otra venta ===\n";
$b = sell($service, $companyId, $itemId, $nextNo++, [mb_strtolower($nuevaEtiqueta)]);
check('(B1) la venta entra', $b['ok'] === true, (string) $b['error'], $failures, $checks);
check('(B2) y NO se creó una segunda etiqueta',
    countTagsNamed($companyId, $nuevaEtiqueta) === 1,
    'el catálogo se llenó de variantes: ' . countTagsNamed($companyId, $nuevaEtiqueta) . ' filas', $failures, $checks);
if ($b['ok'] && $tagId !== null) {
    check('(B3) y linkea el MISMO tagId que la venta (A)',
        linkedTagIds((string) $b['transId']) === [$tagId],
        'links: ' . json_encode(linkedTagIds((string) $b['transId'])), $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// (D) Dos casings de lo mismo en la MISMA venta → un solo link
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (D) la misma etiqueta dos veces en una venta ===\n";
$d = sell($service, $companyId, $itemId, $nextNo++, [$nuevaEtiqueta, mb_strtoupper($nuevaEtiqueta)]);
check('(D1) la venta entra', $d['ok'] === true, (string) $d['error'], $failures, $checks);
if ($d['ok']) {
    check('(D2) y se linkea UNA sola vez', count(linkedTagIds((string) $d['transId'])) === 1,
        'links: ' . json_encode(linkedTagIds((string) $d['transId'])), $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// (E) Un uuid que no existe se omite, la venta entra
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (E) uuid inexistente ===\n";
$e = sell($service, $companyId, $itemId, $nextNo++, ['3f2504e0-4f89-41d3-9a0c-0305e82c3301']);
check('(E1) la venta entra', $e['ok'] === true, (string) $e['error'], $failures, $checks);
if ($e['ok']) {
    check('(E2) y no se linkeó nada', linkedTagIds((string) $e['transId']) === [],
        'links: ' . json_encode(linkedTagIds((string) $e['transId'])), $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// (F) Un uuid de OTRO tenant se omite
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (F) uuid de otro tenant ===\n";
$foreignTagId = $tags->resolveOrCreateByName($otherCompanyId, 'Etiqueta ajena ' . bin2hex(random_bytes(4)));
$f = sell($service, $companyId, $itemId, $nextNo++, [(string) $foreignTagId]);
check('(F1) la venta entra', $f['ok'] === true, (string) $f['error'], $failures, $checks);
if ($f['ok']) {
    check('(F2) y la etiqueta del otro tenant NO se linkea',
        linkedTagIds((string) $f['transId']) === [],
        'links: ' . json_encode(linkedTagIds((string) $f['transId'])), $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// (G) El camino viejo sigue andando: uuid válido del propio tenant
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (G) uuid válido del propio tenant ===\n";
$ownTagId = $tags->resolveOrCreateByName($companyId, 'Etiqueta propia ' . bin2hex(random_bytes(4)));
$g = sell($service, $companyId, $itemId, $nextNo++, [(string) $ownTagId]);
check('(G1) la venta entra', $g['ok'] === true, (string) $g['error'], $failures, $checks);
if ($g['ok']) {
    check('(G2) y linkea por id, como antes',
        linkedTagIds((string) $g['transId']) === [(string) $ownTagId],
        'links: ' . json_encode(linkedTagIds((string) $g['transId'])), $failures, $checks);
}

harnessFinish($failures, $checks);

<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del reporte fiscal PY (`FiscalService::rg90()`/`libroVentas()`,
 * `GET /v1/reports/fiscal`). F5, context/38-impuestos-multi-pais.md §E.
 *
 * El service lee el desglose fiscal CONGELADO por venta (`toTaxObj`), nunca
 * recalcula contra el catálogo — así que este arnés siembra ventas por SQL
 * directo con su fila de `toTaxObj` a mano (no pasa por SaleService: no hace
 * falta ejercitar el motor de venta completo, solo el export). Mismo patrón
 * que `operations_report_test.php`: fixtures con horarios/valores exactos,
 * comparados contra el número calculado a mano abajo.
 *
 * ── Los casos que importan ───────────────────────────────────────────────────
 *
 *   C1  RUC (contactIdType=11): RG90 pierde el dígito verificador
 *       ('80012345-6' → '80012345', Marangatu rechaza la fila con el DV
 *       pegado) pero Libro Ventas — uso interno del contador — lo conserva
 *       en la MISMA venta. Mismo caso trae el html_entity_decode (C3) y la
 *       prueba de que el prefijo CONGELADO en la transacción le gana al de
 *       la caja (C4): la caja de esta venta tiene otro prefijo vigente hoy.
 *   C2  CI (contactIdType=12): `stripCheckDigit` NO toca nada fuera de RUC —
 *       la CI lleva un guion a propósito para probar que no se trunca nada
 *       que no sea un DV de RUC. Reusa la venta para condicion de venta=2
 *       (transactionType=3, crédito).
 *   C3  html_entity_decode del nombre — ver C1 (mismo comprador, nombre
 *       guardado con '&amp;' como lo dejaba el panel legacy).
 *   C4  ver C1 — el prefijo congelado en `transaction.invoicePrefix` gana
 *       sobre `register.data.registerInvoicePrefix` (context/29, mig 209):
 *       el punto de expedición de un comprobante ya emitido es el que tenía
 *       al emitirse, no el que la caja tenga hoy.
 *   C5  Libro Ventas: orden EXACTO de las 14 columnas + encabezados
 *       literales, con las tres tasas (10%/5%/exenta) presentes en la MISMA
 *       venta para que TOTAL (col 11) y EXENTO (col 14) salgan de sumas
 *       reales, no de un solo bucket.
 *   C6  Venta interna (tag 166227, `isInternalSale($tags, true)`): queda
 *       SIEMPRE fuera — se corre dos veces con `ignoreInternal` apagado y
 *       prendido en `$_fullSettings` para probar que acá NO es una
 *       preferencia del tenant (a diferencia de los reportes de gestión):
 *       una venta interna no es declarable ante la SET.
 *   C7  Paginación: > `PAGE_SIZE` (2000) ventas — 5001, para que el barrido
 *       cruce 3 páginas — sembradas con UN `INSERT ... SELECT` sobre
 *       `generate_series` (no 5001 `ncmExecute` sueltos: el seed tiene que
 *       ser rápido). Protege contra el LIMIT 5000 que se acaba de eliminar:
 *       si volviera, `rows` daría 5000 en vez de 5001. Usa una caja PROPIA
 *       para no chocar contra el índice único de comprobante
 *       (companyid, registerid, timbrado, invoiceno — mig 145) con C1-C6,
 *       que ya usan invoiceNo 123-127 en la caja principal.
 *   C8  Aislamiento: una venta de OTRA empresa (misma ventana) y una venta
 *       ANULADA (`voidedAt`) de la empresa propia no aparecen.
 *
 * Uso: `bash api/tests/run_fiscal_report_test.sh` (levanta Postgres
 * descartable).
 */

$companyId    = '0fca1000-0000-4000-8000-000000000001';
$companyB     = '0fca1000-0000-4000-8000-000000000002';
$outletA      = '0fca1000-0000-4000-8000-000000000011';
$outletB      = '0fca1000-0000-4000-8000-000000000012';
$registerA    = '0fca1000-0000-4000-8000-000000000021';
$registerBulk = '0fca1000-0000-4000-8000-000000000022';
$registerB    = '0fca1000-0000-4000-8000-000000000023';
$userA        = '0fca1000-0000-4000-8000-000000000031';
$userB        = '0fca1000-0000-4000-8000-000000000032';
$custRuc      = '0fca1000-0000-4000-8000-000000000041'; // C1/C3/C4
$custCi       = '0fca1000-0000-4000-8000-000000000042'; // C2
$custBulk     = '0fca1000-0000-4000-8000-000000000043'; // C7
$custB        = '0fca1000-0000-4000-8000-000000000044'; // C8

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletA);
define('USER_ID',    $userA);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Contacts\ContactService;
use Punto\Api\Reports\FiscalService;
use Punto\Api\Reports\Roc;

/** @var \Punto\Api\Database\Query $db */
global $db;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) { echo "OK   $label\n"; return; }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function near(?float $a, float $b): bool
{
    return $a !== null && abs($a - $b) < 0.01;
}

function v(mixed $x): string
{
    return var_export($x, true);
}

// ── Fixtures ────────────────────────────────────────────────────────────────

/**
 * Inserta una venta mínima propia de este arnés y devuelve su transactionId.
 * `$tags`/`$voidedAt` opcionales cubren C6/C8. `$invoicePrefix` es el que
 * queda CONGELADO en la transacción (columna real, no el de la caja).
 */
function seedSale(
    string $companyId,
    string $outletId,
    string $registerId,
    string $userId,
    string $customerId,
    int    $invoiceNo,
    string $invoicePrefix,
    int    $type,
    string $date,
    float  $total,
    ?array $tags = null,
    ?string $voidedAt = null
): string {
    global $db;
    $meta = $tags !== null ? json_encode(['tags' => $tags]) : '{}';
    $rs = $db->Execute(
        "INSERT INTO transaction
             (transactionDate, transactionDiscount, transactionTotal, transactionType,
              invoiceNo, invoicePrefix, customerId, registerId, userId, outletId, companyId,
              meta, voidedat)
         VALUES (?::timestamptz, 0, ?, ?, ?, ?, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid,
                 ?::jsonb, ?::timestamptz)
         RETURNING transactionId",
        [$date, $total, $type, $invoiceNo, $invoicePrefix, $customerId, $registerId, $userId,
         $outletId, $companyId, $meta, $voidedAt]
    );
    return (string) $rs->fields['transactionid'];
}

/**
 * Fila de `toTaxObj` — el desglose fiscal CONGELADO que `FiscalService` lee
 * (nunca recalcula). Sin esta fila la venta queda EXCLUIDA (excludedCount).
 *
 * @param list<array{rate:float,kind:string,base:float,amount:float}> $buckets
 */
function seedTaxObj(string $companyId, string $transactionId, array $buckets): void
{
    global $db;
    $withTaxId = array_map(static fn ($b) => ['taxId' => null] + $b, $buckets);
    $db->Execute(
        "INSERT INTO toTaxObj (toTaxObjText, transactionId, companyId) VALUES (?, ?::uuid, ?::uuid)",
        [json_encode($withTaxId), $transactionId, $companyId]
    );
}

$cleanup = static function () use ($companyId, $companyB): void {
    global $db;
    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute('DELETE FROM toTaxObj  WHERE companyId = ?', [$cid]);
        $db->Execute('DELETE FROM transaction WHERE companyId = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM contact   WHERE companyId = ?', [$cid]);
        $db->Execute('DELETE FROM register  WHERE companyId = ?', [$cid]);
        $db->Execute('DELETE FROM outlet    WHERE companyId = ?', [$cid]);
        $db->Execute('DELETE FROM company   WHERE companyId = ?', [$cid]);
    }
};

try {
    $cleanup();
    $db->Execute("SET TIME ZONE 'UTC'");

    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute(
            "INSERT INTO company (companyId, status, plan, balance, isParent, config)
             VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Fiscal Report Test\",\"settingDecimal\":\"yes\"}'::jsonb)",
            [$cid]
        );
    }
    foreach ([[$outletA, $companyId], [$outletB, $companyB]] as [$o, $c]) {
        $db->Execute('INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
            [$o, 'Sucursal Fiscal', $c]);
    }

    // Caja principal: timbrado + prefijo VIGENTES distintos del que queda
    // congelado en las transacciones — así C4 prueba que el congelado gana.
    $db->Execute(
        "INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId, data)
         VALUES (?::uuid, 'Caja Fiscal A', TRUE, ?::uuid, ?::uuid, ?::jsonb)",
        [$registerA, $outletA, $companyId, json_encode(['registerInvoiceAuth' => '12345678', 'registerInvoicePrefix' => '999-999'])]
    );
    // Caja propia de C7 (paginación): así su rango de invoiceNo 1..5001 no
    // choca con el índice único (companyid, registerid, timbrado, invoiceno)
    // de la caja principal, que ya usa 123-127.
    $db->Execute(
        "INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId, data)
         VALUES (?::uuid, 'Caja Fiscal Bulk', TRUE, ?::uuid, ?::uuid, '{}'::jsonb)",
        [$registerBulk, $outletA, $companyId]
    );
    $db->Execute(
        "INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId, data)
         VALUES (?::uuid, 'Caja Fiscal B', TRUE, ?::uuid, ?::uuid, '{}'::jsonb)",
        [$registerB, $outletB, $companyB]
    );

    // Cajeros (contact type=0) — transaction.userId es NOT NULL FK a contact.
    $db->Execute('INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus) VALUES (?, ?, ?, ?, 0, 1)',
        [$userA, 'Cajero Fiscal A', $companyId, $outletA]);
    $db->Execute('INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus) VALUES (?, ?, ?, ?, 0, 1)',
        [$userB, 'Cajero Fiscal B', $companyB, $outletB]);

    // Comprador RUC (C1/C3/C4): nombre HTML-escapado como lo dejaba el panel
    // legacy ('&amp;'), RUC con dígito verificador.
    $db->Execute(
        "INSERT INTO contact (contactId, contactName, contactTIN, contactIdType, companyId, type, contactStatus)
         VALUES (?, ?, ?, ?, ?, 1, 1)",
        [$custRuc, 'Cliente &amp; Cía', '80012345-6', ContactService::ID_TYPE_RUC, $companyId]
    );
    // Comprador CI (C2): CI con GUION a propósito — stripCheckDigit solo
    // debe tocar RUC, así que este guion tiene que sobrevivir intacto.
    $db->Execute(
        "INSERT INTO contact (contactId, contactName, contactIdType, companyId, type, contactStatus, data)
         VALUES (?, ?, ?, ?, 1, 1, ?::jsonb)",
        [$custCi, 'Cliente CI', ContactService::ID_TYPE_CEDULA, $companyId, json_encode(['contactCI' => '456-7890'])]
    );
    $db->Execute(
        "INSERT INTO contact (contactId, contactName, contactTIN, contactIdType, companyId, type, contactStatus)
         VALUES (?, ?, ?, ?, ?, 1, 1)",
        [$custBulk, 'Cliente Bulk', '80099999-1', ContactService::ID_TYPE_RUC, $companyId]
    );
    $db->Execute(
        "INSERT INTO contact (contactId, contactName, contactTIN, contactIdType, companyId, type, contactStatus)
         VALUES (?, ?, ?, ?, ?, 1, 1)",
        [$custB, 'Cliente Empresa B', '80088888-2', ContactService::ID_TYPE_RUC, $companyB]
    );

    // ── Rango principal: C1-C6, C8 ──────────────────────────────────────────
    $from = '2026-03-01 00:00:00';
    $to   = '2026-03-31 23:59:59';

    // TX1 (C1+C3+C4): factura contado, RUC, prefijo congelado '001-001'
    // (caja vigente dice '999-999' — ver arriba).
    $tx1 = seedSale($companyId, $outletA, $registerA, $userA, $custRuc, 123, '001-001', 0,
        '2026-03-15 10:00:00+00', 1100.0);
    seedTaxObj($companyId, $tx1, [['rate' => 10, 'kind' => 'rate', 'base' => 1000, 'amount' => 100]]);

    // TX2 (C2): venta a CRÉDITO (condicion=2), comprador CI.
    $tx2 = seedSale($companyId, $outletA, $registerA, $userA, $custCi, 124, '001-001', 3,
        '2026-03-15 11:00:00+00', 550.0);
    seedTaxObj($companyId, $tx2, [['rate' => 10, 'kind' => 'rate', 'base' => 500, 'amount' => 50]]);

    // TX3 (C5): las tres tasas juntas para que TOTAL/EXENTO salgan de una suma real.
    $tx3 = seedSale($companyId, $outletA, $registerA, $userA, $custRuc, 125, '001-001', 0,
        '2026-03-16 09:00:00+00', 1825.0);
    seedTaxObj($companyId, $tx3, [
        ['rate' => 10, 'kind' => 'rate',   'base' => 1000, 'amount' => 100],
        ['rate' => 5,  'kind' => 'rate',   'base' => 500,  'amount' => 25],
        ['rate' => 0,  'kind' => 'exempt', 'base' => 200,  'amount' => 0],
    ]);

    // TX4 (C6): venta INTERNA (tag 166227) — siempre fuera, sin importar el ajuste del tenant.
    $tx4 = seedSale($companyId, $outletA, $registerA, $userA, $custRuc, 126, '001-001', 0,
        '2026-03-16 10:00:00+00', 1100.0, ['166227']);
    seedTaxObj($companyId, $tx4, [['rate' => 10, 'kind' => 'rate', 'base' => 1000, 'amount' => 100]]);

    // TX5 (C8): venta ANULADA — voidedAt seteado, dentro del rango.
    $tx5 = seedSale($companyId, $outletA, $registerA, $userA, $custRuc, 127, '001-001', 0,
        '2026-03-16 11:00:00+00', 1100.0, null, '2026-03-16 12:00:00+00');
    seedTaxObj($companyId, $tx5, [['rate' => 10, 'kind' => 'rate', 'base' => 1000, 'amount' => 100]]);

    // TX6 (C8): venta de OTRA EMPRESA, mismo rango de fechas.
    $tx6 = seedSale($companyB, $outletB, $registerB, $userB, $custB, 128, '002-002', 0,
        '2026-03-16 11:30:00+00', 1100.0);
    seedTaxObj($companyB, $tx6, [['rate' => 10, 'kind' => 'rate', 'base' => 1000, 'amount' => 100]]);

    $roc = Roc::build($companyId, '');
    $svc = new FiscalService();

    // ── RG90 ────────────────────────────────────────────────────────────────
    $rg90 = $svc->rg90($from, $to, $roc, $companyId);
    $rows = $rg90['rows'];
    $meta = $rg90['meta'];

    check('rg90: 3 filas (TX1-3; sin interna/anulada/otra empresa)', count($rows) === 3, 'obtenido ' . count($rows), $failures, $checks);
    check('rg90: meta.totalCount = 3', ($meta['totalCount'] ?? null) === 3, 'obtenido ' . v($meta['totalCount'] ?? null), $failures, $checks);
    check('rg90: meta.excludedCount = 0 (todas con toTaxObj)', ($meta['excludedCount'] ?? null) === 0, 'obtenido ' . v($meta['excludedCount'] ?? null), $failures, $checks);
    check('rg90: meta.fallbackCount = 0 (todas resolvieron por toTaxObj directo)', ($meta['fallbackCount'] ?? null) === 0, 'obtenido ' . v($meta['fallbackCount'] ?? null), $failures, $checks);

    // ORDER BY invoiceNo DESC: TX3(125), TX2(124), TX1(123).
    $byInvoice = [];
    foreach ($rows as $r) {
        $byInvoice[$r['NUMERO DEL COMPROBANTE']] = $r;
    }
    $r1 = $byInvoice['0010010000123'] ?? null; // TX1
    $r2 = $byInvoice['0010010000124'] ?? null; // TX2
    $r3 = $byInvoice['0010010000125'] ?? null; // TX3

    check('rg90: TX1/TX2/TX3 presentes por número de comprobante', $r1 !== null && $r2 !== null && $r3 !== null,
        'claves ' . json_encode(array_keys($byInvoice)), $failures, $checks);

    if ($r1 !== null) {
        // C1: RUC sin DV.
        check('C1 rg90: RUC sin dígito verificador (80012345-6 → 80012345)',
            $r1['NUMERO DE IDENTIFICACION DEL COMPRADOR'] === '80012345',
            'obtenido ' . v($r1['NUMERO DE IDENTIFICACION DEL COMPRADOR']), $failures, $checks);
        check('C1 rg90: tipo de identificación = 11 (RUC)',
            $r1['CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR'] === ContactService::ID_TYPE_RUC,
            'obtenido ' . v($r1['CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR']), $failures, $checks);
        // C3: html_entity_decode.
        check("C3 rg90: nombre decodificado ('&amp;' → '&')",
            $r1['NOMBRE O RAZON SOCIAL DEL COMPRADOR'] === 'Cliente & Cía',
            'obtenido ' . v($r1['NOMBRE O RAZON SOCIAL DEL COMPRADOR']), $failures, $checks);
        // C4: prefijo congelado (001-001) le gana al vigente de la caja (999-999).
        check("C4 rg90: número pegado con el prefijo CONGELADO, no el de la caja (999-999)",
            $r1['NUMERO DEL COMPROBANTE'] === '0010010000123',
            'obtenido ' . v($r1['NUMERO DEL COMPROBANTE']), $failures, $checks);
        check('rg90: condicion de venta contado = 1', $r1['CODIGO CONDICION DE VENTA'] === '1',
            'obtenido ' . v($r1['CODIGO CONDICION DE VENTA']), $failures, $checks);
        check('rg90: monto gravado 10% = 1100 (base+IVA)', near((float) $r1['MONTO GRAVADO AL 10%'], 1100.0),
            'obtenido ' . v($r1['MONTO GRAVADO AL 10%']), $failures, $checks);
    }

    if ($r2 !== null) {
        // C2: CI con guion, sin tocar.
        check('C2 rg90: CI NO pierde nada (guion incluido, no es RUC)',
            $r2['NUMERO DE IDENTIFICACION DEL COMPRADOR'] === '456-7890',
            'obtenido ' . v($r2['NUMERO DE IDENTIFICACION DEL COMPRADOR']), $failures, $checks);
        check('C2 rg90: tipo de identificación = 12 (CÉDULA)',
            $r2['CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR'] === ContactService::ID_TYPE_CEDULA,
            'obtenido ' . v($r2['CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR']), $failures, $checks);
        check('rg90: condicion de venta crédito = 2', $r2['CODIGO CONDICION DE VENTA'] === '2',
            'obtenido ' . v($r2['CODIGO CONDICION DE VENTA']), $failures, $checks);
    }

    // C8: ni la anulada (127) ni la de otra empresa (128) aparecen.
    check('C8 rg90: venta ANULADA no aparece', !array_key_exists('0010010000127', $byInvoice),
        'apareció ' . json_encode($byInvoice['0010010000127'] ?? null), $failures, $checks);
    check('C8 rg90: venta de OTRA EMPRESA no aparece', !array_key_exists('0020020000128', $byInvoice),
        'apareció ' . json_encode($byInvoice['0020020000128'] ?? null), $failures, $checks);
    // C6 (parte 1): con el estado actual de $_fullSettings (sin setear), la interna ya no está.
    check('C6 rg90: venta INTERNA no aparece', !array_key_exists('0010010000126', $byInvoice),
        'apareció ' . json_encode($byInvoice['0010010000126'] ?? null), $failures, $checks);

    // ── LIBRO VENTAS ────────────────────────────────────────────────────────
    $lv     = $svc->libroVentas($from, $to, $roc, $companyId);
    $lvRows = $lv['rows'];

    check('libroVentas: 3 filas (mismo universo que rg90)', count($lvRows) === 3, 'obtenido ' . count($lvRows), $failures, $checks);

    $lvByFact = [];
    foreach ($lvRows as $r) {
        $lvByFact[$r['FACT.']] = $r;
    }
    $lv1 = $lvByFact['0010010000123'] ?? null; // TX1
    $lv3 = $lvByFact['0010010000125'] ?? null; // TX3

    // C5: orden EXACTO de las 14 claves + encabezados literales.
    $expectedKeys = [
        'FECHA DE EMISION', 'FACT.', 'NOMBRE O RAZON SOCIAL', 'R.U.C N.', 'TIMBRADO',
        'GRAV. 10%', 'IVA 10%', 'GRAV. 5%', 'IVA 5%', 'EXENTA', 'TOTAL', '10%', '5%', 'EXENTO',
    ];
    check('C5 libroVentas: orden y encabezados EXACTOS de las 14 columnas',
        $lvRows !== [] && array_keys($lvRows[0]) === $expectedKeys,
        'obtenido ' . json_encode($lvRows !== [] ? array_keys($lvRows[0]) : []), $failures, $checks);

    if ($lv1 !== null) {
        // C1 (parte 2): la MISMA venta que en RG90 sale sin DV, acá con DV.
        check('C1 libroVentas: R.U.C N. CON dígito verificador (misma venta que rg90 sin DV)',
            $lv1['R.U.C N.'] === '80012345-6', 'obtenido ' . v($lv1['R.U.C N.']), $failures, $checks);
    }
    if ($lv3 !== null) {
        // C5: TOTAL = total del comprobante (1100+525+200); EXENTO = bruto de exentas.
        check('C5 libroVentas: TOTAL (col. 11) = total del comprobante = 1825',
            near((float) $lv3['TOTAL'], 1825.0), 'obtenido ' . v($lv3['TOTAL']), $failures, $checks);
        check('C5 libroVentas: EXENTO (col. 14) = bruto de exentas = 200',
            near((float) $lv3['EXENTO'], 200.0), 'obtenido ' . v($lv3['EXENTO']), $failures, $checks);
        check('C5 libroVentas: EXENTA (col. 10, base) = 200 (sin IVA, coincide con el bruto)',
            near((float) $lv3['EXENTA'], 200.0), 'obtenido ' . v($lv3['EXENTA']), $failures, $checks);
        check('C5 libroVentas: GRAV. 10%/IVA 10%/GRAV. 5%/IVA 5% con su valor correcto (no el bug intercambiado del legacy)',
            near((float) $lv3['GRAV. 10%'], 1000.0) && near((float) $lv3['IVA 10%'], 100.0)
                && near((float) $lv3['GRAV. 5%'], 500.0) && near((float) $lv3['IVA 5%'], 25.0),
            'obtenido ' . json_encode(array_intersect_key($lv3, array_flip(['GRAV. 10%', 'IVA 10%', 'GRAV. 5%', 'IVA 5%']))),
            $failures, $checks);
    }

    // ── C6: la interna queda fuera con el ajuste del tenant OFF y ON ────────
    // isInternalSale($tags, force:true) ignora `$_fullSettings['ignoreInternal']`
    // por completo (a diferencia de los reportes de gestión) — esto prueba que
    // cambiar el ajuste NO cambia el resultado del reporte fiscal.
    $GLOBALS['_fullSettings'] = ['ignoreInternal' => false];
    $offRun = $svc->rg90($from, $to, $roc, $companyId);
    check('C6 rg90: con ignoreInternal OFF sigue siendo 3 filas', count($offRun['rows']) === 3,
        'obtenido ' . count($offRun['rows']), $failures, $checks);
    check('C6 rg90: con ignoreInternal OFF la interna sigue afuera',
        !in_array('0010010000126', array_column($offRun['rows'], 'NUMERO DEL COMPROBANTE'), true),
        'apareció entre ' . json_encode(array_column($offRun['rows'], 'NUMERO DEL COMPROBANTE')), $failures, $checks);

    $GLOBALS['_fullSettings'] = ['ignoreInternal' => true];
    $onRun = $svc->rg90($from, $to, $roc, $companyId);
    check('C6 rg90: con ignoreInternal ON sigue siendo 3 filas', count($onRun['rows']) === 3,
        'obtenido ' . count($onRun['rows']), $failures, $checks);
    check('C6 rg90: con ignoreInternal ON la interna sigue afuera',
        !in_array('0010010000126', array_column($onRun['rows'], 'NUMERO DEL COMPROBANTE'), true),
        'apareció entre ' . json_encode(array_column($onRun['rows'], 'NUMERO DEL COMPROBANTE')), $failures, $checks);
    unset($GLOBALS['_fullSettings']);

    // ── C7: paginación — 5001 ventas, más de PAGE_SIZE (2000) ───────────────
    // INSERT ... SELECT sobre generate_series: 5001 ncmExecute sueltos harían
    // el seed inaceptablemente lento. Caja propia ($registerBulk) para no
    // chocar con el índice único de comprobante de la caja principal.
    $bulkCount = 5001;
    $bulkFrom  = '2026-04-01 00:00:00';
    $bulkTo    = '2026-04-01 23:59:59';

    $db->Execute(
        "INSERT INTO transaction
             (transactionId, transactionDate, transactionDiscount, transactionTotal, transactionType,
              invoiceNo, invoicePrefix, customerId, registerId, userId, outletId, companyId, meta)
         SELECT
             ('0fca1000-9999-4000-8000-' || lpad(gs::text, 12, '0'))::uuid,
             '2026-04-01 14:00:00+00'::timestamptz,
             0,
             1000,
             0,
             gs::bigint,
             '001-001',
             ?::uuid,
             ?::uuid,
             ?::uuid,
             ?::uuid,
             ?::uuid,
             '{}'::jsonb
         FROM generate_series(1, ?::int) AS gs",
        [$custBulk, $registerBulk, $userA, $outletA, $companyId, $bulkCount]
    );
    $db->Execute(
        "INSERT INTO toTaxObj (toTaxObjText, transactionId, companyId)
         SELECT
             '[{\"taxId\":null,\"rate\":0,\"kind\":\"exempt\",\"base\":1000,\"amount\":0}]',
             ('0fca1000-9999-4000-8000-' || lpad(gs::text, 12, '0'))::uuid,
             ?::uuid
         FROM generate_series(1, ?::int) AS gs",
        [$companyId, $bulkCount]
    );

    $bulkRoc = Roc::build($companyId, '');
    $bulkRg90 = $svc->rg90($bulkFrom, $bulkTo, $bulkRoc, $companyId);
    $bulkRows = $bulkRg90['rows'];
    $bulkDocs = array_column($bulkRows, 'NUMERO DEL COMPROBANTE');

    check("C7 rg90: $bulkCount filas (cruza 3 páginas de PAGE_SIZE=2000, sin cap en 5000)",
        count($bulkRows) === $bulkCount, 'obtenido ' . count($bulkRows), $failures, $checks);
    check('C7 rg90: meta.totalCount coincide con lo sembrado', ($bulkRg90['meta']['totalCount'] ?? null) === $bulkCount,
        'obtenido ' . v($bulkRg90['meta']['totalCount'] ?? null), $failures, $checks);
    check('C7 rg90: números de comprobante ÚNICOS (sin filas repetidas ni salteadas entre páginas)',
        count(array_unique($bulkDocs)) === $bulkCount,
        'únicos ' . count(array_unique($bulkDocs)) . ' de ' . count($bulkDocs), $failures, $checks);
} finally {
    $db->Execute("SET TIME ZONE 'UTC'");
    unset($GLOBALS['_fullSettings']);
    $cleanup();
}

harnessFinish($failures, $checks);

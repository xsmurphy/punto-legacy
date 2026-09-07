<?php

declare(strict_types=1);

/**
 * verify_cdc.php — arnés del CDC: el generador propio, el dígito verificador
 * módulo 11, el guard de numeración y la extracción del QR de ekuatía.
 *
 * ── El caso de oro ───────────────────────────────────────────────────────
 *
 * `01035951931001001000083312026090410000000012` es el CDC REAL de una factura
 * electrónica legal, emitida por el sistema anterior de un tenant y aceptada
 * por SIFEN (`context/refs/kude-ejemplo-balloon-party.pdf`). No es un fixture
 * inventado: si `Cdc::build()` reproduce exactamente esos 44 dígitos a partir
 * de sus componentes, entonces nuestro generador arma CDCs que la SET acepta.
 * Ese es el único test que prueba algo sobre el mundo real; todo lo demás
 * verifica que el código sea coherente consigo mismo.
 *
 * El mismo documento aporta dos casos MÁS, gratis: los RUC `3595193-1` (emisor)
 * y `4178655-6` (receptor). El DV del RUC paraguayo sale del MISMO módulo 11
 * base 11 que el DV del CDC, así que las tres verificaciones ejercitan la misma
 * función desde ángulos distintos. Con pesos 2..9 —la variante de módulo 11 más
 * común, y el error fácil de cometer— el CDC de oro daría 7 en vez de 2.
 *
 * ── Por qué corre contra Postgres real ───────────────────────────────────
 *
 * `Cdc` es pura y no necesitaría BD, pero el guard sí: lo que hay que
 * demostrar es que un documento con `numbering_mismatch` (mig 204) deja de
 * entregar CDC y QR a los caminos de impresión y portal. Eso es una condición
 * SQL, y un mock de la fila probaría el mock.
 *
 * Uso: lo invoca `run.sh`. Standalone contra un Postgres ya migrado:
 *   php api/lib/Sales/verify_chain/verify_cdc.php
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

use Punto\Api\EInvoice\Cdc;
use Punto\Api\EInvoice\EInvoiceService;

$PY_COMPANY = '0ea6c5d8-57e5-4226-8140-ec914deec024';

/** CDC real del KuDE de referencia. */
const GOLDEN_CDC = '01035951931001001000083312026090410000000012';

/** Sus componentes, leídos del documento impreso. */
const GOLDEN_PARTS = [
    'documentType'    => '01',        // factura electrónica
    'ruc'             => '03595193',  // RUC del emisor, sin DV
    'rucCheckDigit'   => '1',
    'establishment'   => '001',
    'expeditionPoint' => '001',
    'number'          => '0000833',   // el 001-001-0000833 impreso arriba
    'taxpayerType'    => '1',
    'date'            => '20260904',  // 04/09/2026
    'emissionType'    => '1',
    'securityCode'    => '000000001',
];

$failures = [];

try {
    // ── 1. CASO DE ORO: reproducir el CDC real dígito por dígito ──────────
    $built = Cdc::build(GOLDEN_PARTS);
    if ($built !== GOLDEN_CDC) {
        $failures[] = "Caso 1 (ORO): Cdc::build() devolvió {$built}, se esperaba " . GOLDEN_CDC;
    } else {
        echo "[verify_cdc] OK caso 1 (ORO): el generador reproduce el CDC real del KuDE de referencia\n";
    }

    // El DV por separado, para que un fallo diga CUÁL de las dos mitades se
    // rompió (armado posicional vs. cálculo del verificador).
    $dv = Cdc::checkDigit(substr(GOLDEN_CDC, 0, 43));
    if ((string) $dv !== '2') {
        $failures[] = "Caso 1b: el DV módulo 11 del CDC de oro dio {$dv}, se esperaba 2 " .
            '(si dio 7, los pesos están ciclando 2..9 en vez de 2..11)';
    } else {
        echo "[verify_cdc] OK caso 1b: DV módulo 11 base 11 = 2\n";
    }

    // ── 2. El MISMO módulo 11 produce el DV del RUC paraguayo ─────────────
    foreach ([['3595193', 1, 'emisor'], ['4178655', 6, 'receptor']] as [$ruc, $want, $who]) {
        $got = Cdc::checkDigit($ruc);
        if ($got !== $want) {
            $failures[] = "Caso 2: DV del RUC {$ruc} ({$who} del KuDE) dio {$got}, se esperaba {$want}";
        }
    }
    if ($failures === []) {
        echo "[verify_cdc] OK caso 2: el mismo mod 11 valida los dos RUC del documento real\n";
    }

    // ── 3. parse() es la inversa exacta de build() ────────────────────────
    $parsed = Cdc::parse(GOLDEN_CDC);
    foreach (GOLDEN_PARTS as $field => $want) {
        if (($parsed[$field] ?? null) !== $want) {
            $failures[] = "Caso 3: parse()['{$field}'] = " . var_export($parsed[$field] ?? null, true) .
                ", se esperaba '{$want}'";
        }
    }
    if (!Cdc::isValid(GOLDEN_CDC)) {
        $failures[] = 'Caso 3: isValid() rechazó el CDC real de referencia';
    }
    // Un dígito cambiado tiene que romper el DV — si no, el verificador no
    // está verificando nada.
    $tampered = substr(GOLDEN_CDC, 0, 20) . (((int) GOLDEN_CDC[20] + 1) % 10) . substr(GOLDEN_CDC, 21);
    if (Cdc::isValid($tampered)) {
        $failures[] = "Caso 3: isValid() aceptó un CDC con un dígito alterado ({$tampered})";
    }
    echo "[verify_cdc] OK caso 3: parse/isValid coherentes, y un dígito alterado se detecta\n";

    // ── 4. Largos: no se trunca nunca ─────────────────────────────────────
    try {
        Cdc::build(array_merge(GOLDEN_PARTS, ['number' => '12345678'])); // 8 dígitos en un campo de 7
        $failures[] = 'Caso 4: build() aceptó un número de 8 dígitos en el componente de 7 — ' .
            'truncarlo produciría un CDC válido que identifica otro documento';
    } catch (\InvalidArgumentException) {
        echo "[verify_cdc] OK caso 4: un componente que no entra lanza en vez de truncarse\n";
    }

    // ── 5. Código de seguridad: 9 dígitos, aleatorio, nunca todo ceros ────
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $code = Cdc::securityCode();
        if (strlen($code) !== 9 || preg_match('/^\d{9}$/', $code) !== 1) {
            $failures[] = "Caso 5: securityCode() devolvió '{$code}' (se esperaban 9 dígitos)";
            break;
        }
        if ($code === '000000000') {
            $failures[] = 'Caso 5: securityCode() devolvió 000000000, el valor reservado para "sin generar"';
            break;
        }
        $seen[$code] = true;
    }
    // Secuencial o constante colapsaría esta cuenta. No prueba calidad
    // criptográfica —eso lo da random_int()— pero sí atrapa el reemplazo
    // accidental por un contador, que es el riesgo real acá.
    if (count($seen) < 190) {
        $failures[] = 'Caso 5: 200 códigos de seguridad produjeron solo ' . count($seen) .
            ' valores distintos — ¿alguien lo volvió secuencial?';
    } else {
        echo "[verify_cdc] OK caso 5: código de seguridad de 9 dígitos, aleatorio, nunca 000000000\n";
    }

    // ── 6. EL GUARD: un número ajeno en el CDC devuelto se detecta ────────
    // Mismo CDC de oro, pero la venta dice que se imprimió el 833 y el
    // proveedor numeró el 907. Es EXACTAMENTE el escenario que el guard
    // existe para atrapar.
    $foreign = Cdc::build(array_merge(GOLDEN_PARTS, ['number' => '907']));
    $problems = Cdc::assertMatchesSale($foreign, [
        'number'          => 833,
        'ruc'             => '03595193',
        'establishment'   => '001',
        'expeditionPoint' => '001',
    ]);
    if ($problems === []) {
        $failures[] = 'Caso 6: el guard NO detectó que el CDC devuelto lleva el número 907 ' .
            'mientras la venta se imprimió con el 833';
    } elseif (!str_contains(implode(' ', $problems), '907')) {
        $failures[] = 'Caso 6: el guard detectó algo pero su mensaje no dice qué número trae el CDC: ' .
            implode('; ', $problems);
    } else {
        echo "[verify_cdc] OK caso 6 (EL GUARD): número ajeno en el CDC detectado y explicado\n";
    }

    // Y el caso feliz no puede dar falso positivo.
    $ok = Cdc::assertMatchesSale(GOLDEN_CDC, [
        'number'          => 833,
        'ruc'             => '3595193',   // sin ceros a la izquierda: se normaliza
        'establishment'   => '1',         // sin padear: se normaliza
        'expeditionPoint' => '001',
        'date'            => '2026-09-04', // con guiones: se normaliza
    ]);
    if ($ok !== []) {
        $failures[] = 'Caso 6b: el guard marcó discrepancia sobre el CDC real correcto (falso positivo): ' .
            implode('; ', $ok);
    } else {
        echo "[verify_cdc] OK caso 6b: sin falsos positivos con RUC/establecimiento/fecha sin normalizar\n";
    }

    // Un emisor distinto también se detecta (no solo el número).
    $otherRuc = Cdc::assertMatchesSale(GOLDEN_CDC, ['ruc' => '80012345']);
    if ($otherRuc === []) {
        $failures[] = 'Caso 6c: el guard no detectó que el CDC es de OTRO RUC emisor';
    }

    // ── 7. Extracción del QR (`DCarQR`) del JSONB crudo ───────────────────
    $qr = 'https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=' . GOLDEN_CDC;
    $cases = [
        'clave PascalCase' => ['Items' => [['DCarQR' => $qr]]],
        'clave camelCase'  => ['Items' => [['dCarQR' => $qr]]],
        'items minúscula'  => ['items' => [['DCarQR' => $qr]]],
        'segundo item'     => ['Items' => [['Otro' => 1], ['DCarQR' => $qr]]],
        'string JSON'      => json_encode(['Items' => [['DCarQR' => $qr]]]),
    ];
    foreach ($cases as $label => $payload) {
        $got = EInvoiceService::extractQrUrl($payload);
        if ($got !== $qr) {
            $failures[] = "Caso 7 ({$label}): extractQrUrl devolvió " . var_export($got, true);
        }
    }
    foreach (['sin Items' => ['Foo' => 1], 'vacío' => [], 'null' => null, 'basura' => 'no-json'] as $label => $payload) {
        if (EInvoiceService::extractQrUrl($payload) !== null) {
            $failures[] = "Caso 7 ({$label}): extractQrUrl debía devolver null";
        }
    }
    echo "[verify_cdc] OK caso 7: el QR se extrae del JSONB en sus variantes y null cuando no está\n";

    // ── 8. CONTRA POSTGRES: el guard corta el QR en la fuente ─────────────
    // Dos documentos idénticos salvo `numbering_mismatch`. `qrUrlFor()` tiene
    // que entregar el primero y NEGAR el segundo: es la condición que impide
    // que un comprobante impreso lleve el QR de otra factura.
    $svc = new EInvoiceService();
    $mk = function (string $txId, ?string $mismatch) use ($PY_COMPANY, $qr): void {
        ncmExecute('DELETE FROM einvoice_document WHERE companyid = ? AND transactionid = ?', [$PY_COMPANY, $txId]);
        ncmExecute(
            "INSERT INTO einvoice_document
                (companyid, transactionid, doctype, status, cdc, provider_response, numbering_mismatch, issued_at)
             VALUES (?, ?, 'FC', 'issued', ?, ?::jsonb, ?, now())",
            [$PY_COMPANY, $txId, GOLDEN_CDC, json_encode(['Items' => [['DCarQR' => $qr]]]), $mismatch]
        );
    };

    $txOk  = '11111111-1111-4111-8111-111111111111';
    $txBad = '22222222-2222-4222-8222-222222222222';
    $mk($txOk, null);
    $mk($txBad, 'el CDC dice número 907 y la venta declara 833');

    $gotOk = $svc->qrUrlFor($PY_COMPANY, $txOk);
    if ($gotOk !== $qr) {
        $failures[] = 'Caso 8: qrUrlFor() no devolvió el QR de un documento sano: ' . var_export($gotOk, true);
    }
    $gotBad = $svc->qrUrlFor($PY_COMPANY, $txBad);
    if ($gotBad !== null) {
        $failures[] = 'Caso 8: qrUrlFor() entregó el QR de un documento marcado por el guard de ' .
            'numeración (' . var_export($gotBad, true) . ') — ese QR manda a consultar OTRA factura';
    }
    // Y el accesor canónico —el que usa la reimpresión desde el panel— tiene
    // que negar el documento ENTERO, no solo el QR: un CDC impreso que
    // identifica otra factura es igual de falso que un QR que lleva a ella.
    if ($svc->printableDocumentFor($PY_COMPANY, $txOk) === null) {
        $failures[] = 'Caso 8b: printableDocumentFor() no devolvió el documento sano';
    }
    $badDoc = $svc->printableDocumentFor($PY_COMPANY, $txBad);
    if ($badDoc !== null) {
        $failures[] = 'Caso 8b: printableDocumentFor() entregó el CDC de un documento marcado por el ' .
            'guard de numeración — la reimpresión estamparía en el comprobante el código de OTRA factura';
    }

    if ($gotOk === $qr && $gotBad === null && $badDoc === null) {
        echo "[verify_cdc] OK caso 8 (Postgres): el documento marcado por el guard no entrega ni CDC ni QR\n";
    }

    ncmExecute('DELETE FROM einvoice_document WHERE companyid = ? AND transactionid IN (?, ?)', [$PY_COMPANY, $txOk, $txBad]);
} catch (\Throwable $e) {
    $failures[] = 'Excepción: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_cdc] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_cdc] TODO OK\n";
exit(0);

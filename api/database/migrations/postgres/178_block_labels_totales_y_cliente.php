<?php
/**
 * Migration 178 — Los títulos que la 174 no puso.
 *
 * La 174 rotuló fecha, timbrado, condición y los datos del cliente, pero dejó
 * afuera los TOTALES (total, subtotal, IVA) y varios campos que el owner usa en
 * su ticket. Reporte del owner (2026-08-28): "muchas líneas del ticket siguen
 * sin título, por ej. Total, Total IVA 5, 10, exenta, Cliente, RUC".
 *
 * ── Los dos motivos por los que faltaban ────────────────────────────────────
 *
 *  1. El mapa de la 174 era conservador y no incluía los totales.
 *  2. Un backfill solo alcanza a lo que YA existía. Los bloques agregados
 *     DESPUÉS de que corriera nacían sin título. Eso se arregla en el editor,
 *     no acá: `DEFAULT_BLOCK_LABELS` (frontend/lib/print-template-palette.ts)
 *     le pone el título sugerido a todo bloque nuevo. Esta migración es la
 *     mitad que le toca al dato viejo, y su mapa es el MISMO de ese archivo.
 *
 * ── Los bloques por tasa ────────────────────────────────────────────────────
 *
 * `subtotal_by_rate` / `iva_by_rate` / `item_total_by_rate` no pueden salir de
 * un mapa fijo: el título lleva la TASA ("Total IVA 10%:" vs "Total IVA 5%:") y
 * la tasa es dato del comercio — el bloque guarda el `taxId` en `text`. Se
 * resuelve contra `tax` de la misma empresa. Un `taxId` que no resuelve se
 * SALTA: mejor sin título que con uno equivocado en un comprobante.
 *
 * IDEMPOTENTE: solo escribe donde `label` está vacío. Respeta cualquier título
 * que el comercio haya editado a mano.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR 178: migrationPdo no disponible\n");
    return;
}

/** Espejo de `DEFAULT_BLOCK_LABELS` — mismo orden, mismos textos. */
const LABELS_178 = [
    'discount'            => 'Descuento:',
    'subtotal'            => 'Subtotal:',
    'tax_total'           => 'Total IVA:',
    'iva_total'           => 'Total IVA:',
    'total'               => 'TOTAL A PAGAR:',
    'nums_to_words'       => 'Son:',
    'associated_document' => 'Documento asociado:',
    'customer_full_name'  => 'Cliente:',
    'customer_email'      => 'Email:',
    'order_number'        => 'Orden Nro.:',
    'order_destination'   => 'Destino:',
    'table_number'        => 'Mesa:',
    'transfer_reason'     => 'Motivo:',
    'transfer_origin'     => 'Origen:',
    'transfer_destination'=> 'Destino:',
];

/** Prefijo del título de cada bloque por-tasa. */
const BY_RATE_178 = [
    'subtotal_by_rate'   => 'Subtotal',
    'iva_by_rate'        => 'IVA',
    'item_total_by_rate' => 'Total',
];

const SALE_DOC_TYPES_178 = ['receipt', 'invoice', 'factura', 'credit'];

try {
    $pdo->beginTransaction();

    $placeholders = implode(',', array_fill(0, count(SALE_DOC_TYPES_178), '?'));
    $select = $pdo->prepare(
        "SELECT templateid, companyid, config
           FROM document_template
          WHERE docType IN ($placeholders)
          FOR UPDATE"
    );
    $select->execute(SALE_DOC_TYPES_178);
    $rows = $select->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        $pdo->commit();
        fwrite(STDOUT, "[migrate] 178: no hay plantillas de venta, nada que hacer\n");
        return;
    }

    // Tasas por empresa, para los bloques por-tasa. Una query, no una por bloque.
    $taxStmt = $pdo->prepare('SELECT taxid, rate FROM tax WHERE companyid = ?');
    $taxCache = [];

    $update = $pdo->prepare(
        "UPDATE document_template SET config = ?::jsonb, updated_at = now() WHERE templateid = ?"
    );

    $patched = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $config = json_decode((string) ($row['config'] ?? '{}'), true);
        if (!is_array($config)) {
            $config = [];
        }
        $blocks = isset($config['data']) && is_array($config['data']) ? $config['data'] : [];
        $companyId = (string) $row['companyid'];

        if (!array_key_exists($companyId, $taxCache)) {
            $taxStmt->execute([$companyId]);
            $map = [];
            foreach ($taxStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $map[(string) $t['taxid']] = (float) $t['rate'];
            }
            $taxCache[$companyId] = $map;
        }
        $taxes = $taxCache[$companyId];

        $touched = false;
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if (trim((string) ($block['label'] ?? '')) !== '') {
                continue; // ya tiene título (del comercio o de la 174)
            }

            if (isset(LABELS_178[$type])) {
                $blocks[$idx]['label'] = LABELS_178[$type];
                $touched = true;
                continue;
            }

            if (isset(BY_RATE_178[$type])) {
                $taxId = trim((string) ($block['text'] ?? ''));
                if ($taxId === '' || !isset($taxes[$taxId])) {
                    continue; // sin tasa resoluble, se deja sin título
                }
                $rate = $taxes[$taxId];
                // "10" y no "10.00": el título es texto para una persona.
                $rateLabel = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
                $blocks[$idx]['label'] = BY_RATE_178[$type] . ' ' . $rateLabel . '%:';
                $touched = true;
            }
        }

        if (!$touched) {
            $skipped++;
            continue;
        }

        $config['data'] = $blocks;
        $update->execute([
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $row['templateid'],
        ]);
        $patched++;
    }

    $pdo->commit();
    fwrite(STDOUT, "[migrate] 178: $patched plantillas con títulos nuevos, $skipped sin cambios\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[migrate] ERROR 178: " . $e->getMessage() . "\n");
    throw $e;
}

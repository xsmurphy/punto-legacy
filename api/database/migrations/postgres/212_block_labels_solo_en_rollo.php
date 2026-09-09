<?php
/**
 * Migration 212 — Saca los títulos que las migs 174/178/179 estamparon en
 * plantillas de HOJA.
 *
 * Aquellas tres backfillearon `block.label` en TODAS las filas de
 * `document_template`, sin mirar el papel. En un ROLLO el título es lo que
 * hace legible al bloque: es una línea suelta y "7659394-0" a secas no se
 * entiende. En una HOJA el rótulo ya está dibujado en la plantilla —el
 * encabezado de la celda o de la columna—, así que el label del bloque se
 * imprime ADEMÁS y sale duplicado:
 *
 *     RUC: RUC: 7659394-0
 *     Dirección: Dirección:
 *     Fecha: Fecha: 2026-09-09
 *
 * Reportado por el owner (2026-09-09) sobre el KuDE de factura de un tenant
 * real que ya está emitiendo.
 *
 * ACOTADA A PROPÓSITO, en dos ejes, porque esto edita el formato de
 * comprobantes fiscales de comercios en producción:
 *
 *  1. Solo plantillas de hoja — `config->>'page_size'` que NO empieza con
 *     `receipt` (`receipt80|receipt76|receipt57`, ver `PaperSize` en
 *     frontend/lib/types/print-template.ts). Una fila sin `page_size` NO se
 *     toca: sin saber el papel no se puede decidir, y equivocarse hacia
 *     "limpiar" deja un ticket con datos sin rótulo.
 *  2. Solo labels que coinciden EXACTAMENTE con lo que aquellas migs
 *     escribieron. Un título que el operador redactó ("Nro. de contribuyente:")
 *     no matchea y sobrevive. El falso positivo posible es un operador que
 *     escribió a mano exactamente "Dirección:" en una hoja — en cuyo caso el
 *     resultado es el que quería igual, porque el rótulo lo pone su diseño.
 *
 * El mapa es la UNIÓN de LABELS_179 y de los tipos que la 174/178 cubrían,
 * más las variantes de tokens de país que `substituteLabels` produce
 * (`R.U.C.:`/`C.I.:` los estampó la 179 en literal, pero un tenant no-PY pudo
 * quedar con otra etiqueta desde el editor).
 *
 * IDEMPOTENTE: correrla dos veces no cambia nada la segunda.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR 212: migrationPdo no disponible\n");
    return;
}

/** type => títulos estampados por las migs 174/178/179 (y sus variantes de país). */
const STAMPED_212 = [
    'date'                 => ['Fecha:'],
    'duedate'              => ['Vencimiento:'],
    'sale_type'            => ['Condición:'],
    'payment_methods'      => ['Formas de pago:'],
    'associated_document'  => ['Documento asociado:'],
    'discount'             => ['Descuento:'],
    'subtotal'             => ['Subtotal:'],
    'tax_total'            => ['Total IVA:'],
    'iva_total'            => ['Total IVA:'],
    'total'                => ['TOTAL A PAGAR:'],
    'nums_to_words'        => ['Son:'],
    'register_name'        => ['Caja:'],
    'user_name'            => ['Cajero:', 'Usuario:'],
    'auth_number'          => ['Timbrado No.:'],
    'auth_start_date'      => ['Válido desde:'],
    'auth_expiration'      => ['Válido hasta:'],
    'customer_name'        => ['Cliente:'],
    'customer_full_name'   => ['Cliente:'],
    'customer_tin'         => ['R.U.C.:', 'RUC:', 'CUIT:', 'NIT:', 'RUT:', 'RFC:'],
    'customer_ci'          => ['C.I.:', 'CI:', 'DNI:', 'CC:', 'CURP:'],
    'customer_address'     => ['Dirección:'],
    'customer_phone'       => ['Teléfono:'],
    'customer_email'       => ['Email:'],
    'order_number'         => ['Orden Nro.:'],
    'order_destination'    => ['Espacio:'],
    'table_number'         => ['Mesa:'],
    'transfer_reason'      => ['Motivo:'],
    'transfer_origin'      => ['Origen:'],
    'transfer_destination' => ['Destino:'],
    'fe_cdc'               => ['CDC:'],
];

try {
    $pdo->beginTransaction();

    // El filtro del papel va en SQL para no traer las plantillas de rollo a PHP.
    // `config->>'page_size'` es el id del editor, no el enum reducido de la
    // columna `pageSize` del service: el JSON es el que consume el renderer.
    $rows = $pdo->query(
        "SELECT templateid, config
           FROM document_template
          WHERE config->>'page_size' IS NOT NULL
            AND config->>'page_size' NOT LIKE 'receipt%'
          FOR UPDATE"
    )->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare(
        "UPDATE document_template SET config = ?::jsonb, updated_at = now() WHERE templateid = ?"
    );

    $patched = 0;
    $cleared = 0;
    foreach ($rows as $row) {
        $config = json_decode((string) ($row['config'] ?? '{}'), true);
        if (!is_array($config)) continue;
        $blocks = isset($config['data']) && is_array($config['data']) ? $config['data'] : [];

        $touched = false;
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) continue;
            $type  = (string) ($block['type'] ?? '');
            $label = trim((string) ($block['label'] ?? ''));
            if ($label === '' || !isset(STAMPED_212[$type])) continue;
            if (!in_array($label, STAMPED_212[$type], true)) continue;
            $blocks[$idx]['label'] = '';
            $touched = true;
            $cleared++;
        }
        if (!$touched) continue;

        $config['data'] = $blocks;
        $update->execute([
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $row['templateid'],
        ]);
        $patched++;
    }

    $pdo->commit();
    fwrite(STDOUT, "[migrate] 212: $patched plantillas de hoja, $cleared titulos duplicados removidos\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR 212: " . $e->getMessage() . "\n");
    throw $e;
}

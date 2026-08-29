<?php
/**
 * Migration 179 — Títulos para las plantillas que la 174/178 dejaron afuera.
 *
 * Las 174/178 backfillearon SOLO doctypes de venta, con la premisa de que la
 * comanda no quiere rótulos. El owner la corrigió con su plantilla real
 * (2026-08-29): el cajero ("Juan Pérez" pelado), el total y el espacio también
 * necesitan decir qué son, en cualquier tipo de documento.
 *
 * DOS diferencias deliberadas con la 178:
 *
 *  1. Cubre TODOS los doctypes (order, workorder, quote, delivery, giftcard
 *     además de los de venta — estos últimos por los tipos que la 178 no
 *     mapeaba, como user_name).
 *  2. `document_number` NO se estampa: desde 2026-08-29 su título por defecto
 *     es DINÁMICO por tipo de documento ("Factura Nro.:" / "Orden Nro.:" /
 *     "Recibo Nro.:", ver DOC_NUMBER_LABELS en
 *     frontend/lib/hardware/printers/blocks.ts) y solo aplica cuando el
 *     bloque no tiene título propio — estamparlo acá lo congelaría.
 *
 * El mapa es el espejo de DEFAULT_BLOCK_LABELS (print-template-palette.ts).
 * IDEMPOTENTE: solo escribe donde `label` está vacío; respeta lo editado.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR 179: migrationPdo no disponible\n");
    return;
}

const LABELS_179 = [
    'date'                => 'Fecha:',
    'duedate'             => 'Vencimiento:',
    'sale_type'           => 'Condición:',
    'payment_methods'     => 'Formas de pago:',
    'associated_document' => 'Documento asociado:',
    'discount'            => 'Descuento:',
    'subtotal'            => 'Subtotal:',
    'tax_total'           => 'Total IVA:',
    'iva_total'           => 'Total IVA:',
    'total'               => 'TOTAL A PAGAR:',
    'nums_to_words'       => 'Son:',
    'register_name'       => 'Caja:',
    'user_name'           => 'Cajero:',
    'auth_number'         => 'Timbrado No.:',
    'auth_start_date'     => 'Válido desde:',
    'auth_expiration'     => 'Válido hasta:',
    'customer_name'       => 'Cliente:',
    'customer_full_name'  => 'Cliente:',
    'customer_tin'        => 'R.U.C.:',
    'customer_ci'         => 'C.I.:',
    'customer_address'    => 'Dirección:',
    'customer_phone'      => 'Teléfono:',
    'customer_email'      => 'Email:',
    'order_number'        => 'Orden Nro.:',
    'order_destination'   => 'Espacio:',
    'table_number'        => 'Mesa:',
    'transfer_reason'     => 'Motivo:',
    'transfer_origin'     => 'Origen:',
    'transfer_destination'=> 'Destino:',
    'fe_cdc'              => 'CDC:',
];

try {
    $pdo->beginTransaction();

    $rows = $pdo->query(
        "SELECT templateid, config FROM document_template FOR UPDATE"
    )->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare(
        "UPDATE document_template SET config = ?::jsonb, updated_at = now() WHERE templateid = ?"
    );

    $patched = 0;
    foreach ($rows as $row) {
        $config = json_decode((string) ($row['config'] ?? '{}'), true);
        if (!is_array($config)) $config = [];
        $blocks = isset($config['data']) && is_array($config['data']) ? $config['data'] : [];

        $touched = false;
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) continue;
            $type = (string) ($block['type'] ?? '');
            if (!isset(LABELS_179[$type])) continue;
            if (trim((string) ($block['label'] ?? '')) !== '') continue;
            $blocks[$idx]['label'] = LABELS_179[$type];
            $touched = true;
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
    fwrite(STDOUT, "[migrate] 179: $patched plantillas con títulos nuevos\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR 179: " . $e->getMessage() . "\n");
    throw $e;
}

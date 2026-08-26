<?php
/**
 * Migration 174 — Los tickets ya impresos estrenan los títulos de campo.
 *
 * CONTEXTO
 *
 * Hasta 2026-08-26 un bloque de plantilla imprimía SOLO su valor: el ticket
 * salía "12-03-2026", "001-001-1233454", "Contado", uno debajo del otro, sin
 * decir qué era cada cosa. El owner lo pidió con la foto de un ticket de Punto
 * al lado de uno de referencia: quiere "Fecha: 12-03-2026", "Fact. Nro.:
 * 001-001-1233454", "Condición: Contado".
 *
 * El mismo commit agrega `PrintBlock.label` — un título OPCIONAL POR BLOQUE,
 * guardado en la plantilla. No es una tabla de títulos en el renderer a
 * propósito: lo que se imprime lo decide la plantilla (context/20), y un mapa
 * fijo `date => "Fecha:"` obligaría a un gating por docType para que la
 * comanda de cocina no imprima rótulos que no necesita — exactamente lo que la
 * regla prohíbe.
 *
 * PROBLEMA QUE RESUELVE ESTA MIGRACIÓN
 *
 * Ser una propiedad de la plantilla significa que las plantillas que YA
 * existen siguen imprimiendo sin títulos hasta que alguien los escriba a mano,
 * bloque por bloque, en el editor. No hay seeding de plantillas en ningún lado
 * (`DocumentTemplateService` no siembra; una plantilla nueva arranca vacía),
 * así que nadie lo repara solo. Mismo caso que la mig 168 con el banner de
 * comanda, y misma respuesta: backfill de datos, una vez.
 *
 * ALCANCE
 *
 * Solo `docType` de venta — 'receipt' (ticket POS), 'invoice'/'factura' y
 * 'credit'. Deliberadamente NO toca:
 *
 *   - 'order' (comanda de cocina): el cocinero lee producto y cantidad; los
 *     rótulos gastan ancho de papel y no aportan.
 *   - 'quote'/'delivery'/'workorder'/'giftcard': el owner pidió el ticket, y
 *     un backfill que reescribe plantillas que nadie miró es peor que la
 *     ausencia del título — se pone a mano desde el editor, que es de lo que
 *     se trata el campo.
 *
 * Los títulos salen del ticket de referencia que mandó el owner (FECHA:,
 * CAJA:, CAJERO:, TIMBRADO No.:, R.U.C.:, …), con la ortografía de su pedido
 * ("Fecha:", no "FECHA:"): la plantilla ya tiene su propio toggle
 * `page_font_case` para imprimir todo en mayúsculas, y forzarlas acá le sacaría
 * esa decisión al comercio.
 *
 * NO toca los bloques de ítem (`item_*`): se repiten una vez por producto y el
 * título saldría en cada línea del detalle.
 *
 * IDEMPOTENTE
 *
 * Solo escribe el título de un bloque que NO tiene uno. Un bloque con `label`
 * ya puesto —por esta migración o por el comercio en el editor— se respeta tal
 * cual. Correrla dos veces no cambia nada la segunda vez.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR 174: migrationPdo no disponible\n");
    return;
}

/**
 * Título por tipo de bloque. Un tipo que no está acá NO recibe título: el
 * nombre del comercio, el logo, el pie de página y los totales por tasa se
 * leen solos, y rotularlos sería ruido.
 */
const BLOCK_LABELS = [
    // Transacción
    'date'             => 'Fecha:',
    'duedate'          => 'Vencimiento:',
    'document_number'  => 'Fact. Nro.:',
    'sale_type'        => 'Condición:',
    'payment_methods'  => 'Forma de pago:',
    // Caja / operador
    'register_name'    => 'Caja:',
    'user_name'        => 'Cajero:',
    // Timbrado
    'auth_number'      => 'Timbrado No.:',
    'auth_start_date'  => 'Válido desde:',
    'auth_expiration'  => 'Válido hasta:',
    // Cliente
    'customer_name'    => 'Razón Social:',
    'customer_tin'     => 'R.U.C.:',
    'customer_ci'      => 'C.I.:',
    'customer_address' => 'Dirección:',
    'customer_phone'   => 'Teléfono:',
];

/** Tipos de documento de VENTA — ver ALCANCE en el docblock. */
const SALE_DOC_TYPES = ['receipt', 'invoice', 'factura', 'credit'];

try {
    $pdo->beginTransaction();

    // `document_template` nació sin comillas (mig 18): columnas en minúscula.
    // `= ANY(...)` y no `IN (...)`: Postgres normaliza el segundo al primero de
    // todos modos, y con PDO el array va como un solo placeholder.
    $placeholders = implode(',', array_fill(0, count(SALE_DOC_TYPES), '?'));
    $select = $pdo->prepare(
        "SELECT templateid, config
           FROM document_template
          WHERE docType IN ($placeholders)
          FOR UPDATE"
    );
    $select->execute(SALE_DOC_TYPES);
    $rows = $select->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        $pdo->commit();
        fwrite(STDOUT, "[migrate] 174: no hay plantillas de venta, nada que hacer\n");
        return;
    }

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

        $touched = false;
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if (!isset(BLOCK_LABELS[$type])) {
                continue;
            }
            // Título ya puesto (por el comercio o por una corrida anterior).
            if (trim((string) ($block['label'] ?? '')) !== '') {
                continue;
            }
            $blocks[$idx]['label'] = BLOCK_LABELS[$type];
            $touched = true;
        }

        if (!$touched) {
            $skipped++;
            continue;
        }

        $config['data'] = $blocks;
        $update->execute([json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['templateid']]);
        $patched++;
    }

    $pdo->commit();
    fwrite(STDOUT, "[migrate] 174: $patched plantillas con títulos, $skipped sin cambios\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[migrate] ERROR 174: " . $e->getMessage() . "\n");
    throw $e;
}

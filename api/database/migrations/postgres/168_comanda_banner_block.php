<?php
/**
 * Migration 168 — La comanda conserva su encabezado cuando el renderer deja
 * de inventarlo.
 *
 * CONTEXTO
 *
 * Hasta 2026-08-24 los dos renderers de impresión inyectaban un banner
 * "COMANDA #12 · MESA 3" por su cuenta, disparado por `docType === 'order'`:
 *
 *     html-renderer.ts  renderOrderBanner()
 *     render-template.ts (mismo forzado, antes del cuerpo)
 *
 * Eso viola la regla del owner — "lo que se imprime lo decide la plantilla, si
 * el bloque está en la plantilla sale, si no no" — y era la única pieza del
 * ticket que no salía del document builder. Se eliminó de los dos renderers.
 *
 * PROBLEMA QUE RESUELVE ESTA MIGRACIÓN
 *
 * Al sacar la inyección, las plantillas que YA existen no tienen forma de
 * imprimir ese dato: el banner nunca fue un bloque, así que no está en el
 * `config` de ninguna. Sin backfill, la primera comanda después del deploy
 * sale sin número ni destino — el cocinero no sabe para qué mesa es. No hay
 * seeding de plantillas en ningún lado (`DocumentTemplateService` no siembra),
 * así que nadie lo repara solo.
 *
 * QUÉ HACE
 *
 * A cada plantilla apuntada por un binding de impresora que rutea comandas
 * (`printer_binding.doctypes` contiene 'order') le agrega ARRIBA un bloque
 * `custom` con el MISMO texto que pintaba el banner, y baja el resto del
 * diseño una fila para hacerle lugar. El ticket sale igual que antes del
 * deploy, pero ahora porque la plantilla lo dice.
 *
 * POR QUÉ UN BLOQUE `custom` INTERPOLADO Y NO LOS BLOQUES TIPADOS
 *
 * El mismo commit agrega los tipos `order_number` / `order_destination` a la
 * paleta, y son lo correcto para una plantilla NUEVA. Pero son dos bloques, y
 * el banner era UNA línea con texto fijo entre los datos
 * ("COMANDA #" ... " · "). Reproducirlo con dos bloques tipados daría dos
 * líneas, o una línea sin los literales — en los dos casos el ticket cambia de
 * forma justo en el deploy que promete no cambiarla.
 *
 * `custom` ya interpola `{{campo}}` contra `TicketData`
 * (`interpolate()` en blocks.ts), y `ticketNo`/`orderDestination` son campos
 * reales de ese objeto, así que un solo bloque reproduce el banner exacto. Es
 * un bloque normal de la plantilla: el comercio lo puede mover, editar o
 * borrar desde el editor, que es todo el punto del cambio.
 *
 * Única diferencia con el banner viejo: el destino ya no se fuerza a
 * MAYÚSCULAS (el banner hacía `.toUpperCase()`). Sale como está cargado el
 * espacio/mesa. Si el comercio lo quiere en mayúsculas, tiene el toggle de
 * `page_font_case` de la plantilla.
 *
 * GEOMETRÍA
 *
 * El alto de fila se calcula con la MISMA cuenta que el renderer
 * (`rollGeometry` en roll-grid.ts): el ancho del canvas equivale a `columns`
 * caracteres y la celda de la Font A de ESC/POS es el doble de alta que ancha.
 *
 *     charWidthPx = anchoDiseñoMm * mm / columns
 *     rowHeightPx = charWidthPx * 2
 *
 * Es una copia PUNTUAL de esa fórmula, no una segunda fuente de verdad: acá
 * corre una sola vez sobre datos, no en cada impresión. Si la fórmula del
 * renderer cambia, esta migración ya corrió y no hay que sincronizarla.
 *
 * IDEMPOTENTE
 *
 * Salta cualquier plantilla que ya tenga un bloque de comanda (el `custom`
 * interpolado, o los tipos `order_number`/`order_destination` puestos a mano).
 * Correrla dos veces no duplica el encabezado ni vuelve a bajar el diseño.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR 168: migrationPdo no disponible\n");
    return;
}

/** Texto del banner viejo, con la interpolación que ya soporta `custom`. */
const COMANDA_BANNER_TEXT = 'COMANDA #{{ticketNo}} · {{orderDestination}}';

/**
 * Ancho del papel de DISEÑO en mm — espejo de `PAPER_DIMENSIONS`
 * (lib/types/print-template.ts). El default de 80 cubre un `page_size`
 * corrupto o ausente, igual que hacen los renderers.
 */
function comandaDesignWidthMm(string $pageSize): float
{
    switch ($pageSize) {
        case 'receipt57':    return 57.0;
        case 'receipt76':    return 76.0;
        case 'receipt80':    return 80.0;
        case 'a4page':       return 210.0;
        case 'a4page-h':     return 297.0;
        case 'legalpage':    return 215.9;
        case 'legalpage-h':  return 355.6;
        case 'letterpage':   return 215.9;
        case 'letterpage-h': return 279.4;
        default:             return 80.0;
    }
}

/** Columnas del dispositivo: solo 57mm baja a 32; el resto va a la térmica de
 *  80mm (`nearestReceiptPaperWidthMm` en roll-grid.ts). */
function comandaColumns(string $pageSize): int
{
    return $pageSize === 'receipt57' ? 32 : 48;
}

/** ¿Esta plantilla ya imprime número/destino de comanda? */
function comandaAlreadyHasBanner(array $blocks): bool
{
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = $block['type'] ?? '';
        if ($type === 'order_number' || $type === 'order_destination') {
            return true;
        }
        if ($type === 'custom' && strpos((string) ($block['text'] ?? ''), '{{ticketNo}}') !== false) {
            return true;
        }
    }
    return false;
}

try {
    $pdo->beginTransaction();

    // `jsonb_exists()` y NUNCA el operador `?`: el `?` de jsonb colisiona con
    // el placeholder de PDO, se reescribe a `$1` y aborta el boot del
    // contenedor. Ya tiró dos deploys (migs 74/77).
    //
    // `printer_binding` tiene sus columnas en minúscula desde la mig 150;
    // `document_template` nació sin comillas (mig 18), así que también.
    //
    // El JOIN exige que la plantilla sea DE LA MISMA COMPANY que el binding.
    // No hay FK de `printer_binding.templateid` a `document_template` ni
    // ningún check que lo garantice, así que un binding con un templateid de
    // otro tenant (dato corrupto, import viejo) haría que esta migración le
    // reescribiera la plantilla a un comercio ajeno. No lo repara — lo
    // IGNORA, que es lo correcto para un backfill.
    $templateIds = $pdo->query(
        "SELECT DISTINCT dt.templateid
           FROM printer_binding pb
           JOIN document_template dt
             ON dt.templateid = pb.templateid
            AND dt.companyid  = pb.companyid
          WHERE pb.templateid IS NOT NULL
            AND jsonb_exists(pb.doctypes, 'order')"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!$templateIds) {
        $pdo->commit();
        fwrite(STDOUT, "[migrate] 168: ningún binding rutea comandas, nada que hacer\n");
        return;
    }

    $select = $pdo->prepare(
        "SELECT templateid, config FROM document_template WHERE templateid = ? FOR UPDATE"
    );
    $update = $pdo->prepare(
        "UPDATE document_template SET config = ?::jsonb, updated_at = now() WHERE templateid = ?"
    );

    $patched = 0;
    $skipped = 0;

    foreach ($templateIds as $templateId) {
        $select->execute([$templateId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Binding apuntando a una plantilla borrada — no es asunto de esta
            // migración; el renderer ya cae al ticket de fallback.
            continue;
        }

        $config = json_decode((string) ($row['config'] ?? '{}'), true);
        if (!is_array($config)) {
            $config = [];
        }
        $blocks = isset($config['data']) && is_array($config['data']) ? $config['data'] : [];

        if (comandaAlreadyHasBanner($blocks)) {
            $skipped++;
            continue;
        }

        $pageSize = is_string($config['page_size'] ?? null) ? $config['page_size'] : 'receipt80';
        $mm       = isset($config['mm']) && is_numeric($config['mm']) && (float) $config['mm'] > 0
            ? (float) $config['mm']
            : 3.78;

        $canvasWidthPx = comandaDesignWidthMm($pageSize) * $mm;
        $rowHeightPx   = ($canvasWidthPx / comandaColumns($pageSize)) * 2;
        $shift         = (int) round($rowHeightPx);

        // El banner iba ARRIBA de todo el cuerpo, así que el diseño existente
        // baja una fila y el bloque nuevo ocupa la que queda libre. Sin el
        // corrimiento, el banner caería encima de lo que hoy está en la fila 0
        // y el renderer tendría que empujarlo por colisión — moviendo un solo
        // bloque y desalineando la fila.
        $shifted = [];
        foreach ($blocks as $block) {
            if (is_array($block) && isset($block['top']) && is_numeric($block['top'])) {
                $block['top'] = (int) round((float) $block['top']) + $shift;
            }
            $shifted[] = $block;
        }

        // Shape EXACTO de `PrintBlock` (lib/types/print-template.ts) — el JSON
        // persistido replica el del legacy y el editor lo lee tal cual.
        $banner = [
            'type'     => 'custom',
            'text'     => COMANDA_BANNER_TEXT,
            'top'      => 0,
            'left'     => 0,
            'width'    => (int) round($canvasWidthPx),
            'height'   => $shift,
            'size'     => 'inherit',
            'family'   => 'inherit',
            'align'    => 'center',
            'bold'     => 'bold',
            // `wrap` y no `cut`: un destino largo ("Salón principal - Mesa 12")
            // pasa el ancho del rollo y tiene que BAJAR de línea, no cortarse.
            'textwrap' => 'wrap',
            'url'      => '',
        ];

        $config['data'] = array_merge([$banner], $shifted);

        $update->execute([json_encode($config, JSON_UNESCAPED_UNICODE), $templateId]);
        $patched++;
    }

    $pdo->commit();
    fwrite(STDOUT, "[migrate] 168: {$patched} plantilla(s) de comanda con encabezado, {$skipped} ya lo tenían\n");
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[migrate] ERROR 168: " . $e->getMessage() . "\n");
    throw $e;
}

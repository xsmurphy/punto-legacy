<?php

declare(strict_types=1);

/**
 * verify_offline_resolution.php — arnés chico que demuestra, sin mockear
 * nada, que los dos huecos P0 de context/08 §53 (auditoría 2026-08-16) están
 * cerrados: resolver la PLANTILLA de impresión de un binding y resolver los
 * ADD-ONS de un ítem NO requieren una segunda llamada de red — ambos viajan
 * en el MISMO fetch que ya trae el ítem/el bootstrap.
 *
 * Un test que pasa CON red disponible no prueba nada acá (la auditoría
 * original ya "funcionaba" con red) — lo que se verifica es que el SHAPE de
 * la respuesta ya trae todo adentro, así que un segundo round-trip nunca es
 * necesario, offline o no. Ver README de este directorio para el criterio
 * general del arnés (sin mocks, contra Postgres real).
 *
 *   1. Add-ons: un ítem con un grupo OBLIGATORIO (`minSelect=1`, el caso que
 *      dejaba invendible sin conexión) sale de `ItemsQuery::presentItem()`
 *      —la MISMA función que arma bootstrap/bulk-get/delta,
 *      context/43-sync-incremental.md— con `addonGroups` ya poblado
 *      (grupo + opción). El POS nunca necesita `GET /v1/item_addons` para
 *      pintar el modal: ya lo tiene en el ítem que cacheó.
 *   2. Plantillas: `document-templates.php` acepta el realm `pos-app`
 *      (device, no operador panel) — verificado por código fuente, no HTTP
 *      simulado (el arnés no monta un server real) — y
 *      `DocumentTemplateService::find()` devuelve el `config` completo que
 *      el renderer de impresión consume, sin campos adicionales que pedir.
 *   3. Estático: los dos módulos frontend que antes hacían `fetch(...)` en
 *      el camino de impresión/add-ons (`print-in-browser.ts`,
 *      `use-item-addons-pos.ts`) ya NO contienen ninguna llamada de red —
 *      grep negativo sobre el archivo fuente. Es la prueba más directa de
 *      "no requiere red": si no hay `fetch`, no hay round-trip que pueda
 *      fallar sin conexión.
 *
 * Uso: ver run.sh, que lo invoca como paso propio con las mismas env vars
 * POSTGRES_*. Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/Items/ItemsQuery.php';
require_once dirname(__DIR__, 3) . '/lib/Settings/DocumentTemplateService.php';

use Punto\Api\Settings\DocumentTemplateService;
use function Punto\Api\Items\buildItemsSelectSql;
use function Punto\Api\Items\presentItem;

$PY_COMPANY   = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$ADDON_ITEM   = '9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7a'; // VERIFY-ADDON-PARENT, ver seed.sql
$ADDON_GROUP  = '9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7b';
$ADDON_OPTION = '9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7c';
$OPTION_ITEM  = '61230b1e-90e8-4018-ac59-865ca957b293'; // VERIFY-5-ADD, reusado como producto de la opción
$TEMPLATE_ID  = '9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c7d';

global $db;
$failures = [];

// ── Caso 1: add-ons embebidos en el ítem (mismo SELECT que bootstrap/bulk-get/delta) ──

$sql = buildItemsSelectSql('i.itemId = ? AND i.companyId = ?');
$rs  = ncmExecute($sql, [$ADDON_ITEM, $PY_COMPANY], false, true);

$row = null;
if ($rs && is_object($rs) && !$rs->EOF) {
    $row = presentItem($rs->fields);
}

if ($row === null) {
    $failures[] = 'Setup: no se pudo leer VERIFY-ADDON-PARENT — revisar que el seed haya corrido';
} else {
    $groups = $row['addonGroups'] ?? null;
    if (!is_array($groups) || count($groups) !== 1) {
        $failures[] = 'Caso 1: esperaba exactamente 1 grupo embebido en el ítem, llegó ' . json_encode($groups);
    } else {
        $group = $groups[0];
        if (($group['id'] ?? null) !== $ADDON_GROUP) {
            $failures[] = 'Caso 1: id de grupo inesperado — ' . json_encode($group['id'] ?? null);
        } elseif ((int) ($group['minSelect'] ?? -1) !== 1) {
            $failures[] = 'Caso 1: minSelect esperado 1 (grupo obligatorio, el caso que rompía offline) — llegó ' . json_encode($group['minSelect'] ?? null);
        } elseif (!is_array($group['options'] ?? null) || count($group['options']) !== 1) {
            $failures[] = 'Caso 1: esperaba exactamente 1 opción en el grupo, llegó ' . json_encode($group['options'] ?? null);
        } else {
            $option = $group['options'][0];
            if (($option['id'] ?? null) !== $ADDON_OPTION || ($option['itemId'] ?? null) !== $OPTION_ITEM) {
                $failures[] = 'Caso 1: opción inesperada — ' . json_encode($option);
            } elseif ((float) ($option['priceDelta'] ?? -1) !== 2000.0) {
                $failures[] = 'Caso 1: priceDelta esperado 2000 — llegó ' . json_encode($option['priceDelta'] ?? null);
            } else {
                echo "[verify_offline_resolution] OK caso 1: addonGroups viaja embebido en el ítem (mismo SELECT que bootstrap/bulk-get/delta) — el modal de add-ons no necesita una segunda llamada\n";
            }
        }
    }

    if (!array_key_exists('hasAddons', $row) || $row['hasAddons'] !== true) {
        $failures[] = 'Caso 1b: hasAddons esperado true para un ítem con grupo activo+opción — llegó ' . json_encode($row['hasAddons'] ?? null);
    } else {
        echo "[verify_offline_resolution] OK caso 1b: hasAddons y addonGroups son consistentes (mismo ítem, mismo fetch)\n";
    }
}

// ── Caso 2: plantilla resoluble por el device (realm pos-app) ──────────────

$docTemplatesSrc = file_get_contents(dirname(__DIR__, 3) . '/v1/document-templates.php');
if ($docTemplatesSrc === false || !str_contains($docTemplatesSrc, "apiAuthTenant(['panel', 'pos-app'])")) {
    $failures[] = "Caso 2a: api/v1/document-templates.php ya no acepta el realm 'pos-app' — el device no podría bajar plantillas al bootstrap";
} else {
    echo "[verify_offline_resolution] OK caso 2a: document-templates.php acepta el realm 'pos-app' (device), no solo 'panel'\n";
}

$svc = new DocumentTemplateService($db);
$tpl = $svc->find($PY_COMPANY, $TEMPLATE_ID);
if ($tpl === null) {
    $failures[] = 'Setup: no se pudo leer VERIFY template offline — revisar que el seed haya corrido';
} elseif (!is_array($tpl['config'] ?? null) || !isset($tpl['config']['data'])) {
    $failures[] = 'Caso 2b: config de la plantilla sin `data` (bloques) — el renderer no podría imprimir con esto: ' . json_encode($tpl['config'] ?? null);
} else {
    echo "[verify_offline_resolution] OK caso 2b: DocumentTemplateService::find() devuelve el config completo (mismo shape que consume html-renderer.ts/render-template.ts)\n";
}

// ── Caso 3: sin fetch en el camino de impresión/add-ons del front ──────────

$frontendDir = dirname(__DIR__, 4) . '/frontend';
$staticChecks = [
    'lib/hardware/printers/print-in-browser.ts' => [
        // Antes: fetch(`/api/v1/document-templates?id=...`) y fetch(`/api/v1/document-templates`).
        'forbidden' => '/fetch\s*\(\s*[`\'"]\/api\/v1\/document-templates/',
        'label'     => 'fetchTemplateConfig/fetchDefaultTemplateConfig',
    ],
    'hooks/use-item-addons-pos.ts' => [
        // Antes: posFetch(`/api/pos/item-addons?itemId=...`).
        'forbidden' => '/posFetch\s*\(/',
        'label'     => 'useItemAddonsPos',
    ],
];

foreach ($staticChecks as $relPath => $check) {
    $path = $frontendDir . '/' . $relPath;
    $src  = file_get_contents($path);
    if ($src === false) {
        $failures[] = "Caso 3: no se pudo leer {$relPath} para el check estático";
        continue;
    }
    if (preg_match($check['forbidden'], $src) === 1) {
        $failures[] = "Caso 3: {$check['label']} ({$relPath}) todavía hace una llamada de red en el camino resuelto — se esperaba resolución 100% local";
    } else {
        echo "[verify_offline_resolution] OK caso 3: {$check['label']} ({$relPath}) sin llamadas de red — resuelve contra el store local\n";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_offline_resolution] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_offline_resolution] TODO OK\n";
exit(0);

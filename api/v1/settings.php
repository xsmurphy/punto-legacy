<?php
/**
 * REST canónico (API compartida /api) — Ajustes de la empresa (motor ERP, raw).
 *
 *   GET  /v1/settings?view=general                 → ajustes (perfil + parámetros), CRUDO.
 *   GET  /v1/settings?view=options                  → listas para selects.
 *   GET  /v1/settings?view=taxpayer&ruc=<ruc>       → razón social del RUC del propio comercio en el padrón (gate: settings.company.edit).
 *   GET  /v1/settings?view=taxonomies&type=<t>       → items de taxonomía (tax/category/tag/...).
 *   GET  /v1/settings?view=currencies                → matriz de monedas (cotizaciones).
 *   GET  /v1/settings?view=templates                 → plantillas de impresión (taxonomy).
 *   GET  /v1/settings?view=templateFields            → datos dinámicos del template builder.
 *   POST /v1/settings (action=update&type=setting + campos)        → guarda en company.config.
 *   POST /v1/settings (action=update&type=currencies&currencies=…) → guarda cotizaciones (settingObj).
 *   POST /v1/settings (action=saveTemplate&data=…[&i=id])          → crea/actualiza plantilla.
 *   POST /v1/settings (action=removeTemplate&id=…)                 → elimina plantilla.
 *
 * Arregla el guardado roto en PG (tabla `setting` eliminada → company.config; el save de monedas
 * legacy interpolaba COMPANY_ID sin comillas; el delete de plantillas usaba LIMIT 1 inválido en PG y
 * el update no scopeaba companyId → IDOR). Escritura scopeada por COMPANY_ID del JWT. Auth: realm
 * `panel` (apiAuthTenant(['panel'])).
 *
 * Port FIEL de panel/API/v1/settings.php (Fase 2 del desacople de /panel). Cambios respecto al
 * original: `apiMiddleware()` → `apiAuthTenant(['panel'])`; `PANEL_AUTHED_ROLE` → `$ctx['roleId']`;
 * service en namespace `Punto\Api\Settings`. El contrato (vistas, acciones, shape, status codes)
 * es idéntico — el JS de a_settings.js y a_settings_templates.js dependen de él.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx    = apiAuthTenant(['panel', 'api']);
$svc    = new \Punto\Api\Settings\SettingsService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (!hasPermission('settings.company.edit')) {
        apiError('No tenés permiso para esta acción (requiere: settings.company.edit)', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');

    // Plantillas de impresión (taxonomy printTemplate).
    if ($action === 'saveTemplate') {
        $data = (string) (validateHttp('data', 'post') ?: '');
        $id   = (string) (validateHttp('i', 'post') ?: '');
        if ($data === '') {
            apiError('Falta el diseño de la plantilla', 422);
        }
        $r = $svc->saveTemplate(COMPANY_ID, $id, $data);
        if ($r === false) {
            apiError('No se pudo guardar la plantilla', 500);
        }
        apiOk(['action' => 'saveTemplate', 'id' => is_string($r) ? $r : $id]);
    }
    if ($action === 'removeTemplate') {
        $id = (string) (validateHttp('id', 'post') ?: '');
        if ($id === '') {
            apiError('Falta el id de la plantilla', 422);
        }
        if (!$svc->removeTemplate(COMPANY_ID, $id)) {
            apiError('No se pudo eliminar la plantilla', 500);
        }
        apiOk(['action' => 'removeTemplate', 'id' => $id]);
    }

    // Logo de la empresa — upload multipart + delete. Path en S3 idéntico al
    // legacy ({companyId}.jpg en raíz) → POS y panel legacy ven el mismo archivo.
    if ($action === 'uploadLogo') {
        if (empty($_FILES['logo']['tmp_name'])) {
            apiError('Falta el archivo (campo "logo")', 422);
        }
        try {
            apiOk($svc->uploadLogo(COMPANY_ID, $_FILES['logo']));
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
    }
    if ($action === 'deleteLogo') {
        try {
            apiOk($svc->deleteLogo(COMPANY_ID));
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 500);
        }
    }

    $type   = (string) (validateHttp('type', 'post') ?: '');
    if ($action !== 'update' || !in_array($type, ['setting', 'currencies'], true)) {
        apiError('Acción no soportada', 422);
    }

    if ($type === 'currencies') {
        $raw  = (string) (validateHttp('currencies', 'post') ?: '');
        $list = json_decode($raw, true);
        if (!is_array($list)) { $list = []; }
        if (!$svc->updateCurrencies(COMPANY_ID, $list)) {
            apiError('No se pudo guardar', 500);
        }
        apiOk(['action' => 'update', 'type' => 'currencies']);
    }

    $b = fn($k) => (bool) validateHttp($k, 'post');
    // Lectura directa de $_POST, NO validateHttp: `Validation::isValid()`
    // colapsa a false todo valor "falsy" de PHP, y el string "0" lo es. El
    // rubro "Otro" vale exactamente "0" (`lib/company-categories.ts`), así que
    // elegirlo guardaba '' y al recargar la pantalla el Select volvía a
    // "Seleccionar…" — el usuario nunca lograba dejarlo guardado (reporte del
    // owner 2026-08-01). Aplica a cualquier setting cuyo valor legítimo sea
    // "0", no solo al rubro. `is_scalar` mantiene el guard contra arrays/objetos
    // que daba isValid; db_prepare() hoy es no-op, así que no se pierde
    // sanitización.
    $s = fn($k) => is_scalar($_POST[$k] ?? null) ? trim((string) $_POST[$k]) : '';
    // Presencia real en el payload, NO el valor. `isset($_POST[$k])` colapsa
    // null (PHP lo trata igual que "no existe"), y `?? ''` ya escondía la
    // distinción antes de llegar acá. `array_key_exists` es la única forma
    // correcta de saber si el cliente mandó la key: el form ahora manda solo
    // los campos de la sección que se está guardando (ver serialize() en
    // frontend/hooks/use-settings.ts), así que una key ausente significa "no
    // tocar", no "vaciar". Fix del bug donde guardar Apariencia pisaba RUC/
    // rubro con '' porque el POST mandaba SIEMPRE los ~40 campos del form
    // (reporte owner 2026-08-18: settingThousandSeparator quedó '' tras un
    // guardado que no la tocaba).
    $present = fn($k) => array_key_exists($k, $_POST);

    $fields = [];

    $stringMap = [
        'name', 'address', 'website', 'email', 'ruc', 'phone', 'city',
        'country', 'language', 'timeZone', 'currency', 'taxName',
        'billingName', 'tin', 'billDetail', 'category',
        // Identificador único de la empresa (URLs públicas). Normalizado y
        // validado server-side en SettingsService::updateGeneral() — acá solo
        // pasa el crudo trimeado, nunca confiamos en el charset del cliente.
        'slug', 'thousandSeparator', 'itemsSaleLimit',
    ];
    foreach ($stringMap as $k) {
        if ($present($k)) { $fields[$k] = $s($k); }
    }

    // Asistente IA — nombre y personalidad por empresa (context/22 no aplica;
    // ver SettingsService::AGENT_PERSONALITIES para el enum). Nombre truncado
    // acá (mb_substr, respeta multibyte) y personalidad clampeada al enum
    // cerrado — nunca texto libre del cliente llega al system prompt.
    if ($present('agentName')) {
        $fields['agentName'] = mb_substr(trim($s('agentName')), 0, 40);
    }
    if ($present('agentPersonality')) {
        $fields['agentPersonality'] = in_array($s('agentPersonality'), \Punto\Api\Settings\SettingsService::AGENT_PERSONALITIES, true)
            ? $s('agentPersonality')
            : 'professional';
    }

    // Redes sociales: viaja como 4 keys flat (facebook/instagram/youtube/
    // twitter), no anidado — el nesting a 'social' es solo interno para
    // SettingsService::updateGeneral(), que mergea contra lo existente
    // (mismo criterio que settingObj) si alguna subkey vino en el payload.
    $socialKeys = ['facebook', 'instagram', 'youtube', 'twitter'];
    $socialPresent = array_filter($socialKeys, $present);
    if ($socialPresent) {
        $fields['social'] = [];
        foreach ($socialKeys as $k) {
            if ($present($k)) { $fields['social'][$k] = $s($k); }
        }
    }

    $boolMap = [
        'decimal', 'sellsoldout', 'itemSerialized', 'drawerEmail', 'drawerBlind',
        'drawerRequireClosedOrders',
        'settingRemoveTaxes', 'paymentId', 'creditLine', 'storeCredit',
        'ignoreInternal', 'stockCountBlind', 'stockCountRecordOnly',
        'blockUsedDocNo', 'autoSendDocs',
        'weightBarcodes', 'deletedItemsHistory',
        // D2 de context/40-anulacion-y-nota-credito.md — devoluciones.
        'settingReturnAllowIngredientReversal',
    ];
    foreach ($boolMap as $k) {
        if ($present($k)) { $fields[$k] = $b($k); }
    }

    // Listas fijas de conteo de stock (D3 de context/63). Viajan como UN
    // string JSON, igual que `currencies` más arriba: son objetos anidados con
    // un array adentro, y un POST form-encoded no los transporta sin inventar
    // una convención de nombres de campo.
    //
    // Acá solo se decodifica; la normalización (descartar listas sin nombre o
    // sin ítems, deduplicar, truncar) vive en `StockCountSettings::decodeLists()`,
    // que es también el lector — así lo que se guarda es exactamente lo que la
    // caja va a leer. Un JSON inválido se trata como lista vacía, nunca como
    // "no tocar": la key vino presente, el usuario quiso guardar algo.
    if ($present('stockCountLists')) {
        $decoded = json_decode($s('stockCountLists'), true);
        $fields['stockCountLists'] = is_array($decoded) ? $decoded : [];
    }

    // D3 de context/40 — enum cerrado, clampeado server-side (mismo criterio
    // que agentPersonality arriba): nunca texto libre del cliente llega a
    // company.config.
    if ($present('settingReturnRefund')) {
        $fields['settingReturnRefund'] = in_array($s('settingReturnRefund'), ['cash', 'credit'], true)
            ? $s('settingReturnRefund')
            : 'ask';
    }

    // D7/E1b de context/48-escalamiento-de-datos.md — ancho de la ventana
    // abierta de cierre de período, clampeado 1..12 (mismo criterio que
    // period_close_due() en SQL).
    if ($present('settingPeriodCloseMonths')) {
        $fields['settingPeriodCloseMonths'] = max(1, min(12, (int) $s('settingPeriodCloseMonths')));
    }

    // Tolerancia de cuadre del arqueo (mig 164). Clampeada server-side igual
    // que las de arriba: el veredicto verde/rojo/amarillo del reporte de cajas
    // se calcula en el backend, así que el margen se valida en el backend.
    if ($present('settingDrawerTolerance')) {
        $fields['settingDrawerTolerance'] = max(0.0, min(
            \Punto\Api\Reports\CashCountStatus::MAX_TOLERANCE,
            (float) $s('settingDrawerTolerance')
        ));
    }

    try {
        if (!$svc->updateGeneral(COMPANY_ID, $fields)) {
            apiError('No se pudo guardar', 500);
        }
    } catch (\RuntimeException $e) {
        // Slug ya usado por otra company — mensaje legible, no un 500 crudo.
        apiError($e->getMessage(), 422);
    }
    apiOk(['action' => 'update', 'type' => 'setting']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$view = (string) (validateHttp('view') ?: 'general');

if ($view === 'options') {
    apiOk($svc->options());
}

// ── Lookup del RUC del PROPIO comercio en el padrón ────────────────────────
//
// La misma consulta existe en `/v1/contacts?resource=taxpayer`, y ahí está
// bien gateada: quien da de alta un CLIENTE necesita `contacts.customer.create`
// o `contacts.supplier.manage`. Pero acá el RUC que se consulta es el DEL
// COMERCIO, para completar su propia identidad fiscal — un encargado con
// permiso de configurar la empresa y sin permiso de crear clientes se comía un
// 403 al querer cargar el RUC de su propio negocio.
//
// No se toca aquel gate (el caller del alta de clientes sigue igual): el
// SERVICIO es uno solo y cada superficie afirma el permiso que corresponde a
// lo que está por hacer. Acá eso es `settings.company.edit`, que es
// exactamente el permiso que hace falta para PERSISTIR el resultado — sin él,
// la consulta no serviría para nada.
if ($view === 'taxpayer') {
    if (!hasPermission('settings.company.edit')) {
        apiError('No tenés permiso para esta acción (requiere: settings.company.edit)', 403);
    }
    $ruc = trim((string) ($_GET['ruc'] ?? ''));
    if ($ruc === '') {
        apiError('Falta el RUC a consultar', 422);
    }
    $taxpayer = (new \Punto\Api\Contacts\TaxpayerLookupService())->lookup(COMPANY_ID, $ruc);
    if ($taxpayer === null) {
        apiError('No se encontraron datos para ese RUC', 404);
    }
    apiOk($taxpayer);
}

if ($view === 'currencies') {
    apiOk($svc->currencies(COMPANY_ID));
}

if ($view === 'templates') {
    apiOk($svc->templates(COMPANY_ID));
}

if ($view === 'templateFields') {
    apiOk($svc->templateFields(COMPANY_ID));
}

if ($view === 'taxonomies') {
    $allowed = ['tax', 'category', 'tag', 'paymentMethod', 'bank', 'transactionCategory'];
    $type = (string) (validateHttp('type') ?: '');
    if (!in_array($type, $allowed, true)) {
        apiError('Tipo de taxonomía no soportado', 422);
    }
    apiOk(['rows' => $svc->taxonomies(COMPANY_ID, $type)]);
}

// default: general
$row = $svc->general(COMPANY_ID);
if ($row === null) {
    apiError('Empresa no encontrada', 404);
}
apiOk($row);

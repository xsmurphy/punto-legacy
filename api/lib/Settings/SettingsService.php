<?php
declare(strict_types=1);

namespace Punto\Api\Settings;

use DateTimeZone;
use Punto\Api\Support\DbQueryException;
use Punto\Api\Support\Slug;

require_once __DIR__ . '/StockCountSettings.php';

/**
 * Dominio de Ajustes (Settings) — API compartida (motor ERP).
 *
 *   GET  general   → ajustes de la empresa (perfil + parámetros), CRUDOS
 *   GET  options   → listas para los selects (países, categorías, timezones, idiomas, separadores)
 *   GET  taxonomies→ items de taxonomía para los dropdowns adm (tax/category/tag/payment/bank)
 *   POST general   → guarda los ajustes en company.config JSONB
 *   POST currencies→ guarda cotizaciones en settingObj.currencies (MERGE no-destructivo)
 *   POST saveTemplate/removeTemplate → plantillas de impresión (taxonomy type=printTemplate)
 *
 * FIX PG CRÍTICO (heredado del original): el legacy `a_settings.php?action=update&type=setting` hace
 * `AutoExecute('setting', …)` pero la tabla `setting` fue ELIMINADA en Phase PG (todo vive en
 * `company.config` JSONB) → el guardado de ajustes está ROTO en PG. Acá se rutea a `company.config`
 * vía `ncmUpdate` (que enruta claves desconocidas al JSONB con merge `||` no-destructivo).
 *
 * Los flags "extra" (ignoreInternal/stockCountBlind/blockUsedDocNo/autoSendDocs/
 * weightBarcodes/deletedItemsHistory) + las monedas viven en `config.settingObj` (un JSON
 * anidado). El write hace MERGE no-destructivo de settingObj (preserva currencies y claves
 * desconocidas) — a diferencia del legacy que hacía `settingObj = json_encode($_POST)` (clobber).
 *
 * Port FIEL de panel/lib/settings/SettingsService.php (Fase 2 del desacople de /panel). Únicos
 * cambios respecto al original: namespace, `final`, `declare(strict_types=1)`, `use DateTimeZone`,
 * y los 3 resources estáticos (countries.php / company_categories.php / countries_hispanic.json)
 * viajan en `resources/` del propio módulo para no depender de panel/libraries (mantiene /api
 * self-contained — el plan declara independencia entre módulos).
 *
 * Tenant: companyId bindeado en cada query. Datos CRUDOS (el front formatea). Ver REGLA RAÍZ 2.
 *
 * Nota namespace: funciones globales (ncmExecute, ncmUpdate, ncmInsert, ncmDelete) y constantes
 * (ASSETS_URL) resuelven a sus globales por fallback de PHP — no requieren `use`.
 */
final class SettingsService
{
    /** Personalidades soportadas del asistente IA — enum cerrado, nunca texto libre
     *  del cliente. El prompt real de cada una vive server-side en el route del
     *  agente (frontend/app/api/agent/chat/route.ts); acá solo se valida el slug. */
    public const AGENT_PERSONALITIES = ['professional', 'friendly', 'direct', 'teacher'];

    /** Ajustes de la empresa (perfil + parámetros) para el form. */
    public function general($companyId)
    {
        $r = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [$companyId]);
        if (!$r) { return null; }

        $social = json_decode((string) ($r['settingSocialMedia'] ?? ''), true);
        if (!is_array($social)) { $social = []; }
        $obj = json_decode((string) ($r['settingObj'] ?? ''), true);
        if (!is_array($obj)) { $obj = []; }

        // Logo: el flag `hasLogo` + `logoUrl` + `logoUploadedAt` (cache-bust)
        // se persisten en settingObj cuando el frontend sube/borra via
        // uploadLogo()/deleteLogo(). La URL es la pública directa de S3
        // (path-style devuelto por S3Client::put) — NO va por el resize
        // service legacy, porque ese servicio depende de un prefix S3
        // distinto al de frontend (S3_KEY_PREFIX=puntosys agrega un nivel
        // que el resize legacy no conoce → 404).
        $hasLogo  = !empty($obj['hasLogo']);
        $logoUrl  = (string) ($obj['logoUrl'] ?? '');
        $logoStmp = isset($obj['logoUploadedAt']) ? (int) $obj['logoUploadedAt'] : null;
        $logo     = ($hasLogo && $logoUrl !== '')
            ? $logoUrl . ($logoStmp ? '?v=' . $logoStmp : '')
            : null;

        return [
            // Perfil
            'logo'            => $logo,
            'hasLogo'         => $hasLogo,
            'name'            => (string) ($r['settingName'] ?? ''),
            'address'         => (string) ($r['settingAddress'] ?? ''),
            'email'           => (string) ($r['settingEmail'] ?? ''),
            'billingName'     => (string) ($r['settingBillingName'] ?? ''),
            'ruc'             => (string) ($r['settingRUC'] ?? ''),
            'billDetail'      => (string) ($r['settingBillDetail'] ?? ''),
            'website'         => (string) ($r['settingWebSite'] ?? ''),
            'social'          => [
                'facebook'  => (string) ($social['facebook'] ?? ''),
                'instagram' => (string) ($social['instagram'] ?? ''),
                'youtube'   => (string) ($social['youtube'] ?? ''),
                'twitter'   => (string) ($social['twitter'] ?? ''),
            ],
            'category'        => (string) ($r['settingCompanyCategoryId'] ?? ''),
            // Identificador único de la empresa (URLs públicas). Columna real
            // `company.slug` — ver mig 113_company_slug_unique.sql.
            'slug'            => (string) ($r['slug'] ?? ''),
            'phone'           => (string) ($r['settingPhone'] ?? ''),
            'city'            => (string) ($r['settingCity'] ?? ''),
            'country'         => (string) ($r['settingCountry'] ?? ''),
            'language'        => $this->withDefault($r['settingLanguage'] ?? null, 'es'),
            'timeZone'        => (string) ($r['settingTimeZone'] ?? ''),
            // Parámetros (app)
            'currency'        => (string) ($r['settingCurrency'] ?? ''),
            'thousandSeparator' => $this->withDefault($r['settingThousandSeparator'] ?? null, 'dot'),
            'taxName'         => $this->withDefault($r['settingTaxName'] ?? null, 'IVA'),
            'tin'             => (string) ($r['settingTIN'] ?? ''),
            'itemsSaleLimit'  => (string) ($r['settingItemsSaleLimit'] ?? ''),
            // Toggles (settingX)
            'decimal'         => ((string) ($r['settingDecimal'] ?? '') === 'yes'),
            'sellsoldout'     => ((string) ($r['settingSellSoldOut'] ?? '') === 'yes'),
            'itemSerialized'  => $this->truthy($r['settingItemSerialized'] ?? null),
            'drawerEmail'     => $this->truthy($r['settingDrawerEmail'] ?? null),
            'drawerBlind'     => $this->truthy($r['settingDrawerBlind'] ?? null),
            // Gate de cierre de turno (owner 2026-08-25): con esto prendido la
            // caja no cierra si la SUCURSAL tiene órdenes o espacios abiertos.
            // Apagado por default — ver Punto\Api\Services\ShiftCloseGate.
            'drawerRequireClosedOrders' => $this->truthy($r['settingDrawerRequireClosedOrders'] ?? null),
            'settingRemoveTaxes' => $this->truthy($r['settingRemoveTaxes'] ?? null),
            'paymentId'       => $this->truthy($r['settingPaymentMethodId'] ?? null),
            'creditLine'      => $this->truthy($r['settingForceCreditLine'] ?? null),
            'storeCredit'     => $this->truthy($r['settingStoreCredit'] ?? null),
            // D3/D2 de context/40-anulacion-y-nota-credito.md — devoluciones.
            // 'ask' es el default explícito de negocio (no vacío/no-seteado):
            // el comercio elige forzar 'cash'/'credit' o dejar que el cajero
            // pregunte en cada devolución.
            'settingReturnRefund' => in_array((string) ($r['settingReturnRefund'] ?? ''), ['cash', 'credit'], true)
                ? (string) $r['settingReturnRefund']
                : 'ask',
            'settingReturnAllowIngredientReversal' => ((string) ($r['settingReturnAllowIngredientReversal'] ?? '')) === 'yes',
            // D7/E1b de context/48-escalamiento-de-datos.md (mig 157) — ancho
            // de la ventana abierta de cierre de período (mes en curso + N
            // meses anteriores). Mismo default/clamp que `period_close_due()`
            // en SQL (1..12, default 1) para que panel y job de mantenimiento
            // coincidan siempre.
            'settingPeriodCloseMonths' => max(1, min(12, (int) ($r['settingPeriodCloseMonths'] ?? 1))),
            // Tolerancia de cuadre del arqueo (context/08 §58 — toda regla que
            // clasifica es configurable). Default 0: el comercio que no la tocó
            // arquea exacto. El piso de redondeo (1 unidad mínima de la moneda)
            // NO se aplica acá sino en CashCountStatus::effectiveTolerance() —
            // este valor es lo que el dueño escribió, no lo que rige.
            'settingDrawerTolerance' => max(0.0, min(
                \Punto\Api\Reports\CashCountStatus::MAX_TOLERANCE,
                (float) ($r['settingDrawerTolerance'] ?? 0)
            )),
            // Toggles (settingObj / _fullSettings)
            'ignoreInternal'      => $this->truthy($obj['ignoreInternal'] ?? null),
            'stockCountBlind'     => $this->truthy($obj['stockCountBlind'] ?? null),
            // D9 de context/63 — nombrado en negativo a propósito: el default
            // del owner es que el conteo SÍ ajuste, y un flag ausente en el
            // JSONB vale falso. Ver StockCountSettings.
            'stockCountRecordOnly' => $this->truthy($obj['stockCountRecordOnly'] ?? null),
            'blockUsedDocNo'      => $this->truthy($obj['blockUsedDocNo'] ?? null),
            'autoSendDocs'        => $this->truthy($obj['autoSendDocs'] ?? null),
            'weightBarcodes'      => $this->truthy($obj['weightBarcodes'] ?? null),
            'deletedItemsHistory' => $this->truthy($obj['deletedItemsHistory'] ?? null),
            // Asistente IA — nombre y personalidad por empresa. Viven como claves
            // top-level de `config` (igual que settingName/settingAddress: ninguna
            // de las dos es columna real de `company`, así que ncmUpdate las
            // enruta solas al JSONB vía _routeToJsonb + merge no-destructivo).
            // agentPersonality se re-valida acá por si quedó un valor viejo/inválido
            // en BD — nunca se propaga texto libre al prompt del agente.
            'agentName'           => (string) ($r['agentName'] ?? ''),
            'agentPersonality'    => in_array($r['agentPersonality'] ?? null, self::AGENT_PERSONALITIES, true)
                ? (string) $r['agentPersonality']
                : 'professional',
            // Listas fijas de conteo (D3 de context/63). Clave top-level de
            // `config` con el array serializado (mismo trato que settingObj):
            // ncmUpdate enruta las claves desconocidas al JSONB con merge no
            // destructivo. Se normaliza con el MISMO decodificador que usa la
            // caja, así lo que devuelve el form es lo que el conteo va a leer.
            'stockCountLists'     => \Punto\Api\Settings\StockCountSettings::decodeLists(
                $r['stockCountLists'] ?? null
            ),
        ];
    }

    /**
     * Guarda los ajustes en company.config. SCOPEADO por companyId.
     *
     * MERGE PARCIAL: `$f` trae solo las keys que el caller efectivamente quiere
     * tocar (el form manda solo los campos de la sección activa — ver
     * serialize() en frontend/hooks/use-settings.ts). Una key ausente de `$f`
     * NO se escribe; una key presente con '' SÍ se escribe (el usuario puede
     * limpiar un campo a propósito). `array_key_exists` es la única forma
     * correcta de distinguir esos dos casos — `??`/`isset` los colapsan.
     *
     * Antes esta función reescribía las ~30 columnas SIEMPRE, con '' para
     * cualquier campo que el caller no mandara. Eso rompió settingThousandSeparator
     * en prod (2026-08-18): guardar una sección que no la toca la dejó en ''.
     *
     * @param array $f campos presentes (booleans ya como bool, strings limpios). @return bool
     * @throws \RuntimeException  slug pedido ya en uso por otra company (mensaje legible para el front).
     */
    public function updateGeneral($companyId, array $f)
    {
        // Slug primero, ANTES de tocar nada — si está en uso abortamos sin
        // side effects (fail fast, no llegamos a leer/reescribir settingObj).
        $slug = null;
        if (array_key_exists('slug', $f)) {
            $slug = Slug::normalize((string) $f['slug']);
            if ($slug !== null) {
                $this->assertSlugAvailable($companyId, $slug);
            }
        }

        $record = [];

        // Columnas 1:1 con el nombre del campo del form → settingX. Solo se
        // tocan las que vinieron presentes en $f.
        $simpleMap = [
            'address'     => 'settingAddress',
            'website'     => 'settingWebSite',
            'email'       => 'settingEmail',
            'ruc'         => 'settingRUC',
            'phone'       => 'settingPhone',
            'city'        => 'settingCity',
            'country'     => 'settingCountry',
            'timeZone'    => 'settingTimeZone',
            'currency'    => 'settingCurrency',
            'taxName'     => 'settingTaxName',
            'billingName' => 'settingBillingName',
            'tin'         => 'settingTIN',
            'billDetail'  => 'settingBillDetail',
            'category'    => 'settingCompanyCategoryId',
            'thousandSeparator' => 'settingThousandSeparator',
            'itemsSaleLimit'    => 'settingItemsSaleLimit',
        ];
        foreach ($simpleMap as $fKey => $col) {
            if (array_key_exists($fKey, $f)) {
                $record[$col] = $f[$fKey];
            }
        }
        if (array_key_exists('name', $f)) {
            $record['settingName'] = $f['name'] ?? '';
        }
        if (array_key_exists('language', $f)) {
            $record['settingLanguage'] = $f['language'] !== '' ? $f['language'] : 'es';
        }
        if (array_key_exists('slug', $f)) {
            // NULL (no '') cuando el usuario borra el campo — el índice UNIQUE
            // parcial de la mig 113 solo cubre valores no vacíos, pero NULL es
            // la forma canónica de "sin slug" (evita filas con '' acumulándose).
            $record['slug'] = $slug;
        }
        if (array_key_exists('decimal', $f)) {
            $record['settingDecimal'] = !empty($f['decimal']) ? 'yes' : 'no';
        }
        if (array_key_exists('sellsoldout', $f)) {
            $record['settingSellSoldOut'] = !empty($f['sellsoldout']) ? 'yes' : 'no';
        }
        // D3/D2 de context/40-anulacion-y-nota-credito.md — mismo criterio
        // 'yes'/'no' que settingSellSoldOut/settingDecimal de arriba (NO el
        // 1/0 de $tinyBoolMap): StockReversalPolicy/ReturnService ya leen
        // `config->>'settingReturnAllowIngredientReversal' = 'yes'` — escribir
        // 1/0 acá los dejaría desincronizados con esa lectura.
        if (array_key_exists('settingReturnRefund', $f)) {
            $val = (string) $f['settingReturnRefund'];
            $record['settingReturnRefund'] = in_array($val, ['cash', 'credit'], true) ? $val : 'ask';
        }
        if (array_key_exists('settingReturnAllowIngredientReversal', $f)) {
            $record['settingReturnAllowIngredientReversal'] = !empty($f['settingReturnAllowIngredientReversal']) ? 'yes' : 'no';
        }
        // D7/E1b de context/48-escalamiento-de-datos.md — mismo clamp 1..12
        // que period_close_due() en SQL.
        if (array_key_exists('settingPeriodCloseMonths', $f)) {
            $record['settingPeriodCloseMonths'] = max(1, min(12, (int) $f['settingPeriodCloseMonths']));
        }
        // Tolerancia de cuadre del arqueo — mismo clamp que la lectura de
        // general() y que CashCountStatus, para que no haya forma de guardar un
        // valor que el clasificador después reinterprete.
        if (array_key_exists('settingDrawerTolerance', $f)) {
            $record['settingDrawerTolerance'] = max(0.0, min(
                \Punto\Api\Reports\CashCountStatus::MAX_TOLERANCE,
                (float) $f['settingDrawerTolerance']
            ));
        }
        $tinyBoolMap = [
            'itemSerialized'     => 'settingItemSerialized',
            'drawerEmail'        => 'settingDrawerEmail',
            'drawerBlind'        => 'settingDrawerBlind',
            'drawerRequireClosedOrders' => 'settingDrawerRequireClosedOrders',
            'settingRemoveTaxes' => 'settingRemoveTaxes',
            'paymentId'          => 'settingPaymentMethodId',
            'creditLine'         => 'settingForceCreditLine',
            'storeCredit'        => 'settingStoreCredit',
        ];
        foreach ($tinyBoolMap as $fKey => $col) {
            if (array_key_exists($fKey, $f)) {
                $record[$col] = !empty($f[$fKey]) ? 1 : 0;
            }
        }

        // Redes sociales: viven como un blob JSON anidado (settingSocialMedia),
        // igual que settingObj. Mergeamos contra lo existente para no perder
        // las 3 subkeys no tocadas si algún caller manda solo una.
        if (array_key_exists('social', $f) && is_array($f['social'])) {
            $sm = $this->readSocialMedia($companyId);
            if ($sm === null) {
                return false;
            }
            foreach (['facebook', 'instagram', 'youtube', 'twitter'] as $sk) {
                if (array_key_exists($sk, $f['social'])) {
                    $sm[$sk] = $f['social'][$sk];
                }
            }
            $record['settingSocialMedia'] = json_encode($sm);
        }

        // settingObj: mismo criterio de merge parcial para los flags que
        // vive ahí — un flag ausente de $f NO debe colapsar a 0 (antes lo
        // hacía siempre, porque esta función asumía que $f traía los 40
        // campos completos). Solo leemos/reescribimos settingObj si al menos
        // uno de los flags vino presente — evita un round-trip de lectura
        // innecesario cuando la sección guardada no los toca (ej. Apariencia).
        $flagMap = [
            'ignoreInternal'      => 'ignoreInternal',
            'stockCountBlind'     => 'stockCountBlind',
            'stockCountRecordOnly' => 'stockCountRecordOnly',
            'blockUsedDocNo'      => 'blockUsedDocNo',
            'autoSendDocs'        => 'autoSendDocs',
            'weightBarcodes'      => 'weightBarcodes',
            'deletedItemsHistory' => 'deletedItemsHistory',
        ];
        $presentFlags = array_intersect_key($flagMap, $f);
        if ($presentFlags) {
            // Igual guard que antes: si la lectura FALLA (null, no [] vacío
            // legítimo) abortamos — escribir un settingObj a medias borraría
            // currencies y los flags no tocados.
            $obj = $this->readSettingObj($companyId);
            if ($obj === null) {
                return false;
            }
            foreach ($presentFlags as $fKey => $objKey) {
                $obj[$objKey] = !empty($f[$fKey]) ? 1 : 0;
            }
            $record['settingObj'] = json_encode($obj);
        }

        // Listas fijas de conteo (D3). Se guardan como JSON en una clave
        // top-level de `config`, normalizadas por el MISMO decodificador que
        // las lee: una lista sin nombre o sin ítems no se persiste, así la
        // caja nunca recibe una lista que no puede completar.
        if (array_key_exists('stockCountLists', $f)) {
            $record['stockCountLists'] = json_encode(
                \Punto\Api\Settings\StockCountSettings::decodeLists($f['stockCountLists'])
            );
        }

        // Asistente IA — ver comentario en general(). Ya vienen sanitizados
        // desde api/v1/settings.php (name truncado a 40, personality clamped
        // al enum); acá solo defensa adicional para no persistir basura.
        if (array_key_exists('agentName', $f)) {
            $record['agentName'] = mb_substr((string) $f['agentName'], 0, 40);
        }
        if (array_key_exists('agentPersonality', $f)) {
            $record['agentPersonality'] = in_array($f['agentPersonality'], self::AGENT_PERSONALITIES, true)
                ? (string) $f['agentPersonality']
                : 'professional';
        }

        if (!$record) {
            // Nada presente para guardar (ej. sección Apariencia, que hoy no
            // tiene campos propios en el form) — no-op válido, no un error.
            return true;
        }

        try {
            $res = ncmUpdate([
                'records'     => $record,
                'table'       => 'company',
                'where'       => 'companyId = ?',
                'whereParams' => [$companyId],
            ]);
        } catch (DbQueryException $e) {
            // Red final: violación del UNIQUE parcial (mig 113) por carrera
            // entre el pre-check de assertSlugAvailable() y este UPDATE.
            // Antes se leía del `['error' => msg]` que devolvía ncmUpdate
            // cuando el wrapper devolvía false; ahora el wrapper lanza y el
            // contrato de error de ncmUpdate ya no se alcanza para fallos de
            // SQL — la detección tiene que vivir acá.
            if ($e->sqlState() === '23505'
                || stripos($e->getMessage(), 'idx_company_slug_unique') !== false
                || stripos($e->getMessage(), 'duplicate key') !== false) {
                throw new \RuntimeException('Ese slug ya está en uso');
            }
            throw $e;
        }

        // El cache por request de las preferencias de conteo quedó viejo con
        // este UPDATE. Se invalida DESPUÉS de escribir: quien lea en el mismo
        // request (el propio form al responder) tiene que ver lo guardado, no
        // lo que había al empezar.
        \Punto\Api\Settings\StockCountSettings::forget((string) $companyId);

        return is_array($res) && $res['error'] === false;
    }

    /**
     * Chequeo previo de unicidad (UX: error claro antes de intentar el UPDATE).
     * El UNIQUE parcial de la mig 113 es la red final ante carreras — ver
     * el mapeo de error en updateGeneral(). SCOPEADO: excluye la propia company.
     * @throws \RuntimeException
     */
    private function assertSlugAvailable(string $companyId, string $slug): void
    {
        if (!Slug::isAvailable($slug, $companyId)) {
            throw new \RuntimeException('Ese slug ya está en uso');
        }
    }

    /**
     * Lee el blob settingObj (JSON anidado dentro de config) crudo.
     * @return array|null  array con el contenido (o [] si la fila no tiene settingObj);
     *                     NULL si la lectura falló (no hay fila) → el caller debe abortar el write.
     */
    private function readSettingObj($companyId)
    {
        // Extraemos settingObj DIRECTO con el operador JSONB ->> (devuelve texto).
        // Leer la columna `config` entera vía el wrapper DB devolvía un valor NO
        // usable (ni string ni array) → el json_decode fallaba y readSettingObj
        // devolvía [] SIEMPRE → cada write de settingObj clobbereaba el resto
        // (el upload de logo escribía {logo}, el "Guardar" escribía {7 flags} y se
        // pisaban mutuamente; también borraba currencies). ->> lee confiable.
        $r = ncmExecute(
            "SELECT config->>'settingObj' AS so FROM company WHERE companyId = ? LIMIT 1",
            [$companyId],
            false,
            true
        );
        if (!$r || !is_object($r) || $r->EOF) {
            return null;   // lectura fallida → no arriesgar clobber
        }
        $so = $r->fields['so'] ?? null;
        $r->Close();
        if ($so === null || $so === '') {
            return [];     // company sin settingObj todavía (nueva) → [] legítimo
        }
        $obj = json_decode((string) $so, true);
        return is_array($obj) ? $obj : [];
    }

    /**
     * Lee el blob settingSocialMedia (JSON anidado dentro de config) crudo.
     * Mismo criterio y mismo motivo que readSettingObj() (->> directo, no la
     * columna `config` entera vía el wrapper) — ver el comentario de arriba.
     * @return array|null  array con el contenido (o [] si la fila no lo tiene);
     *                     NULL si la lectura falló → el caller debe abortar el write.
     */
    private function readSocialMedia($companyId)
    {
        $r = ncmExecute(
            "SELECT config->>'settingSocialMedia' AS sm FROM company WHERE companyId = ? LIMIT 1",
            [$companyId],
            false,
            true
        );
        if (!$r || !is_object($r) || $r->EOF) {
            return null;
        }
        $sm = $r->fields['sm'] ?? null;
        $r->Close();
        if ($sm === null || $sm === '') {
            return [];
        }
        $obj = json_decode((string) $sm, true);
        return is_array($obj) ? $obj : [];
    }

    /** Listas para los selects del form. */
    public function options()
    {
        $countries = [];
        $cFile = __DIR__ . '/resources/countries.php';
        if (is_file($cFile)) {
            require $cFile;   // countries.php asigna $countries (no return) en este scope
        }
        $countryOpts = [];
        foreach ((is_array($countries) ? $countries : []) as $code => $v) {
            $countryOpts[] = ['code' => (string) $code, 'name' => (string) ($v['name'] ?? $code)];
        }

        $catFile = __DIR__ . '/resources/company_categories.php';
        $categories = is_file($catFile) ? require $catFile : [];

        return [
            'countries'         => $countryOpts,
            'categories'        => $categories,                 // { grupo: { label: code } }
            'timezones'         => $this->timezones(),          // { region: [ {value,label} ] }
            'languages'         => [['code' => 'es', 'name' => 'Español'], ['code' => 'en', 'name' => 'English'], ['code' => 'pt', 'name' => 'Portugues']],
            'thousandSeparator' => [['value' => 'comma', 'name' => 'Coma'], ['value' => 'dot', 'name' => 'Punto']],
        ];
    }

    /** Zonas horarias agrupadas por región (sin la hora actual — el front no la necesita). */
    private function timezones()
    {
        $regions = [
            'America'    => DateTimeZone::AMERICA,
            'Europe'     => DateTimeZone::EUROPE,
            'Asia'       => DateTimeZone::ASIA,
            'Atlantic'   => DateTimeZone::ATLANTIC,
            'Pacific'    => DateTimeZone::PACIFIC,
            'Africa'     => DateTimeZone::AFRICA,
            'Australia'  => DateTimeZone::AUSTRALIA,
            'Indian'     => DateTimeZone::INDIAN,
            'Antarctica' => DateTimeZone::ANTARCTICA,
            'Arctic'     => DateTimeZone::ARCTIC,
        ];
        $out = [];
        foreach ($regions as $name => $mask) {
            $zones = DateTimeZone::listIdentifiers($mask);
            $list  = [];
            foreach ($zones as $tz) {
                $list[] = ['value' => $tz, 'label' => substr($tz, strlen($name) + 1)];
            }
            if ($list) { $out[] = ['region' => $name, 'zones' => $list]; }
        }
        return $out;
    }

    /** Items de una taxonomía de la company (para los dropdowns adm del app tab). */
    public function taxonomies($companyId, $type)
    {
        $res = ncmExecute(
            "SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = ? AND companyId = ? ORDER BY taxonomyName ASC",
            [$type, $companyId], false, true
        );
        $out = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $out[] = ['id' => (string) $res->fields['taxonomyId'], 'name' => (string) $res->fields['taxonomyName']];
                $res->MoveNext();
            }
            $res->Close();
        }
        return $out;
    }

    /**
     * Matriz de monedas: lista de monedas del mundo (LatAm) con su cotización configurada.
     * Las cotizaciones viven en config.settingObj.currencies = [ {CODE: monto}, ... ].
     * @return array{rows: array<int, array{ccode:string, code:string, value:float}>}
     */
    public function currencies($companyId)
    {
        $cFile     = __DIR__ . '/resources/countries_hispanic.json';
        $countries = is_file($cFile) ? json_decode((string) file_get_contents($cFile), true) : [];
        if (!is_array($countries)) { $countries = []; }

        // Cotizaciones guardadas → mapa CODE => monto.
        $obj   = $this->readSettingObj($companyId);
        $saved = [];
        if (is_array($obj) && !empty($obj['currencies']) && is_array($obj['currencies'])) {
            foreach ($obj['currencies'] as $pair) {
                if (is_array($pair)) {
                    foreach ($pair as $code => $amt) {
                        if ((float) $amt > 0) { $saved[(string) $code] = (float) $amt; }
                    }
                }
            }
        }

        // UNA fila por MONEDA, no por país. El roster sale de un JSON de
        // PAÍSES y varios comparten moneda (USD: Ecuador, El Salvador,
        // Panamá...). Sin este dedup, cargar la cotización de USD la hacía
        // matchear todas esas filas: el POS mostraba "USD 2.813,56" cuatro
        // veces bajo el total, el editor de Ajustes pintaba cuatro inputs
        // para la misma moneda (y su key de React ya estaba parchada con el
        // idx para taparlo), y el picker de precios por moneda ofrecía USD
        // repetido. La moneda es la entidad; el país solo aporta la bandera
        // (`ccode`) — se queda el primero que la trae.
        $rows = [];
        $seen = [];
        foreach ($countries as $ccode => $v) {
            $code = (string) ($v['currency']['code'] ?? '');
            if ($code === '' || isset($seen[$code])) { continue; }
            $seen[$code] = true;
            $rows[] = ['ccode' => (string) $ccode, 'code' => $code, 'value' => $saved[$code] ?? 0];
        }
        return ['rows' => $rows];
    }

    /**
     * Guarda las cotizaciones de monedas en config.settingObj.currencies (MERGE no-destructivo:
     * lee el settingObj completo, reemplaza solo `currencies`, reescribe — preserva los 7 flags).
     * Bound params (el legacy interpolaba COMPANY_ID sin comillas → ROTO en PG). SCOPEADO por companyId.
     * @param array $list  [{code, value}, ...] del front. @return bool
     */
    public function updateCurrencies($companyId, array $list)
    {
        $obj = $this->readSettingObj($companyId);
        if ($obj === null) {
            return false;   // lectura fallida → no arriesgar clobber de settingObj
        }

        $updt = [];
        $seen = [];
        foreach ($list as $val) {
            if (!is_array($val)) { continue; }
            $amount   = (float) ($val['value'] ?? 0);
            $currency = preg_replace('/[^a-z]/i', '', (string) ($val['code'] ?? ''));
            if ($currency === '' || strlen($currency) > 3) { continue; }
            // Dedup espejo del de currencies(): un cliente viejo (o el editor
            // antes del fix) mandaba USD repetido por cada país que lo usa, y
            // acá se guardaba repetido. Primera aparición gana — es la que el
            // usuario ve arriba en el editor.
            if (isset($seen[$currency])) { continue; }
            $seen[$currency] = true;
            if ($amount > 0) { $updt[] = [$currency => $amount]; }
        }
        $obj['currencies'] = $updt;

        $res = ncmUpdate([
            'records'     => ['settingObj' => json_encode($obj)],
            'table'       => 'company',
            'where'       => 'companyId = ?',
            'whereParams' => [$companyId],
        ]);
        return is_array($res) && ($res['error'] === false);
    }

    /**
     * Plantillas de impresión de la empresa (taxonomy type=printTemplate).
     * `json` = taxonomyExtra (el diseño serializado que rinde el templateBuilder en el front).
     * NOTA PG: el legacy incluía `OR companyId = 1` (plantillas globales) — eso ROMPE contra una
     * columna UUID (invalid input syntax for type uuid: "1") → se consultan solo las propias.
     * @return array{rows: array<int, array{id:string, name:string, json:string}>}
     */
    public function templates($companyId)
    {
        $res = ncmExecute(
            "SELECT taxonomyId, taxonomyName, taxonomyExtra
             FROM taxonomy
             WHERE taxonomyType = 'printTemplate' AND companyId = ?
             ORDER BY taxonomyName ASC",
            [$companyId], false, true
        );
        $out = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $out[] = [
                    'id'   => (string) $res->fields['taxonomyId'],
                    'name' => (string) $res->fields['taxonomyName'],
                    'json' => (string) ($res->fields['taxonomyExtra'] ?? ''),
                ];
                $res->MoveNext();
            }
            $res->Close();
        }
        return ['rows' => $out];
    }

    /**
     * Datos dinámicos de la PALETA del template builder (los campos arrastrables que dependen de la
     * empresa): defaults de los campos de empresa + lista de impuestos (para los campos por-impuesto)
     * + taxName/tinName. El resto de la paleta (labels fijos) lo rinde el front estático.
     * El default del logo es la URL del resize (el widget rinde <img src=default>); el legacy
     * embebía base64 vía curl — innecesario para el diseñador.
     */
    public function templateFields($companyId)
    {
        $r = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [$companyId]);
        if (!$r) { $r = []; }

        $assets = defined('ASSETS_URL') ? rtrim((string) ASSETS_URL, '/') : '/assets';

        return [
            'taxName' => (string) ($r['settingTaxName'] ?? 'IVA'),
            // Mismo criterio que /v1/bootstrap: si el tenant no configuró la
            // etiqueta, sale la de su PAÍS, no el literal 'TIN'. `?? 'TIN'`
            // ni siquiera cubría el caso real — settingTIN llega como string
            // VACÍO, no como null, así que el fallback nunca se disparaba y
            // la paleta quedaba sin nombre de documento.
            'tinName' => self::labelOrCountryDefault(
                $r['settingTIN'] ?? null,
                \Punto\Api\Support\TenantLocale::country((string) $companyId)
            ),
            // Documento personal del cliente (Cédula/DNI/CPF…). No tiene
            // ajuste propio: sale del país, igual que en el front.
            'docName' => \Punto\Api\Support\CountryDefaults::personalIdLabel(
                \Punto\Api\Support\TenantLocale::country((string) $companyId)
            ) ?? '',
            'company' => [
                'logo'        => $assets . '/200-200/0/' . $companyId . '.jpg',
                'logoBw'      => $assets . '/200-200/&f=2%7C4,-50/' . $companyId . '.jpg',
                'name'        => (string) ($r['settingName'] ?? ''),
                'billingName' => (string) ($r['settingBillingName'] ?? ''),
                'tin'         => (string) ($r['settingRUC'] ?? ''),
                'address'     => (string) ($r['settingAddress'] ?? ''),
                'email'       => (string) ($r['settingEmail'] ?? ''),
                'website'     => (string) ($r['settingWebSite'] ?? ''),
            ],
            'taxes' => $this->taxonomies($companyId, 'tax'),   // [{id, name}] para los campos por-impuesto
        ];
    }

    /**
     * Etiqueta configurada por el tenant, o la de su país si no configuró
     * ninguna, o '' si tampoco conocemos el país.
     *
     * El trim importa: la config guarda '' (no null) cuando el campo nunca se
     * tocó, así que `?? $default` no alcanza — es la misma trampa que hay
     * documentada para la moneda.
     */
    private static function labelOrCountryDefault($configured, ?string $iso): string
    {
        $configured = trim((string) $configured);
        if ($configured !== '') {
            return $configured;
        }

        return \Punto\Api\Support\CountryDefaults::taxIdLabel($iso) ?? '';
    }

    /**
     * Crea o actualiza una plantilla de impresión. SCOPEADO por companyId (el UPDATE legacy
     * filtraba solo por taxonomyId → IDOR; acá se agrega companyId). El nombre sale del propio
     * diseño (page_name). @param string $id  vacío = insert. @return string|bool  id nuevo | true | false
     */
    public function saveTemplate($companyId, $id, $dataJson)
    {
        $jdata = json_decode((string) $dataJson, true);
        $name  = (is_array($jdata) && !empty($jdata['page_name'])) ? (string) $jdata['page_name'] : 'Nueva Plantilla';

        if ($id !== '' && $id !== null) {
            $res = ncmUpdate([
                'records'     => ['taxonomyName' => $name, 'taxonomyExtra' => (string) $dataJson],
                'table'       => 'taxonomy',
                'where'       => 'taxonomyId = ? AND companyId = ?',
                'whereParams' => [$id, $companyId],
            ]);
            return is_array($res) && ($res['error'] === false);
        }

        $newId = ncmInsert(['table' => 'taxonomy', 'records' => [
            'taxonomyName'  => $name,
            'taxonomyExtra' => (string) $dataJson,
            'taxonomyType'  => 'printTemplate',
            'companyId'     => $companyId,
        ]]);
        return $newId !== false ? $newId : false;
    }

    /**
     * Elimina una plantilla. SCOPEADO por companyId + type (defensa). Sin LIMIT (inválido en PG).
     * @return bool
     */
    public function removeTemplate($companyId, $id)
    {
        $res = ncmDelete(
            "DELETE FROM taxonomy WHERE taxonomyId = ? AND companyId = ? AND taxonomyType = 'printTemplate'",
            [$id, $companyId]
        );
        return $res !== false;
    }

    /** Normaliza un valor PG (1/0, 't'/'f', '1', true, 'yes') a bool. */
    private function truthy($v)
    {
        if (is_bool($v)) { return $v; }
        $s = strtolower((string) $v);
        return in_array($s, ['1', 't', 'true', 'yes', 'on'], true) || (is_numeric($s) && (float) $s > 0);
    }

    /**
     * NULL o '' caen al default; cualquier otro valor pasa tal cual.
     *
     * Solo se usa para los 3 campos que ya tenían un default hardcodeado acá
     * mismo (`?? 'dot'`/`?? 'es'`/`?? 'IVA'`) y que el usuario nunca puede
     * dejar en '' desde la UI (son Select, no texto libre) — no es un intento
     * de esconder bugs de escritura: el merge parcial de updateGeneral() ya
     * no puede producir '' accidental. Es una red de lectura para filas ya
     * corruptas por el bug viejo (ej. settingThousandSeparator = '' en la
     * company del owner, 2026-08-18) sin tocar la BD directamente. NO se
     * aplica a `currency`: es un input de texto libre sin un default único
     * válido para todos los países — inventar uno acá escondería el caso
     * real en vez de arreglarlo.
     */
    private function withDefault($raw, string $default): string
    {
        $v = (string) ($raw ?? '');
        return $v !== '' ? $v : $default;
    }

    // ── Logo de la empresa ─────────────────────────────────────────────────

    /** Tope de upload (pre-resize): 4 MB. Suficiente para PNG transparentes 1024×1024. */
    private const LOGO_MAX_INPUT_BYTES = 4 * 1024 * 1024;
    /** Dimensión máxima del logo procesado (px en el lado más largo). El resize
     *  service genera variantes más chicas a demanda; subimos un master de 400×400. */
    private const LOGO_MAX_DIMENSION = 400;
    private const LOGO_JPEG_QUALITY = 90;

    /**
     * Sube el logo de la empresa a S3. Procesa con GD (resize a 400×400 máx,
     * convierte a JPEG q90 con fondo blanco para PNG transparentes) y persiste
     * el flag `hasLogo` + `logoUrl` (URL pública directa de S3) + `logoUploadedAt`
     * (cache-bust) en settingObj.
     *
     * Path S3: `companies/{companyId}/logo.jpg` — coherente con el pattern de
     * items (`items/{cid}/{iid}/{imgid}.jpg`) y compatible con S3_KEY_PREFIX.
     * NO usa el resize service legacy ni la convención `{cid}.jpg` raíz — para
     * que el POS legacy siga viendo su logo en `{cid}.jpg` haría falta dual
     * write, que se atenderá cuando el panel legacy migre.
     *
     * @return array{logo: string, hasLogo: true}
     * @throws \RuntimeException con mensaje legible si la validación o el upload fallan.
     */
    public function uploadLogo(string $companyId, array $file): array
    {
        $this->validateLogoUpload($file);

        $data = file_get_contents($file['tmp_name']);
        if ($data === false) throw new \RuntimeException('No se pudo leer el archivo');
        $src = @imagecreatefromstring($data);
        if ($src === false) throw new \RuntimeException('Archivo no es una imagen válida');

        [$origW, $origH] = [imagesx($src), imagesy($src)];
        [$newW, $newH]   = $this->scaleDown($origW, $origH, self::LOGO_MAX_DIMENSION);

        if ($newW !== $origW || $newH !== $origH || $this->mimeOf($file) !== 'image/jpeg') {
            $dst = imagecreatetruecolor($newW, $newH);
            // Fondo blanco para preservar areas transparentes al convertir a JPEG.
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        imagejpeg($src, null, self::LOGO_JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);

        // Subir a S3 — S3Client::put retorna la URL pública directa (path-style)
        // ya con el S3_KEY_PREFIX aplicado. Esa es la URL que persistimos y
        // devolvemos al front.
        $objectKey = "companies/$companyId/logo.jpg";
        $publicUrl = $this->s3()->put($objectKey, $jpeg, 'image/jpeg', true);

        $stamp = (int) time();
        if (!$this->persistLogoFlag($companyId, true, $stamp, $publicUrl)) {
            throw new \RuntimeException('No se pudo guardar el logo en la BD');
        }

        return [
            'logo'    => $publicUrl . '?v=' . $stamp,
            'hasLogo' => true,
        ];
    }

    /**
     * Borra el logo de S3 (best-effort) y limpia los flags en settingObj.
     * Si S3 falla (red, archivo ya no existe) seguimos con la limpieza local —
     * lo importante es que el front deje de mostrar el logo.
     *
     * @return array{hasLogo: false}
     */
    public function deleteLogo(string $companyId): array
    {
        try {
            $this->s3()->delete("companies/$companyId/logo.jpg");
        } catch (\Throwable $e) {
            // swallow: el flag en BD es la fuente de verdad para el front
        }
        $this->persistLogoFlag($companyId, false, null, null);
        return ['hasLogo' => false];
    }

    private function validateLogoUpload(array $file): void
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Archivo no recibido');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::LOGO_MAX_INPUT_BYTES) {
            throw new \RuntimeException('Archivo vacío o > 4 MB');
        }
        $mime = $this->mimeOf($file);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new \RuntimeException('Formato no soportado (JPG/PNG/WEBP/GIF)');
        }
    }

    private function mimeOf(array $file): string
    {
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) throw new \RuntimeException('No es una imagen válida');
        return (string) ($info['mime'] ?? '');
    }

    /** @return array{0:int,1:int} */
    private function scaleDown(int $w, int $h, int $max): array
    {
        if ($w <= $max && $h <= $max) return [$w, $h];
        $ratio = $w / $h;
        if ($w >= $h) return [$max, (int) round($max / $ratio)];
        return [(int) round($max * $ratio), $max];
    }

    private function s3(): \Punto\Api\Storage\S3Client
    {
        return new \Punto\Api\Storage\S3Client(
            defined('S3_ENDPOINT')   ? S3_ENDPOINT   : '',
            defined('S3_REGION')     ? S3_REGION     : 'us-east-1',
            defined('S3_BUCKET')     ? S3_BUCKET     : '',
            defined('S3_KEY')        ? S3_KEY        : '',
            defined('S3_SECRET')     ? S3_SECRET     : '',
            defined('S3_KEY_PREFIX') ? S3_KEY_PREFIX : ''
        );
    }

    /**
     * Actualiza `hasLogo` + `logoUrl` + `logoUploadedAt` dentro de settingObj
     * — leemos el blob actual primero para no clobbear `currencies` ni los
     * demás flags. Devuelve true si el UPDATE se ejecutó OK (para que el
     * caller pueda fallar visible si la persistencia rompió).
     */
    private function persistLogoFlag(string $companyId, bool $hasLogo, ?int $stamp, ?string $url): bool
    {
        $obj = $this->readSettingObj($companyId);
        if ($obj === null) return false; // company no existe
        if ($hasLogo) {
            $obj['hasLogo']        = true;
            $obj['logoUploadedAt'] = $stamp;
            $obj['logoUrl']        = $url;
        } else {
            unset($obj['hasLogo'], $obj['logoUploadedAt'], $obj['logoUrl']);
        }
        $res = ncmUpdate([
            'records'     => ['settingObj' => json_encode($obj)],
            'table'       => 'company',
            'where'       => 'companyId = ?',
            'whereParams' => [$companyId],
        ]);
        return is_array($res) && empty($res['error']);
    }
}

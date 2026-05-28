<?php
/**
 * Dominio de Ajustes (Settings) — capa API, motor ERP.
 *
 *   GET  general   → ajustes de la empresa (perfil + parámetros), CRUDOS
 *   GET  options   → listas para los selects (países, categorías, timezones, idiomas, separadores)
 *   GET  taxonomies→ items de taxonomía para los dropdowns adm (tax/category/tag/payment/bank)
 *   POST general   → guarda los ajustes en company.config JSONB
 *
 * FIX PG CRÍTICO: el legacy `a_settings.php?action=update&type=setting` hace
 * `AutoExecute('setting', …)` pero la tabla `setting` fue ELIMINADA en Phase PG (todo vive en
 * `company.config` JSONB) → el guardado de ajustes está ROTO en PG. Acá se rutea a `company.config`
 * vía `ncmUpdate` (que enruta claves desconocidas al JSONB con merge `||` no-destructivo).
 *
 * Los flags "extra" (ignoreInternal/stockCountBlind/blockUsedDocNo/autoSendDocs/taxPy/
 * weightBarcodes/deletedItemsHistory) + las monedas viven en `config.settingObj` (un JSON
 * anidado). El write hace MERGE no-destructivo de settingObj (preserva currencies y claves
 * desconocidas) — a diferencia del legacy que hacía `settingObj = json_encode($_POST)` (clobber).
 *
 * Tenant: companyId bound en cada query. Datos CRUDOS (el front formatea). Ver REGLA RAÍZ 2.
 */
class SettingsService
{
    /** Ajustes de la empresa (perfil + parámetros) para el form. */
    public function general($companyId)
    {
        $r = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [$companyId]);
        if (!$r) { return null; }

        $social = json_decode((string) ($r['settingSocialMedia'] ?? ''), true);
        if (!is_array($social)) { $social = []; }
        $obj = json_decode((string) ($r['settingObj'] ?? ''), true);
        if (!is_array($obj)) { $obj = []; }

        // Logo: URL del endpoint de resize (igual patrón que companyLogo()); el front cae a
        // images/add.png con @error si la empresa aún no subió logo. uploadUrl = destino del POST
        // multipart a upload.php (que guarda en SYSIMGS_FOLDER/{companyId}.jpg). enc() = identity.
        $assets = defined('ASSETS_URL') ? rtrim((string) ASSETS_URL, '/') : '/assets';

        return [
            // Perfil
            'logo'            => $assets . '/150-150/0/' . $companyId . '.jpg',
            'uploadUrl'       => 'upload.php?id=' . $companyId,
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
            'phone'           => (string) ($r['settingPhone'] ?? ''),
            'city'            => (string) ($r['settingCity'] ?? ''),
            'country'         => (string) ($r['settingCountry'] ?? ''),
            'language'        => (string) ($r['settingLanguage'] ?? 'es'),
            'timeZone'        => (string) ($r['settingTimeZone'] ?? ''),
            // Parámetros (app)
            'currency'        => (string) ($r['settingCurrency'] ?? ''),
            'thousandSeparator' => (string) ($r['settingThousandSeparator'] ?? 'dot'),
            'taxName'         => (string) ($r['settingTaxName'] ?? 'IVA'),
            'tin'             => (string) ($r['settingTIN'] ?? ''),
            'itemsSaleLimit'  => (string) ($r['settingItemsSaleLimit'] ?? ''),
            // Toggles (settingX)
            'decimal'         => ((string) ($r['settingDecimal'] ?? '') === 'yes'),
            'sellsoldout'     => ((string) ($r['settingSellSoldOut'] ?? '') === 'yes'),
            'itemSerialized'  => $this->truthy($r['settingItemSerialized'] ?? null),
            'drawerEmail'     => $this->truthy($r['settingDrawerEmail'] ?? null),
            'drawerBlind'     => $this->truthy($r['settingDrawerBlind'] ?? null),
            'settingRemoveTaxes' => $this->truthy($r['settingRemoveTaxes'] ?? null),
            'paymentId'       => $this->truthy($r['settingPaymentMethodId'] ?? null),
            'creditLine'      => $this->truthy($r['settingForceCreditLine'] ?? null),
            'storeCredit'     => $this->truthy($r['settingStoreCredit'] ?? null),
            // Toggles (settingObj / _fullSettings)
            'ignoreInternal'      => $this->truthy($obj['ignoreInternal'] ?? null),
            'stockCountBlind'     => $this->truthy($obj['stockCountBlind'] ?? null),
            'blockUsedDocNo'      => $this->truthy($obj['blockUsedDocNo'] ?? null),
            'autoSendDocs'        => $this->truthy($obj['autoSendDocs'] ?? null),
            'taxPy'               => $this->truthy($obj['taxPy'] ?? null),
            'weightBarcodes'      => $this->truthy($obj['weightBarcodes'] ?? null),
            'deletedItemsHistory' => $this->truthy($obj['deletedItemsHistory'] ?? null),
        ];
    }

    /**
     * Guarda los ajustes en company.config. SCOPEADO por companyId.
     * @param array $f campos validados (booleans ya como bool, strings limpios). @return bool
     */
    public function updateGeneral($companyId, array $f)
    {
        // settingObj: leer el blob anidado actual y MERGEAR (preserva currencies + claves desconocidas).
        // Si la lectura FALLA (null, no [] vacío legítimo), abortar: escribir un settingObj con solo
        // los 7 flags borraría currencies. [] vacío (company nueva) sí procede.
        $obj = $this->readSettingObj($companyId);
        if ($obj === null) {
            return false;
        }
        foreach (['ignoreInternal', 'stockCountBlind', 'blockUsedDocNo', 'autoSendDocs', 'taxPy', 'weightBarcodes', 'deletedItemsHistory'] as $k) {
            $obj[$k] = !empty($f[$k]) ? 1 : 0;
        }

        $record = [
            'settingAddress'           => $f['address'],
            'settingWebSite'           => $f['website'],
            'settingEmail'             => $f['email'],
            'settingRUC'               => $f['ruc'],
            'settingPhone'             => $f['phone'],
            'settingCity'              => $f['city'],
            'settingCountry'           => $f['country'],
            'settingLanguage'          => $f['language'] !== '' ? $f['language'] : 'es',
            'settingTimeZone'          => $f['timeZone'],
            'settingCurrency'          => $f['currency'],
            'settingTaxName'           => $f['taxName'],
            'settingBillingName'       => $f['billingName'],
            'settingTIN'               => $f['tin'],
            'settingBillDetail'        => $f['billDetail'],
            'settingCompanyCategoryId' => $f['category'],
            'settingThousandSeparator' => $f['thousandSeparator'],
            'settingItemsSaleLimit'    => $f['itemsSaleLimit'],
            'settingDecimal'           => !empty($f['decimal']) ? 'yes' : 'no',
            'settingSellSoldOut'       => !empty($f['sellsoldout']) ? 'yes' : 'no',
            'settingItemSerialized'    => !empty($f['itemSerialized']) ? 1 : 0,
            'settingDrawerEmail'       => !empty($f['drawerEmail']) ? 1 : 0,
            'settingDrawerBlind'       => !empty($f['drawerBlind']) ? 1 : 0,
            'settingRemoveTaxes'       => !empty($f['settingRemoveTaxes']) ? 1 : 0,
            'settingPaymentMethodId'   => !empty($f['paymentId']) ? 1 : 0,
            'settingForceCreditLine'   => !empty($f['creditLine']) ? 1 : 0,
            'settingStoreCredit'       => !empty($f['storeCredit']) ? 1 : 0,
            'settingSocialMedia'       => json_encode([
                'facebook'  => $f['social']['facebook'] ?? '',
                'instagram' => $f['social']['instagram'] ?? '',
                'youtube'   => $f['social']['youtube'] ?? '',
                'twitter'   => $f['social']['twitter'] ?? '',
            ]),
            'settingObj'               => json_encode($obj),
        ];

        $res = ncmUpdate([
            'records'     => $record,
            'table'       => 'company',
            'where'       => 'companyId = ?',
            'whereParams' => [$companyId],
        ]);
        return is_array($res) && ($res['error'] === false);
    }

    /**
     * Lee el blob settingObj (JSON anidado dentro de config) crudo.
     * @return array|null  array con el contenido (o [] si la fila no tiene settingObj);
     *                     NULL si la lectura falló (no hay fila) → el caller debe abortar el write.
     */
    private function readSettingObj($companyId)
    {
        $r = ncmExecute("SELECT config FROM company WHERE companyId = ? LIMIT 1", [$companyId], false, true);
        if (!$r || !is_object($r) || $r->EOF) {
            return null;   // lectura fallida → no arriesgar clobber de currencies
        }
        $cfg = $r->fields['config'] ?? null;
        $cfg = is_array($cfg) ? $cfg : json_decode((string) $cfg, true);
        $r->Close();
        if (is_array($cfg) && !empty($cfg['settingObj'])) {
            $obj = is_array($cfg['settingObj']) ? $cfg['settingObj'] : json_decode((string) $cfg['settingObj'], true);
            if (is_array($obj)) { return $obj; }
        }
        return [];
    }

    /** Listas para los selects del form. */
    public function options()
    {
        $countries = [];
        $cFile = __DIR__ . '/../../libraries/countries.php';
        if (is_file($cFile)) {
            require $cFile;   // countries.php asigna $countries (no return) en este scope
        }
        $countryOpts = [];
        foreach ((is_array($countries) ? $countries : []) as $code => $v) {
            $countryOpts[] = ['code' => (string) $code, 'name' => (string) ($v['name'] ?? $code)];
        }

        $catFile = __DIR__ . '/../../libraries/company_categories.php';
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
        $cFile     = __DIR__ . '/../../libraries/countries_hispanic.json';
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

        $rows = [];
        foreach ($countries as $ccode => $v) {
            $code = $v['currency']['code'] ?? null;
            if ($code === null || $code === '') { continue; }
            $rows[] = ['ccode' => (string) $ccode, 'code' => (string) $code, 'value' => $saved[(string) $code] ?? 0];
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
        foreach ($list as $val) {
            if (!is_array($val)) { continue; }
            $amount   = (float) ($val['value'] ?? 0);
            $currency = preg_replace('/[^a-z]/i', '', (string) ($val['code'] ?? ''));
            if ($currency === '' || strlen($currency) > 3) { continue; }
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
}

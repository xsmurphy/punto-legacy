<?php
declare(strict_types=1);

namespace Punto\Api\Settings;

use DateTimeZone;

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
 * Los flags "extra" (ignoreInternal/stockCountBlind/blockUsedDocNo/autoSendDocs/taxPy/
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
    /** Ajustes de la empresa (perfil + parámetros) para el form. */
    public function general($companyId)
    {
        $r = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [$companyId]);
        if (!$r) { return null; }

        $social = json_decode((string) ($r['settingSocialMedia'] ?? ''), true);
        if (!is_array($social)) { $social = []; }
        $obj = json_decode((string) ($r['settingObj'] ?? ''), true);
        if (!is_array($obj)) { $obj = []; }

        // Logo: el flag `hasLogo` + `logoUploadedAt` (cache-bust) se persisten en
        // settingObj cuando el panel-next sube/borra via uploadLogo()/deleteLogo().
        // Si no hay logo → devolvemos null (el front decide qué mostrar). Si hay,
        // construimos la URL del endpoint de resize con la convención legacy
        // (raíz del bucket, `{companyId}.jpg`) — compat con el helper companyLogo()
        // del panel legacy y con el POS, que apuntan al mismo path.
        $hasLogo  = !empty($obj['hasLogo']);
        $logoStmp = isset($obj['logoUploadedAt']) ? (int) $obj['logoUploadedAt'] : null;

        return [
            // Perfil
            'logo'            => $hasLogo ? $this->buildLogoUrl($companyId, $logoStmp) : null,
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
            'tinName' => (string) ($r['settingTIN'] ?? 'TIN'),
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
     * el flag `hasLogo` + `logoUploadedAt` en settingObj para cache-bust.
     *
     * Convención de path en S3: `{companyId}.jpg` en raíz del bucket — la misma
     * que usa el helper legacy `companyLogo()` de panel/includes/functions.php,
     * para que el POS y el panel legacy sigan viendo el mismo archivo.
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

        // Subir a S3 — la S3_KEY_PREFIX se aplica desde S3Client; el objectKey
        // lógico es solo `{companyId}.jpg`.
        $this->s3()->put($companyId . '.jpg', $jpeg, 'image/jpeg', true);

        $stamp = (int) time();
        $this->persistLogoFlag($companyId, true, $stamp);

        return [
            'logo'    => $this->buildLogoUrl($companyId, $stamp),
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
            $this->s3()->delete($companyId . '.jpg');
        } catch (\Throwable $e) {
            // swallow: el flag en BD es la fuente de verdad para el front
        }
        $this->persistLogoFlag($companyId, false, null);
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

    private function buildLogoUrl(string $companyId, ?int $stamp): string
    {
        $assets = defined('ASSETS_URL') ? rtrim((string) ASSETS_URL, '/') : '/assets';
        $bust   = $stamp ? '?v=' . $stamp : '';
        return $assets . '/150-150/0/' . $companyId . '.jpg' . $bust;
    }

    /**
     * Actualiza `hasLogo` + `logoUploadedAt` dentro de settingObj — leemos el
     * blob actual primero para no clobbear `currencies` ni los demás flags.
     */
    private function persistLogoFlag(string $companyId, bool $hasLogo, ?int $stamp): void
    {
        $obj = $this->readSettingObj($companyId);
        if ($obj === null) return; // company no existe — el caller no debería haber llegado acá
        if ($hasLogo) {
            $obj['hasLogo']        = true;
            $obj['logoUploadedAt'] = $stamp;
        } else {
            unset($obj['hasLogo'], $obj['logoUploadedAt']);
        }
        ncmUpdate([
            'records'     => ['settingObj' => json_encode($obj)],
            'table'       => 'company',
            'where'       => 'companyId = ?',
            'whereParams' => [$companyId],
        ]);
    }
}

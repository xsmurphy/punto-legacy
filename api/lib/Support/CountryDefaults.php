<?php
declare(strict_types=1);

namespace Punto\Api\Support;

/**
 * Defaults derivados del PAÍS (ISO-3166 alpha-2), sin tocar la base.
 *
 * Existe porque el sistema venía asumiendo Paraguay en cada lugar donde
 * faltaba un dato de localización: TZ 'America/Asuncion', moneda 'PYG',
 * país 'PY' para validar teléfonos. Punto se vende fuera de Paraguay, así
 * que ese supuesto produce datos MAL sin fallar ruidosamente (un tenant
 * argentino nacía con la TZ de Asunción y nadie se enteraba).
 *
 * La regla es: el default sale del país elegido por el comercio, nunca de
 * un país cableado en el código. Este resolver es la única fuente de esa
 * derivación — si mañana hace falta otro dato por país (formato de fecha,
 * primer día de semana), se agrega acá y no en 20 call-sites.
 *
 * Es PURO: no lee la base ni sabe qué tenant está operando. La parte
 * tenant-aware (¿qué país eligió ESTE comercio?) vive en TenantLocale.
 *
 * Dos catálogos, en este orden:
 *   1. `lib/Settings/resources/countries_hispanic.json` — 23 países de LATAM,
 *      CURADO a mano: trae `timezone` elegida (la de la capital comercial) y
 *      `currency.code`. Gana siempre que el país esté ahí.
 *   2. `libraries/countries.php` — 273 países, SIN `timezone` (por eso el
 *      signup caía al fallback paraguayo para todos). Aporta `currency.code`
 *      para los países fuera de LATAM.
 * Para la TZ de un país fuera del catálogo curado usamos la base IANA que
 * PHP ya trae (`DateTimeZone::listIdentifiers(PER_COUNTRY, $iso)`), que
 * cubre los 249 códigos ISO sin mantener una tabla nuestra.
 */
final class CountryDefaults
{
    /**
     * Cómo se llaman los DOS documentos del cliente en cada país donde Punto
     * opera: el fiscal ('tax') y el personal ('personal').
     *
     * Espejo EXACTO de `COUNTRY_LOCALE[iso].tinName` / `.personalIdName`
     * (frontend/lib/tenant-locale.ts). Si agregás un país allá, agregalo acá:
     * el front rotula el formulario y este lado siembra el ajuste del tenant
     * en el alta, así que una divergencia se ve como un campo que cambia de
     * nombre según por dónde se lo mire.
     *
     * OJO — CL repite 'RUT' a propósito: el número de la cédula chilena (RUN)
     * y el tributario (RUT) son EL MISMO, y en el comercio se pide "RUT" para
     * los dos. Poner 'RUN' inventaría una distinción que un chileno no hace.
     *
     * Lo que NO va acá: códigos numéricos de tipo de documento. Los de la
     * Tabla 3 de la SET son de un fisco concreto y viven en
     * `ContactService::ID_TYPE_*`; esta tabla es presentación.
     */
    private const ID_LABELS = [
        'PY' => ['tax' => 'RUC',  'personal' => 'Cédula de identidad'],
        'AR' => ['tax' => 'CUIT', 'personal' => 'DNI'],
        'UY' => ['tax' => 'RUT',  'personal' => 'Cédula de identidad'],
        'BR' => ['tax' => 'CNPJ', 'personal' => 'CPF'],
        'CL' => ['tax' => 'RUT',  'personal' => 'RUT'],
        'BO' => ['tax' => 'NIT',  'personal' => 'Cédula de identidad'],
        'PE' => ['tax' => 'RUC',  'personal' => 'DNI'],
        'CO' => ['tax' => 'NIT',  'personal' => 'Cédula de ciudadanía'],
        'EC' => ['tax' => 'RUC',  'personal' => 'Cédula de identidad'],
        'VE' => ['tax' => 'RIF',  'personal' => 'Cédula de identidad'],
        'MX' => ['tax' => 'RFC',  'personal' => 'CURP'],
        'ES' => ['tax' => 'NIF',  'personal' => 'DNI'],
        'US' => ['tax' => 'EIN',  'personal' => 'SSN'],
    ];

    /** @var array<string,mixed>|null Catálogo curado LATAM (con `timezone`). */
    private static ?array $curated = null;

    /** @var array<string,mixed>|null Catálogo ancho (273 países, sin `timezone`). */
    private static ?array $wide = null;

    /**
     * Zona horaria IANA del país, o null si el código no se puede resolver.
     *
     * Devuelve null en vez de adivinar: el caller decide si eso es un error
     * (alta de tenant → abortar) o si cae a la TZ de la plataforma (lectura).
     */
    public static function timezone(?string $iso): ?string
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        // 1. Catálogo curado: para los países donde operamos, la TZ está
        //    elegida a mano (importa cuál, ver el caso de Argentina abajo).
        $curated = self::curated()[$iso]['timezone'] ?? null;
        if (is_string($curated) && self::isValidTimezone($curated)) {
            return $curated;
        }

        // 2. Base IANA de PHP. Para países con una sola zona (PY, UY, PE)
        //    la respuesta es exacta. Para países con varias (AR, BR, US, MX)
        //    es la primera del listado, que es una aproximación honesta:
        //    el comercio puede corregirla en Ajustes, y el dato queda
        //    guardado en `settingTimeZone`. Sigue siendo infinitamente mejor
        //    que asumir Asunción.
        $ianaList = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $iso);
        if (is_array($ianaList) && isset($ianaList[0]) && is_string($ianaList[0])) {
            return $ianaList[0];
        }

        return null;
    }

    /**
     * Código ISO-4217 de la moneda del país (ej. 'PYG', 'ARS'), o null.
     *
     * OJO: NO es lo mismo que `company.config->>'settingCurrency'`, que
     * guarda el SÍMBOLO para mostrar ('Gs', '$'), no el código. Cuando hace
     * falta un código de moneda (facturación electrónica, pasarelas), este
     * es el lugar de donde sale.
     */
    public static function currencyCode(?string $iso): ?string
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        foreach ([self::curated(), self::wide()] as $catalog) {
            $code = $catalog[$iso]['currency']['code'] ?? null;
            if (is_string($code) && trim($code) !== '') {
                return strtoupper(trim($code));
            }
        }

        return null;
    }

    /** Símbolo de la moneda del país (ej. 'Gs', 'AR$'), o null. */
    public static function currencySymbol(?string $iso): ?string
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        foreach ([self::curated(), self::wide()] as $catalog) {
            $symbol = $catalog[$iso]['currency']['symbol'] ?? null;
            if (is_string($symbol) && trim($symbol) !== '') {
                return trim($symbol);
            }
        }

        return null;
    }

    /**
     * Cómo se llama el documento FISCAL del cliente en ese país: 'RUC' en
     * PY/PE/EC, 'CUIT' en AR, 'RUT' en UY/CL, 'CNPJ' en BR, 'NIT' en BO/CO,
     * 'RFC' en MX. null si el código no se puede resolver.
     *
     * El dato ya estaba en los dos catálogos (campo `tin`), pero se leía
     * suelto: el alta de tenant hacía `$countries[$post['country']]['tin']`
     * a mano, que revienta con un país fuera del catálogo. Acá se resuelve
     * una vez, con el mismo orden de precedencia que el resto de la clase.
     *
     * PRECEDENCIA: primero la tabla de países donde Punto opera
     * (self::ID_LABELS), después los catálogos JSON. Ese orden importa porque
     * el JSON conflaciona los dos documentos en una sola etiqueta —trae
     * 'CPF/CNPJ' para BR, 'NIF/CIF' para ES, 'SSN/TIN' para US—, que era
     * razonable cuando había UN solo campo de documento y dejó de serlo
     * ahora: el formulario tiene el campo fiscal y el personal por separado,
     * así que rotular el fiscal "CPF/CNPJ" al lado de uno que dice "CPF" no
     * describe nada. Para el fisco de una empresa brasileña la etiqueta es
     * CNPJ, y punto. El JSON sigue cubriendo los otros ~260 países.
     */
    public static function taxIdLabel(?string $iso): ?string
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        $own = self::ID_LABELS[$iso]['tax'] ?? null;
        if (is_string($own) && $own !== '') {
            return $own;
        }

        foreach ([self::curated(), self::wide()] as $catalog) {
            $label = $catalog[$iso]['tin'] ?? null;
            if (is_string($label) && trim($label) !== '') {
                return trim($label);
            }
        }

        return null;
    }

    /**
     * Cómo se llama el documento PERSONAL del cliente — el que NO es el
     * fiscal. Pedido del owner (2026-08-31): «en Argentina no se usa tanto
     * cédula de identidad, se usa DNI».
     *
     * Sale de self::ID_LABELS y no de los catálogos JSON porque ninguno de
     * los dos lo trae: `libraries/countries.php` y `countries_hispanic.json`
     * tienen `tin` y nada más sobre documentos. Agregar la columna a 273
     * países sería inventar 250 valores que nadie va a verificar; la tabla
     * cubre los países donde Punto opera y devuelve null para el resto, que
     * es la respuesta honesta (el caller cae a su etiqueta genérica).
     */
    public static function personalIdLabel(?string $iso): ?string
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        return self::ID_LABELS[$iso]['personal'] ?? null;
    }

    /**
     * Padrón público de contribuyentes del país, o null si no conocemos uno.
     *
     * Un padrón es un servicio POR PAÍS: el de Paraguay no sabe nada de un RUC
     * chileno. Por eso la URL se deriva del país del comercio y no de una
     * constante global — cableada en `simple.config.php`, se consultaba el
     * padrón paraguayo para cualquier tenant, mandándole el identificador
     * tributario de un cliente extranjero a un servicio de otro país.
     *
     * Vive acá y no en env para que el caso que YA funciona (Paraguay) siga
     * funcionando sin que nadie tenga que configurar nada: sacarlo a una
     * variable de entorno sin default mataba la búsqueda de RUC de los
     * comercios paraguayos apenas deployara. `TAXPAYER_LOOKUP_URL` sigue
     * existiendo como override de despliegue (ver TaxpayerLookupService).
     *
     * Sumar un país es agregar una fila acá, no tocar ningún call-site.
     */
    public static function taxpayerRegistryUrl(?string $iso): ?string
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        // Padrones públicos conocidos, por país.
        $registries = [
            // Paraguay — consulta de contribuyentes de la SET. Espera el
            // documento SIN dígito verificador y devuelve el RUC completo.
            'PY' => 'https://turuc.com.py/api/contribuyente',
        ];

        return $registries[$iso] ?? null;
    }

    /** true si el string es un identificador IANA que PHP reconoce. */
    public static function isValidTimezone(?string $tz): bool
    {
        $tz = trim((string) $tz);
        return $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
    }

    /** 'py' / ' PY ' → 'PY'. Cualquier cosa que no sean 2 letras → null. */
    private static function normalizeIso(?string $iso): ?string
    {
        $iso = strtoupper(trim((string) $iso));
        return preg_match('/^[A-Z]{2}$/', $iso) === 1 ? $iso : null;
    }

    /** @return array<string,mixed> */
    private static function curated(): array
    {
        if (self::$curated !== null) {
            return self::$curated;
        }

        $file = dirname(__DIR__, 2) . '/lib/Settings/resources/countries_hispanic.json';
        $json = is_file($file) ? file_get_contents($file) : false;
        $data = $json !== false ? json_decode($json, true) : null;

        return self::$curated = is_array($data) ? $data : [];
    }

    /** @return array<string,mixed> */
    private static function wide(): array
    {
        if (self::$wide !== null) {
            return self::$wide;
        }

        // `libraries/countries.php` es un `$countries = json_decode(...)` sin
        // `global`, así que incluirlo DENTRO de esta función deja la variable
        // en scope local: no pisa el `$countries` global que head.php ya cargó
        // para el código legacy. El static de arriba evita re-parsear el JSON.
        $countries = null;
        $file = dirname(__DIR__, 2) . '/libraries/countries.php';
        if (is_file($file)) {
            include $file;
        }

        return self::$wide = is_array($countries) ? $countries : [];
    }
}

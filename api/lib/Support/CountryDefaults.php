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
     * Localización de los países donde Punto opera, en UNA fila por país.
     *
     * Espejo de `COUNTRY_LOCALE` (frontend/lib/tenant-locale.ts): para los 13
     * países que esa tabla declara, los valores son los mismos, fila por fila
     * y columna por columna. Si agregás un país allá, agregalo
     * acá: el front rotula el formulario y este lado siembra los ajustes del
     * tenant en el alta, así que una divergencia se ve como un campo que
     * cambia de nombre —o una moneda que cambia de símbolo— según por dónde
     * se lo mire.
     *
     * Las 7 filas de Centroamérica y el Caribe (CR, DO, GT, HN, NI, PA, SV)
     * NO están en `tenant-locale.ts`: se agregan acá porque el alta acepta
     * cualquier país del catálogo ancho y esos siete son mercados de LATAM.
     * Mientras el front no las declare, un tenant de esos países ve el valor
     * correcto sembrado en el alta y el default genérico si vuelve a elegir
     * país en Ajustes — molesto, pero no destructivo.
     *
     * POR QUÉ EXISTE — el alta leía estos cinco datos del catálogo ANCHO
     * (`libraries/countries.php`, 273 países generados, sin curar), y ahí:
     * Panamá trae el símbolo del baht tailandés (`฿`), Guatemala y Honduras
     * traen el CÓDIGO donde va el símbolo, Brasil dice que su impuesto es el
     * "IPI" (es el ICMS), Nicaragua no trae documento fiscal y Chile trae dos
     * decimales que el peso chileno no usa. Ninguno de esos errores falla
     * ruidosamente: se escriben una vez en el alta y quedan para siempre.
     *
     * ALCANCE — dos columnas de esta tabla NO se leen solo en el alta y por lo
     * tanto cambian de valor para tenants que YA existen:
     *   `code`   — `TenantLocale::currencyCode()` lo deriva del país en cada
     *              llamada, sin valor congelado por tenant. Venezuela pasa de
     *              'VEF' a 'VES': los dos catálogos traen el código anterior a
     *              la redenominación de 2018, que ninguna pasarela ni fisco
     *              acepta hoy. Es la corrección, no el efecto colateral.
     *   `symbol` — `TenantLocale::currencySymbol()` prefiere el
     *              `settingCurrency` que el comercio tenga guardado y solo cae
     *              acá si está vacío. Colombia pasa de 'CO$' a '$', que es lo
     *              que el front ya venía mostrando (`tenant-locale.ts`): lo que
     *              cambia es que ahora los dos lados dicen lo mismo.
     *
     * Columnas:
     *   symbol    — lo que se IMPRIME al lado del monto (no el código ISO).
     *   code      — ISO-4217, para facturación electrónica y pasarelas.
     *   decimals  — cuántos decimales USA el comercio, no los que la ISO
     *               declara: CLP y PYG son 0 por definición, y COP/CRC son 0
     *               por uso real (nadie cotiza centavos de peso colombiano).
     *   thousand  — separador de MILES en notación de símbolo ('.' o ',').
     *   taxName   — cómo se llama el impuesto al consumo del país.
     *   taxId     — documento FISCAL del cliente.
     *   personalId— documento PERSONAL del cliente (el que no es el fiscal).
     *
     * OJO — CL repite 'RUT' a propósito: el número de la cédula chilena (RUN)
     * y el tributario (RUT) son EL MISMO, y en el comercio se pide "RUT" para
     * los dos. Poner 'RUN' inventaría una distinción que un chileno no hace.
     *
     * Lo que NO va acá: códigos numéricos de tipo de documento. Los de la
     * Tabla 3 de la SET son de un fisco concreto y viven en
     * `ContactService::ID_TYPE_*`; esta tabla es presentación.
     */
    private const LOCALE = [
        // ── Cono Sur / Andes — espejo de tenant-locale.ts ──────────────────
        'PY' => ['symbol' => 'Gs',  'code' => 'PYG', 'decimals' => 0, 'thousand' => '.', 'taxName' => 'IVA',        'taxId' => 'RUC',  'personalId' => 'Cédula de identidad'],
        'AR' => ['symbol' => '$',   'code' => 'ARS', 'decimals' => 2, 'thousand' => '.', 'taxName' => 'IVA',        'taxId' => 'CUIT', 'personalId' => 'DNI'],
        'UY' => ['symbol' => '$',   'code' => 'UYU', 'decimals' => 2, 'thousand' => '.', 'taxName' => 'IVA',        'taxId' => 'RUT',  'personalId' => 'Cédula de identidad'],
        'BR' => ['symbol' => 'R$',  'code' => 'BRL', 'decimals' => 2, 'thousand' => '.', 'taxName' => 'ICMS',       'taxId' => 'CNPJ', 'personalId' => 'CPF'],
        'CL' => ['symbol' => '$',   'code' => 'CLP', 'decimals' => 0, 'thousand' => '.', 'taxName' => 'IVA',        'taxId' => 'RUT',  'personalId' => 'RUT'],
        'BO' => ['symbol' => 'Bs',  'code' => 'BOB', 'decimals' => 2, 'thousand' => '.', 'taxName' => 'IVA',        'taxId' => 'NIT',  'personalId' => 'Cédula de identidad'],
        'PE' => ['symbol' => 'S/',  'code' => 'PEN', 'decimals' => 2, 'thousand' => '.', 'taxName' => 'IGV',        'taxId' => 'RUC',  'personalId' => 'DNI'],
        'CO' => ['symbol' => '$',   'code' => 'COP', 'decimals' => 0, 'thousand' => '.', 'taxName' => 'IVA',        'taxId' => 'NIT',  'personalId' => 'Cédula de ciudadanía'],
        'EC' => ['symbol' => '$',   'code' => 'USD', 'decimals' => 2, 'thousand' => ',', 'taxName' => 'IVA',        'taxId' => 'RUC',  'personalId' => 'Cédula de identidad'],
        'VE' => ['symbol' => 'Bs',  'code' => 'VES', 'decimals' => 2, 'thousand' => '.', 'taxName' => 'IVA',        'taxId' => 'RIF',  'personalId' => 'Cédula de identidad'],
        'MX' => ['symbol' => '$',   'code' => 'MXN', 'decimals' => 2, 'thousand' => ',', 'taxName' => 'IVA',        'taxId' => 'RFC',  'personalId' => 'CURP'],
        'ES' => ['symbol' => '€',   'code' => 'EUR', 'decimals' => 2, 'thousand' => '.', 'taxName' => 'IVA',        'taxId' => 'NIF',  'personalId' => 'DNI'],
        'US' => ['symbol' => '$',   'code' => 'USD', 'decimals' => 2, 'thousand' => ',', 'taxName' => 'Sales Tax',  'taxId' => 'EIN',  'personalId' => 'SSN'],

        // ── Centroamérica y Caribe — todavía NO están en tenant-locale.ts ──
        // CR usa punto para los miles (uso local); el resto de la región usa
        // coma, por influencia del formato estadounidense.
        'CR' => ['symbol' => '₡',   'code' => 'CRC', 'decimals' => 0, 'thousand' => '.', 'taxName' => 'IVA',   'taxId' => 'Cédula jurídica', 'personalId' => 'Cédula de identidad'],
        'DO' => ['symbol' => 'RD$', 'code' => 'DOP', 'decimals' => 2, 'thousand' => ',', 'taxName' => 'ITBIS', 'taxId' => 'RNC',             'personalId' => 'Cédula de identidad'],
        'GT' => ['symbol' => 'Q',   'code' => 'GTQ', 'decimals' => 2, 'thousand' => ',', 'taxName' => 'IVA',   'taxId' => 'NIT',             'personalId' => 'DPI'],
        'HN' => ['symbol' => 'L',   'code' => 'HNL', 'decimals' => 2, 'thousand' => ',', 'taxName' => 'ISV',   'taxId' => 'RTN',             'personalId' => 'Tarjeta de identidad'],
        'NI' => ['symbol' => 'C$',  'code' => 'NIO', 'decimals' => 2, 'thousand' => ',', 'taxName' => 'IVA',   'taxId' => 'RUC',             'personalId' => 'Cédula de identidad'],
        // Panamá: el balboa está atado 1:1 al dólar y circula como dólar; el
        // símbolo impreso es 'B/.'. El catálogo ancho trae '฿' (baht).
        'PA' => ['symbol' => 'B/.', 'code' => 'PAB', 'decimals' => 2, 'thousand' => ',', 'taxName' => 'ITBMS', 'taxId' => 'RUC',             'personalId' => 'Cédula de identidad'],
        'SV' => ['symbol' => '$',   'code' => 'USD', 'decimals' => 2, 'thousand' => ',', 'taxName' => 'IVA',   'taxId' => 'NIT',             'personalId' => 'DUI'],
    ];

    /**
     * Divisor aproximado para llevar un precio EN GUARANÍES a la banda de
     * cada moneda. Clave: código ISO-4217 (no país) — PA/SV/EC comparten el
     * dólar y comparten banda.
     *
     * ESTO NO ES UNA COTIZACIÓN. Es un orden de magnitud, congelado a mano,
     * para que los ítems DEMO del alta salgan en números creíbles: los
     * precios semilla de `InstallConfig` están escritos en guaraníes (12000
     * un plato) y sin convertir aterrizaban como "12000 dólares" o —con la
     * regla anterior, dividir por 100 cuando la moneda tenía decimales— como
     * "USD 120 el plato principal". Ninguna decisión de negocio, ningún
     * documento fiscal y ningún reporte leen esta tabla: solo el catálogo
     * demo que el comercio va a borrar en su primera semana. Por eso no se
     * consulta un FX real (una dependencia de red en medio del alta, que
     * además fallaría) ni hace falta mantenerla al día: si la inflación la
     * corre, el precio demo queda raro y nada más.
     */
    private const DEMO_PRICE_DIVISOR = [
        'PYG' => 1.0,
        'ARS' => 8.0,
        'BOB' => 1100.0,
        'BRL' => 1500.0,
        'CLP' => 8.0,
        'COP' => 1.8,
        'CRC' => 15.0,
        'DOP' => 130.0,
        'EUR' => 8000.0,
        'GTQ' => 1000.0,
        'HNL' => 300.0,
        'MXN' => 400.0,
        'NIO' => 200.0,
        'PAB' => 7500.0,
        'PEN' => 2000.0,
        'USD' => 7500.0,
        'UYU' => 190.0,
        // Venezuela: el bolívar se redenominó tres veces en quince años, así
        // que cualquier número acá envejece en meses. 210 lo deja en la banda
        // del dólar del momento en que se escribió; es lo mismo que decir
        // "más o menos como en dólares".
        'VES' => 210.0,
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

        $own = self::LOCALE[$iso]['code'] ?? null;
        if (is_string($own) && $own !== '') {
            return $own;
        }

        foreach ([self::curated(), self::wide()] as $catalog) {
            $code = $catalog[$iso]['currency']['code'] ?? null;
            if (is_string($code) && trim($code) !== '') {
                return strtoupper(trim($code));
            }
        }

        return null;
    }

    /**
     * Símbolo de la moneda del país (ej. 'Gs', 'R$'), o null.
     *
     * PRECEDENCIA self::LOCALE → catálogos, por la misma razón que
     * taxIdLabel(): el catálogo ancho tiene el símbolo MAL en varios de los
     * países donde vendemos (Panamá con el baht tailandés, Guatemala y
     * Honduras con el código ISO en lugar del símbolo). Ese valor se escribe
     * en `settingCurrency` durante el alta y es lo que el cajero ve impreso
     * al lado de cada monto.
     */
    public static function currencySymbol(?string $iso): ?string
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        $own = self::LOCALE[$iso]['symbol'] ?? null;
        if (is_string($own) && $own !== '') {
            return $own;
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
     * Cuántos decimales USA el comercio de ese país (0 o 2), o null si el
     * código no se puede resolver.
     *
     * No es el `decimal_digits` de la ISO-4217 y a veces no coincide: el peso
     * colombiano declara 2 y nadie cotiza centavos. Por eso los países donde
     * operamos salen de self::LOCALE y el resto del catálogo.
     */
    public static function decimalDigits(?string $iso): ?int
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        $own = self::LOCALE[$iso]['decimals'] ?? null;
        if (is_int($own)) {
            return $own;
        }

        foreach ([self::curated(), self::wide()] as $catalog) {
            $digits = $catalog[$iso]['currency']['decimal_digits'] ?? null;
            if (is_numeric($digits)) {
                return (int) $digits;
            }
        }

        return null;
    }

    /**
     * Separador de MILES del país, en notación de símbolo ('.' o ','), o null.
     *
     * El alta lo cableaba en 'dot' para todo el mundo: un comercio mexicano o
     * ecuatoriano nacía mostrando 1.234,50 donde su cliente espera 1,234.50.
     * NINGÚN catálogo trae este dato — es exclusivo de self::LOCALE, así que
     * fuera de los países declarados devuelve null y el caller decide.
     */
    public static function thousandSeparator(?string $iso): ?string
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        $own = self::LOCALE[$iso]['thousand'] ?? null;
        return in_array($own, ['.', ','], true) ? $own : null;
    }

    /**
     * Cómo se llama el impuesto al consumo del país ('IVA', 'IGV', 'ICMS',
     * 'ITBIS'...), o null.
     *
     * PRECEDENCIA self::LOCALE → catálogos: el ancho trae `vat_name` = 'IPI'
     * para Brasil (el IPI es el impuesto a los productos industrializados; lo
     * que un comercio brasileño cobra en el mostrador es el ICMS) y devuelve
     * el genérico 'VAT' para varios países hispanos.
     */
    public static function taxName(?string $iso): ?string
    {
        $iso = self::normalizeIso($iso);
        if ($iso === null) {
            return null;
        }

        $own = self::LOCALE[$iso]['taxName'] ?? null;
        if (is_string($own) && $own !== '') {
            return $own;
        }

        foreach ([self::curated(), self::wide()] as $catalog) {
            $name = $catalog[$iso]['currency']['vat_name'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }

    /**
     * Lleva un precio semilla EN GUARANÍES a la banda de la moneda del país,
     * redondeado a un número "de vidriera" (990, 1500, 12.90).
     *
     * Solo para los ítems DEMO del alta — ver el comentario de
     * self::DEMO_PRICE_DIVISOR sobre por qué esto no es ni pretende ser una
     * conversión de moneda. La regla anterior («dividir por 100 si la moneda
     * tiene decimales») no era una conversión de nada: producía un plato
     * principal de USD 120 y una laptop de USD 2.250.
     *
     * Paraguay no pasa por ningún redondeo (divisor 1.0): los precios de
     * InstallConfig YA están escritos en su banda y con números elegidos.
     */
    public static function demoPrice(float $priceInPyg, ?string $iso): float
    {
        $currency = self::currencyCode($iso);
        $decimals = self::decimalDigits($iso);
        $divisor  = $currency !== null ? (self::DEMO_PRICE_DIVISOR[$currency] ?? null) : null;

        if ($divisor === null) {
            // Moneda fuera de la tabla (~250 países que no son mercado
            // nuestro). Con decimales asumimos banda de moneda fuerte —el
            // dólar es la referencia más probable—; sin decimales, banda de
            // moneda de denominación alta, que es de donde vienen los montos
            // semilla. Es un supuesto declarado, no un dato.
            $divisor = ($decimals !== null && $decimals >= 2) ? self::DEMO_PRICE_DIVISOR['USD'] : 1.0;
        }

        if ($divisor <= 0.0) {
            $divisor = 1.0;
        }

        $converted = $priceInPyg / $divisor;
        if ($divisor === 1.0) {
            return round($converted, max(0, (int) $decimals));
        }

        return self::showcasePrice($converted, (int) ($decimals ?? 0));
    }

    /**
     * Redondeo "de vidriera": nadie pone 6.67 en una etiqueta, pone 6.90.
     *
     * Con decimales el precio termina en .90 salvo en la banda de un dígito,
     * donde el paso es de 0.10 para no colapsar dos ítems distintos en el
     * mismo número; sin decimales, se apoya en el múltiplo redondo que
     * corresponde a la magnitud. El paso crece con el monto para que un precio de seis cifras
     * no quede con precisión de dos.
     */
    private static function showcasePrice(float $value, int $decimals): float
    {
        if ($value <= 0.0) {
            return 0.0;
        }

        if ($decimals >= 2) {
            if ($value < 10.0) {
                // Paso de 0.10 —y no de 0.50— porque en esta banda entran
                // varios ítems del mismo grupo demo: con medio punto de paso,
                // el plato principal y los tragos aterrizaban en el mismo
                // precio. El .00 exacto se baja a .90 (nadie etiqueta 4.00).
                $snapped = max(0.10, round($value * 10) / 10);
                if (abs($snapped - round($snapped)) < 0.001) {
                    $snapped -= 0.10;
                }
                return round(max(0.10, $snapped), 2);
            }
            if ($value < 100.0) {
                return round(round($value) - 0.10, 2);
            }
            if ($value < 1000.0) {
                return round(round($value / 10) * 10 - 1.0, 2);
            }
            return round(round($value / 100) * 100, 2);
        }

        if ($value < 100.0) {
            return (float) max(10, (int) round($value / 10) * 10);
        }
        if ($value < 1000.0) {
            return (float) ((int) round($value / 50) * 50);
        }
        if ($value < 10000.0) {
            return (float) ((int) round($value / 500) * 500);
        }
        if ($value < 100000.0) {
            return (float) ((int) round($value / 1000) * 1000);
        }
        return (float) ((int) round($value / 5000) * 5000);
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
     * (self::LOCALE), después los catálogos JSON. Ese orden importa porque
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

        $own = self::LOCALE[$iso]['taxId'] ?? null;
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
     * Sale de self::LOCALE y no de los catálogos JSON porque ninguno de
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

        return self::LOCALE[$iso]['personalId'] ?? null;
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

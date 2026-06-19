<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * Consultas sobre la tabla `taxonomy` del POS.
 *
 * Reemplaza las funciones globales (Slice 7 del plan PSR-4):
 *   - getPaymentMethodName($id, $decode)      → Taxonomy::getPaymentMethodName($id, $decode)
 *   - getCategoriesIds($companyId)            → Taxonomy::getCategoriesIds($companyId)
 *   - getTaxValue($id)                        → Taxonomy::getTaxValue($id)
 *   - getTaxonomyArray($type, $co, $cz)       → Taxonomy::getArray($type, $company, $compZero)
 *   - getTagsDefaults($idsOnly)               → Taxonomy::getTagsDefaults($idsOnly)
 *   - getCustomTemplates($company)            → Taxonomy::getCustomTemplates($company)
 *   - getTaxonomyName($id, $num, $co)         → Taxonomy::getName($id, $numeric, $company)
 *   - getAllTaxonomyNames($companyId)          → Taxonomy::getAllNames($companyId)
 *   - printOutTags($tags, $bg)                → Taxonomy::printTags($tags, $bg)
 *   - selectInputTaxonomy($type, $m, $multi)  → Taxonomy::selectInput($type, $match, $multi)
 *   - getTaxonomyIdOrInsert($n, $t, $ins)     → Taxonomy::getIdOrInsert($name, $type, $insertIt)
 *   - getAllItemCategories($companyId)         → Taxonomy::getAllItemCategories($companyId)
 *
 * Las funciones globales permanecen como wrappers que delegan acá — cero
 * breaking changes en los ~112 callsites totales del POS.
 *
 * Cache: getName() almacena el nombre raw por "id:companyClause" para evitar
 * N+1 en printTags (iteración de tags por registro).
 */
final class Taxonomy
{
    /** @var array<string, string|null> nombre raw por "id:company" (null = no encontrado) */
    private static array $nameCache = [];

    /**
     * Nombre de un método de pago. Maneja aliases hardcodeados y fallback DB.
     * Equivalente legacy: `getPaymentMethodName($id, $decode)`.
     *
     * Lógica de fallback verbatim del legacy:
     *   - $decode=false: intenta con $id crudo; si falla, decodifica y reintenta.
     *   - $decode=true:  decodifica antes del primer intento; segundo intento es redundante.
     */
    public static function getPaymentMethodName(mixed $id, bool $decode = false): string
    {
        $map = [
            'cash'            => 'Efectivo',
            'pix'             => 'PIX',
            'creditcard'      => 'T. Crédito',
            'debitcard'       => 'T. Débito',
            'check'           => 'Cheque',
            'giftcard'        => 'Gift Card',
            'inCredit'        => 'Crédito Interno',
            'storeCredit'     => 'Crédito Interno',
            'points'          => 'Loyalty',
            'QRPayment'       => 'ePOS',
            'VPOS'            => 'ePOS',
            'ePOS'            => 'ePOS',
            'epos'            => 'ePOS',
            'bancardQROnline' => 'ePOS',
            'ePOSCard'        => 'ePOS Card',
        ];

        if (isset($map[$id])) {
            return $map[$id];
        }

        if ($decode) {
            $id = dec($id);
        }
        $result = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID]);
        if ($result) {
            return $result['taxonomyName'];
        }

        if (!$decode) {
            $id = dec($id);
        }
        $result = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID]);
        return $result ? $result['taxonomyName'] : '';
    }

    /**
     * Nombre de un taxonomy por ID. Cachea el nombre raw para evitar N+1.
     * Equivalente legacy: `getTaxonomyName($id, $numeric, $company)`.
     *
     * @param bool  $numeric   Si true y no encontrado → devuelve 0 en vez de 'None'.
     * @param mixed $company   Cláusula SQL de empresa (default: $SQLcompanyId global).
     */
    public static function getName(mixed $id, bool $numeric = false, mixed $company = false): mixed
    {
        global $db, $SQLcompanyId;

        $companyClause = $company ?: $SQLcompanyId;
        $cacheKey      = $id . ':' . $companyClause;

        if (!array_key_exists($cacheKey, self::$nameCache)) {
            $result = $db->Execute(
                'SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND ' . $companyClause,
                [$id]
            );
            // Guarda el nombre raw o null si no encontrado/inválido
            $raw = $result->fields['taxonomyName'] ?? null;
            self::$nameCache[$cacheKey] = validity($raw) ? $raw : null;
        }

        $cached = self::$nameCache[$cacheKey];
        if ($cached !== null) {
            return $cached;
        }
        return $numeric ? 0 : 'None';
    }

    /**
     * Valor de un tipo 'tax' (nombre/porcentaje) por ID.
     * Equivalente legacy: `getTaxValue($id)`.
     */
    public static function getTaxValue(mixed $id): string|false
    {
        $tax = ncmExecute(
            "SELECT taxonomyName FROM taxonomy WHERE taxonomyType = 'tax' AND taxonomyId = ? LIMIT 1",
            [$id]
        );
        return $tax['taxonomyName'] ?? false;
    }

    /**
     * Array JSON de taxonomies por tipo. Excluye type='internal'.
     * Equivalente legacy: `getTaxonomyArray($type, $company, $compZero)`.
     *
     * @param mixed $company  UUID de empresa, o false para usar $SQLcompanyId.
     * @param bool  $compZero Si true, incluye también los defaults globales.
     */
    public static function getArray(string $type, mixed $company, bool $compZero = false): string
    {
        global $SQLcompanyId;

        // compZero = incluir defaults globales. En PG los globales son
        // companyId IS NULL (en MySQL eran companyId=1).
        $globalScope = $compZero ? 'companyId IS NULL OR ' : '';
        if ($company) {
            // $company es el UUID de la empresa → va parametrizado (`?`). Sin
            // comillas PG da "trailing junk after numeric literal"; con `?` además
            // queda a salvo de SQLi si un caller futuro pasa input no-JWT.
            $companyClause = $globalScope . 'companyId = ?';
            $params        = [$type, $company];
        } else {
            // $SQLcompanyId ya es un clause con el UUID del JWT entre comillas.
            $companyClause = $globalScope . $SQLcompanyId;
            $params        = [$type];
        }

        $result = ncmExecute(
            "SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = ? AND taxonomyExtra != 'internal' AND (" . $companyClause . ') LIMIT 500',
            $params,
            false,
            true
        );

        $out = [];
        if ($result) {
            while (!$result->EOF) {
                $out[] = ['tagid' => $result->fields['taxonomyId'], 'tagname' => $result->fields['taxonomyName']];
                $result->MoveNext();
            }
            $result->Close();
        }

        return json_encode($out);
    }

    /**
     * Tags por defecto de la instancia base (globales, excluye 'internal').
     * En PG los globales son companyId IS NULL (en MySQL eran companyId=1).
     * Equivalente legacy: `getTagsDefaults($idsOnly)`.
     *
     * @param bool $idsOnly Si true devuelve solo los IDs; si false, [{tagid, tagname}].
     */
    public static function getTagsDefaults(bool $idsOnly = false): array
    {
        $result = ncmExecute(
            "SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = 'tag' AND companyId IS NULL AND taxonomyExtra != 'internal' LIMIT 20",
            [],
            false,
            true
        );

        $out = [];
        if ($result) {
            while (!$result->EOF) {
                $out[] = $idsOnly
                    ? $result->fields['taxonomyId']
                    : ['tagid' => $result->fields['taxonomyId'], 'tagname' => $result->fields['taxonomyName']];
                $result->MoveNext();
            }
        }

        return $out;
    }

    /**
     * Templates de impresión custom (taxonomyType='printTemplate') de una empresa.
     * Incluye templates globales (companyId IS NULL).
     * Equivalente legacy: `getCustomTemplates($company)`.
     */
    public static function getCustomTemplates(mixed $company): string
    {
        $result = ncmExecute(
            "SELECT taxonomyId,taxonomyName,taxonomyExtra FROM taxonomy WHERE taxonomyType = 'printTemplate' AND (companyId = ? OR companyId IS NULL)",
            [$company],
            false,
            true
        );

        $out = [];
        if (!$result || !is_object($result)) {
            return json_encode($out);
        }

        while (!$result->EOF) {
            $out[] = [
                'id'   => enc($result->fields['taxonomyId']),
                'name' => $result->fields['taxonomyName'],
                'data' => $result->fields['taxonomyExtra'],
            ];
            $result->MoveNext();
        }
        $result->Close();

        return json_encode($out);
    }

    /**
     * Mapa taxonomyId → {name} de todos los taxonomies de una empresa.
     * Equivalente legacy: `getAllTaxonomyNames($companyId, $numeric)`.
     *
     * @param bool $numeric Parámetro sin uso en el legacy — preservado para compatibilidad.
     */
    public static function getAllNames(mixed $companyId, bool $numeric = false): array
    {
        $a      = [];
        $result = ncmExecute(
            'SELECT taxonomyId, taxonomyName FROM taxonomy WHERE companyId = ?',
            [$companyId],
            false,
            true
        );

        if ($result) {
            while (!$result->EOF) {
                $a[$result->fields['taxonomyId']] = ['name' => $result->fields['taxonomyName']];
                $result->MoveNext();
            }
            $result->Close();
        }

        return $a;
    }

    /**
     * Renderiza tags como spans Bootstrap. Usa getName() (con cache).
     * Equivalente legacy: `printOutTags($tags, $bg)`.
     */
    public static function printTags(mixed $tags, string $bg = 'bg-white'): string
    {
        $tagout = '';
        if ($tags) {
            foreach ($tags as $tag) {
                $tagout .= '<span class="label ' . $bg . '">' . self::getName($tag) . '</span> ';
            }
        }
        return $tagout;
    }

    /**
     * IDs de categorías visibles de una empresa (STRING_AGG CSV).
     * Excluye taxonomyExtra=2 (ocultas) y nulos.
     * Equivalente legacy: `getCategoriesIds($companyId)`.
     */
    public static function getCategoriesIds(mixed $companyId): string|false
    {
        $result = ncmExecute(
            "SELECT STRING_AGG(taxonomyId::text, ',') as ids FROM taxonomy WHERE (taxonomyExtra = 2 OR taxonomyExtra IS NULL) AND taxonomyType = 'category' AND companyId = ?",
            [$companyId],
            false,
            true
        );

        if ($result) {
            $ids = $result->fields['ids'] ?? null;
            $result->Close();
            return $ids ?: false;
        }

        return false;
    }

    /**
     * Mapa enc(taxonomyId) → {name, sort} de categorías de una empresa, ordenado por sort.
     * Equivalente legacy: `getAllItemCategories($companyId)`.
     */
    public static function getAllItemCategories(mixed $companyId): array
    {
        $a      = [];
        $result = ncmExecute(
            'SELECT taxonomyId,taxonomyName, CAST(taxonomyExtra AS INTEGER) as sort FROM taxonomy WHERE taxonomyType = ? AND companyId = ? ORDER BY sort ASC LIMIT 500',
            ['category', $companyId],
            false,
            true
        );

        if ($result) {
            while (!$result->EOF) {
                $a[enc($result->fields['taxonomyId'])] = [
                    'name' => $result->fields['taxonomyName'],
                    'sort' => (int) ($result->fields['taxonomyExtra'] ?? 0),
                ];
                $result->MoveNext();
            }
            $result->Close();
        }

        return $a;
    }

    /**
     * Genera el HTML de un <select> de taxonomies por tipo. Retorna string
     * (el wrapper legacy hace echo del retorno para mantener el contrato original).
     * Equivalente legacy: `selectInputTaxonomy($type, $match, $multi)`.
     */
    public static function selectInput(string $type, mixed $match, bool $multi = false): string
    {
        global $db, $SQLcompanyId;

        $result = $db->Execute(
            'SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = ? AND ' . $SQLcompanyId . ' ORDER BY taxonomyName ASC',
            [$type]
        );

        $name = $multi ? $type . '[]' : $type;
        $html = '<select name="' . $name . '" class="form-control">';

        if ($result) {
            while (!$result->EOF) {
                $selected = $result->fields['taxonomyId'] == $match ? 'selected' : '';
                $html .= '<option value="' . enc($result->fields['taxonomyId']) . '" ' . $selected . '>' . $result->fields['taxonomyName'] . '</option>';
                $result->MoveNext();
            }
            $result->Close();
        }

        $html .= '</select>';
        return $html;
    }

    /**
     * Busca un taxonomy por nombre+tipo y lo inserta si no existe.
     * Equivalente legacy: `getTaxonomyIdOrInsert($name, $type, $insertIt)`.
     *
     * @return mixed  taxonomyId si existe/insertado; null si nombre inválido o $insertIt=false.
     */
    public static function getIdOrInsert(mixed $name, string $type, bool $insertIt = true): mixed
    {
        global $db;

        if (!validity($name)) {
            return null;
        }

        // Usar COMPANY_ID parametrizado en vez del SQL-fragment $SQLcompanyId:
        // en /api esa global queda VACÍA (apiAuthTenant la define como local
        // de función) → el SELECT queda truncado y nunca encuentra la fila →
        // el caller termina creando una taxonomy nueva cada vez (bug del
        // importador de items: dup de "Materia Prima" por cada upload).
        $obj = $db->Execute(
            'SELECT taxonomyId FROM taxonomy WHERE taxonomyName = ? AND taxonomyType = ? AND companyId = ?',
            [$name, $type, COMPANY_ID]
        );

        if ($obj !== false && validity($obj->fields['taxonomyId'] ?? null)) {
            return $obj->fields['taxonomyId'];
        }

        if (!$insertIt) {
            return null;
        }

        $record = [
            'taxonomyName' => $name,
            'taxonomyType' => $type,
            'companyId'    => COMPANY_ID,
        ];
        $insert = $db->AutoExecute('taxonomy', $record, 'INSERT');

        return $insert === true ? $db->Insert_ID() : null;
    }
}

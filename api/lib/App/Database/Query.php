<?php
declare(strict_types=1);

namespace Punto\App\Database;

/**
 * Capa de acceso a DB del POS. Envuelve el wrapper PDO propio del proyecto
 * (`api/includes/lib/DB.php`, ÚNICA capa de DB) + JSONB demoting.
 *
 * Reemplaza las funciones globales (Slice 10 del plan PSR-4):
 *   - _flattenJsonb($row)                         → Query::flattenJsonb($row)
 *   - ncmExecute($sql, $arr, $cache, $obj, $assoc)→ Query::execute(...)
 *   - ncmUpdate($options)                          → Query::update($options)
 *   - ncmInsert($options)                          → Query::insert($options)
 *   - ncmDelete($from, $where)                     → Query::delete($from, $where)
 *   - ncmWhile($result, $callback, $vars)          → Query::iterate($result, $callback, $vars)
 *   - getValue($table, $field, $where, $type, $c)  → Query::getValue(...)
 *
 * Las funciones globales permanecen como wrappers — cero breaking changes en
 * los ~1273 callsites totales del POS.
 *
 * CRÍTICO: ncmExecute/Query::execute es el god node de DB del POS (1035 callers).
 * La semántica se preserva VERBATIM — cualquier cambio aquí rompe en cascada.
 */
final class Query
{
    /**
     * Aplana columnas JSONB (data/meta/config) en una fila y devuelve CIA.
     * Las keys del JSONB se mezclan en la fila; la columna nombrada gana sobre JSONB.
     * Equivalente legacy: `_flattenJsonb($row)`.
     *
     * Devuelve CaseInsensitiveArray — el lookup de keys es case-insensitive,
     * igual que el contrato de la CIA de DB.php. Callers que lean $row['outletId']
     * con PG devolviendo 'outletid' funcionan sin cambios.
     *
     * @param mixed $row  CaseInsensitiveArray (de DB.php), o array plano (keys lowercase de PG).
     */
    public static function flattenJsonb(mixed $row): \CaseInsensitiveArray
    {
        // Usa la CIA canónica del DB layer (api/includes/lib/DB.php) — la MISMA
        // que referencian todos los typehints `CaseInsensitiveArray|array` del
        // codebase. NO crear una clase nueva: rompía esos typehints (incidente
        // POS 502 2026-06-29).
        // La normalización a array nativo vive en `ncmRow()` (DB.php), junto a
        // la clase que la hace necesaria — este método era una de las copias.
        $arr = ncmRow($row);

        static $jsonbCols = ['data', 'meta', 'config'];
        $raw = [];
        foreach ($jsonbCols as $col) {
            $val = $arr[$col] ?? null;
            if (isset($val) && is_string($val) && $val !== '') {
                $decoded = json_decode($val, true);
                if (is_array($decoded) && !array_is_list($decoded)) {
                    $raw[$col] = $val;                 // NO se pierde: ver rawJsonb()
                    $arr = array_merge($decoded, $arr); // columna nombrada gana sobre JSONB
                    unset($arr[$col]);
                }
            }
        }

        $cia = new \CaseInsensitiveArray($arr);
        if ($raw !== []) {
            self::rawStore()->offsetSet($cia, $raw);
        }
        return $cia;
    }

    /**
     * JSON crudo de una columna JSONB que `flattenJsonb()` desempaquetó.
     *
     * El flatten mezcla el contenido del JSONB al nivel de la fila y saca la
     * columna del array — necesario para que los campos demoted se lean como
     * columnas, pero el payload original quedaba DESTRUIDO: todo caller que
     * hiciera `$row['meta']` recibía null y, como no hay error, el dato
     * simplemente desaparecía. Costó cuatro features en producción
     * (ventas guardadas vacías, config del POS ignorada, ítems de compra
     * perdidos, anular compra sin revertir stock — 2026-07-30).
     *
     * Ahora el crudo se preserva en un side-channel (WeakMap indexada por la
     * fila devuelta) en vez de dejarse en el array. Por qué no simplemente no
     * borrar la clave:
     *   - Presenters que iteran TODAS las claves de la fila (ej. presentItem en
     *     api/v1/items.php) empezarían a emitir el blob crudo en la respuesta.
     *   - Los flujos leer-modificar-escribir mandarían ese string de vuelta a
     *     ncmUpdate, donde `data` SÍ es columna real → se pisaría el JSONB.
     * El side-channel deja la forma de la fila idéntica a hoy y no pierde nada.
     *
     * Uso:
     *   $row = ncmExecute('SELECT * FROM transaction WHERE ...');
     *   $meta = json_decode(Query::rawJsonb($row, 'meta') ?? '{}', true);
     *
     * Alternativa igual de válida cuando controlás el SQL: aliasear la columna
     * (`meta::text AS meta_raw`) — el alias no está en la lista de flatten y
     * sobrevive intacto.
     *
     * @return string|null JSON crudo, o null si esa fila no traía esa columna.
     */
    public static function rawJsonb(object $row, string $col): ?string
    {
        $store = self::rawStore();
        if (!$store->offsetExists($row)) {
            return null;
        }
        return $store->offsetGet($row)[strtolower($col)] ?? null;
    }

    /**
     * WeakMap fila → {columna: JSON crudo}. Weak a propósito: las entradas se
     * liberan cuando la fila se recolecta, así un SELECT de miles de filas no
     * retiene los blobs durante todo el request.
     */
    private static function rawStore(): \WeakMap
    {
        static $store = null;
        if ($store === null) {
            $store = new \WeakMap();
        }
        return $store;
    }

    /**
     * Ejecuta un SELECT sobre la DB con soporte de caché de la capa DB y JSONB demoting.
     * Equivalente legacy: `ncmExecute($sql, $array, $cache, $forceObj, $getAssoc)`.
     *
     * Retorno:
     *   - DML sin RETURNING (INSERT/UPDATE/DELETE) → int de filas afectadas
     *     (0 incluido — la query corrió OK, simplemente no tocó filas).
     *   - $getAssoc=true  → array asociativo con flattenJsonb en cada fila (valores son CIA), o false.
     *   - $forceObj=true  → RecordsetIterator (iterable, fields devuelve CIA via flattenJsonb), o false.
     *   - count=1         → CaseInsensitiveArray (propia) de la primera fila, o false.
     *   - count>1         → RecordsetIterator (el flatten se aplica al iterar via fields).
     *   - count=0 (SELECT)→ 0 (sin forceObj), o false.
     *   - false           → error real de DB (PDOException) o SELECT/RETURNING inválido.
     *
     * El CIA (de DB.php) restaura el lookup case-insensitive: $row['outletId']
     * funciona aunque PG devuelva la key como 'outletid'.
     *
     * SEMÁNTICA CASI-VERBATIM — único cambio intencional: DML exitoso ya no
     * devuelve `false` (bug corregido, ver bloque WasLastDml() abajo). No
     * modificar más allá de esto sin regression suite completo.
     */
    public static function execute(
        string $sql,
        mixed  $array    = false,
        mixed  $cache    = false,
        bool   $forceObj = false,
        bool   $getAssoc = false
    ): mixed {
        global $db;

        $go = false;

        if (!$cache) {
            if ($getAssoc) {
                $result = $db->GetAssoc($sql, $array);
            } else {
                $result = $db->Execute($sql, $array);
            }
        } else {
            $cachTime = 3600;
            if (is_numeric($cache)) {
                $cachTime = $cache;
            }

            if ($getAssoc) {
                $result = $db->CacheGetAssoc($cachTime, $sql, $array);
            } else {
                $result = $db->cacheExecute($cachTime, $sql, $array);
            }
        }

        // DML sin RETURNING (UPDATE/DELETE/INSERT) no devuelve filas — RecordCount()
        // es SIEMPRE 0 aunque la query haya aplicado cambios. La validación de abajo
        // (RecordCount() > 0) trataba eso como fallo → un UPDATE exitoso de 0 filas
        // devueltas (el caso normal) reportaba `false` como si hubiera sido un error
        // real de DB, produciendo 500 fantasma en endpoints que chequean el retorno
        // (ej. active-register.php). `false` queda reservado para errores reales
        // (PDOException, ver DB::Execute). Detección vía DB::WasLastDml() — misma
        // regex que ya corre en DB.php:267-269, no se duplica acá.
        if (!$getAssoc && $result !== false && $db->WasLastDml()) {
            return $db->Affected_Rows();
        }

        if ($getAssoc) {
            $count = counts($result);
        } else {
            $count = validateResultFromDB($result, true);
        }

        if ($getAssoc) {
            if (validity($result, 'array')) {
                $go = true;
            }
        } else {
            if (validateResultFromDB($result)) {
                $go = true;
            }
        }

        if ($go) {
            if ($getAssoc) {
                return array_map([self::class, 'flattenJsonb'], $result);
            } else {
                if ($count > 1 || $forceObj) {
                    return new RecordsetIterator($result);
                } elseif ($count > 0) {
                    return self::flattenJsonb($result->fields);
                } else {
                    return 0;
                }
            }
        } else {
            // forceObj + query exitosa con 0 filas → recordset vacío (iterable seguro)
            if ($forceObj && $result && is_object($result)) {
                return new RecordsetIterator($result);
            }
            return false;
        }
    }

    /**
     * TODAS las filas de un SELECT, en orden, sin ninguna clave que pueda pisar.
     * Equivalente global: `ncmRows($sql, $params)` (functions.php delega acá).
     *
     * Contraparte segura de `execute(..., $getAssoc: true)`, que indexa por la
     * PRIMERA columna proyectada y pierde en silencio las filas que repiten ese
     * valor (ver el docblock de `DB::GetAssoc()`). El caller típico de getAssoc
     * nunca quiso el índice —hacía `foreach` sobre el resultado creyéndolo
     * completo— y ese caller va acá.
     *
     * Cada fila es un `CaseInsensitiveArray` con el JSONB ya aplanado, mismo
     * shape que las filas de getAssoc: migrar un caller no cambia cómo lee sus
     * campos, solo cuántas filas ve.
     *
     * @param  array|false $params
     * @return list<\CaseInsensitiveArray>
     */
    public static function rows(string $sql, mixed $params = []): array
    {
        $rs = self::execute($sql, $params === [] ? false : $params, false, true);
        if (!$rs) {
            return [];
        }
        $out = [];
        while (!$rs->EOF) {
            $out[] = $rs->fields;
            $rs->MoveNext();
        }
        return $out;
    }

    /**
     * UPDATE via AutoExecute (capa de DB propia, DB.php).
     * Equivalente legacy: `ncmUpdate($options)`.
     *
     * @param array $options  {records: array, table: string, where: string}
     * @return array|false    {error: false, id: lastId} o {error: errorMsg} o false.
     */
    public static function update(array $options): array|false
    {
        // El slice 10 PSR-4 originalmente implementaba el UPDATE acá llamando a AutoExecute
        // directo, pero NO hacía JSONB routing (campos demoted a `data` tiraban "column does
        // not exist") ni whereParams binding (mismatch SET/WHERE → 500 silente). El cuerpo
        // canónico vive ahora en `ncmUpdate()` en app/includes/functions.php — Query delega
        // para que cualquier caller futuro (panel o /api) tenga el routing correcto.
        return ncmUpdate($options);
    }

    /**
     * INSERT via AutoExecute (capa de DB propia, DB.php). Devuelve el ID insertado o false.
     * Equivalente legacy: `ncmInsert($options)`.
     *
     * @param array $options  {records: array, table: string}
     * @return mixed  ID insertado o false.
     */
    public static function insert(array $options): mixed
    {
        // Mismo razonamiento que update(): el cuerpo canónico vive en ncmInsert(), que hace
        // JSONB routing + UUID v7 auto-generation. Query delega para evitar drift.
        return ncmInsert($options);
    }

    /**
     * DELETE con guardia de seguridad (from y where obligatorios).
     * Equivalente legacy: `ncmDelete($from, $where)`.
     */
    public static function delete(mixed $from, mixed $where): bool
    {
        global $db;

        if (!validity($from) || !validity($where)) {
            return false;
        }

        $deleted = $db->Execute('DELETE FROM ? WHERE ?', [$from, $where]);

        return $deleted !== false;
    }

    /**
     * Itera sobre un recordset DBResult invocando $callback en cada fila.
     * Equivalente legacy: `ncmWhile($result, $callback, $vars)`.
     */
    public static function iterate(mixed $result, callable $callback, mixed $vars): void
    {
        if ($result) {
            while (!$result->EOF) {
                $field = $result->fields;
                if (is_callable($callback)) {
                    call_user_func($callback, $field, $vars);
                }
                $result->MoveNext();
            }
            $result->Close();
        }
    }

    /**
     * SELECT de un único campo de una tabla. Devuelve el valor o un fallback
     * según $returnType ('number'→0, 'boolean'→false, 'string'→'').
     * Equivalente legacy: `getValue($table, $field, $where, $returnType, $cache)`.
     */
    public static function getValue(
        string $table,
        string $field,
        string $where      = '',
        string $returnType = 'number',
        mixed  $cache      = false
    ): mixed {
        $limit = ' LIMIT 1';

        if (strpos($where, 'LIMIT') !== false) {
            $limit = '';
        }

        $result = self::execute(
            'SELECT ' . $field . ' FROM ' . $table . ' ' . $where . $limit,
            [],
            $cache
        );

        if ($result) {
            return $result[$field];
        }

        return match ($returnType) {
            'boolean' => false,
            'string'  => '',
            default   => 0,
        };
    }
}

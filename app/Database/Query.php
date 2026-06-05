<?php
declare(strict_types=1);

namespace Punto\App\Database;

/**
 * Capa de acceso a DB del POS. Envuelve ADOdb + JSONB demoting.
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
     * Aplana columnas JSONB (data/meta/config) en el array de una fila.
     * Las keys del JSONB se mezclan en el array; la columna nombrada gana sobre JSONB.
     * Equivalente legacy: `_flattenJsonb($row)`.
     *
     * @param mixed $row  CaseInsensitiveArray o array.
     */
    public static function flattenJsonb(mixed $row): \CaseInsensitiveArray
    {
        $arr = ($row instanceof \CaseInsensitiveArray) ? $row->toArray() : (array) $row;

        static $jsonbCols = ['data', 'meta', 'config'];
        foreach ($jsonbCols as $col) {
            $val = $arr[$col] ?? $arr[strtolower($col)] ?? null;
            if (isset($val) && is_string($val) && $val !== '') {
                $decoded = json_decode($val, true);
                if (is_array($decoded) && !array_is_list($decoded)) {
                    $arr = array_merge($decoded, $arr); // columna gana sobre JSONB
                    unset($arr[$col]);
                }
            }
        }

        return new \CaseInsensitiveArray($arr);
    }

    /**
     * Ejecuta un SELECT sobre la DB con soporte de caché ADOdb y JSONB demoting.
     * Equivalente legacy: `ncmExecute($sql, $array, $cache, $forceObj, $getAssoc)`.
     *
     * Retorno:
     *   - $getAssoc=true  → array asociativo con _flattenJsonb en cada fila, o false.
     *   - $forceObj=true  → objeto ADOdb recordset (inclusive si 0 filas), o false.
     *   - count=1         → CaseInsensitiveArray de la primera fila, o false.
     *   - count>1         → objeto ADOdb recordset (el flatten se aplica al iterar).
     *   - count=0         → 0 (sin forceObj), o false.
     *
     * SEMÁNTICA VERBATIM — no modificar sin regression suite completo.
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
                    return $result;
                } elseif ($count > 0) {
                    return self::flattenJsonb($result->fields);
                } else {
                    return 0;
                }
            }
        } else {
            // forceObj + query exitosa con 0 filas → recordset vacío (iterable seguro)
            if ($forceObj && $result && is_object($result)) {
                return $result;
            }
            return false;
        }
    }

    /**
     * UPDATE via AutoExecute de ADOdb.
     * Equivalente legacy: `ncmUpdate($options)`.
     *
     * @param array $options  {records: array, table: string, where: string}
     * @return array|false    {error: false, id: lastId} o {error: errorMsg} o false.
     */
    public static function update(array $options): array|false
    {
        global $db;

        if (
            !validity($options, 'array') ||
            !validity($options['records'], 'array') ||
            !validity($options['table']) ||
            !validity($options['where'])
        ) {
            return false;
        }

        $table    = $options['table'];
        $record   = $options['records'];
        $where    = $options['where'];

        $update   = $db->AutoExecute($table, $record, 'UPDATE', $where);
        $updateId = $db->Insert_ID();

        if ($update !== false) {
            return ['error' => false, 'id' => $updateId];
        }

        return ['error' => $db->ErrorMsg()];
    }

    /**
     * INSERT via AutoExecute de ADOdb. Devuelve el ID insertado o false.
     * Equivalente legacy: `ncmInsert($options)`.
     *
     * @param array $options  {records: array, table: string}
     * @return mixed  ID insertado o false.
     */
    public static function insert(array $options): mixed
    {
        global $db;

        if (
            !validity($options, 'array') ||
            !validity($options['records'], 'array') ||
            !validity($options['table'])
        ) {
            return false;
        }

        $table      = $options['table'];
        $record     = $options['records'];

        $insert     = $db->AutoExecute($table, $record, 'INSERT');
        $insertedId = $db->Insert_ID();

        if ($insert !== false) {
            return $insertedId;
        }

        return false;
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
     * Itera sobre un ADOdb recordset invocando $callback en cada fila.
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

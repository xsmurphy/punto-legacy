<?php
declare(strict_types=1);

namespace Punto\App\Helpers;

/**
 * Validación de valores y entrada HTTP del POS.
 *
 * Reemplaza las funciones globales (Slice 3 del plan PSR-4):
 *   - validity($v, $force)              → Validation::isValid($v, $force)
 *   - validateBool($key, $server, $t)   → Validation::fromRequest($key, $server, $t)
 *   - validateHttp($key, $t)            → Validation::http($key, $t)
 *   - validateResultFromDB($r, $num)    → Validation::fromDbResult($r, $num)
 *
 * Las funciones globales permanecen como wrappers que delegan acá — cero
 * breaking changes en los ~2298 callsites totales del POS:
 *   - validity:             716 callers (linchpin del refactor)
 *   - validateHttp:       1524 callers (alias de validateBool con db_prepare)
 *   - validateBool:         58 callers
 *   - validateResultFromDB: pocos callers
 *
 * Semántica preservada VERBATIM del legacy. NO REFACTORIZAR la lógica de
 * `isValid` — la usa toda la app para distinguir valores "vacíos" del POS
 * (incluido el string literal 'undefined' que llega del front cuando JS
 * serializa `undefined`). Cualquier cambio rompe en 716 lugares.
 */
final class Validation
{
    /**
     * Validez "viva" de un valor. Reemplaza el legacy `validity()`.
     *
     * Retorna `false` si el valor es:
     *   - no seteado (isset false)
     *   - vacío (!$value, empty(), == '', == false)
     *   - el string literal 'undefined' (legacy del front JS)
     *   - cuenta < 0.00001 (counts())
     * Sino retorna el valor original.
     *
     * Con $force se valida tipo:
     *   - 'email'  → filter_var FILTER_VALIDATE_EMAIL
     *   - 'string' / 'array' / etc. → gettype($value) === $force
     *
     * @param mixed $value
     * @param string|false $force
     * @return mixed false si inválido, sino el valor.
     */
    public static function isValid(mixed $value, string|false $force = false): mixed
    {
        if (!isset($value)) {
            return false;
        }
        if (!$value || empty($value) || $value == 'undefined' || $value === null || $value == false || $value === false || $value == '' || counts($value) < 0.00001) {
            return false;
        }
        if ($force) {
            if ($force === 'email') {
                return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : false;
            }
            return gettype($value) === $force ? $value : false;
        }
        return $value;
    }

    /**
     * Lee y valida una key de la request HTTP. Reemplaza el legacy `validateBool()`.
     *
     * @param string $key      key en $_GET / $_POST.
     * @param bool   $server   si true, verifica que REQUEST_METHOD sea GET o POST.
     * @param string $type     'get' | 'post' | otro (lee $key directo).
     * @return mixed false si inválido, sino el valor.
     */
    public static function fromRequest(string $key, bool $server = true, string $type = 'get'): mixed
    {
        if ($server === true && $_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }
        return match ($type) {
            'get'  => self::isValid($_GET[$key]  ?? null),
            'post' => self::isValid($_POST[$key] ?? null),
            default => self::isValid($key),
        };
    }

    /**
     * Idem `fromRequest()` pero pasa el resultado por `db_prepare()` (sanitiza para SQL).
     * Reemplaza el legacy `validateHttp()` (alias de validateBool + db_prepare).
     *
     * @return mixed false si inválido, sino el valor preparado para SQL.
     */
    public static function http(string $key, string $type = 'get'): mixed
    {
        $result = db_prepare(self::fromRequest($key, true, $type));
        return $result;
    }

    /**
     * Verifica que un recordset de DB tenga resultados.
     * Reemplaza el legacy `validateResultFromDB()`.
     *
     * @param mixed $result  el recordset (objeto con ->RecordCount()) o false.
     * @param bool  $num     si true devuelve el count, sino bool true.
     * @return int|bool
     */
    public static function fromDbResult(mixed $result, bool $num = false): int|bool
    {
        if ($result && $result->RecordCount() > 0) {
            return $num ? $result->RecordCount() : true;
        }
        return $num ? 0 : false;
    }
}

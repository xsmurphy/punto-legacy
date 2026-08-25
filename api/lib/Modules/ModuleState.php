<?php

namespace Punto\Api\Modules;

/**
 * Resolución del estado (activo / inactivo) de un módulo para un tenant.
 *
 * Es la ÚNICA definición de cómo se lee ese estado desde una fila de `company`.
 * Existía duplicada en `ModulesService::list()` y en `TenantHealthService`, y
 * la copia de health leía las keys como si fueran COLUMNAS de `company`
 * (`SELECT orderspanel, tables, production, moduleData FROM company`) — no lo
 * son: viven dentro del JSONB `config`, así que el semáforo de salud tiraba
 * 42703 y `/admin` quedaba sin listado de tenants.
 *
 * Orden de resolución (el mismo que aplicaba `ModulesService` desde siempre):
 *   1. key plana de la fila (columna real o key de `config` ya aplanada por
 *      `Query::flattenJsonb()`) — es lo que lee el POS;
 *   2. `moduleData[key]['status']` — módulos que solo viven en el JSON;
 *   3. `moduleData[key]` como valor directo (formato legacy).
 *
 * NO aplica el kill-switch de plataforma: ese es estado global, no del tenant,
 * y lo resuelve `ModulesService` con el catálogo de `platform_config`.
 */
final class ModuleState
{
    /**
     * @param array|\CaseInsensitiveArray $row fila de `company` con el JSONB
     *        `config` ya aplanado (`Query::flattenJsonb`) o normalizada a
     *        lowercase — ambas se leen igual.
     */
    public static function enabled($row, string $key): bool
    {
        $flat = self::pick($row, $key);
        if ($flat !== null && $flat !== '') {
            return self::truthy($flat);
        }

        $entry = self::moduleData($row)[$key] ?? null;
        if (is_array($entry)) {
            return isset($entry['status']) ? self::truthy($entry['status']) : false;
        }
        if ($entry !== null) {
            return self::truthy($entry);
        }

        return false;
    }

    /**
     * `moduleData` de la fila, siempre como array. Tolera las dos formas en que
     * llega: JSON crudo (fila sin aplanar) o array (post-flattenJsonb).
     *
     * @param array|\CaseInsensitiveArray $row
     */
    public static function moduleData($row): array
    {
        $raw = self::pick($row, 'moduleData');
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** Normaliza a bool: PG devuelve 't'/'f', el JSON true/false, el legacy '1'/'0'. */
    public static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_array($v)) {
            return false;
        }
        $s = strtolower((string) $v);
        return in_array($s, ['1', 't', 'true', 'yes', 'on'], true)
            || (is_numeric($s) && (float) $s > 0);
    }

    /**
     * Lectura case-insensitive: PG baja los identificadores a lowercase, pero
     * las keys de `config` conservan el camelCase con el que se guardaron
     * (`ordersPanel`, `moduleData`).
     *
     * @param array|\CaseInsensitiveArray $row
     */
    private static function pick($row, string $key)
    {
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        if (!is_array($row) && !($row instanceof \ArrayAccess)) {
            return null;
        }

        $val = $row[$key] ?? null;
        if ($val !== null) {
            return $val;
        }

        $lower = strtolower($key);
        if (is_array($row)) {
            foreach ($row as $k => $v) {
                if (strtolower((string) $k) === $lower) {
                    return $v;
                }
            }
            return null;
        }

        return $row[$lower] ?? null;
    }
}

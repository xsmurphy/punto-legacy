<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Sincroniza el catálogo geográfico fiscal (mig 207) desde el proveedor.
 *
 * ── Qué hace y qué NO ────────────────────────────────────────────────────
 *
 * Baja los tres niveles y hace UPSERT. No borra NUNCA: una fila que el
 * proveedor dio de baja se marca `active = false` y se conserva, porque puede
 * ser la ciudad que un comercio ya tiene guardada en su domicilio fiscal y
 * hacerla desaparecer dejaría ese código sin nombre en la pantalla.
 *
 * IDEMPOTENTE por construcción: la clave de conflicto es `(countrycode, code)`
 * en los tres niveles. Correrlo dos veces seguidas deja exactamente las mismas
 * filas y los mismos conteos — lo verifica `api/tests/geo_catalog_test.php`.
 *
 * ── El orden NO es un detalle ────────────────────────────────────────────
 *
 * Departamentos → distritos → ciudades, y en ese orden por las FK de la mig
 * 207: un distrito exige su departamento y una ciudad exige su distrito. Las
 * FK son deliberadas — una ciudad huérfana es una ciudad que la cascada nunca
 * podría mostrar, y es mucho mejor descubrirlo acá que en el alta fiscal de un
 * comercio.
 *
 * ── De dónde sale cada nivel ─────────────────────────────────────────────
 *
 *   - Departamentos: del endpoint propio (18 filas) MÁS los que vengan
 *     anidados en las ciudades. La unión y no solo el endpoint: si el
 *     catálogo de ciudades menciona un departamento que el endpoint no listó,
 *     sin agregarlo la FK del distrito tiraría la sincronización entera.
 *   - Distritos: SOLO de lo anidado en la ciudad. `GET /api/District/get`
 *     responde HTTP 500 del lado del proveedor (verificado 2026-09-08), y la
 *     ciudad ya trae el distrito completo, así que no es una degradación.
 *   - Ciudades: del endpoint de ciudades.
 *
 * ── Nada hardcodeado a un país ───────────────────────────────────────────
 *
 * `countrycode` viene del dato (`CountryCode`). Una fila SIN país no se
 * inventa ni se completa con un default: se saltea y se cuenta en
 * `skippedNoCountry`, que sale en el resultado del job. Es la única forma de
 * que "el catálogo quedó raro" sea un número visible y no un domicilio fiscal
 * mal declarado.
 */
final class GeoCatalogSync
{
    private GeoCatalogSource $source;

    public function __construct(?GeoCatalogSource $source = null)
    {
        $this->source = $source ?? new FactomateGeoSource();
    }

    /**
     * Corre la sincronización completa.
     *
     * @return array{departments:int,districts:int,cities:int,deactivated:int,skippedNoCountry:int,countries:array<int,string>}
     */
    public function run(): array
    {
        $rawDepartments = $this->source->departments();
        $rawCities      = $this->source->cities();

        $skipped = 0;

        // ── 1. Departamentos: los del endpoint + los anidados en la ciudad ──
        $departments = [];
        foreach ($rawDepartments as $row) {
            $norm = self::normalizeDepartment($row);
            if ($norm === null) {
                $skipped++;
                continue;
            }
            $departments[$norm['countrycode'] . ':' . $norm['code']] = $norm;
        }

        $districts = [];
        $cities    = [];
        foreach ($rawCities as $row) {
            // `Disctrict` — sí, con la falta de ortografía de la API. Ver
            // FactomateProvider::cities().
            $district = $row['Disctrict'] ?? $row['District'] ?? null;
            $district = is_array($district) ? $district : [];
            $parent   = $district['Department'] ?? null;
            $parent   = is_array($parent) ? $parent : [];

            $normParent = self::normalizeDepartment($parent);
            if ($normParent === null) {
                // Sin país no hay clave: la ciudad, su distrito y su
                // departamento se saltean juntos. No se "hereda" el país de
                // otra fila: sería adivinar en qué país está una ciudad.
                $skipped++;
                continue;
            }
            $country = $normParent['countrycode'];
            $departments[$country . ':' . $normParent['code']] ??= $normParent;

            $normDistrict = self::normalizeDistrict($district, $country, $normParent['code']);
            if ($normDistrict === null) {
                $skipped++;
                continue;
            }
            $districts[$country . ':' . $normDistrict['code']] = $normDistrict;

            $normCity = self::normalizeCity($row, $country, $normDistrict['code'], $normParent['code']);
            if ($normCity === null) {
                $skipped++;
                continue;
            }
            $cities[$country . ':' . $normCity['code']] = $normCity;
        }

        // ── 2. Upsert, padres primero (las FK de la mig 207 lo exigen) ──
        $startedAt = self::now();

        foreach ($departments as $d) {
            $this->upsertDepartment($d);
        }
        foreach ($districts as $d) {
            $this->upsertDistrict($d);
        }
        foreach ($cities as $c) {
            $this->upsertCity($c);
        }

        // ── 3. Bajas: lo que el origen ya no menciona ──
        //
        // Se marca inactivo, no se borra (ver docblock). El criterio es
        // "no lo tocó ESTA corrida": `synced_at` quedó atrás. Acotado a los
        // países que la corrida efectivamente trajo, para que una corrida
        // parcial de un país no apague el catálogo de otro.
        $countries   = array_values(array_unique(array_map(
            static fn (array $d): string => $d['countrycode'],
            $departments
        )));
        $deactivated = $countries === [] ? 0 : $this->deactivateStale($countries, $startedAt);

        return [
            'departments'      => count($departments),
            'districts'        => count($districts),
            'cities'           => count($cities),
            'deactivated'      => $deactivated,
            'skippedNoCountry' => $skipped,
            'countries'        => $countries,
        ];
    }

    // ── Normalización ───────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $row
     * @return array{countrycode:string,code:int,name:string,searchname:string,providerid:?int,active:bool}|null
     */
    private static function normalizeDepartment(array $row): ?array
    {
        $country = self::str($row['CountryCode'] ?? $row['countryCode'] ?? '');
        $code    = self::intOrNull($row['Identifier'] ?? $row['identifier'] ?? null);
        if ($country === '' || $code === null) {
            return null;
        }
        return [
            'countrycode' => strtoupper($country),
            'code'        => $code,
            'name'        => self::str($row['Name'] ?? $row['name'] ?? ''),
            'searchname'  => self::searchName(self::str($row['Name'] ?? $row['name'] ?? '')),
            'providerid'  => self::intOrNull($row['Id'] ?? $row['id'] ?? null),
            'active'      => !self::truthy($row['Deleted'] ?? $row['deleted'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{countrycode:string,code:int,name:string,searchname:string,departmentcode:int,providerid:?int,active:bool}|null
     */
    private static function normalizeDistrict(array $row, string $country, int $departmentCode): ?array
    {
        $code = self::intOrNull($row['Identifier'] ?? $row['identifier'] ?? null);
        if ($code === null) {
            return null;
        }
        return [
            'countrycode'    => $country,
            'code'           => $code,
            'name'           => self::str($row['Name'] ?? $row['name'] ?? ''),
            'searchname'     => self::searchName(self::str($row['Name'] ?? $row['name'] ?? '')),
            'departmentcode' => $departmentCode,
            'providerid'     => self::intOrNull($row['Id'] ?? $row['id'] ?? null),
            'active'         => !self::truthy($row['Deleted'] ?? $row['deleted'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{countrycode:string,code:int,name:string,searchname:string,districtcode:int,departmentcode:int,providerid:?int,active:bool}|null
     */
    private static function normalizeCity(array $row, string $country, int $districtCode, int $departmentCode): ?array
    {
        $code = self::intOrNull($row['Identifier'] ?? $row['identifier'] ?? null);
        if ($code === null) {
            return null;
        }
        return [
            'countrycode'    => $country,
            'code'           => $code,
            'name'           => self::str($row['Name'] ?? $row['name'] ?? ''),
            'searchname'     => self::searchName(self::str($row['Name'] ?? $row['name'] ?? '')),
            'districtcode'   => $districtCode,
            'departmentcode' => $departmentCode,
            'providerid'     => self::intOrNull($row['Id'] ?? $row['id'] ?? null),
            'active'         => !self::truthy($row['Deleted'] ?? $row['deleted'] ?? false),
        ];
    }

    // ── Upserts ─────────────────────────────────────────────────────────

    /** @param array<string,mixed> $d */
    private function upsertDepartment(array $d): void
    {
        ncmExecute(
            'INSERT INTO geo_department (countrycode, code, name, searchname, providerid, active, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, now())
             ON CONFLICT (countrycode, code) DO UPDATE
                SET name = EXCLUDED.name,
                    searchname = EXCLUDED.searchname,
                    providerid = EXCLUDED.providerid,
                    active = EXCLUDED.active,
                    synced_at = now()',
            [$d['countrycode'], $d['code'], $d['name'], $d['searchname'], $d['providerid'], $d['active'] ? 't' : 'f']
        );
    }

    /** @param array<string,mixed> $d */
    private function upsertDistrict(array $d): void
    {
        ncmExecute(
            'INSERT INTO geo_district (countrycode, code, name, searchname, departmentcode, providerid, active, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, now())
             ON CONFLICT (countrycode, code) DO UPDATE
                SET name = EXCLUDED.name,
                    searchname = EXCLUDED.searchname,
                    departmentcode = EXCLUDED.departmentcode,
                    providerid = EXCLUDED.providerid,
                    active = EXCLUDED.active,
                    synced_at = now()',
            [
                $d['countrycode'], $d['code'], $d['name'], $d['searchname'],
                $d['departmentcode'], $d['providerid'], $d['active'] ? 't' : 'f',
            ]
        );
    }

    /** @param array<string,mixed> $c */
    private function upsertCity(array $c): void
    {
        ncmExecute(
            'INSERT INTO geo_city (countrycode, code, name, searchname, districtcode, departmentcode, providerid, active, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, now())
             ON CONFLICT (countrycode, code) DO UPDATE
                SET name = EXCLUDED.name,
                    searchname = EXCLUDED.searchname,
                    districtcode = EXCLUDED.districtcode,
                    departmentcode = EXCLUDED.departmentcode,
                    providerid = EXCLUDED.providerid,
                    active = EXCLUDED.active,
                    synced_at = now()',
            [
                $c['countrycode'], $c['code'], $c['name'], $c['searchname'],
                $c['districtcode'], $c['departmentcode'], $c['providerid'], $c['active'] ? 't' : 'f',
            ]
        );
    }

    /**
     * Marca inactivo lo que el origen ya no menciona. Hijos primero por
     * simetría con el upsert (no hay FK que lo exija en un UPDATE, pero deja
     * el catálogo consistente si algo corta a mitad).
     *
     * @param array<int,string> $countries
     */
    private function deactivateStale(array $countries, string $startedAt): int
    {
        $total = 0;
        foreach (['geo_city', 'geo_district', 'geo_department'] as $table) {
            $affected = ncmExecute(
                "UPDATE $table SET active = FALSE
                  WHERE active = TRUE
                    AND countrycode = ANY(?::text[])
                    AND synced_at < ?::timestamptz",
                [self::pgArray($countries), $startedAt]
            );
            $total += is_int($affected) ? $affected : 0;
        }
        return $total;
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** Marca temporal del arranque, leída de Postgres para comparar contra `now()` del mismo reloj. */
    private static function now(): string
    {
        global $db;
        return (string) $db->GetOne('SELECT now()::text');
    }

    /**
     * Literal de array de Postgres. Los códigos de país son `[A-Z0-9]` por
     * construcción (salen de `strtoupper` sobre el dato del proveedor), pero
     * el escape va igual: un literal de array armado por concatenación sin
     * comillas es una inyección esperando el primer dato raro.
     *
     * @param array<int,string> $values
     */
    private static function pgArray(array $values): string
    {
        $quoted = array_map(
            static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"',
            $values
        );
        return '{' . implode(',', $quoted) . '}';
    }

    /**
     * Nombre normalizado para buscar: minúsculas y sin acentos. Se calcula
     * acá y se guarda como columna porque `unaccent()` no es IMMUTABLE y no
     * se puede indexar directo (ver mig 207).
     */
    public static function searchName(string $name): string
    {
        $lower = mb_strtolower(trim($name), 'UTF-8');
        $map = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
            // Vocales nasales y puso: aparecen en nombres reales de ciudades
            // (Ñemby, Yvyra'i). Sin esto, buscar "yvyrai" no encontraría
            // "Yvyra'i". `ã` y `õ` ya están arriba en el grupo latino.
            'ẽ' => 'e', 'ĩ' => 'i', 'ũ' => 'u', 'ỹ' => 'y',
            "'" => '', '’' => '',
        ];
        return strtr($lower, $map);
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private static function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        return is_numeric($v) ? (int) $v : null;
    }

    /** `Deleted` puede llegar como bool, como 0/1 o como 't'/'f' según el driver. */
    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }
}

<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Carga el catálogo geográfico fiscal (mig 207) desde su fuente.
 *
 * ── De dónde sale el dato ────────────────────────────────────────────────
 *
 * De `SifenGeoSource`: el catálogo de la SET que FE-PY —el motor propio de
 * facturación electrónica de Punto— usa para VALIDAR los códigos antes de
 * armar el XML. Es un SEED versionado en el repo, así que la carga es
 * instantánea y no depende de que ninguna red esté arriba. La fuente se
 * inyecta (interfaz `GeoCatalogSource`) para que el arnés pueda verificar
 * idempotencia y jerarquía con un catálogo mínimo.
 *
 * ── Qué hace y qué NO ────────────────────────────────────────────────────
 *
 * Carga los tres niveles y hace UPSERT. No borra NUNCA: una fila que la
 * fuente dejó de mencionar se marca `active = false` y se conserva, porque
 * puede ser la ciudad que un comercio ya tiene guardada en su domicilio
 * fiscal y hacerla desaparecer dejaría ese código sin nombre en la pantalla.
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
 * ── Huérfanos: se saltean y se CUENTAN ───────────────────────────────────
 *
 * Un distrito cuyo departamento la fuente no declara, o una ciudad cuyo
 * distrito no existe, no se guardan y suben el contador `skipped`, que sale
 * en el resultado del job. Nunca se les inventa un padre: un domicilio fiscal
 * colgado del departamento equivocado es un dato mal declarado ante la
 * autoridad tributaria. Que "el catálogo quedó raro" sea un número visible es
 * la única forma de enterarse.
 *
 * ── Nada hardcodeado a un país ───────────────────────────────────────────
 *
 * `countrycode` lo declara la FUENTE (`GeoCatalogSource::countryCode()`), no
 * el sync. Ver el docblock de la interfaz.
 *
 * ── De dónde salió cada fila ─────────────────────────────────────────────
 *
 * Cada upsert escribe `source` con la clave de la fuente. Antes la columna
 * existía pero nadie la escribía (vivía de su DEFAULT), así que una fila no
 * podía decir de qué catálogo salió su código — y dos catálogos que discrepan
 * en un código son exactamente un documento fiscal rechazado.
 */
final class GeoCatalogSync
{
    /**
     * Filas por sentencia en los upserts masivos. El catálogo entero son
     * ~7.000 filas: en lotes de 2.000 la carga son 6 sentencias en vez de
     * 7.000 round-trips, que es lo que la vuelve viable al arranque del
     * container.
     */
    private const CHUNK = 2000;

    private GeoCatalogSource $source;

    public function __construct(?GeoCatalogSource $source = null)
    {
        $this->source = $source ?? new SifenGeoSource();
    }

    /**
     * Corre la carga completa.
     *
     * @return array{source:string,countryCode:string,departments:int,districts:int,cities:int,deactivated:int,skipped:int}
     */
    public function run(): array
    {
        $sourceKey = $this->source->sourceKey();
        $country   = strtoupper(trim($this->source->countryCode()));
        if ($country === '') {
            throw new \RuntimeException(
                'La fuente del catálogo geográfico no declara país. Un catálogo sin país no se puede ' .
                'guardar: la clave única de los tres niveles es (countrycode, code).'
            );
        }

        $skipped = 0;

        // ── 1. Departamentos ──
        $departments = [];
        foreach ($this->source->departments() as $row) {
            $departments[$row['code']] = [
                'code'       => $row['code'],
                'name'       => $row['name'],
                'searchname' => self::searchName($row['name']),
            ];
        }

        // ── 2. Distritos: solo los que tienen su departamento ──
        $districts = [];
        foreach ($this->source->districts() as $row) {
            if (!isset($departments[$row['departmentCode']])) {
                $skipped++;
                continue;
            }
            $districts[$row['code']] = [
                'code'           => $row['code'],
                'name'           => $row['name'],
                'searchname'     => self::searchName($row['name']),
                'departmentcode' => $row['departmentCode'],
            ];
        }

        // ── 3. Ciudades: el departamento se DERIVA del distrito ──
        //
        // Denormalización deliberada de la mig 207 (permite listar las
        // ciudades de un departamento sin join). Se toma del distrito y no de
        // la fuente para que no pueda contradecir a la FK.
        $cities = [];
        foreach ($this->source->cities() as $row) {
            $parent = $districts[$row['districtCode']] ?? null;
            if ($parent === null) {
                $skipped++;
                continue;
            }
            $cities[$row['code']] = [
                'code'           => $row['code'],
                'name'           => $row['name'],
                'searchname'     => self::searchName($row['name']),
                'districtcode'   => $row['districtCode'],
                'departmentcode' => $parent['departmentcode'],
            ];
        }

        // ── 4. Upsert, padres primero (las FK de la mig 207 lo exigen) ──
        $startedAt = self::now();

        $this->upsertDepartments(array_values($departments), $country, $sourceKey);
        $this->upsertDistricts(array_values($districts), $country, $sourceKey);
        $this->upsertCities(array_values($cities), $country, $sourceKey);

        // ── 5. Bajas: lo que la fuente ya no menciona ──
        //
        // Se marca inactivo, no se borra (ver docblock). El criterio es "no lo
        // tocó ESTA corrida": `synced_at` quedó atrás. Acotado al país de la
        // fuente, para que cargar el catálogo de un país no apague el de otro.
        $deactivated = $this->deactivateStale($country, $startedAt);

        return [
            'source'      => $sourceKey,
            'countryCode' => $country,
            'departments' => count($departments),
            'districts'   => count($districts),
            'cities'      => count($cities),
            'deactivated' => $deactivated,
            'skipped'     => $skipped,
        ];
    }

    // ── Upserts masivos ─────────────────────────────────────────────────
    //
    // Un `unnest()` de arrays paralelos en vez de una sentencia por fila. El
    // catálogo se carga entero en cada corrida (es un archivo local, no una
    // llamada paginada), así que el costo estaba en los round-trips, no en el
    // dato.

    /** @param array<int,array{code:int,name:string,searchname:string}> $rows */
    private function upsertDepartments(array $rows, string $country, string $sourceKey): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            ncmExecute(
                'INSERT INTO geo_department (countrycode, code, name, searchname, source, active, synced_at)
                 SELECT ?, u.code, u.name, u.searchname, ?, TRUE, now()
                   FROM unnest(?::int[], ?::text[], ?::text[]) AS u(code, name, searchname)
                 ON CONFLICT (countrycode, code) DO UPDATE
                    SET name = EXCLUDED.name,
                        searchname = EXCLUDED.searchname,
                        source = EXCLUDED.source,
                        active = TRUE,
                        synced_at = now()',
                [
                    $country,
                    $sourceKey,
                    self::intArray(array_column($chunk, 'code')),
                    self::textArray(array_column($chunk, 'name')),
                    self::textArray(array_column($chunk, 'searchname')),
                ]
            );
        }
    }

    /** @param array<int,array{code:int,name:string,searchname:string,departmentcode:int}> $rows */
    private function upsertDistricts(array $rows, string $country, string $sourceKey): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            ncmExecute(
                'INSERT INTO geo_district (countrycode, code, name, searchname, departmentcode, source, active, synced_at)
                 SELECT ?, u.code, u.name, u.searchname, u.departmentcode, ?, TRUE, now()
                   FROM unnest(?::int[], ?::text[], ?::text[], ?::int[]) AS u(code, name, searchname, departmentcode)
                 ON CONFLICT (countrycode, code) DO UPDATE
                    SET name = EXCLUDED.name,
                        searchname = EXCLUDED.searchname,
                        departmentcode = EXCLUDED.departmentcode,
                        source = EXCLUDED.source,
                        active = TRUE,
                        synced_at = now()',
                [
                    $country,
                    $sourceKey,
                    self::intArray(array_column($chunk, 'code')),
                    self::textArray(array_column($chunk, 'name')),
                    self::textArray(array_column($chunk, 'searchname')),
                    self::intArray(array_column($chunk, 'departmentcode')),
                ]
            );
        }
    }

    /** @param array<int,array{code:int,name:string,searchname:string,districtcode:int,departmentcode:int}> $rows */
    private function upsertCities(array $rows, string $country, string $sourceKey): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            ncmExecute(
                'INSERT INTO geo_city (countrycode, code, name, searchname, districtcode, departmentcode, source, active, synced_at)
                 SELECT ?, u.code, u.name, u.searchname, u.districtcode, u.departmentcode, ?, TRUE, now()
                   FROM unnest(?::int[], ?::text[], ?::text[], ?::int[], ?::int[])
                        AS u(code, name, searchname, districtcode, departmentcode)
                 ON CONFLICT (countrycode, code) DO UPDATE
                    SET name = EXCLUDED.name,
                        searchname = EXCLUDED.searchname,
                        districtcode = EXCLUDED.districtcode,
                        departmentcode = EXCLUDED.departmentcode,
                        source = EXCLUDED.source,
                        active = TRUE,
                        synced_at = now()',
                [
                    $country,
                    $sourceKey,
                    self::intArray(array_column($chunk, 'code')),
                    self::textArray(array_column($chunk, 'name')),
                    self::textArray(array_column($chunk, 'searchname')),
                    self::intArray(array_column($chunk, 'districtcode')),
                    self::intArray(array_column($chunk, 'departmentcode')),
                ]
            );
        }
    }

    /**
     * Marca inactivo lo que la fuente ya no menciona. Hijos primero por
     * simetría con el upsert (no hay FK que lo exija en un UPDATE, pero deja
     * el catálogo consistente si algo corta a mitad).
     */
    private function deactivateStale(string $country, string $startedAt): int
    {
        $total = 0;
        foreach (['geo_city', 'geo_district', 'geo_department'] as $table) {
            $affected = ncmExecute(
                "UPDATE $table SET active = FALSE
                  WHERE active = TRUE
                    AND countrycode = ?
                    AND synced_at < ?::timestamptz",
                [$country, $startedAt]
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
     * Literal de array de enteros. Los valores ya son `int` de PHP, así que no
     * hay nada que escapar — el cast lo garantiza.
     *
     * @param array<int,int> $values
     */
    private static function intArray(array $values): string
    {
        return '{' . implode(',', array_map('intval', $values)) . '}';
    }

    /**
     * Literal de array de texto. Los nombres del catálogo traen apóstrofos
     * (`YBY YA'U`) y puntos; el escape va igual que en cualquier literal de
     * array: comillas dobles alrededor y `\` / `"` escapados.
     *
     * @param array<int,string> $values
     */
    private static function textArray(array $values): string
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
            // Vocales nasales y puso: aparecen en nombres reales del catálogo
            // de la SET (ÑEMBY, YBY YA'U). Sin esto, buscar "yby yau" no
            // encontraría "YBY YA'U". `ã` y `õ` ya están arriba en el grupo
            // latino.
            'ẽ' => 'e', 'ĩ' => 'i', 'ũ' => 'u', 'ỹ' => 'y',
            "'" => '', '’' => '',
        ];
        return strtr($lower, $map);
    }
}

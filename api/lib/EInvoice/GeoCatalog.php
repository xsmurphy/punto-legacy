<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Lecturas del catálogo geográfico fiscal (mig 207) — lo que alimenta el
 * selector en cascada del domicilio de los establecimientos.
 *
 * SIN `companyid` EN NINGUNA QUERY, y no es un olvido: el mapa de un país es
 * el mismo para todos los comercios (ver el docblock de la mig 207). No hay
 * dato de tenant que aislar acá; el aislamiento multi-tenant aplica a lo que
 * el comercio GUARDA con estos códigos, no al catálogo.
 *
 * Escrituras: ninguna. Las hace `GeoCatalogSync` desde el job.
 */
final class GeoCatalog
{
    /** Tope de filas por lectura. El buscador de ciudades manda de a poco; el resto son listas cortas. */
    private const MAX_LIMIT = 500;

    /**
     * Qué tan cargado está el catálogo. Es lo que decide si la pantalla puede
     * ofrecer los selects o tiene que degradar a los campos manuales.
     *
     * @return array{departments:int,districts:int,cities:int,syncedAt:?string,countries:array<int,string>}
     */
    public function status(): array
    {
        $row = ncmExecute(
            'SELECT (SELECT count(*) FROM geo_department WHERE active) AS departments,
                    (SELECT count(*) FROM geo_district   WHERE active) AS districts,
                    (SELECT count(*) FROM geo_city       WHERE active) AS cities,
                    (SELECT max(synced_at)::text FROM geo_department)  AS syncedat'
        );

        $countries = array_map(
            static fn ($r): string => (string) $r['countrycode'],
            ncmRows('SELECT DISTINCT countrycode FROM geo_department WHERE active ORDER BY countrycode')
        );

        return [
            'departments' => (int) ($row['departments'] ?? 0),
            'districts'   => (int) ($row['districts'] ?? 0),
            'cities'      => (int) ($row['cities'] ?? 0),
            'syncedAt'    => isset($row['syncedat']) && $row['syncedat'] !== null
                ? (string) $row['syncedat']
                : null,
            'countries'   => $countries,
        ];
    }

    /**
     * Departamentos de un país. Sin filtro de país devuelve TODOS: hoy hay
     * uno solo cargado y forzar al caller a saber cuál sería hardcodearlo.
     *
     * @return array<int,array{code:int,name:string,countryCode:string}>
     */
    public function departments(?string $countryCode = null): array
    {
        [$where, $params] = self::countryFilter($countryCode);
        return self::shape(ncmRows(
            "SELECT code, name, countrycode FROM geo_department
              WHERE active $where
              ORDER BY searchname",
            $params
        ));
    }

    /**
     * Distritos de un departamento. El departamento es OBLIGATORIO: la lista
     * completa de distritos no le sirve a nadie y la cascada siempre viene
     * de haber elegido uno.
     *
     * @return array<int,array{code:int,name:string,countryCode:string}>
     */
    public function districts(int $departmentCode, ?string $countryCode = null): array
    {
        [$where, $params] = self::countryFilter($countryCode);
        array_unshift($params, $departmentCode);
        return self::shape(ncmRows(
            "SELECT code, name, countrycode FROM geo_district
              WHERE active AND departmentcode = ? $where
              ORDER BY searchname",
            $params
        ));
    }

    /**
     * Ciudades, filtradas por distrito o por departamento, con búsqueda por
     * nombre opcional.
     *
     * Al menos uno de los dos padres es obligatorio: son ~6.400 filas y
     * devolverlas sin filtro sería mandarle el catálogo entero al browser en
     * cada apertura del formulario.
     *
     * `$search` compara contra `searchname` (minúsculas sin acentos), así que
     * "yvyrai" encuentra "Yvyra'i" y "capiata" encuentra "Capiatá" — que es
     * exactamente lo que alguien tipea cuando busca su ciudad.
     *
     * @return array<int,array{code:int,name:string,countryCode:string}>
     */
    public function cities(
        ?int $districtCode,
        ?int $departmentCode,
        string $search = '',
        ?string $countryCode = null,
        int $limit = 100
    ): array {
        if ($districtCode === null && $departmentCode === null) {
            throw new \InvalidArgumentException('Falta el distrito o el departamento para listar ciudades.');
        }

        $params = [];
        $where  = '';
        if ($districtCode !== null) {
            $where .= ' AND districtcode = ?';
            $params[] = $districtCode;
        } else {
            $where .= ' AND departmentcode = ?';
            $params[] = $departmentCode;
        }

        [$countryWhere, $countryParams] = self::countryFilter($countryCode);
        $where .= ' ' . $countryWhere;
        $params = array_merge($params, $countryParams);

        $search = trim($search);
        if ($search !== '') {
            // LIKE con el término escapado: `%` y `_` tipeados por el usuario
            // son literales, no comodines. El `\` es el escape por defecto de
            // LIKE en Postgres.
            $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], GeoCatalogSync::searchName($search));
            $where .= ' AND searchname LIKE ?';
            $params[] = '%' . $needle . '%';
        }

        $params[] = max(1, min($limit, self::MAX_LIMIT));

        return self::shape(ncmRows(
            "SELECT code, name, countrycode FROM geo_city
              WHERE active $where
              ORDER BY searchname
              LIMIT ?",
            $params
        ));
    }

    /**
     * Resuelve un trío de códigos ya guardado a sus nombres, INCLUIDAS las
     * filas dadas de baja.
     *
     * Es lo que permite que un domicilio fiscal cargado hace meses siga
     * mostrándose con nombre y no como un número suelto. Sin esto, la
     * pantalla nueva sería peor que la vieja para los que ya cargaron algo.
     *
     * @return array{department:?string,district:?string,city:?string}
     */
    public function resolve(?int $departmentCode, ?int $districtCode, ?int $cityCode, ?string $countryCode = null): array
    {
        return [
            'department' => self::nameOf('geo_department', $departmentCode, $countryCode),
            'district'   => self::nameOf('geo_district', $districtCode, $countryCode),
            'city'       => self::nameOf('geo_city', $cityCode, $countryCode),
        ];
    }

    private static function nameOf(string $table, ?int $code, ?string $countryCode): ?string
    {
        if ($code === null) {
            return null;
        }
        [$where, $params] = self::countryFilter($countryCode);
        array_unshift($params, $code);
        // Sin `WHERE active`: resolver un código guardado tiene que funcionar
        // aunque el origen lo haya dado de baja.
        $row = ncmExecute("SELECT name FROM $table WHERE code = ? $where LIMIT 1", $params);
        return $row ? (string) $row['name'] : null;
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function countryFilter(?string $countryCode): array
    {
        $code = strtoupper(trim((string) $countryCode));
        return $code === '' ? ['', []] : ['AND countrycode = ?', [$code]];
    }

    /**
     * @param array<int,\CaseInsensitiveArray> $rows
     * @return array<int,array{code:int,name:string,countryCode:string}>
     */
    private static function shape(array $rows): array
    {
        return array_map(
            static fn ($r): array => [
                'code'        => (int) $r['code'],
                'name'        => (string) $r['name'],
                'countryCode' => (string) $r['countrycode'],
            ],
            $rows
        );
    }
}

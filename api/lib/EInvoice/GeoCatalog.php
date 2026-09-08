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
     * Resuelve NOMBRES a códigos fiscales, con toda su jerarquía.
     *
     * ── Para qué existe ──────────────────────────────────────────────────
     *
     * La pantalla del alta fiscal resuelve esto en cascada (elegís
     * departamento, después distrito, después ciudad) y no necesita buscar por
     * nombre suelto. El asistente sí: el comercio le escribe "estamos en San
     * Lorenzo" y de ahí tienen que salir los tres códigos numéricos que van al
     * documento electrónico. Sin esta lectura, la única salida del bot era
     * pedirle al usuario códigos que nadie sabe de memoria.
     *
     * ── Nunca elige por su cuenta ────────────────────────────────────────
     *
     * Los homónimos no son un caso raro: en el catálogo de la SET hay 666
     * nombres de ciudad repetidos ("SAN ANTONIO" existe 35 veces, en
     * departamentos distintos). Elegir uno sería declarar el domicilio fiscal
     * del comercio en el departamento equivocado ante la autoridad tributaria.
     * Así que devuelve TODAS las candidatas con su jerarquía, y `resolved`
     * queda en `null` salvo que haya UNA sola: quién decide es el usuario.
     *
     * ── Exacto primero, y solo si no hay, parcial ────────────────────────
     *
     * En dos fases y no en una consulta rankeada: si "CAPIATA" existe tal
     * cual, mezclarla con los diez nombres que la contienen convierte una
     * respuesta inequívoca en una pregunta. Y al revés, la fase parcial es
     * imprescindible: en el catálogo de la SET Asunción se llama "ASUNCION
     * (DISTRITO)", así que quien escriba "Asunción" no matchea exacto NADA.
     *
     * `$district` y `$department` son PISTAS para acotar, no lo que se
     * resuelve: comparan por substring siempre. El nivel que se resuelve es el
     * más profundo que el caller nombró.
     *
     * @return array{level:string,candidates:array<int,array<string,mixed>>,resolved:?array<string,mixed>,matchType:?string,truncated:bool}
     */
    public function lookup(
        ?string $city,
        ?string $district,
        ?string $department,
        ?string $countryCode = null,
        int $limit = 25
    ): array {
        $city       = trim((string) $city);
        $district   = trim((string) $district);
        $department = trim((string) $department);

        if ($city === '' && $district === '' && $department === '') {
            throw new \InvalidArgumentException(
                'Pasá al menos un nombre de ciudad, distrito o departamento para resolver.'
            );
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));

        if ($city !== '') {
            $level = 'city';
        } elseif ($district !== '') {
            $level = 'district';
        } else {
            $level = 'department';
        }

        $needle = GeoCatalogSync::searchName($level === 'city' ? $city : ($level === 'district' ? $district : $department));

        // Exacto primero; parcial solo si el exacto no encontró nada.
        $rows      = $this->lookupRows($level, $needle, true, $district, $department, $countryCode, $limit + 1);
        $matchType = 'exact';
        if ($rows === []) {
            $rows      = $this->lookupRows($level, $needle, false, $district, $department, $countryCode, $limit + 1);
            $matchType = 'partial';
        }

        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        $candidates = array_map(static fn ($r): array => self::candidate($r), $rows);

        return [
            'level'      => $level,
            'candidates' => $candidates,
            // UNA sola candidata es el único caso en que esto no es ambiguo.
            'resolved'   => count($candidates) === 1 ? $candidates[0] : null,
            'matchType'  => $candidates === [] ? null : $matchType,
            'truncated'  => $truncated,
        ];
    }

    /**
     * @return array<int,\CaseInsensitiveArray>
     */
    private function lookupRows(
        string $level,
        string $needle,
        bool $exact,
        string $district,
        string $department,
        ?string $countryCode,
        int $limit
    ): array {
        // `%` y `_` tipeados por el usuario son literales, no comodines.
        $escaped = static fn (string $v): string => str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            GeoCatalogSync::searchName($v)
        );

        $params = [];
        $where  = '';

        if ($level === 'city') {
            $from = 'geo_city c
                       JOIN geo_district d   ON d.countrycode = c.countrycode AND d.code = c.districtcode
                       JOIN geo_department p ON p.countrycode = d.countrycode AND p.code = d.departmentcode';
            $select = 'c.code AS citycode, c.name AS cityname, c.searchname AS target,
                       d.code AS districtcode, d.name AS districtname,
                       p.code AS departmentcode, p.name AS departmentname, c.countrycode';
            $self   = 'c';
            $active = 'c.active AND d.active AND p.active';
        } elseif ($level === 'district') {
            $from = 'geo_district d
                       JOIN geo_department p ON p.countrycode = d.countrycode AND p.code = d.departmentcode';
            $select = 'NULL::int AS citycode, NULL::text AS cityname, d.searchname AS target,
                       d.code AS districtcode, d.name AS districtname,
                       p.code AS departmentcode, p.name AS departmentname, d.countrycode';
            $self   = 'd';
            $active = 'd.active AND p.active';
        } else {
            $from = 'geo_department p';
            $select = 'NULL::int AS citycode, NULL::text AS cityname, p.searchname AS target,
                       NULL::int AS districtcode, NULL::text AS districtname,
                       p.code AS departmentcode, p.name AS departmentname, p.countrycode';
            $self   = 'p';
            $active = 'p.active';
        }

        if ($exact) {
            $where   .= " AND $self.searchname = ?";
            $params[] = $needle;
        } else {
            $where   .= " AND $self.searchname LIKE ?";
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
        }

        // Pistas de acotamiento: siempre por substring, nunca deciden el nivel.
        if ($level === 'city' && $district !== '') {
            $where   .= ' AND d.searchname LIKE ?';
            $params[] = '%' . $escaped($district) . '%';
        }
        if ($level !== 'department' && $department !== '') {
            $where   .= ' AND p.searchname LIKE ?';
            $params[] = '%' . $escaped($department) . '%';
        }

        $country = strtoupper(trim((string) $countryCode));
        if ($country !== '') {
            $where   .= " AND $self.countrycode = ?";
            $params[] = $country;
        }

        // Prefijo antes que substring: quien escribe "Asunción" espera ver
        // "ASUNCION (DISTRITO)" antes que "STA.ASUNCION".
        $params[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
        $params[] = $limit;

        return ncmRows(
            "SELECT $select
               FROM $from
              WHERE $active $where
              ORDER BY ($self.searchname LIKE ?) DESC, $self.searchname
              LIMIT ?",
            $params
        );
    }

    /**
     * Una candidata con su jerarquía COMPLETA. Siempre trae el departamento —
     * es lo que distingue a dos ciudades homónimas — y los niveles que no
     * aplican van en `null`, no ausentes: un campo que a veces no está se lee
     * como un dato que se perdió.
     *
     * @return array<string,mixed>
     */
    private static function candidate(\CaseInsensitiveArray $r): array
    {
        $node = static fn ($code, $name): ?array => $code === null
            ? null
            : ['code' => (int) $code, 'name' => (string) $name];

        return [
            'countryCode' => (string) $r['countrycode'],
            'department'  => $node($r['departmentcode'], $r['departmentname']),
            'district'    => $node($r['districtcode'], $r['districtname']),
            'city'        => $node($r['citycode'], $r['cityname']),
        ];
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

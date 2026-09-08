<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de cualquier otra cosa (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del CATÁLOGO GEOGRÁFICO FISCAL (migs 207/208) — carga y lectura.
 *
 * ── Qué problema cubre ───────────────────────────────────────────────────
 *
 * La pantalla de facturación electrónica pedía departamento, distrito y
 * ciudad como códigos numéricos tipeados a mano. Ahora los ofrece en cascada
 * desde una copia local del catálogo de SIFEN, y el asistente los resuelve a
 * partir del nombre de la ciudad. Todo el valor de eso depende de tres cosas
 * que este arnés verifica: que la carga sea IDEMPOTENTE (corre en cada boot
 * del container y no puede duplicar ni borrar catálogo), que la JERARQUÍA
 * quede bien armada (una ciudad cuelga de su distrito y éste de su
 * departamento — si eso falla, la cascada muestra ciudades del departamento
 * equivocado y el comercio declara un domicilio fiscal falso ante la
 * autoridad tributaria), y que la resolución por NOMBRE nunca elija sola
 * entre homónimas.
 *
 * ── Por qué NO lee el seed real ──────────────────────────────────────────
 *
 * La fuente se inyecta: `GeoCatalogSync` recibe un `GeoCatalogSource` en
 * memoria con un catálogo mínimo. Cargar las 7.056 filas del seed de SIFEN
 * para verificar idempotencia no probaría nada más y haría que el arnés pise
 * el catálogo real de la base contra la que corra.
 *
 * ── Qué cubre ────────────────────────────────────────────────────────────
 *
 *   (A) IDEMPOTENCIA: dos corridas seguidas dejan los mismos conteos en la
 *       base y el mismo resultado.
 *   (B) JERARQUÍA: la ciudad cuelga de su distrito, y ese distrito de
 *       CAPITAL (departamento, código 1 del catálogo de la SET). Y el
 *       departamento de la ciudad se DERIVA del distrito, no de la fuente.
 *   (C) FILTRO POR PADRE: los distritos de un departamento son los suyos, las
 *       ciudades de un distrito son las suyas, y ninguna se filtra de otro.
 *   (D) BÚSQUEDA sin acentos: "capiata" encuentra "Capiatá".
 *   (E) BAJAS: lo que la fuente deja de mencionar queda inactivo (fuera del
 *       selector) pero SE CONSERVA, así un código ya guardado por un comercio
 *       se sigue resolviendo a su nombre.
 *   (F) MULTI-PAÍS: un segundo país convive sin contaminar las listas del
 *       primero — el modelo no está atado a un solo país aunque hoy el
 *       catálogo sea de uno.
 *   (G) HUÉRFANOS: una fila cuyo padre la fuente no declara se saltea y se
 *       CUENTA, en vez de guardarse colgando de un padre inventado.
 *   (H) SOURCE: cada fila dice de qué catálogo salió su código.
 *   (I) LOOKUP por nombre: resuelve la jerarquía completa, nunca elige entre
 *       homónimas, y encuentra por substring lo que no matchea exacto.
 *
 * Uso (necesita Postgres migrado — ver `run_geo_catalog_test.sh`):
 *   POSTGRES_HOST=... php -d variables_order=EGPCS api/tests/geo_catalog_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\EInvoice\GeoCatalog;
use Punto\Api\EInvoice\GeoCatalogSource;
use Punto\Api\EInvoice\GeoCatalogSync;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "  OK   $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label\n";
    echo "       $detail\n";
}

/**
 * Fuente en memoria con el shape del DOMINIO (ver `GeoCatalogSource`): tres
 * niveles planos, el país declarado por la fuente entera.
 */
final class FakeGeoSource implements GeoCatalogSource
{
    /**
     * @param array<int,array{code:int,name:string}> $departments
     * @param array<int,array{code:int,name:string,departmentCode:int}> $districts
     * @param array<int,array{code:int,name:string,districtCode:int}> $cities
     */
    public function __construct(
        private string $country,
        private array $departments,
        private array $districts,
        private array $cities,
    ) {
    }

    public function sourceKey(): string
    {
        return 'fixture';
    }

    public function countryCode(): string
    {
        return $this->country;
    }

    public function departments(): array
    {
        return $this->departments;
    }

    public function districts(): array
    {
        return $this->districts;
    }

    public function cities(): array
    {
        return $this->cities;
    }
}

// ── Códigos del fixture ────────────────────────────────────────────────────
const PY = 'PY';
const ZZ = 'ZZ';                 // país sintético, para el caso multi-país
const DEP_CAPITAL = 1;           // código real del catálogo de la SET
const DEP_CENTRAL = 9101;        // fixture
const DIS_ASUNCION = 9111;       // fixture
const DIS_CAPIATA = 9112;        // fixture
const CIU_ASUNCION = 1;          // código real del catálogo de la SET
const CIU_CAPIATA = 9122;        // fixture
const CIU_EFIMERA = 9123;        // fixture — se da de baja en el caso (E)
const CIU_HOMONIMA = 9124;       // fixture — homónima de CIU_CAPIATA, otro departamento
const DIS_HUERFANO = 9131;       // fixture — su departamento no se declara
const CIU_HUERFANA = 9141;       // fixture — su distrito no se declara
const DEP_ZZ = 9001;
const DIS_ZZ = 9011;
const CIU_ZZ = 9021;

/** @return array{code:int,name:string} */
function dep(int $code, string $name): array
{
    return ['code' => $code, 'name' => $name];
}

/** @return array{code:int,name:string,departmentCode:int} */
function dis(int $code, string $name, int $departmentCode): array
{
    return ['code' => $code, 'name' => $name, 'departmentCode' => $departmentCode];
}

/** @return array{code:int,name:string,districtCode:int} */
function ciu(int $code, string $name, int $districtCode): array
{
    return ['code' => $code, 'name' => $name, 'districtCode' => $districtCode];
}

$departments = [
    dep(DEP_CAPITAL, 'CAPITAL'),
    dep(DEP_CENTRAL, 'CENTRAL FIXTURE'),
];

$districts = [
    dis(DIS_ASUNCION, 'ASUNCION (DISTRITO)', DEP_CAPITAL),
    dis(DIS_CAPIATA, 'CAPIATA DISTRITO', DEP_CENTRAL),
    // (G) su departamento no está declarado: se saltea, no se le inventa uno.
    dis(DIS_HUERFANO, 'DISTRITO HUERFANO', 9999),
];

$cities = [
    ciu(CIU_ASUNCION, 'ASUNCION (DISTRITO)', DIS_ASUNCION),
    ciu(CIU_CAPIATA, 'Capiatá', DIS_CAPIATA),
    ciu(CIU_EFIMERA, 'CIUDAD EFIMERA', DIS_CAPIATA),
    // (I) mismo NOMBRE que CIU_CAPIATA pero en otro departamento: es el caso
    // que obliga al lookup a preguntar en vez de elegir.
    ciu(CIU_HOMONIMA, 'Capiatá', DIS_ASUNCION),
    // (G) su distrito no está declarado: se saltea.
    ciu(CIU_HUERFANA, 'CIUDAD HUERFANA', 9998),
];

$zzDepartments = [dep(DEP_ZZ, 'PROVINCIA ZZ')];
$zzDistricts   = [dis(DIS_ZZ, 'DISTRITO ZZ', DEP_ZZ)];
$zzCities      = [ciu(CIU_ZZ, 'CIUDAD ZZ', DIS_ZZ)];

/** Limpieza: SOLO los códigos que este arnés escribió. Hijos primero (FK). */
function cleanup(): void
{
    ncmExecute('DELETE FROM geo_city WHERE code = ANY(?::int[]) AND countrycode = ANY(?::text[])', [
        '{' . implode(',', [CIU_ASUNCION, CIU_CAPIATA, CIU_EFIMERA, CIU_HOMONIMA, CIU_HUERFANA, CIU_ZZ]) . '}',
        '{"' . PY . '","' . ZZ . '"}',
    ]);
    ncmExecute('DELETE FROM geo_district WHERE code = ANY(?::int[]) AND countrycode = ANY(?::text[])', [
        '{' . implode(',', [DIS_ASUNCION, DIS_CAPIATA, DIS_HUERFANO, DIS_ZZ]) . '}',
        '{"' . PY . '","' . ZZ . '"}',
    ]);
    ncmExecute('DELETE FROM geo_department WHERE code = ANY(?::int[]) AND countrycode = ANY(?::text[])', [
        '{' . implode(',', [DEP_CAPITAL, DEP_CENTRAL, DEP_ZZ]) . '}',
        '{"' . PY . '","' . ZZ . '"}',
    ]);
}

// Arranque en limpio: si una corrida anterior murió a mitad, sus filas no
// pueden hacer pasar (ni fallar) esta.
cleanup();

$catalog = new GeoCatalog();

/** El catálogo PY del fixture con la lista de ciudades que se le pase (el caso (E) le saca una). */
$pySource = static fn (array $c): FakeGeoSource => new FakeGeoSource(PY, $departments, $districts, $c);

echo "\n=== (A) Idempotencia: dos corridas dejan el mismo catálogo ===\n";

$first  = (new GeoCatalogSync($pySource($cities)))->run();
$countAfterFirst = countRows();

$second = (new GeoCatalogSync($pySource($cities)))->run();
$countAfterSecond = countRows();

check(
    'la primera corrida cargó los 3 niveles',
    $first['departments'] === 2 && $first['districts'] === 2 && $first['cities'] === 4,
    'resultado: ' . json_encode($first),
    $failures,
    $checks
);

check(
    'la segunda corrida NO duplicó ni borró filas',
    $countAfterFirst === $countAfterSecond,
    'antes: ' . json_encode($countAfterFirst) . ' | después: ' . json_encode($countAfterSecond),
    $failures,
    $checks
);

check(
    'la segunda corrida no dio de baja nada (nada dejó de mencionarse)',
    $second['deactivated'] === 0,
    'deactivated=' . $second['deactivated'],
    $failures,
    $checks
);

echo "\n=== (G) Una fila sin padre declarado se saltea, no se le inventa uno ===\n";

check(
    'el distrito y la ciudad huérfanos quedaron fuera y se contaron',
    $first['skipped'] === 2,
    'skipped=' . $first['skipped'],
    $failures,
    $checks
);

check(
    'y no se guardaron colgando de un padre inventado',
    ncmExecute('SELECT count(*) AS n FROM geo_district WHERE code = ?', [DIS_HUERFANO])['n'] == 0
        && ncmExecute('SELECT count(*) AS n FROM geo_city WHERE code = ?', [CIU_HUERFANA])['n'] == 0,
    'quedó guardada alguna fila huérfana',
    $failures,
    $checks
);

echo "\n=== (B) Jerarquía: la ciudad → su distrito → CAPITAL ===\n";

$asuncion = ncmExecute(
    'SELECT c.name AS city, c.districtcode, c.departmentcode AS citydepartmentcode,
            d.name AS district, d.departmentcode, p.name AS department
       FROM geo_city c
       JOIN geo_district d   ON d.countrycode = c.countrycode AND d.code = c.districtcode
       JOIN geo_department p ON p.countrycode = d.countrycode AND p.code = d.departmentcode
      WHERE c.countrycode = ? AND c.code = ?',
    [PY, CIU_ASUNCION]
);

check(
    'la ciudad cuelga de su distrito y ese distrito de CAPITAL (código 1)',
    $asuncion
        && (string) $asuncion['city'] === 'ASUNCION (DISTRITO)'
        && (int) $asuncion['districtcode'] === DIS_ASUNCION
        && (int) $asuncion['departmentcode'] === DEP_CAPITAL
        && (string) $asuncion['department'] === 'CAPITAL',
    'fila: ' . ($asuncion
        ? implode(' | ', [
            'city=' . (string) $asuncion['city'],
            'districtcode=' . (string) $asuncion['districtcode'],
            'departmentcode=' . (string) $asuncion['departmentcode'],
            'department=' . (string) $asuncion['department'],
        ])
        : 'sin fila — la ciudad no cuelga de ningún distrito'),
    $failures,
    $checks
);

check(
    'el departamento denormalizado de la ciudad SALE del distrito, no puede contradecirlo',
    $asuncion && (int) $asuncion['citydepartmentcode'] === DEP_CAPITAL,
    'geo_city.departmentcode=' . ($asuncion ? (string) $asuncion['citydepartmentcode'] : 'sin fila'),
    $failures,
    $checks
);

echo "\n=== (H) Cada fila dice de qué catálogo salió su código ===\n";

check(
    'el upsert escribe `source` (antes vivía del DEFAULT y una fila no sabía su origen)',
    (string) (ncmExecute('SELECT source FROM geo_city WHERE countrycode = ? AND code = ?', [PY, CIU_ASUNCION])['source'] ?? '') === 'fixture',
    'source=' . var_export(ncmExecute('SELECT source FROM geo_city WHERE countrycode = ? AND code = ?', [PY, CIU_ASUNCION])['source'] ?? null, true),
    $failures,
    $checks
);

echo "\n=== (C) El endpoint filtra hijos por padre ===\n";

$districtsCapital = $catalog->districts(DEP_CAPITAL, PY);
check(
    'los distritos de CAPITAL son solo los suyos',
    count($districtsCapital) === 1 && $districtsCapital[0]['code'] === DIS_ASUNCION,
    json_encode($districtsCapital),
    $failures,
    $checks
);

$citiesCapiata = $catalog->cities(DIS_CAPIATA, null, '', PY);
$codesCapiata  = array_column($citiesCapiata, 'code');
sort($codesCapiata);
check(
    'las ciudades del distrito CAPIATA son solo las suyas',
    $codesCapiata === [CIU_CAPIATA, CIU_EFIMERA],
    json_encode($codesCapiata),
    $failures,
    $checks
);

check(
    'ninguna ciudad de otro distrito se filtra',
    !in_array(CIU_ASUNCION, $codesCapiata, true),
    'la ciudad de CAPITAL apareció bajo el distrito de CAPIATA',
    $failures,
    $checks
);

check(
    'listar ciudades sin distrito NI departamento se rechaza (serían 6.766 filas al browser)',
    (static function () use ($catalog): bool {
        try {
            $catalog->cities(null, null, '', PY);
            return false;
        } catch (\InvalidArgumentException) {
            return true;
        }
    })(),
    'devolvió resultados en vez de cortar',
    $failures,
    $checks
);

echo "\n=== (D) Búsqueda sin acentos ===\n";

$found = $catalog->cities(null, DEP_CENTRAL, 'capiata', PY);
check(
    '"capiata" encuentra "Capiatá"',
    count($found) === 1 && $found[0]['code'] === CIU_CAPIATA && $found[0]['name'] === 'Capiatá',
    json_encode($found),
    $failures,
    $checks
);

check(
    'el % tipeado por el usuario es literal, no comodín',
    $catalog->cities(null, DEP_CENTRAL, '%', PY) === [],
    'el % se interpretó como comodín',
    $failures,
    $checks
);

echo "\n=== (I) Lookup por nombre: jerarquía completa y cero adivinanza ===\n";

$homonimas = $catalog->lookup('capiata', null, null, PY);
check(
    'un nombre con homónimas devuelve TODAS las candidatas',
    count($homonimas['candidates']) === 2,
    json_encode($homonimas),
    $failures,
    $checks
);

check(
    'y NO resuelve por su cuenta: elegir sería declarar el departamento equivocado',
    $homonimas['resolved'] === null,
    'resolved=' . json_encode($homonimas['resolved']),
    $failures,
    $checks
);

check(
    'cada candidata trae la jerarquía completa (ciudad + distrito + departamento)',
    (static function () use ($homonimas): bool {
        foreach ($homonimas['candidates'] as $c) {
            if (!isset($c['city']['code'], $c['district']['code'], $c['department']['code'])) {
                return false;
            }
        }
        return true;
    })(),
    json_encode($homonimas['candidates']),
    $failures,
    $checks
);

check(
    'las homónimas se distinguen por su departamento',
    (static function () use ($homonimas): bool {
        $deps = array_map(static fn ($c) => $c['department']['code'], $homonimas['candidates']);
        sort($deps);
        return $deps === [DEP_CAPITAL, DEP_CENTRAL];
    })(),
    json_encode($homonimas['candidates']),
    $failures,
    $checks
);

$acotada = $catalog->lookup('capiata', null, 'central fixture', PY);
check(
    'nombrando el departamento, la ambigüedad desaparece y resuelve',
    $acotada['resolved'] !== null && $acotada['resolved']['city']['code'] === CIU_CAPIATA,
    json_encode($acotada),
    $failures,
    $checks
);

// En el catálogo de la SET, Asunción se llama "ASUNCION (DISTRITO)": quien
// escriba "Asunción" no matchea exacto NADA. Sin la fase parcial, la ciudad
// más obvia del país sería irresoluble.
$parcial = $catalog->lookup('asuncion', null, null, PY);
check(
    'un nombre que no matchea exacto se busca por substring',
    $parcial['matchType'] === 'partial' && $parcial['resolved'] !== null
        && $parcial['resolved']['city']['code'] === CIU_ASUNCION,
    json_encode($parcial),
    $failures,
    $checks
);

check(
    'sin ningún nombre, el lookup se rechaza en vez de devolver el catálogo',
    (static function () use ($catalog): bool {
        try {
            $catalog->lookup('', '', '', PY);
            return false;
        } catch (\InvalidArgumentException) {
            return true;
        }
    })(),
    'devolvió resultados en vez de cortar',
    $failures,
    $checks
);

$sinMatch = $catalog->lookup('ciudad que no existe en ningun catalogo', null, null, PY);
check(
    'un nombre inexistente devuelve vacío explícito, sin candidata inventada',
    $sinMatch['candidates'] === [] && $sinMatch['resolved'] === null && $sinMatch['matchType'] === null,
    json_encode($sinMatch),
    $failures,
    $checks
);

echo "\n=== (E) Baja en la fuente: se desactiva, NO se borra ===\n";

$sinEfimera = array_values(array_filter(
    $cities,
    static fn (array $c): bool => $c['code'] !== CIU_EFIMERA
));
$third = (new GeoCatalogSync($pySource($sinEfimera)))->run();

$efimera = ncmExecute('SELECT name, active FROM geo_city WHERE countrycode = ? AND code = ?', [PY, CIU_EFIMERA]);
$activeFlag = $efimera['active'] ?? null;

check(
    'la ciudad que la fuente dejó de mencionar sigue en la base',
    (bool) $efimera,
    'la fila se borró',
    $failures,
    $checks
);

check(
    'pero quedó inactiva',
    $activeFlag === false || $activeFlag === 'f' || $activeFlag === 0 || $activeFlag === '0',
    'active=' . var_export($activeFlag, true),
    $failures,
    $checks
);

check(
    'y el selector ya no la ofrece',
    !in_array(CIU_EFIMERA, array_column($catalog->cities(DIS_CAPIATA, null, '', PY), 'code'), true),
    'la ciudad dada de baja sigue apareciendo en el selector',
    $failures,
    $checks
);

check(
    'un código ya guardado por un comercio se sigue resolviendo a su nombre',
    $catalog->resolve(DEP_CENTRAL, DIS_CAPIATA, CIU_EFIMERA, PY)['city'] === 'CIUDAD EFIMERA',
    json_encode($catalog->resolve(DEP_CENTRAL, DIS_CAPIATA, CIU_EFIMERA, PY)),
    $failures,
    $checks
);

check(
    'la baja se contó en el resultado del job',
    $third['deactivated'] === 1,
    'deactivated=' . $third['deactivated'],
    $failures,
    $checks
);

echo "\n=== (F) Multi-país: el modelo no está atado a un solo país ===\n";

$zz = (new GeoCatalogSync(new FakeGeoSource(ZZ, $zzDepartments, $zzDistricts, $zzCities)))->run();

check(
    'cargar otro país no da de baja el catálogo del primero',
    $zz['deactivated'] === 0,
    'deactivated=' . $zz['deactivated'],
    $failures,
    $checks
);

$depsPy = array_column($catalog->departments(PY), 'code');
check(
    'el departamento de otro país no aparece en la lista del primero',
    !in_array(DEP_ZZ, $depsPy, true) && in_array(DEP_CAPITAL, $depsPy, true),
    json_encode($depsPy),
    $failures,
    $checks
);

check(
    'y el segundo país tiene su propio catálogo',
    array_column($catalog->departments(ZZ), 'code') === [DEP_ZZ],
    json_encode($catalog->departments(ZZ)),
    $failures,
    $checks
);

check(
    'el estado del catálogo declara los dos países',
    in_array(PY, $catalog->status()['countries'], true) && in_array(ZZ, $catalog->status()['countries'], true),
    json_encode($catalog->status()),
    $failures,
    $checks
);

cleanup();

harnessFinish($failures, $checks);

/** @return array{departments:int,districts:int,cities:int} conteo REAL en la base, no lo que el job dijo. */
function countRows(): array
{
    $row = ncmExecute(
        'SELECT (SELECT count(*) FROM geo_department) AS d,
                (SELECT count(*) FROM geo_district)   AS s,
                (SELECT count(*) FROM geo_city)       AS c'
    );
    return [
        'departments' => (int) $row['d'],
        'districts'   => (int) $row['s'],
        'cities'      => (int) $row['c'],
    ];
}

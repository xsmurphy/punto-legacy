<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de cualquier otra cosa (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del CATÁLOGO GEOGRÁFICO FISCAL (mig 207) — sync y lectura.
 *
 * ── Qué problema cubre ───────────────────────────────────────────────────
 *
 * La pantalla de facturación electrónica pedía departamento, distrito y
 * ciudad como códigos numéricos tipeados a mano. Ahora los ofrece en cascada
 * desde una copia local del catálogo del proveedor fiscal. Todo el valor de
 * ese cambio depende de dos cosas que este arnés verifica: que el sync sea
 * IDEMPOTENTE (el cron corre solo, semanalmente, y no puede duplicar ni
 * borrar catálogo) y que la JERARQUÍA quede bien armada (una ciudad cuelga de
 * su distrito y éste de su departamento — si eso falla, la cascada muestra
 * ciudades del departamento equivocado y el comercio declara un domicilio
 * fiscal falso ante la autoridad tributaria).
 *
 * ── Por qué NO llama a la API del proveedor ──────────────────────────────
 *
 * El ambiente dev de Factomate es inestable (su `PhoneLogin` estuvo caído un
 * día entero el 2026-09-07) y un test que depende de él es un test que no se
 * corre. La fuente se inyecta: `GeoCatalogSync` recibe un `GeoCatalogSource`
 * en memoria que devuelve el shape REAL verificado contra la API el
 * 2026-09-08 — incluida la clave anidada MAL ESCRITA (`Disctrict`), que es
 * justamente el detalle que un fixture "prolijo" perdería y que haría que el
 * sync guardara ciudades huérfanas en producción.
 *
 * ── Qué cubre ────────────────────────────────────────────────────────────
 *
 *   (A) IDEMPOTENCIA: dos corridas seguidas dejan los mismos conteos en la
 *       base y el mismo resultado.
 *   (B) JERARQUÍA: ASUNCION (ciudad) cuelga de su distrito, y ese distrito de
 *       CAPITAL (departamento, código 1 — verificado contra la API real).
 *   (C) FILTRO POR PADRE: los distritos de un departamento son los suyos, las
 *       ciudades de un distrito son las suyas, y ninguna se filtra de otro.
 *   (D) BÚSQUEDA sin acentos: "capiata" encuentra "Capiatá".
 *   (E) BAJAS: lo que el origen deja de mencionar queda inactivo (fuera del
 *       selector) pero SE CONSERVA, así un código ya guardado por un comercio
 *       se sigue resolviendo a su nombre.
 *   (F) MULTI-PAÍS: un segundo país convive sin contaminar las listas del
 *       primero — el modelo no está atado a un solo país aunque hoy el
 *       catálogo sea de uno.
 *   (G) SIN PAÍS NO SE INVENTA: una fila sin `CountryCode` se saltea y se
 *       cuenta, en vez de completarse con un default.
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
 * Fuente en memoria con el shape EXACTO de la API (verificado 2026-09-08).
 *
 * De los datos, solo el departamento CAPITAL (código 1) y la ciudad ASUNCION
 * están verificados contra la API real; el resto son códigos de fixture,
 * elegidos altos y fuera de uso para no pisar catálogo real si alguien corre
 * esto contra una base ya sincronizada (el runner igual lo bloquea salvo
 * confirmación explícita, y el arnés limpia lo suyo al terminar).
 */
final class FakeGeoSource implements GeoCatalogSource
{
    /** @param array<int,array<string,mixed>> $departments @param array<int,array<string,mixed>> $cities */
    public function __construct(private array $departments, private array $cities)
    {
    }

    public function departments(): array
    {
        return $this->departments;
    }

    public function cities(): array
    {
        return $this->cities;
    }
}

// ── Códigos del fixture ────────────────────────────────────────────────────
const PY = 'PY';
const ZZ = 'ZZ';                 // país sintético, para el caso multi-país
const DEP_CAPITAL = 1;           // verificado contra la API real
const DEP_CENTRAL = 9101;        // fixture
const DIS_ASUNCION = 9111;       // fixture
const DIS_CAPIATA = 9112;        // fixture
const CIU_ASUNCION = 1;          // verificado contra la API real
const CIU_CAPIATA = 9122;        // fixture
const CIU_EFIMERA = 9123;        // fixture — se da de baja en el caso (E)
const DEP_ZZ = 9001;
const DIS_ZZ = 9011;
const CIU_ZZ = 9021;

/** Departamento con el shape crudo de `/api/Department/get`. */
function depRow(int $id, int $identifier, string $name, string $country, bool $deleted = false): array
{
    return ['Id' => $id, 'Identifier' => $identifier, 'Name' => $name, 'CountryCode' => $country, 'Deleted' => $deleted];
}

/**
 * Ciudad con el shape crudo de `/api/City/get`, distrito y departamento
 * ANIDADOS. La clave `Disctrict` va mal escrita A PROPÓSITO: así la escribe
 * la API del proveedor y así tiene que leerla el sync.
 */
function cityRow(
    int $id,
    int $identifier,
    string $name,
    int $districtIdentifier,
    string $districtName,
    int $departmentIdentifier,
    string $departmentName,
    string $country,
    bool $deleted = false
): array {
    return [
        'Id'           => $id,
        'Identifier'   => $identifier,
        'Name'         => $name,
        'DistrictCode' => $districtIdentifier,
        'DistrictId'   => $districtIdentifier * 10,
        'Disctrict'    => [
            'Id'             => $districtIdentifier * 10,
            'Identifier'     => $districtIdentifier,
            'Name'           => $districtName,
            'DepartmentCode' => $departmentIdentifier,
            'DepartmentId'   => $departmentIdentifier * 10,
            'Department'     => depRow($departmentIdentifier * 10, $departmentIdentifier, $departmentName, $country),
        ],
        'Deleted'      => $deleted,
    ];
}

$departments = [
    depRow(10, DEP_CAPITAL, 'CAPITAL', PY),
    depRow(11, DEP_CENTRAL, 'CENTRAL FIXTURE', PY),
    depRow(12, DEP_ZZ, 'PROVINCIA ZZ', ZZ),
    // (G) sin CountryCode: no se puede ubicar en ningún país, se saltea.
    ['Id' => 13, 'Identifier' => 9999, 'Name' => 'SIN PAIS', 'CountryCode' => '', 'Deleted' => false],
];

$cities = [
    cityRow(100, CIU_ASUNCION, 'ASUNCION', DIS_ASUNCION, 'ASUNCION DISTRITO', DEP_CAPITAL, 'CAPITAL', PY),
    cityRow(101, CIU_CAPIATA, 'Capiatá', DIS_CAPIATA, 'CAPIATA DISTRITO', DEP_CENTRAL, 'CENTRAL FIXTURE', PY),
    cityRow(102, CIU_EFIMERA, 'CIUDAD EFIMERA', DIS_CAPIATA, 'CAPIATA DISTRITO', DEP_CENTRAL, 'CENTRAL FIXTURE', PY),
    cityRow(103, CIU_ZZ, 'CIUDAD ZZ', DIS_ZZ, 'DISTRITO ZZ', DEP_ZZ, 'PROVINCIA ZZ', ZZ),
];

/** Limpieza: SOLO los códigos que este arnés escribió. Hijos primero (FK). */
function cleanup(): void
{
    ncmExecute('DELETE FROM geo_city WHERE code = ANY(?::int[]) AND countrycode = ANY(?::text[])', [
        '{' . implode(',', [CIU_ASUNCION, CIU_CAPIATA, CIU_EFIMERA, CIU_ZZ]) . '}',
        '{"' . PY . '","' . ZZ . '"}',
    ]);
    ncmExecute('DELETE FROM geo_district WHERE code = ANY(?::int[]) AND countrycode = ANY(?::text[])', [
        '{' . implode(',', [DIS_ASUNCION, DIS_CAPIATA, DIS_ZZ]) . '}',
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

echo "\n=== (A) Idempotencia: dos corridas dejan el mismo catálogo ===\n";

$first  = (new GeoCatalogSync(new FakeGeoSource($departments, $cities)))->run();
$countAfterFirst = countRows();

$second = (new GeoCatalogSync(new FakeGeoSource($departments, $cities)))->run();
$countAfterSecond = countRows();

check(
    'la primera corrida cargó los 3 niveles',
    $first['departments'] === 3 && $first['districts'] === 3 && $first['cities'] === 4,
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

echo "\n=== (G) Una fila sin país se saltea, no se le inventa uno ===\n";

check(
    'el departamento sin CountryCode quedó fuera y se contó',
    $first['skippedNoCountry'] === 1,
    'skippedNoCountry=' . $first['skippedNoCountry'],
    $failures,
    $checks
);

check(
    'y no se guardó bajo ningún país',
    ncmExecute('SELECT count(*) AS n FROM geo_department WHERE code = 9999')['n'] == 0,
    'quedó guardado el departamento 9999',
    $failures,
    $checks
);

echo "\n=== (B) Jerarquía: ASUNCION → su distrito → CAPITAL ===\n";

$asuncion = ncmExecute(
    'SELECT c.name AS city, c.districtcode, d.name AS district, d.departmentcode, p.name AS department
       FROM geo_city c
       JOIN geo_district d   ON d.countrycode = c.countrycode AND d.code = c.districtcode
       JOIN geo_department p ON p.countrycode = d.countrycode AND p.code = d.departmentcode
      WHERE c.countrycode = ? AND c.code = ?',
    [PY, CIU_ASUNCION]
);

check(
    'ASUNCION cuelga de su distrito y ese distrito de CAPITAL (código 1)',
    $asuncion
        && (string) $asuncion['city'] === 'ASUNCION'
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
    'el código guardado es el Identifier (fiscal), no el Id interno del proveedor',
    (int) (ncmExecute('SELECT providerid FROM geo_city WHERE countrycode = ? AND code = ?', [PY, CIU_ASUNCION])['providerid'] ?? 0) === 100,
    'providerid distinto del Id de la API (100)',
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
    'ASUNCION apareció bajo el distrito de CAPIATA',
    $failures,
    $checks
);

check(
    'listar ciudades sin distrito NI departamento se rechaza (serían 6.400 filas al browser)',
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

echo "\n=== (E) Baja en el origen: se desactiva, NO se borra ===\n";

$sinEfimera = array_values(array_filter(
    $cities,
    static fn (array $c): bool => (int) $c['Identifier'] !== CIU_EFIMERA
));
$third = (new GeoCatalogSync(new FakeGeoSource($departments, $sinEfimera)))->run();

$efimera = ncmExecute('SELECT name, active FROM geo_city WHERE countrycode = ? AND code = ?', [PY, CIU_EFIMERA]);
$activeFlag = $efimera['active'] ?? null;

check(
    'la ciudad que el origen dejó de mencionar sigue en la base',
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

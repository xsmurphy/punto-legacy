<?php
/**
 * REST — Catálogo geográfico fiscal (departamento → distrito → ciudad).
 *
 *   GET  /v1/geo?resource=status                                   → cobertura del catálogo (conteos + última sync)
 *   GET  /v1/geo?resource=departments[&country=]                   → departamentos
 *   GET  /v1/geo?resource=districts&department=N[&country=]        → distritos de un departamento
 *   GET  /v1/geo?resource=cities&district=N|&department=N[&search=][&country=][&limit=]
 *                                                                  → ciudades del distrito (o del departamento), con búsqueda
 *   GET  /v1/geo?resource=resolve&department=&district=&city=      → nombres de códigos ya guardados (incluye bajas)
 *   GET  /v1/geo?resource=lookup&city=|&district=|&department=     → NOMBRE → códigos, con toda la jerarquía y las homónimas
 *   POST /v1/geo?action=sync                                       → recarga el catálogo del seed a mano (gateado einvoice.manage)
 *
 * QUÉ ES ESTE ENDPOINT Y QUÉ NO. Sirve DATO DE PLATAFORMA (mig 207): el mapa
 * de un país, idéntico para todos los comercios. Ninguna respuesta lleva
 * información del tenant, y por eso ninguna query filtra por `COMPANY_ID` —
 * no es un olvido de aislamiento, es que no hay nada que aislar. Lo que sí
 * exige sesión de panel es el ACCESO: el catálogo no es superficie pública.
 *
 * El realm es `panel` a secas. No se abre a `api` (API key) porque hoy nadie
 * lo pide desde ahí y una superficie de lectura se abre cuando alguien la
 * necesita, no por si acaso. El asistente (`resolve_geo_codes`) entra por acá
 * con el Bearer del panel, que es el mismo realm.
 *
 * EL SYNC MANUAL VA GATEADO POR `einvoice.manage`, y no por un permiso
 * propio: recarga el catálogo FISCAL entero, y quien lo necesita es
 * exactamente quien está dando de alta la facturación electrónica y encontró
 * el catálogo vacío. Un permiso nuevo para un botón que vive en esa pantalla
 * sería una clave más que administrar sin nadie a quien dársela por separado.
 *
 * El sync ya NO llama a nadie por red: carga el seed versionado de SIFEN
 * (`database/seeds/sifen-geo.json`), que es el catálogo contra el que FE-PY
 * valida los códigos. Corre solo en cada boot del container
 * (`docker-entrypoint.sh`); este POST está para reintentarlo sin un deploy.
 *
 * Sin `apiWrite`: `apiAuthTenant()` corta con 405 cualquier verbo distinto de
 * GET/HEAD para el realm `api`, así que el POST solo existe para el panel.
 */

require_once __DIR__ . '/../bootstrap.php';

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = $_GET['resource'] ?? null;
$action   = $_GET['action'] ?? null;

apiAuthTenant(['panel']);

/** Entero de query string, o null si no vino / no es numérico. */
$intParam = static function (string $key): ?int {
    $raw = $_GET[$key] ?? null;
    return is_numeric($raw) ? (int) $raw : null;
};

$country = trim((string) ($_GET['country'] ?? ''));
$catalog = new \Punto\Api\EInvoice\GeoCatalog();

switch ($method) {
    case 'GET':
        switch ($resource) {
            case 'status':
                apiOk($catalog->status());
                break;

            case 'departments':
                apiOk(['items' => $catalog->departments($country ?: null)]);
                break;

            case 'districts':
                $department = $intParam('department');
                if ($department === null) {
                    apiError('Falta el departamento', 422);
                }
                apiOk(['items' => $catalog->districts($department, $country ?: null)]);
                break;

            case 'cities':
                $district   = $intParam('district');
                $department = $intParam('department');
                if ($district === null && $department === null) {
                    apiError('Falta el distrito o el departamento', 422);
                }
                $limit = $intParam('limit') ?? 100;
                try {
                    apiOk(['items' => $catalog->cities(
                        $district,
                        $department,
                        (string) ($_GET['search'] ?? ''),
                        $country ?: null,
                        $limit
                    )]);
                } catch (\InvalidArgumentException $e) {
                    apiError($e->getMessage(), 422);
                }
                break;

            case 'resolve':
                apiOk($catalog->resolve(
                    $intParam('department'),
                    $intParam('district'),
                    $intParam('city'),
                    $country ?: null
                ));
                break;

            case 'lookup':
                try {
                    apiOk($catalog->lookup(
                        (string) ($_GET['city'] ?? ''),
                        (string) ($_GET['district'] ?? ''),
                        (string) ($_GET['department'] ?? ''),
                        $country ?: null,
                        $intParam('limit') ?? 25
                    ));
                } catch (\InvalidArgumentException $e) {
                    apiError($e->getMessage(), 422);
                }
                break;

            default:
                apiError('resource inválido (esperado: status|departments|districts|cities|resolve|lookup)', 422);
        }
        break;

    case 'POST':
        if ($action !== 'sync') {
            apiError('action inválida (esperado: sync)', 422);
        }
        if (!hasPermission('einvoice.manage')) {
            apiError('No tenés permiso para esta acción (requiere: einvoice.manage)', 403);
        }

        try {
            apiOk((new \Punto\Api\EInvoice\GeoCatalogSync())->run() + ['status' => $catalog->status()]);
        } catch (\RuntimeException $e) {
            // 409 y no 500: la carga lee un archivo del repo, así que el único
            // motivo posible es que el seed no esté donde tiene que estar o no
            // se pueda leer — o sea, un despliegue incompleto. Es un estado del
            // mundo con causa nombrada en el mensaje, no un bug de la request.
            // (Antes esto cubría "el proveedor fiscal está caído"; ya no hay
            // proveedor al que llamar.)
            apiError($e->getMessage(), 409);
        }
        break;

    default:
        apiError('Método no permitido', 405);
}

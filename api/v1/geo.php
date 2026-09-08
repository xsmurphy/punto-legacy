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
 *   POST /v1/geo?action=sync                                       → dispara la sincronización a mano (gateado einvoice.manage)
 *
 * QUÉ ES ESTE ENDPOINT Y QUÉ NO. Sirve DATO DE PLATAFORMA (mig 207): el mapa
 * de un país, idéntico para todos los comercios. Ninguna respuesta lleva
 * información del tenant, y por eso ninguna query filtra por `COMPANY_ID` —
 * no es un olvido de aislamiento, es que no hay nada que aislar. Lo que sí
 * exige sesión de panel es el ACCESO: el catálogo no es superficie pública.
 *
 * El realm es `panel` a secas. No se abre a `api` (API key) porque hoy nadie
 * lo pide desde ahí y una superficie de lectura se abre cuando alguien la
 * necesita, no por si acaso.
 *
 * EL SYNC MANUAL VA GATEADO POR `einvoice.manage`, y no por un permiso
 * propio: es una llamada al proveedor FISCAL, potencialmente lenta (baja el
 * catálogo entero), y quien la necesita es exactamente quien está dando de
 * alta la facturación electrónica y encontró el catálogo vacío. Un permiso
 * nuevo para un botón que vive en esa pantalla sería una clave más que
 * administrar sin nadie a quien dársela por separado.
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

            default:
                apiError('resource inválido (esperado: status|departments|districts|cities|resolve)', 422);
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
            // 409 y no 500: el motivo típico es que el proveedor fiscal esté
            // caído o que todavía no haya con qué autenticarse. Es un estado
            // del mundo que el comercio puede reintentar, no un bug nuestro.
            apiError($e->getMessage(), 409);
        }
        break;

    default:
        apiError('Método no permitido', 405);
}

<?php

/**
 * /API/v1/admin/companies.php — gestión cross-tenant de empresas (realm /admin, F3.1).
 *
 * Gateado por adminMiddleware() (JWT _jwt_admin, aud:"admin"). NO apiMiddleware
 * (ese es del realm tenant y exige companyId).
 *
 * F3.1 (esta entrega): solo lectura.
 *   GET                       → lista paginada de empresas
 *   GET ?limit=N&offset=M&q=  → con paginación + búsqueda libre
 *   GET ?id=<uuid>            → detalle de una empresa
 *
 * F3.2 / F3.3: POST update / setStatus / delete (siguientes slices).
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';
require_once __DIR__ . '/../../../lib/admin/CompanyAdminService.php';

adminMiddleware(); // define ADMIN_AUTHED_ID o mata con 401

$svc    = new CompanyAdminService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $company = $svc->get($id);
        if (!$company) {
            apiNotFound('Empresa no encontrada');
        }
        apiOk($company);
    }

    $limit  = (int) ($_GET['limit']  ?? 200);
    $offset = (int) ($_GET['offset'] ?? 0);
    $q      = trim((string) ($_GET['q'] ?? ''));

    apiOk($svc->listAll($limit, $offset, $q));
}

apiError('Método no permitido', 405);

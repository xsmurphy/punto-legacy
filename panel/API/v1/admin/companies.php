<?php

/**
 * /API/v1/admin/companies.php — gestión cross-tenant de empresas (realm /admin).
 *
 * Gateado por adminMiddleware() (JWT _jwt_admin, aud:"admin"). NO apiMiddleware.
 *
 * F3.1 — lectura:
 *   GET                       → lista paginada de empresas
 *   GET ?limit=N&offset=M&q=  → con paginación + búsqueda libre
 *   GET ?id=<uuid>            → detalle de una empresa
 *
 * F3.2 — escritura:
 *   PATCH ?id=<uuid>  body JSON → actualiza campos de la empresa (PATCH semántico)
 *
 * F3.4 — billing:
 *   GET ?plans=1              → lista de planes (code/name/price) para selector
 *   GET ?id=<uuid>&billing=1  → datos de facturación (balance, plan, cpayments)
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';
require_once __DIR__ . '/../../../lib/admin/CompanyAdminService.php';

adminMiddleware(); // define ADMIN_AUTHED_ID o mata con 401

$svc    = new CompanyAdminService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // F3.4 — lista de planes (selector UI).
    if (!empty($_GET['plans'])) {
        apiOk($svc->listPlans());
    }

    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        // F3.4 — datos de facturación.
        if (!empty($_GET['billing'])) {
            $billing = $svc->getBilling($id);
            if (!$billing) {
                apiNotFound('Empresa no encontrada');
            }
            apiOk($billing);
        }

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

if ($method === 'PATCH') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        apiError('Falta id', 400);
    }

    $body  = (string) file_get_contents('php://input');
    $input = json_decode($body, true);
    if (!is_array($input)) {
        apiError('Body JSON inválido', 400);
    }

    $result = $svc->update($id, $input);
    if (!$result['ok']) {
        apiError($result['error'] ?? 'error', $result['code'] ?? 422);
    }
    apiOk(['updated' => true]);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        apiError('Falta id', 400);
    }

    $type = trim((string) ($_GET['type'] ?? 'soft'));

    if ($type === 'hard') {
        // Verificar que el cliente mandó confirm = nombre exacto de la empresa.
        $body  = (string) file_get_contents('php://input');
        $input = json_decode($body, true);
        if (!is_array($input) || !isset($input['confirm']) || trim((string) $input['confirm']) === '') {
            apiError('Se requiere {"confirm":"<nombre>"} para eliminar permanentemente', 400);
        }

        $company = $svc->get($id);
        if (!$company) {
            apiNotFound('Empresa no encontrada');
        }
        $expectedName = trim((string) ($company['settingName'] ?: $company['name']));
        if (trim((string) $input['confirm']) !== $expectedName) {
            apiError('El nombre no coincide — confirmación incorrecta', 422);
        }

        $result = $svc->hardDelete($id);
    } else {
        $result = $svc->softDelete($id);
    }

    if (!$result['ok']) {
        apiError($result['error'] ?? 'error', $result['code'] ?? 422);
    }
    apiOk(['deleted' => $type]);
}

apiError('Método no permitido', 405);

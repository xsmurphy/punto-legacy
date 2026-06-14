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
 *   GET ?id=<uuid>&billing=1  → datos de facturación (balance, plan, cpayments, aiCredits)
 *   GET ?requests=1[&status=pending|approved|rejected] → solicitudes de cambio de plan
 *
 * F3.4b — AI credits + add-ons + plan-change requests:
 *   POST ?id=<uuid>&action=grantAiCredits  body {delta, reason}
 *   POST ?id=<uuid>&action=setAddons       body {extraUsers?, extraRegisters?, extraItems?}
 *   POST ?id=<uuid>&action=resolveRequest  body {requestId, approve, resolvedBy?}
 *
 * F3.5 — entrar como empresa (impersonar):
 *   POST ?id=<uuid>&action=enter → genera JWT _jwt_panel del propietario
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

    // F3.4b — solicitudes de cambio de plan.
    if (!empty($_GET['requests'])) {
        $status = trim((string) ($_GET['status'] ?? 'pending'));
        apiOk($svc->listRequests($status));
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

    // Parámetros de filtro/paginación server-side (Point 3).
    $q        = trim((string) ($_GET['q']        ?? ''));
    $status   = trim((string) ($_GET['status']   ?? ''));
    $plan     = trim((string) ($_GET['plan']     ?? ''));
    $blocked  = trim((string) ($_GET['blocked']  ?? ''));
    $page     = (int) ($_GET['page']     ?? 1);
    $pageSize = (int) ($_GET['pageSize'] ?? 50);

    // Compatibilidad descendente: si llegan limit/offset (callers legacy), mapear.
    if (isset($_GET['limit']) && !isset($_GET['pageSize'])) {
        $pageSize = max(10, min(200, (int) $_GET['limit']));
    }
    if (isset($_GET['offset']) && !isset($_GET['page'])) {
        $page = (int) floor((int) $_GET['offset'] / max(1, $pageSize)) + 1;
    }

    apiOk($svc->listAll([
        'q'        => $q,
        'status'   => $status,
        'plan'     => $plan !== '' ? (int) $plan : '',
        'blocked'  => $blocked !== '' ? (int) $blocked : '',
        'page'     => $page,
        'pageSize' => $pageSize,
    ]));
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
    // Audit: registrar los campos que cambiaron (keys del body).
    adminAudit('updateCompany', 'company', $id, null, ['fields' => array_keys($input)]);
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
        if ($result['ok']) {
            adminAudit('deleteCompany', 'company', $id, $expectedName ?? null, ['type' => 'hard']);
        }
    } else {
        // Leer nombre ANTES de suspender (sigue existiendo tras softDelete, pero
        // evitamos un segundo SELECT leyendo ahora).
        $preSoftCompany = $svc->get($id);
        $softName = $preSoftCompany ? ($preSoftCompany['settingName'] ?: $preSoftCompany['name']) : null;

        $result = $svc->softDelete($id);
        if ($result['ok']) {
            adminAudit('suspendCompany', 'company', $id, $softName, ['type' => 'soft']);
        }
    }

    if (!$result['ok']) {
        apiError($result['error'] ?? 'error', $result['code'] ?? 422);
    }
    apiOk(['deleted' => $type]);
}

if ($method === 'POST') {
    $id     = trim((string) ($_GET['id']     ?? ''));
    $action = trim((string) ($_GET['action'] ?? ''));

    $uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    // F3.4b — otorgar / descontar créditos IA.
    if ($action === 'grantAiCredits') {
        if ($id === '' || !preg_match($uuidRe, $id)) {
            apiError('id inválido', 400);
        }
        $body  = (string) file_get_contents('php://input');
        $input = json_decode($body, true);
        if (!is_array($input)) {
            apiError('Body JSON inválido', 400);
        }
        $delta  = (int)    ($input['delta']  ?? 0);
        $reason = (string) ($input['reason'] ?? '');
        if ($reason === '') {
            apiError('reason es requerido', 422);
        }
        $result = $svc->grantAiCredits($id, $delta, $reason);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        adminAudit('grantAiCredits', 'company', $id, null, [
            'delta'      => $delta,
            'reason'     => $reason,
            'balanceAfter' => $result['newBalance'] ?? null,
        ]);
        apiOk($result);
    }

    // F3.4b — actualizar add-ons.
    if ($action === 'setAddons') {
        if ($id === '' || !preg_match($uuidRe, $id)) {
            apiError('id inválido', 400);
        }
        $body  = (string) file_get_contents('php://input');
        $input = json_decode($body, true);
        if (!is_array($input)) {
            apiError('Body JSON inválido', 400);
        }
        $result = $svc->setAddons($id, $input);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        // Loguear el moduleData sanitizado (ya validado por setAddons), no el input crudo.
        adminAudit('setAddons', 'company', $id, null, ['moduleData' => $result['moduleData'] ?? []]);
        apiOk($result);
    }

    // F3.4b — resolver solicitud de cambio de plan.
    if ($action === 'resolveRequest') {
        $body  = (string) file_get_contents('php://input');
        $input = json_decode($body, true);
        if (!is_array($input)) {
            apiError('Body JSON inválido', 400);
        }
        $requestId  = trim((string) ($input['requestId']  ?? ''));
        $approve    = (bool) ($input['approve']    ?? false);
        $resolvedBy = trim((string) ($input['resolvedBy'] ?? ADMIN_AUTHED_ID ?? 'admin'));
        if ($requestId === '') {
            apiError('requestId es requerido', 422);
        }
        $result = $svc->resolveRequest($requestId, $approve, $resolvedBy);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        adminAudit('resolveRequest', 'company', null, null, [
            'requestId' => $requestId,
            'approve'   => $approve,
            'status'    => $result['status'] ?? null,
        ]);
        apiOk($result);
    }

    // F3.5 — generar JWT panel para propietario de la empresa (impersonación admin).
    if ($action === 'enter') {
        if ($id === '' || !preg_match($uuidRe, $id)) {
            apiError('Parámetros inválidos: se requiere ?id=<uuid>&action=enter', 400);
        }
        $tokenData = $svc->getEnterToken($id);
        if (!$tokenData) {
            apiNotFound('Empresa no encontrada o sin propietario activo');
        }
        adminAudit('impersonate', 'company', $id);
        apiOk(['token' => $tokenData['token'], 'expiresIn' => $tokenData['expiresIn']]);
    }

    apiError('Acción no soportada', 422);
}

apiError('Método no permitido', 405);

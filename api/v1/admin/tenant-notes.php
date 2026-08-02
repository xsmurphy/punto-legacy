<?php

/**
 * /API/v1/admin/tenant-notes.php — notas internas de un tenant (realm /admin).
 *
 * F3 (context/34-admin-saas-plan.md): CRUD mínimo sobre tenant_note.
 * Gateado por adminMiddleware() (JWT/sesión opaca _jwt_admin). NO apiMiddleware.
 *
 * GET    ?companyId=<uuid>&page=&pageSize=  → lista paginada, createdAt DESC
 * POST   body {companyId, body}             → crea nota (autor = admin en sesión)
 * DELETE ?id=<uuid>                         → borra — SOLO si el autor es el admin en sesión
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/CompanyAdminService.php';

adminMiddleware(); // define ADMIN_AUTHED_ID o mata con 401
adminRequireRole('sales'); // notas: el único bucket de escritura habilitado para sales

$svc    = new CompanyAdminService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

if ($method === 'GET') {
    $companyId = trim((string) ($_GET['companyId'] ?? ''));
    if ($companyId === '' || !preg_match($uuidRe, $companyId)) {
        apiError('companyId inválido', 400);
    }
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = (int) ($_GET['pageSize'] ?? 20);
    apiOk($svc->listNotes($companyId, $page, $pageSize));
}

if ($method === 'POST') {
    $body  = (string) file_get_contents('php://input');
    $input = json_decode($body, true);
    if (!is_array($input)) {
        apiError('Body JSON inválido', 400);
    }
    $companyId = trim((string) ($input['companyId'] ?? ''));
    $noteBody  = (string) ($input['body'] ?? '');
    if ($companyId === '' || !preg_match($uuidRe, $companyId)) {
        apiError('companyId inválido', 400);
    }

    $result = $svc->createNote($companyId, ADMIN_AUTHED_ID, $noteBody);
    if (!$result['ok']) {
        apiError($result['error'] ?? 'error', $result['code'] ?? 422);
    }
    adminAudit('createTenantNote', 'company', $companyId, null, ['noteId' => $result['note']['id'] ?? null]);
    apiOk($result);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '' || !preg_match($uuidRe, $id)) {
        apiError('id inválido', 400);
    }

    $result = $svc->deleteNote($id, ADMIN_AUTHED_ID);
    if (!$result['ok']) {
        apiError($result['error'] ?? 'error', $result['code'] ?? 422);
    }
    adminAudit('deleteTenantNote', 'tenant_note', $id);
    apiOk(['deleted' => true]);
}

apiError('Método no permitido', 405);

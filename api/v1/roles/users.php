<?php
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

if (!hasPermission('settings.role.manage')) {
    apiError('Sin permiso', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$id = (string)($_GET['id'] ?? '');
if ($id === '') {
    apiError('id requerido', 400);
}

$rs = ncmExecute(
    "SELECT contactid, contactname FROM contact
     WHERE companyid = ? AND role::text = ? AND type = 0 AND contactstatus > 0
     ORDER BY contactname ASC",
    [COMPANY_ID, $id],
    false,
    true
);

$users = [];
if ($rs && is_object($rs)) {
    while (!$rs->EOF) {
        $f = $rs->fields;
        $users[] = [
            'id'   => (string)($f['contactid'] ?? $f['contactId'] ?? ''),
            'name' => (string)($f['contactname'] ?? $f['contactName'] ?? ''),
        ];
        $rs->MoveNext();
    }
    $rs->Close();
}

apiOk(['users' => $users]);

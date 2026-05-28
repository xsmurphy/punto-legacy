<?php
/**
 * REST — Datos del admin logueado (gated por adminMiddleware). GET → { id, email, name }.
 * Lo usa el front de /admin para saber si hay sesión admin válida (sino 401 → redirige a login).
 */

require_once __DIR__ . '/../../../includes/db.php';   // $db (explícito, no depender del include de adminMiddleware)
require_once __DIR__ . '/../../lib/admin_auth.php';
adminMiddleware();   // define ADMIN_AUTHED_ID / ADMIN_AUTHED_EMAIL o corta 401

global $db;
$r = $db->Execute("SELECT adminId, email, name FROM admin_user WHERE adminId = ? AND status = 1 LIMIT 1", [ADMIN_AUTHED_ID]);
if (!$r || $r->EOF) {
    apiUnauthorized('Sesión admin inválida');
}

apiOk([
    'id'    => (string) $r->fields['adminId'],
    'email' => (string) $r->fields['email'],
    'name'  => (string) $r->fields['name'],
]);

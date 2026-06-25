<?php
/**
 * REST canónico (API compartida /api) — Auditoría de Acciones Tenant.
 *
 *   GET /v1/reports/audit?from=&to= → { rows: [...] }
 *
 * Solo para roles con privilegio (roleId !== 7). Scoped por companyId del JWT.
 * Resuelve nombre de usuario (contact.contactName) y sucursal (outlet.outletName).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

// Gate: solo roles con permiso de auditoría.
if (!hasPermission('reports.audit.view')) {
    apiError('No tenés permiso para esta acción (requiere: reports.audit.view)', 403);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

$rs = ncmExecute(
    'SELECT
        ta.id,
        ta."companyId",
        ta."userId",
        ta."outletId",
        ta.realm,
        ta.method,
        ta.endpoint,
        ta."targetId",
        ta.meta,
        ta.ip,
        ta."createdAt",
        c.contactName  AS "userName",
        o.outletName   AS "outletName"
     FROM tenant_audit ta
     LEFT JOIN contact c ON c.contactId = ta."userId"   AND c.companyId = ta."companyId"
     LEFT JOIN outlet  o ON o.outletId  = ta."outletId" AND o.companyId = ta."companyId"
     WHERE ta."companyId" = ?
       AND ta."createdAt" BETWEEN ? AND ?
     ORDER BY ta."createdAt" DESC
     LIMIT 1000',
    [(string) COMPANY_ID, $from, $to],
    false,
    true  // forceObj = true → devuelve un recordset ADOdb, hay que iterarlo
);

// ncmExecute con forceObj=true devuelve el recordset, NO un array — materializar.
$rows = [];
if ($rs && is_object($rs)) {
    while (!$rs->EOF) {
        $rows[] = $rs->fields;
        $rs->MoveNext();
    }
}

apiOk(['rows' => $rows]);

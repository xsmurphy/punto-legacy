<?php

/**
 * /API/v1/admin/audit.php — log de auditoría del realm /admin.
 *
 * Gateado por adminMiddleware() (JWT _jwt_admin, aud:"admin"). NO apiMiddleware.
 *
 *   GET ?page=N&pageSize=M&action=X&adminId=Y
 *     → { rows: [...], total: N, page: N, pageSize: M }
 *
 * Paginación: base 1. Ordenado por createdAt DESC.
 * Filtros opcionales: action (exacto), adminId (UUID exacto).
 * adminEmail ya está desnormalizado en la fila (no hace JOIN).
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';

adminMiddleware(); // define ADMIN_AUTHED_ID o mata con 401

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

global $db;

$page     = max(1, (int) ($_GET['page']     ?? 1));
$pageSize = max(10, min(200, (int) ($_GET['pageSize'] ?? 50)));
$action   = substr(trim((string) ($_GET['action']  ?? '')), 0, 40);
$adminId  = trim((string) ($_GET['adminId'] ?? ''));

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$where  = [];
$binds  = [];

if ($action !== '') {
    $where[]  = 'action = ?';
    $binds[]  = $action;
}

// Validar UUID antes de usar como filtro (evita info disclosure + asegura tipo correcto).
if ($adminId !== '' && preg_match($uuidRe, $adminId)) {
    $where[]  = '"adminId" = ?';
    $binds[]  = $adminId;
}

$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total.
$totalRow = $db->Execute(
    "SELECT COUNT(*) AS n FROM admin_audit $whereClause",
    $binds
);
$total = 0;
if ($totalRow && !$totalRow->EOF) {
    $total = (int) ($totalRow->fields['n'] ?? 0);
}

// Filas de la página.
$offset = ($page - 1) * $pageSize;
$bindsPaged = array_merge($binds, [$pageSize, $offset]);

$r = $db->Execute(
    "SELECT id, \"adminId\", \"adminEmail\", action, \"targetType\", \"targetId\",
            \"targetName\", meta, ip, \"createdAt\"
     FROM admin_audit
     $whereClause
     ORDER BY \"createdAt\" DESC
     LIMIT ? OFFSET ?",
    $bindsPaged
);

$rows = [];
if ($r) {
    while (!$r->EOF) {
        $f = $r->fields;
        $get = fn(string $k) => $f[$k] ?? $f[strtolower($k)] ?? null;

        $metaRaw = $get('meta');
        $meta = [];
        if (is_string($metaRaw) && $metaRaw !== '') {
            $decoded = json_decode($metaRaw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } elseif (is_array($metaRaw)) {
            $meta = $metaRaw;
        }

        $rows[] = [
            'id'         => (string) ($get('id')         ?? ''),
            'adminId'    => $get('adminId')     ?? $get('adminid'),
            'adminEmail' => (string) ($get('adminEmail') ?? $get('adminemail') ?? ''),
            'action'     => (string) ($get('action')     ?? ''),
            'targetType' => $get('targetType')  ?? $get('targettype'),
            'targetId'   => $get('targetId')    ?? $get('targetid'),
            'targetName' => $get('targetName')  ?? $get('targetname'),
            'meta'       => $meta,
            'ip'         => $get('ip'),
            'createdAt'  => $get('createdAt')   ?? $get('createdat'),
        ];
        $r->MoveNext();
    }
}

apiOk([
    'rows'     => $rows,
    'total'    => $total,
    'page'     => $page,
    'pageSize' => $pageSize,
]);

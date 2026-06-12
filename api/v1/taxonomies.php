<?php
/**
 * REST canónico (API compartida /api) — Taxonomies (catálogo de categorías,
 * marcas, impuestos, ubicaciones, etc.).
 *
 *   GET /v1/taxonomies                → lista de TODAS las taxonomías del tenant
 *   GET /v1/taxonomies?type=<type>    → filtrado por type (category, brand,
 *                                        tax, location, supplier, ...)
 *
 * Read-only por ahora; las taxonomías se crean en el panel legacy. Cuando
 * panel-next implemente CRUD de categorías/marcas, se agregan POST/PUT/DELETE.
 *
 * Auth: realm panel (apiAuthTenant(['panel'])). Multi-tenant scoping via
 * companyId del JWT.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Solo GET soportado', 405);
}

$type = trim((string) ($_GET['type'] ?? ''));

$where  = ['companyId = ?'];
$params = [COMPANY_ID];
if ($type !== '') {
    $where[]  = 'taxonomyType = ?';
    $params[] = $type;
}

$sql = "SELECT taxonomyId, taxonomyName, taxonomyType, taxonomyExtra, outletId
          FROM taxonomy
         WHERE " . implode(' AND ', $where) . "
         ORDER BY taxonomyType ASC, taxonomyName ASC";

global $db;
$rs = $db->Execute($sql, $params);
if ($rs === false) {
    apiError('Error consultando taxonomías', 500);
}

$out = [];
foreach ($rs->GetRows() as $row) {
    // Para impuestos, taxonomyName trae el porcentaje como string ("10").
    // Para categorías/marcas, taxonomyName es el nombre user-facing.
    // outletId aplica solo a type='location' — para los demás es null.
    $out[] = [
        'id'       => (string) $row['taxonomyid'],
        'name'     => (string) ($row['taxonomyname'] ?? ''),
        'type'     => (string) ($row['taxonomytype'] ?? ''),
        'extra'    => $row['taxonomyextra'] ?? null,
        'outletId' => $row['outletid'] ?? null,
    ];
}

apiOk(['taxonomies' => $out]);

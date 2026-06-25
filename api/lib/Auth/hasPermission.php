<?php
/**
 * Helper global hasPermission() — gate de autorización por permiso.
 *
 * Uso: if (!hasPermission('reports.sales.view')) { apiError('No tenés permiso para esta acción (requiere: reports.sales.view)', 403); }
 *
 * Requiere que ROLE_ID y COMPANY_ID estén definidos (lo hace apiAuthTenant()).
 * Si no están definidos, retorna false por seguridad.
 */

require_once __DIR__ . '/RoleService.php';

if (!function_exists('hasPermission')) {
    function hasPermission(string $perm): bool
    {
        if (!defined('ROLE_ID') || !defined('COMPANY_ID')) return false;
        return RoleService::hasPermission($perm, (string) ROLE_ID, (string) COMPANY_ID);
    }
}

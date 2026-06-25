<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/PermissionCatalog.php';

apiAuthTenant(['panel']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

apiOk(['groups' => PermissionCatalog::byGroup()]);

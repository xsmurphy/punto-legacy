<?php
/**
 * Migration 93 — Backfill `einvoice.manage` en roles existentes.
 *
 * Contexto: F0 de facturación electrónica (context/28-facturacion-electronica-plan.md)
 * agrega el permiso `einvoice.manage`, nuevo en PermissionCatalog.php y en el seed
 * de 'manager' (RoleService::SEED_PERMISSIONS). Esos seeds solo aplican a companies
 * nuevas o a un re-seed explícito — sin este backfill, todo tenant existente
 * quedaría con Encargados sin acceso a configurar la cuenta de Automate aunque el
 * rol sea funcionalmente equivalente al seed nuevo.
 *
 * Estrategia: igual patrón que migs 74 y 77 — cualquier roleData que ya tenga
 * `production.manage` (proxy de "este role tiene el mismo nivel que Encargado",
 * el permiso backfillado más reciente con el mismo criterio) recibe también
 * `einvoice.manage`. Los roles owner no necesitan backfill (RoleService les da
 * TODOS los permisos del catálogo incondicionalmente, sin leer roleData).
 *
 * Idempotente: solo actualiza roleData que tengan production.manage y NO tengan
 * ya einvoice.manage.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

// jsonb_exists(...) en vez del operador `?` — el carácter `?` colisiona con
// el placeholder de PDO/pgsql y rompe el boot (ver migs 74/77).
$rows = $pdo->query(
    "SELECT taxonomyid, taxonomyextra
       FROM taxonomy
      WHERE taxonomytype = 'roleData'
        AND jsonb_exists(taxonomyextra::jsonb->'permissions', 'production.manage')
        AND NOT jsonb_exists(taxonomyextra::jsonb->'permissions', 'einvoice.manage')"
)->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE taxonomy SET taxonomyextra = ? WHERE taxonomyid = ?::uuid');
    foreach ($rows as $row) {
        $extra = json_decode((string) $row['taxonomyextra'], true) ?? [];
        $perms = $extra['permissions'] ?? [];
        if (!in_array('einvoice.manage', $perms, true)) {
            $perms[] = 'einvoice.manage';
        }
        $extra['permissions'] = array_values($perms);
        $upd->execute([json_encode($extra), $row['taxonomyid']]);
        $updated++;
    }
    $pdo->commit();
    echo "[migrate] 93_seed_einvoice_permission: $updated roleData actualizadas\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 93_seed_einvoice_permission: " . $e->getMessage() . "\n");
    throw $e;
}

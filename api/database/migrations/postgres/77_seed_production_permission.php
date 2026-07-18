<?php
/**
 * Migration 77 — Backfill `production.manage` en roles existentes.
 *
 * Contexto: F1 del módulo de Producción (context/23) agrega el permiso
 * `production.manage`, nuevo en PermissionCatalog.php y en el seed de
 * 'manager' (RoleService::SEED_PERMISSIONS). Esos seeds solo aplican a
 * companies nuevas o a un re-seed explícito — sin este backfill, todo
 * tenant existente quedaría con Encargados sin acceso a Producción/merma
 * aunque el rol sea funcionalmente equivalente al seed nuevo.
 *
 * Estrategia: igual patrón que mig 74 — cualquier roleData que ya tenga
 * `inventory.stock.adjust` (proxy de "este role administra inventario",
 * el mismo nivel que Encargado) recibe también `production.manage`. Los
 * roles owner no necesitan backfill (RoleService les da TODOS los permisos
 * del catálogo incondicionalmente, sin leer roleData).
 *
 * Idempotente: solo actualiza roleData que tengan inventory.stock.adjust y
 * NO tengan ya production.manage.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$rows = $pdo->query(
    "SELECT taxonomyid, taxonomyextra
       FROM taxonomy
      WHERE taxonomytype = 'roleData'
        AND taxonomyextra::jsonb->'permissions' ? 'inventory.stock.adjust'
        AND NOT (taxonomyextra::jsonb->'permissions' ? 'production.manage')"
)->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE taxonomy SET taxonomyextra = ? WHERE taxonomyid = ?::uuid');
    foreach ($rows as $row) {
        $extra = json_decode((string) $row['taxonomyextra'], true) ?? [];
        $perms = $extra['permissions'] ?? [];
        if (!in_array('production.manage', $perms, true)) {
            $perms[] = 'production.manage';
        }
        $extra['permissions'] = array_values($perms);
        $upd->execute([json_encode($extra), $row['taxonomyid']]);
        $updated++;
    }
    $pdo->commit();
    echo "[migrate] 77_seed_production_permission: $updated roleData actualizadas\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 77_seed_production_permission: " . $e->getMessage() . "\n");
    throw $e;
}

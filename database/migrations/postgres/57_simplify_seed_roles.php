<?php
/**
 * Migration 57 — Simplificar seed roles de 5 → 3.
 *
 * Decisión owner 2026-06-25: para pymes, los 5 seeds originales (Owner/Admin/
 * Manager/Cashier/Viewer) son demasiados. Reducir a 3 (Dueño/Encargado/Cajero).
 * Si un tenant necesita más granularidad, crea custom desde /settings/roles.
 *
 * Esta migration solo afecta a tenants que ya recibieron los 5 seeds vía
 * `52_seed_roles.php` original. Hace:
 *   1. Elimina los seed roles 'admin' y 'viewer' (y sus roleData asociadas).
 *   2. Reasigna `contact.role` de users que apuntaban a esos roles → al
 *      Cajero de la misma company.
 *   3. Renombra los 3 seeds restantes a español (Owner→Dueño, Manager→Encargado,
 *      Cashier→Cajero).
 *
 * Idempotente: si ya corrió o si los obsoletos no existen, no hace nada.
 */

declare(strict_types=1);

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$pdo->beginTransaction();
try {
    // 1. Localizar seed roles obsoletos (admin, viewer)
    $obsoletes = $pdo->query(
        "SELECT taxonomyid, companyid
           FROM taxonomy
          WHERE taxonomytype = 'role'
            AND taxonomyextra::json->>'isSeed' = 'true'
            AND taxonomyextra::json->>'slug' IN ('admin', 'viewer')"
    )->fetchAll(PDO::FETCH_ASSOC);

    $reassigned = 0;
    $deletedRoles = 0;
    $deletedData = 0;

    foreach ($obsoletes as $row) {
        $roleId    = $row['taxonomyid'];
        $companyId = $row['companyid'];

        // Lookup Cashier de la misma company (target del reassign)
        $cashier = $pdo->prepare(
            "SELECT taxonomyid FROM taxonomy
              WHERE taxonomytype = 'role'
                AND companyid = ?
                AND taxonomyextra::json->>'slug' = 'cashier'
                AND taxonomyextra::json->>'isSeed' = 'true'
              LIMIT 1"
        );
        $cashier->execute([$companyId]);
        $newRoleId = $cashier->fetchColumn();

        if ($newRoleId) {
            // Reasignar users que apuntan al role obsoleto
            $upd = $pdo->prepare(
                "UPDATE contact SET role = ?::text WHERE role = ?::text AND companyid = ?"
            );
            $upd->execute([$newRoleId, $roleId, $companyId]);
            $reassigned += $upd->rowCount();
        }

        // Borrar roleData asociada (permisos del role)
        $delData = $pdo->prepare(
            "DELETE FROM taxonomy WHERE taxonomytype = 'roleData' AND sourceid = ?::uuid"
        );
        $delData->execute([$roleId]);
        $deletedData += $delData->rowCount();

        // Borrar el role
        $delRole = $pdo->prepare(
            "DELETE FROM taxonomy WHERE taxonomyid = ?::uuid"
        );
        $delRole->execute([$roleId]);
        $deletedRoles += $delRole->rowCount();
    }

    // 2. Renombrar los 3 seeds restantes a español
    $renames = [
        'owner'   => 'Dueño',
        'manager' => 'Encargado',
        'cashier' => 'Cajero',
    ];
    foreach ($renames as $slug => $newName) {
        $r = $pdo->prepare(
            "UPDATE taxonomy SET taxonomyname = ?
              WHERE taxonomytype = 'role'
                AND taxonomyextra::json->>'isSeed' = 'true'
                AND taxonomyextra::json->>'slug' = ?"
        );
        $r->execute([$newName, $slug]);
    }

    $pdo->commit();
    echo "[migrate] 57_simplify_seed_roles: "
       . "$deletedRoles roles obsoletos eliminados, "
       . "$deletedData roleData rows eliminadas, "
       . "$reassigned users reasignados a Cajero, nombres en ES\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 57_simplify_seed_roles: " . $e->getMessage() . "\n");
    throw $e; // re-throw para que el runner exit 1
}

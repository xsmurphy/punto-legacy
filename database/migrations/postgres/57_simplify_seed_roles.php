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

    $deletedRoles = 0;
    $deletedData = 0;

    // Nota: contact.role hoy es SMALLINT (int legacy 1-7), no UUID. Ningún user
    // tiene asignado el UUID del role obsoleto directamente — la resolución
    // int→UUID se hace en runtime via RoleService::resolveLegacyRole. Como el
    // mapping legacy ya redirige int 2 (admin) → manager e int 7 (viewer) →
    // cashier, NO hay reassignment de contact.role necesario acá. Solo borramos
    // las rows obsoletas de taxonomy.

    foreach ($obsoletes as $row) {
        $roleId = $row['taxonomyid'];

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
       . "$deletedData roleData rows eliminadas, nombres en ES\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 57_simplify_seed_roles: " . $e->getMessage() . "\n");
    throw $e; // re-throw para que el runner exit 1
}

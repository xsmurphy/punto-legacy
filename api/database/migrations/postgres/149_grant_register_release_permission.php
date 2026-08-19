<?php
declare(strict_types=1);

/**
 * 149_grant_register_release_permission.php
 *
 * Otorga `settings.register.release` a los roles manager existentes.
 *
 * Por qué hace falta una migración NUEVA y no se corrigió la 148: la 148 ya
 * quedó registrada en `schema_migrations` con su comportamiento original
 * (estampar `catalogVersion` SIN tocar `permissions`), así que el runner nunca
 * la vuelve a ejecutar — editar su archivo no tiene ningún efecto sobre una
 * base donde ya corrió. Verificado en prod 2026-08-19: 0 de 12 roles tienen el
 * permiso.
 *
 * Decisión del owner (2026-08-19): el sistema está en desarrollo, no hay roles
 * reales con permisos revocados a mano que preservar, así que se otorga sin
 * intentar distinguir "nunca lo tuvo" de "se lo quitaron". El mecanismo lazy de
 * `RoleService::_reconcileSeedGaps()` sigue siendo conservador para los
 * permisos FUTUROS; esto aplica solo a este permiso, nacido antes del
 * versionado del catálogo.
 *
 * Sin `settings.register.release`, un Encargado no puede liberar una caja
 * tomada por un dispositivo que ya no responde — y desde que la tenencia dejó
 * de vencer sola, esa es la ÚNICA salida. El dueño no depende de esto: su
 * permiso se resuelve en runtime (`RoleService::_isOwnerRole`).
 *
 * Idempotente: si el permiso ya está en el rol, no lo duplica.
 */

$newPermission = 'settings.register.release';

$rs = $pdo->query(
    "SELECT taxonomyid, taxonomyextra FROM taxonomy WHERE taxonomytype = 'roleData'"
);
$rows = $rs ? $rs->fetchAll(\PDO::FETCH_ASSOC) : [];

$granted = 0;
$already = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE taxonomy SET taxonomyextra = ? WHERE taxonomyid = ?::uuid');

    foreach ($rows as $row) {
        $extra = json_decode((string) $row['taxonomyextra'], true) ?? [];
        $slug  = $extra['slug'] ?? null;

        // Solo roles de administración sembrados por el sistema. Los roles
        // custom del tenant (slug null) NO se tocan: sus permisos los definió
        // una persona y no nos corresponde ampliarlos.
        if ($slug !== 'manager') { $skipped++; continue; }

        $perms = is_array($extra['permissions'] ?? null) ? $extra['permissions'] : [];
        if (in_array($newPermission, $perms, true)) { $already++; continue; }

        $perms[] = $newPermission;
        $extra['permissions'] = array_values($perms);
        $upd->execute([json_encode($extra), $row['taxonomyid']]);
        $granted++;
    }
    $pdo->commit();

    echo "[migrate] 149_grant_register_release_permission: "
       . "$granted rol(es) manager recibieron $newPermission, "
       . "$already ya lo tenían, $skipped omitidos (custom u otro slug)\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 149_grant_register_release_permission: " . $e->getMessage() . "\n");
    throw $e;
}

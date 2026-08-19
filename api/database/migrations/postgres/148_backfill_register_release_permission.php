<?php
/**
 * Migration 148 — CIERRE del hueco de versionado para
 * `settings.register.release` en roles manager existentes. Complemento del
 * mecanismo general que reemplaza el patrón de las migs 74/77/93 (proxy
 * heurístico) por reconciliación lazy versionada en RoleService (ver
 * api/lib/Auth/RoleService.php, _reconcileSeedGaps() /
 * PermissionCatalog::since()).
 *
 * IMPORTANTE — esta mig NO otorga el permiso a nadie. Solo estampa
 * `catalogVersion`. Ver "Decisión de producto pendiente" abajo: otorgarlo
 * automáticamente requeriría adivinar, y el codebase no tiene con qué
 * adivinar bien.
 *
 * Por qué esta mig existe además del mecanismo general
 * ------------------------------------------------------
 * `settings.register.release` NO es nuevo en este deploy: se agregó al
 * catálogo y a SEED_PERMISSIONS['manager'] en el commit ffa71355
 * (2026-08-17 20:58), casi 2 días antes de este backfill, y desde entonces
 * el endpoint `api/v1/roles.php` (PATCH) + la UI de Settings → Roles ya
 * permiten a un admin editar los permisos de su rol Encargado y guardarlos
 * — incluyendo, en teoría, destildar este permiso a propósito.
 *
 * El mecanismo general (RoleService::_reconcileSeedGaps) marca `since=2`
 * en este permiso y trata cualquier roleData SIN `catalogVersion` guardado
 * como "sincronizado hasta BASELINE_VERSION=1" — lo cual, aplicado en
 * caliente a TODO roleData de manager existente, revertiría silenciosamente
 * cualquier destilde hecho en esa ventana de ~44h. Eso es exactamente el
 * incidente de seguridad que la tarea pide evitar. La tabla `taxonomy` no
 * tiene columna de fecha ni hay audit log de ediciones de rol — no hay
 * forma de distinguir, por fila, "nunca se tocó desde antes de ffa71355" de
 * "el admin destildó justo este permiso durante esa ventana y dejó todo lo
 * demás igual" (un set idéntico al default salvo por el permiso nuevo es
 * EXACTAMENTE lo que produce cualquiera de los dos casos — no hay señal que
 * los diferencie; se descartó un primer intento de esta mig que comparaba
 * el set contra una foto "pristine" y otorgaba el permiso en caso de match,
 * precisamente porque ese caso ambiguo lo revive en silencio).
 *
 * Qué hace esta mig (conservador, sin adivinar)
 * ----------------------------------------------
 * Para cada roleData de un rol slug='manager' que todavía no tenga
 * `catalogVersion=2`: estampa `catalogVersion=2` SIN tocar `permissions`.
 * Esto cierra el hueco de la reconciliación lazy para siempre en ambas
 * direcciones — nunca vuelve a evaluar `since=2` contra esa fila, así que
 * nunca le agrega el permiso más adelante tampoco. El resultado neto es:
 * ningún rol manager existente recibe `settings.register.release` por este
 * deploy (ni ahora ni después); solo se "congela" su estado actual como
 * definitivo.
 *
 * Decisión de producto pendiente (reportada, no resuelta acá)
 * -------------------------------------------------------------
 * El dueño de cada company YA tiene este permiso de forma incondicional
 * (RoleService::_isOwnerRole() devuelve el catálogo completo en runtime,
 * sin leer storage — no requiere backfill, nunca lo requirió). Es decir: la
 * "caja inutilizable sin recurso" que motivó este incidente NO está
 * expuesta hoy — el dueño siempre puede liberar la caja. El único efecto
 * práctico de no otorgar este permiso a Encargados existentes es que el
 * dueño tiene que hacerlo él mismo, o re-otorgarlo manualmente por company
 * vía Settings → Roles si quiere delegarlo. Si Punto quiere que los
 * Encargados existentes lo reciban automáticamente pese a la ambigüedad de
 * arriba, es una decisión de producto explícita (aceptar el riesgo acotado
 * de revivir una revocación deliberada en, como mucho, un puñado de
 * companies que editaron el rol manager en esa ventana de 44h) — no una
 * decisión técnica que corresponda tomar en esta mig.
 *
 * Idempotente: una fila ya estampada con catalogVersion=2 se salta sin
 * reescribir nada.
 * Alcance: no cruza companies — el JOIN filtra companyid en ambos lados y
 * cada UPDATE toca una sola fila por taxonomyid (PK), sin WHERE compartido
 * entre tenants.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$newPermission = 'settings.register.release';
$catalogVersion = 2;

// Default de manager ANTES de ffa71355 (sin $newPermission) — usado SOLO
// para el conteo informativo de abajo (cuántas companies quedan en el caso
// ambiguo), nunca para decidir un write.
$pristineOld = [
    'pos.sale.create', 'pos.sale.void', 'pos.sale.refund', 'pos.sale.creditPayment',
    'pos.drawer.open', 'pos.drawer.close', 'pos.discount.apply',
    'inventory.item.view', 'inventory.item.create', 'inventory.item.edit', 'inventory.item.delete',
    'inventory.stock.adjust', 'inventory.transfer',
    'contacts.customer.view', 'contacts.customer.create', 'contacts.customer.edit', 'contacts.customer.delete',
    'contacts.supplier.view', 'contacts.supplier.manage',
    'contacts.user.view', 'contacts.user.manage',
    'reports.sales.view', 'reports.drawers.view', 'reports.audit.view', 'reports.expenses.view',
    'reports.satisfaction.view', 'reports.giftcards.view', 'reports.purchases.view',
    'reports.schedule.view', 'reports.recurring.view',
    'settings.outlet.manage', 'settings.register.manage', 'settings.tax.manage',
    'settings.template.manage', 'settings.device.pair', 'settings.device.manage',
    'settings.company.edit',
    'ai.agent.use', 'ai.agent.elevated',
    'finance.manage',
    'production.manage',
    'einvoice.manage',
];

$normalize = static function (array $perms): array {
    $set = array_values(array_unique($perms));
    sort($set);
    return $set;
};

$pristineOldSet = $normalize($pristineOld);

// Solo roleData de roles con slug='manager' — owner se resuelve en runtime
// sin leer storage (RoleService::_isOwnerRole) y no necesita backfill;
// cashier no tiene este permiso en su default, nada que reconciliar.
$rows = $pdo->query(
    "SELECT rd.taxonomyid, rd.taxonomyextra
       FROM taxonomy rd
       JOIN taxonomy r
         ON r.taxonomyid = rd.sourceid
        AND r.taxonomytype = 'role'
        AND r.companyid = rd.companyid
      WHERE rd.taxonomytype = 'roleData'
        AND r.taxonomyextra::jsonb->>'slug' = 'manager'"
)->fetchAll(PDO::FETCH_ASSOC);

$stamped         = 0;
$untouched       = 0;
$stillMissingNew = 0; // ya tenía catalogVersion=2 o quedó frozen SIN el permiso — candidatas a revisión manual del owner

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE taxonomy SET taxonomyextra = ? WHERE taxonomyid = ?::uuid');

    foreach ($rows as $row) {
        $extra = json_decode((string) $row['taxonomyextra'], true) ?? [];
        $perms = is_array($extra['permissions'] ?? null) ? $extra['permissions'] : [];
        $hasIt = in_array($newPermission, $perms, true);

        if (($extra['catalogVersion'] ?? null) === $catalogVersion) {
            $untouched++; // ya reconciliado (p.ej. re-corrida de la mig)
            if (!$hasIt) $stillMissingNew++;
            continue;
        }

        // NUNCA se toca `permissions` acá — ver cabecera del archivo. Solo se
        // estampa la versión, para que el mecanismo lazy deje de reconsiderar
        // esta fila (en ninguna dirección) de acá en más.
        $extra['catalogVersion'] = $catalogVersion;
        $upd->execute([json_encode($extra), $row['taxonomyid']]);
        $stamped++;
        if (!$hasIt) $stillMissingNew++;
    }
    $pdo->commit();

    // Informativo únicamente — cuántas de las que quedaron sin el permiso son
    // indistinguibles de "nunca tocado" (ambiguas) vs. claramente customizadas.
    $ambiguous = 0;
    foreach ($rows as $row) {
        $extra = json_decode((string) $row['taxonomyextra'], true) ?? [];
        $perms = is_array($extra['permissions'] ?? null) ? $extra['permissions'] : [];
        if (!in_array($newPermission, $perms, true) && $normalize($perms) === $pristineOldSet) {
            $ambiguous++;
        }
    }

    echo "[migrate] 148_backfill_register_release_permission: "
        . "$stamped fila(s) manager estampadas con catalogVersion=$catalogVersion (sin tocar permissions), "
        . "$untouched ya estaban reconciliadas, "
        . "$stillMissingNew rol(es) manager siguen SIN $newPermission tras esta mig — "
        . "de esas, $ambiguous coinciden exactamente con el default pre-ffa71355 (indistinguibles de "
        . "\"nunca tocado\": es la decisión de producto pendiente descrita en la cabecera del archivo)\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 148_backfill_register_release_permission: " . $e->getMessage() . "\n");
    throw $e;
}

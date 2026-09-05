<?php
/**
 * Migration 190 — backfill de `pos.order.item.cancel` (anular un ítem de una
 * comanda) a los roles que HOY ya tienen `pos.sale.create`.
 *
 * Criterio del otorgamiento
 * --------------------------
 * `pos.sale.create` es la clave con la que el comercio ya autorizó a un rol a
 * OPERAR EL MOSTRADOR: cargar lo que el cliente pide. Anular una línea mal
 * cargada es la otra mitad de esa misma capacidad —el mozo que tipeó "2" en
 * vez de "1", el cliente que cambió de idea antes de que el plato saliera— y
 * no propagarla dejaría a la feature sin nadie que la use: el default de la
 * ventana (`settingOrderItemCancelWindowMinutes` = 0) es "sin límite", así que
 * lo único que gobierna quién anula es esta clave.
 *
 * Lo que NO se otorga acá es `pos.order.item.cancel.late` (mig 191): esa es la
 * elevación para anular DESPUÉS de que la cocina ya tenía el plato, y su
 * criterio es otro.
 *
 * Por qué se EXCLUYE el rol `device` — y por qué acá sí hay que decirlo
 * ---------------------------------------------------------------------------
 * La mig 187 no tuvo que excluirlo porque su clave fuente
 * (`inventory.stock.adjust`) no está en el seed de `device`. Acá SÍ está:
 * `pos.sale.create` es parte del piso de capacidades de una terminal
 * (`RoleService::SEED_PERMISSIONS['device']`), así que un backfill que solo
 * mirara la clave fuente le daría `pos.order.item.cancel` al dispositivo.
 *
 * Eso contradice la feature entera. La clave se evalúa contra el rol del
 * OPERADOR del PIN (`OperatorContext`), y su razón de ser es que la anulación
 * quede atada a una PERSONA: una clave de responsabilidad individual sentada
 * en el rol de la tablet compartida es una contradicción en los términos. Se
 * filtra por `slug` explícito.
 *
 * Por qué hace falta esta mig si el mecanismo lazy ya existe
 * -----------------------------------------------------------
 * Mismo motivo que la 187: `RoleService::_reconcileSeedGaps()` solo reconcilia
 * roles SEED con default fijo (`manager`, `cashier`). Los roles CUSTOM (slug
 * null) se devuelven sin tocar por diseño, así que un rol "Mozo" creado por el
 * comercio con `pos.sale.create` no recibiría la clave nunca.
 *
 * Por qué NO revive revocaciones (el riesgo de la mig 148)
 * ---------------------------------------------------------
 * `pos.order.item.cancel` es una clave NUEVA: no existía en el catálogo antes
 * de este deploy, así que ningún admin pudo habérsela quitado a ningún rol. La
 * ambigüedad "nunca la tuvo" vs "se la sacaron a mano" que hacía peligrosa a
 * la 148 acá no existe — es el caso seguro del docblock de
 * `PermissionCatalog::since()`.
 *
 * Qué NO hace
 * ------------
 * - NO estampa `catalogVersion`: el mecanismo lazy es un aliado, no un riesgo.
 * - NO toca el rol `device` (arriba).
 * - NO toca `owner` de forma significativa: `RoleService::_isOwnerRole()` le
 *   devuelve el catálogo completo en runtime sin leer storage, así que ya la
 *   tiene. Si su fila existe y lleva la clave fuente igual se actualiza: es un
 *   no-op funcional que deja el storage coherente con lo que el runtime
 *   resuelve.
 *
 * Sin operadores jsonb en SQL: el filtrado se hace en PHP sobre el array
 * decodificado, igual que la 148, la 181 y la 187. El operador `?` de jsonb lo
 * reescribe PDO a placeholder y aborta el boot del contenedor (incidente de
 * las migs 74/77).
 *
 * Idempotente: una fila que ya tiene la clave se saltea sin reescribir.
 * Alcance: no cruza companies — el JOIN filtra `companyid` en ambos lados y
 * cada UPDATE toca una sola fila por `taxonomyid` (PK).
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$newPermission    = 'pos.order.item.cancel';
$sourcePermission = 'pos.sale.create';
$excludedSlug     = 'device';

// LEFT JOIN al rol para poder reportar seed vs custom Y para excluir `device`.
//
// El `slug` sale de COALESCE(fila de permisos, fila del rol) y no solo del
// JOIN: `_savePermissions()` (RoleService) lo escribe DENTRO de la propia fila
// `roleData`, así que la exclusión de `device` no queda colgando de que el
// LEFT JOIN por `sourceid` resuelva. Si no resolviera, `slug` sería NULL, el
// rol no se excluiría, y como `device` tiene la clave fuente en su seed se
// llevaría el permiso — exactamente lo que esta mig no puede permitir. Con el
// COALESCE la exclusión falla del lado seguro.
$rows = $pdo->query(
    "SELECT rd.taxonomyid,
            rd.taxonomyextra,
            COALESCE(rd.taxonomyextra::jsonb->>'slug',
                     r.taxonomyextra::jsonb->>'slug') AS slug
       FROM taxonomy rd
       LEFT JOIN taxonomy r
         ON r.taxonomyid = rd.sourceid
        AND r.taxonomytype = 'role'
        AND r.companyid = rd.companyid
      WHERE rd.taxonomytype = 'roleData'"
)->fetchAll(PDO::FETCH_ASSOC);

$granted       = 0;  // recibieron la clave en esta corrida
$alreadyHad    = 0;  // ya la tenían (re-corrida de la mig)
$notEligible   = 0;  // sin la clave fuente — el admin la tilda a mano
$skippedDevice = 0;  // rol `device`: excluido a propósito, ver docblock
$grantedCustom = 0;  // de los otorgados, cuántos son roles custom (slug null)

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE taxonomy SET taxonomyextra = ? WHERE taxonomyid = ?::uuid');

    foreach ($rows as $row) {
        if (($row['slug'] ?? null) === $excludedSlug) {
            $skippedDevice++;
            continue;
        }

        // `is_array` y no solo `?? []`: un `taxonomyextra` con JSON válido pero
        // escalar (un número, un string suelto) decodifica a algo que no es
        // array, y el `$extra['permissions'] = ...` de abajo se perdería en
        // silencio sobre ese valor. Fila rara, pero esta mig corre en el
        // arranque del contenedor de TODOS los tenants.
        $decoded = json_decode((string) $row['taxonomyextra'], true);
        $extra = is_array($decoded) ? $decoded : [];
        $perms = is_array($extra['permissions'] ?? null) ? $extra['permissions'] : [];

        if (in_array($newPermission, $perms, true)) {
            $alreadyHad++;
            continue;
        }
        if (!in_array($sourcePermission, $perms, true)) {
            $notEligible++;
            continue;
        }

        $perms[] = $newPermission;
        $extra['permissions'] = array_values($perms);
        $upd->execute([json_encode($extra), $row['taxonomyid']]);

        $granted++;
        if (($row['slug'] ?? null) === null) {
            $grantedCustom++;
        }
    }
    $pdo->commit();

    echo "[migrate] 190_backfill_pos_order_item_cancel_permission: "
        . "$granted rol(es) recibieron $newPermission (de esos, $grantedCustom custom), "
        . "$alreadyHad ya la tenían, "
        . "$notEligible sin $sourcePermission (no aplican), "
        . "$skippedDevice rol(es) device excluidos\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 190_backfill_pos_order_item_cancel_permission: " . $e->getMessage() . "\n");
    throw $e;
}

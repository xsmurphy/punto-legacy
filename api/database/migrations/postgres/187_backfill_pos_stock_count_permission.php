<?php
/**
 * Migration 187 — backfill de `pos.stock.count` (conteo de stock desde la
 * caja, context/63 F1) a los roles que HOY ya tienen `inventory.stock.adjust`.
 *
 * Criterio del otorgamiento
 * --------------------------
 * `inventory.stock.adjust` es la clave con la que el comercio ya autorizó a un
 * rol a corregir el inventario — es la que gatea el conteo del PANEL. La clave
 * nueva no amplía ese permiso en el eje de "puede mover stock": lo expresa del
 * lado del mostrador, donde `unlock-pin.php:127-131` filtra los permisos del
 * operador al prefijo `pos.` antes de mandarlos al dispositivo y una clave sin
 * ese prefijo nunca llega a la caja.
 *
 * Así que quien ya podía contar y ajustar desde el panel arranca pudiendo
 * hacerlo desde la caja. Al revés NO se hace: el que recibe `pos.stock.count`
 * no obtiene `inventory.stock.adjust`, porque contar el mostrador y corregir
 * el inventario a mano no son la misma capacidad. Un cajero que solo tiene que
 * contar lo tilda un admin desde Ajustes → Roles — que es exactamente el
 * comercio que pidió esta feature.
 *
 * Por qué hace falta esta mig si el mecanismo lazy ya existe
 * -----------------------------------------------------------
 * `RoleService::_reconcileSeedGaps()` solo reconcilia roles SEED con default
 * fijo (`manager`, `cashier`): los roles CUSTOM (slug null) se devuelven sin
 * tocar, por diseño — su lista de permisos es enteramente decisión del admin.
 * Un rol custom "Encargado de depósito" al que le tildaron
 * `inventory.stock.adjust` no recibiría `pos.stock.count` nunca. Eso es lo que
 * esta mig cubre. Para `manager` el efecto es redundante con la reconciliación
 * lazy (la clave entra a `SEED_PERMISSIONS['manager']` en el mismo commit, con
 * `since` = 7), pero acá llega en el arranque en vez de en la primera lectura
 * de permisos.
 *
 * Por qué NO revive revocaciones (el riesgo de la mig 148)
 * ---------------------------------------------------------
 * `pos.stock.count` es una clave NUEVA: no existía en el catálogo antes de
 * este deploy, así que ningún admin pudo habérsela quitado a ningún rol. La
 * ambigüedad "nunca lo tuvo" vs "se lo sacaron a mano" que hacía peligrosa a
 * la 148 acá no existe — es el caso seguro del docblock de
 * `PermissionCatalog::since()`.
 *
 * Qué NO hace
 * ------------
 * - NO estampa `catalogVersion`. La 148 lo hacía para CONGELAR el estado de
 *   una clave preexistente y que la reconciliación lazy no la re-evaluara
 *   nunca. Acá el mecanismo lazy es un aliado, no un riesgo.
 * - NO toca el rol `device`, y no debe: bajo `pos-app` ese rol es el mismo
 *   para todas las personas que usan la tablet, así que dárselo ahí
 *   habilitaría el conteo —que ajusta stock— para cualquiera que agarre el
 *   mostrador. `pos.stock.count` se evalúa contra el rol del OPERADOR del PIN
 *   (`OperatorContext`), igual que `pos.space.override` y `pos.ai.use`.
 * - NO toca `cashier`: no tiene `inventory.stock.adjust`, así que no es
 *   elegible. Es deliberado — ver el criterio de arriba.
 * - NO toca `owner`: `RoleService::_isOwnerRole()` le devuelve el catálogo
 *   completo en runtime sin leer storage, así que ya lo tiene. Si su fila de
 *   `roleData` existe y lleva la clave fuente, igual se actualiza: es un no-op
 *   funcional, pero deja el storage coherente con lo que el runtime resuelve.
 *
 * Sin operadores jsonb en SQL: el filtrado se hace en PHP sobre el array
 * decodificado, igual que la 148 y la 181. El operador `?` de jsonb lo
 * reescribe PDO a placeholder y aborta el boot del contenedor (incidente de
 * las migs 74/77).
 *
 * Idempotente: una fila que ya tiene `pos.stock.count` se saltea sin
 * reescribir. Alcance: no cruza companies — el JOIN filtra `companyid` en
 * ambos lados y cada UPDATE toca una sola fila por `taxonomyid` (PK).
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$newPermission    = 'pos.stock.count';
$sourcePermission = 'inventory.stock.adjust';

// LEFT JOIN al rol para poder reportar seed vs custom. La fila de permisos es
// la de tipo 'roleData'; `sourceid` apunta al rol dueño.
$rows = $pdo->query(
    "SELECT rd.taxonomyid,
            rd.taxonomyextra,
            r.taxonomyextra::jsonb->>'slug' AS slug
       FROM taxonomy rd
       LEFT JOIN taxonomy r
         ON r.taxonomyid = rd.sourceid
        AND r.taxonomytype = 'role'
        AND r.companyid = rd.companyid
      WHERE rd.taxonomytype = 'roleData'"
)->fetchAll(PDO::FETCH_ASSOC);

$granted       = 0;  // recibieron la clave en esta corrida
$alreadyHad    = 0;  // ya la tenían (re-corrida de la mig)
$notEligible   = 0;  // sin `inventory.stock.adjust` — el admin la tilda a mano
$grantedCustom = 0;  // de los otorgados, cuántos son roles custom (slug null)

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE taxonomy SET taxonomyextra = ? WHERE taxonomyid = ?::uuid');

    foreach ($rows as $row) {
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

    echo "[migrate] 187_backfill_pos_stock_count_permission: "
        . "$granted rol(es) recibieron $newPermission (de esos, $grantedCustom custom), "
        . "$alreadyHad ya la tenían, "
        . "$notEligible sin $sourcePermission (no aplican)\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 187_backfill_pos_stock_count_permission: " . $e->getMessage() . "\n");
    throw $e;
}

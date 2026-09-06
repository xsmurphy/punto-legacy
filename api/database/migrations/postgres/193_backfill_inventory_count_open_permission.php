<?php
/**
 * Migration 193 — backfill de `inventory.count.open` (ver el stock teórico
 * mientras se cuenta, context/63 F2) a los roles que HOY ya tienen
 * `inventory.stock.adjust`.
 *
 * Criterio del otorgamiento
 * --------------------------
 * `inventory.stock.adjust` es la clave con la que el comercio ya autorizó a un
 * rol a CORREGIR el inventario. Quien la tiene ve las existencias en el tab
 * Stock, en los reportes y en la pantalla de ajuste — esconderle el teórico
 * mientras cuenta no protege nada, solo lo obliga a abrir otra pantalla para
 * mirar el mismo número. El conteo ciego existe para el que NO tiene esa
 * visibilidad: el cajero del mostrador.
 *
 * Al revés NO se hace: quien recibe `inventory.count.open` no obtiene
 * `inventory.stock.adjust`. Ver un número mientras se cuenta y mover el
 * inventario a mano no son la misma capacidad, y el pedido del cliente fue
 * exactamente poder habilitar lo primero a una persona puntual.
 *
 * Qué cambia para un comercio que hoy cuenta a ciegas
 * ---------------------------------------------------
 * Hasta esta mig, `stockCountBlind` prendido escondía el esperado a TODOS. A
 * partir de acá es el PISO: sigue escondiéndolo, salvo a quien tenga esta
 * clave. Los roles que la reciben acá son los que ya podían ajustar stock, así
 * que para ellos el flag deja de tener efecto — es la consecuencia buscada de
 * mover la granularidad de comercio a persona, no un descuido. Un comercio que
 * quiera que su encargado también cuente a ciegas le destilda la clave en
 * Ajustes → Roles.
 *
 * Por qué hace falta esta mig si el mecanismo lazy ya existe
 * -----------------------------------------------------------
 * Mismo motivo que las migs 187, 190 y 191: `RoleService::_reconcileSeedGaps()`
 * solo reconcilia roles SEED con default fijo (`manager`, `cashier`); los roles
 * CUSTOM (slug null) se devuelven sin tocar, por diseño. Un rol custom
 * "Encargado de depósito" al que le tildaron `inventory.stock.adjust` no
 * recibiría la clave nunca.
 *
 * Para `manager` los dos caminos coexisten y NO son equivalentes, así que vale
 * dejar dicho qué hace cada uno: esta mig se la da solo si HOY tiene
 * `inventory.stock.adjust`, mientras que la reconciliación lazy se la va a dar
 * igual —en la primera lectura de permisos— por estar en
 * `SEED_PERMISSIONS['manager']` con `since` = 9, incluso en el tenant que le
 * revocó `inventory.stock.adjust` a mano. Es el comportamiento normal del
 * mecanismo de seeds (un default nuevo entra a los roles seed salvo que lo
 * revoquen DESPUÉS), no una excepción de esta clave: quien no quiera que su
 * Encargado vea el teórico se la destilda en Ajustes → Roles, y de ahí en más
 * no vuelve.
 *
 * Por qué NO revive revocaciones (el riesgo de la mig 148)
 * ---------------------------------------------------------
 * `inventory.count.open` es una clave NUEVA: no existía en el catálogo antes de
 * este deploy, así que ningún admin pudo habérsela quitado a ningún rol. La
 * ambigüedad "nunca la tuvo" vs "se la sacaron a mano" que hacía peligrosa a la
 * 148 acá no existe — es el caso seguro del docblock de
 * `PermissionCatalog::since()`.
 *
 * Qué NO hace
 * ------------
 * - NO estampa `catalogVersion`: el mecanismo lazy es un aliado, no un riesgo.
 * - NO toca el rol `device`, y no debe. Bajo `pos-app` ese rol es el mismo para
 *   todas las personas que agarran la tablet: dárselo ahí significaría "el que
 *   tiene la tablet ve el teórico", que es justo lo contrario del pedido.
 *   `inventory.count.open` se evalúa contra el rol del OPERADOR del PIN
 *   (`StockCountMode` → `OperatorContext`), igual que `pos.stock.count`.
 *   El filtro es por `slug` explícito y no por "no es elegible": el rol
 *   `device` NO tiene `inventory.stock.adjust` hoy, pero apoyarse en eso ataría
 *   la exclusión a una lista que puede cambiar.
 * - NO toca `cashier`: no tiene `inventory.stock.adjust`, así que no es
 *   elegible. Es deliberado — el cajero cuenta a ciegas por default (D2).
 * - NO toca `owner`: `RoleService::_isOwnerRole()` le devuelve el catálogo
 *   completo en runtime sin leer storage, así que ya la tiene. Si su fila de
 *   `roleData` existe y lleva la clave fuente, igual se actualiza: es un no-op
 *   funcional, pero deja el storage coherente con lo que el runtime resuelve.
 *
 * Sin operadores jsonb en SQL: el filtrado se hace en PHP sobre el array
 * decodificado, igual que la 148, la 181 y la 187. El operador `?` de jsonb lo
 * reescribe PDO a placeholder y aborta el boot del contenedor (incidente de las
 * migs 74/77).
 *
 * Idempotente: una fila que ya tiene `inventory.count.open` se saltea sin
 * reescribir. Alcance: no cruza companies — el JOIN filtra `companyid` en ambos
 * lados y cada UPDATE toca una sola fila por `taxonomyid` (PK).
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$newPermission    = 'inventory.count.open';
$sourcePermission = 'inventory.stock.adjust';
$excludedSlug     = 'device';

// `slug` por COALESCE(fila de permisos, fila del rol): la exclusión de `device`
// no puede depender de que el LEFT JOIN resuelva — mismo criterio que la 190/191.
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
$notEligible   = 0;  // sin `inventory.stock.adjust` — el admin la tilda a mano
$skippedDevice = 0;  // roles `device`, excluidos por diseño
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

    echo "[migrate] 193_backfill_inventory_count_open_permission: "
        . "$granted rol(es) recibieron $newPermission (de esos, $grantedCustom custom), "
        . "$alreadyHad ya la tenían, "
        . "$notEligible sin $sourcePermission (no aplican), "
        . "$skippedDevice rol(es) device excluidos\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 193_backfill_inventory_count_open_permission: " . $e->getMessage() . "\n");
    throw $e;
}

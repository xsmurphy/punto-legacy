<?php
/**
 * Migration 191 — backfill de `pos.order.item.cancel.late` (anular un ítem
 * FUERA de la ventana de tiempo) a los roles que HOY ya tienen
 * `pos.sale.void`.
 *
 * Criterio del otorgamiento — por qué `pos.sale.void` y no `pos.sale.create`
 * ---------------------------------------------------------------------------
 * La mig 190 propaga la clave BASE con el criterio "quién toma pedidos". Esta
 * es la ELEVACIÓN, y su pregunta es otra: quién puede deshacer algo que ya
 * produjo consecuencias. Anular un ítem que la cocina ya empezó a preparar es
 * merma real —producto consumido que nadie va a pagar—, exactamente el mismo
 * tipo de decisión que anular una venta ya emitida.
 *
 * `pos.sale.void` es la clave con la que el comercio ya designó quién se hace
 * cargo de eso. En el seed la tienen `owner` y `manager` y NO `cashier`, que
 * es la separación que pidió la feature (solo admin/encargado). Usar
 * `pos.sale.create` como fuente habría dado la elevación a todo el mostrador y
 * la ventana no habría frenado a nadie.
 *
 * Por qué se EXCLUYE el rol `device`
 * -----------------------------------
 * Mismo caso que la 190, y acá más grave: `pos.sale.void` TAMBIÉN está en el
 * piso de capacidades de la terminal (`RoleService::SEED_PERMISSIONS['device']`
 * lo incluye para que la caja pueda anular una venta con el Bearer del
 * dispositivo). Un backfill que solo mirara la clave fuente le daría al rol de
 * la tablet compartida el permiso de saltarse la ventana de tiempo — es decir,
 * la excepción que esta feature existe para acotar a un encargado
 * identificado. Se filtra por `slug` explícito.
 *
 * Por qué hace falta esta mig si el mecanismo lazy ya existe
 * -----------------------------------------------------------
 * Mismo motivo que la 187 y la 190: `RoleService::_reconcileSeedGaps()` no
 * toca los roles CUSTOM (slug null). Un rol "Encargado de turno" creado por el
 * comercio con `pos.sale.void` no recibiría la clave nunca.
 *
 * Por qué NO revive revocaciones (el riesgo de la mig 148)
 * ---------------------------------------------------------
 * `pos.order.item.cancel.late` es una clave NUEVA: no existía en el catálogo
 * antes de este deploy, así que ningún admin pudo habérsela quitado a ningún
 * rol. La ambigüedad "nunca la tuvo" vs "se la sacaron a mano" que hacía
 * peligrosa a la 148 acá no existe — es el caso seguro del docblock de
 * `PermissionCatalog::since()`.
 *
 * Qué NO hace
 * ------------
 * - NO otorga la clave BASE. Un rol que salga de acá con `.late` y sin
 *   `pos.order.item.cancel` no puede anular nada: el gate exige la base
 *   SIEMPRE y `.late` solo levanta el límite de tiempo (ver
 *   `OrderItemCancelGate`). No es un descuido — mantiene las dos decisiones
 *   separadas, y en la práctica todo rol con `pos.sale.void` tiene también
 *   `pos.sale.create`, así que la 190 ya le dio la base.
 * - NO estampa `catalogVersion`: el mecanismo lazy es un aliado, no un riesgo.
 * - NO toca el rol `device` (arriba).
 *
 * Sin operadores jsonb en SQL: el filtrado se hace en PHP sobre el array
 * decodificado (el operador `?` de jsonb lo reescribe PDO a placeholder y
 * aborta el boot del contenedor — incidente de las migs 74/77).
 *
 * Idempotente. Alcance: no cruza companies — el JOIN filtra `companyid` en
 * ambos lados y cada UPDATE toca una sola fila por `taxonomyid` (PK).
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$newPermission    = 'pos.order.item.cancel.late';
$sourcePermission = 'pos.sale.void';
$excludedSlug     = 'device';

// `slug` por COALESCE(fila de permisos, fila del rol): ver el comentario
// equivalente en la 190 — la exclusión de `device` no puede depender de que el
// LEFT JOIN resuelva, y acá menos, porque `device` tiene `pos.sale.void` en su
// seed y sería elegible.
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

$granted       = 0;
$alreadyHad    = 0;
$notEligible   = 0;
$skippedDevice = 0;
$grantedCustom = 0;

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE taxonomy SET taxonomyextra = ? WHERE taxonomyid = ?::uuid');

    foreach ($rows as $row) {
        if (($row['slug'] ?? null) === $excludedSlug) {
            $skippedDevice++;
            continue;
        }

        // `is_array` y no solo `?? []`: ver el comentario equivalente en la 190
        // (un taxonomyextra escalar se perdería en silencio).
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

    echo "[migrate] 191_backfill_pos_order_item_cancel_late_permission: "
        . "$granted rol(es) recibieron $newPermission (de esos, $grantedCustom custom), "
        . "$alreadyHad ya la tenían, "
        . "$notEligible sin $sourcePermission (no aplican), "
        . "$skippedDevice rol(es) device excluidos\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 191_backfill_pos_order_item_cancel_late_permission: " . $e->getMessage() . "\n");
    throw $e;
}

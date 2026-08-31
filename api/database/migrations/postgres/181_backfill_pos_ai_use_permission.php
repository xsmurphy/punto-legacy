<?php
/**
 * Migration 181 — backfill de `pos.ai.use` (asistente de IA en la caja,
 * context/59 D4) a los roles que HOY ya tienen `ai.agent.use`.
 *
 * Criterio del otorgamiento
 * --------------------------
 * `ai.agent.use` es la clave con la que el comercio ya autorizó a un rol a
 * gastar créditos de IA. `pos.ai.use` no habilita nada nuevo en ese eje: es la
 * MISMA autorización expresada del lado del mostrador, necesaria únicamente
 * porque `unlock-pin.php:127-131` filtra los permisos del operador al prefijo
 * `pos.` antes de mandarlos al dispositivo y una clave sin ese prefijo nunca
 * llega a la caja. Así que quien ya podía usar el agente del panel arranca
 * pudiendo usar el de la caja; el resto lo tilda un admin desde Ajustes → Roles.
 *
 * Por qué hace falta esta mig si el mecanismo lazy ya existe
 * -----------------------------------------------------------
 * `RoleService::_reconcileSeedGaps()` solo reconcilia roles SEED con default
 * fijo (`manager`, `cashier`): los roles CUSTOM (slug null) se devuelven sin
 * tocar, por diseño — su lista de permisos es enteramente decisión del admin.
 * Un rol custom "Encargado de turno" al que le tildaron `ai.agent.use` no
 * recibiría `pos.ai.use` nunca. Eso es lo que esta mig cubre. Para `manager`
 * el efecto es redundante con la reconciliación lazy (la clave entra a
 * `SEED_PERMISSIONS['manager']` en el mismo commit, con `since` = 6), pero acá
 * llega en el arranque en vez de en la primera lectura de permisos.
 *
 * Por qué NO revive revocaciones (el riesgo de la mig 148)
 * ---------------------------------------------------------
 * `pos.ai.use` es una clave NUEVA: no existía en el catálogo antes de este
 * deploy, así que ningún admin pudo habérsela quitado a ningún rol. La
 * ambigüedad "nunca lo tuvo" vs "se lo sacaron a mano" que hacía peligrosa a la
 * 148 acá no existe — es el caso seguro del docblock de
 * `PermissionCatalog::since()`.
 *
 * Qué NO hace
 * ------------
 * - NO estampa `catalogVersion`. La 148 lo hacía para CONGELAR el estado de una
 *   clave preexistente y que la reconciliación lazy no la re-evaluara nunca.
 *   Acá el mecanismo lazy es un aliado, no un riesgo: dejar el `catalogVersion`
 *   como está lo mantiene funcionando para los permisos futuros de esos roles.
 * - NO toca el rol `device`. Su default no incluye ninguna clave `ai.*` y no
 *   debe incluirla: bajo `pos-app` ese rol es el mismo para todas las personas
 *   que usan la tablet, así que dárselo ahí habilitaría el asistente para
 *   cualquiera que agarre el mostrador. `pos.ai.use` se evalúa contra el rol
 *   del OPERADOR (`OperatorContext`), igual que `pos.space.override`.
 * - NO toca `owner`: `RoleService::_isOwnerRole()` le devuelve el catálogo
 *   completo en runtime sin leer storage, así que ya lo tiene. Si su fila de
 *   `roleData` existe y lleva `ai.agent.use`, igual se actualiza —es un no-op
 *   funcional, pero deja el storage coherente con lo que el runtime resuelve.
 *
 * Sin operadores jsonb en SQL: el filtrado se hace en PHP sobre el array
 * decodificado, igual que la 148. El operador `?` de jsonb lo reescribe PDO a
 * placeholder y aborta el boot del contenedor (incidente de las migs 74/77).
 *
 * Idempotente: una fila que ya tiene `pos.ai.use` se saltea sin reescribir.
 * Alcance: no cruza companies — el JOIN filtra `companyid` en ambos lados y
 * cada UPDATE toca una sola fila por `taxonomyid` (PK).
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$newPermission    = 'pos.ai.use';
$sourcePermission = 'ai.agent.use';

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
$notEligible   = 0;  // sin `ai.agent.use` — el admin la tilda a mano si quiere
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

    echo "[migrate] 181_backfill_pos_ai_use_permission: "
        . "$granted rol(es) recibieron $newPermission (de esos, $grantedCustom custom), "
        . "$alreadyHad ya la tenían, "
        . "$notEligible sin $sourcePermission (no aplican)\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 181_backfill_pos_ai_use_permission: " . $e->getMessage() . "\n");
    throw $e;
}

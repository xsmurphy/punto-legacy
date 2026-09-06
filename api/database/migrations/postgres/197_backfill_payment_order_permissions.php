<?php
/**
 * Migration 197 — backfill de las TRES claves de la orden de pago a proveedor
 * (`purchases.paymentorder.view` / `.create` / `.approve`) a los roles
 * existentes.
 *
 * ============================================================
 * EL PROBLEMA DE PARTIDA: COMPRAS NO TIENE PERMISO
 * ============================================================
 *
 * El brief pedía backfillear `view`/`create` "a quien ya tiene el permiso de
 * compras equivalente". Ese permiso NO EXISTE: `api/v1/purchases.php:21` y
 * `purchase-drafts.php:33` solo exigen `apiAuthTenant(['panel'])`, sin una sola
 * llamada a `hasPermission()` — cualquier sesión de panel, de cualquier rol,
 * carga y anula compras hoy. Está documentado como hueco conocido en
 * `context/modules/08-compras.md` regla 11.
 *
 * Cerrar ESE hueco no es trabajo de esta feature (tocaría el alta y la
 * anulación de compras de todos los tenants en el mismo deploy). Lo que se
 * hizo acá es elegir las claves fuente más cercanas por CAPACIDAD, no por
 * nombre:
 *
 *   view    <- `reports.purchases.view`
 *             Es la única clave del catálogo que hoy gobierna VER datos de
 *             compras. Quien ya puede mirar el reporte de compras puede mirar
 *             las órdenes de pago armadas sobre esas compras. No la tienen ni
 *             `cashier` ni `device`, que es lo correcto.
 *
 *   create  <- `finance.manage`
 *             Es la clave que HOY gatea pagarle a un proveedor
 *             (`credit-payments.php` con contactType=2, tanto el alta como la
 *             anulación). Armar la orden es estrictamente MENOS que eso: el
 *             que ya puede desembolsar puede proponer el desembolso. Elegir
 *             una fuente más laxa habría dado la clave a roles que hoy no
 *             tocan plata.
 *
 *   approve <- NADIE por clave fuente. Ver abajo.
 *
 * ============================================================
 * `approve` NO SE DERIVA DE NINGUNA CLAVE — ES EL PUNTO DE LA FEATURE
 * ============================================================
 *
 * La orden de pago existe para SEPARAR quién arma el pago de quién lo
 * autoriza. Backfillear `approve` desde `finance.manage` —la misma fuente que
 * `create`— haría que todo rol que reciba una reciba la otra, y la separación
 * nacería vacía: el día del deploy nadie tendría cuatro ojos sobre nada.
 *
 * Así que `approve` se otorga por SLUG, solo a `owner` y `manager`. Es la
 * decisión del owner del producto, no una derivación. Un comercio que quiera
 * dársela a otro rol la tilda en Ajustes → Roles, que es exactamente la
 * conversación que esta feature quiere provocar.
 *
 * ============================================================
 * EL ROL `device` SE EXCLUYE — Y ACÁ HAY QUE DECIRLO
 * ============================================================
 *
 * `finance.manage` (la fuente de `create`) SÍ está en el piso del rol
 * `device`: extracción e ingreso de efectivo de la caja pasan por esa clave
 * (`RoleService::SEED_PERMISSIONS['device']`). Un backfill que solo mirara la
 * clave fuente le daría `purchases.paymentorder.create` al dispositivo.
 *
 * Eso no tiene sentido en ninguna lectura: el POS no le compra a proveedores
 * (`credit-payments.php` ya cierra el pago a proveedor al realm `panel`), y el
 * rol `device` es el mismo para todas las personas que agarran la tablet — una
 * clave de autorización de desembolsos sentada ahí es una contradicción.
 * Mismo criterio y mismo mecanismo de exclusión que la mig 190.
 *
 * ============================================================
 * POR QUÉ HACE FALTA ESTA MIG SI EL MECANISMO LAZY YA EXISTE
 * ============================================================
 *
 * `RoleService::_reconcileSeedGaps()` solo reconcilia roles SEED con default
 * fijo (`manager`, `cashier`). Los roles CUSTOM (slug null) se devuelven sin
 * tocar por diseño, así que un rol "Administración" creado por el comercio con
 * `finance.manage` no recibiría nada nunca. Eso es lo que cubre esta mig. Para
 * `manager` el efecto es redundante con la reconciliación lazy (las tres
 * claves entran a `SEED_PERMISSIONS['manager']` en el mismo commit, con
 * `since` = 9), pero acá llega en el arranque del contenedor en vez de en la
 * primera lectura de permisos.
 *
 * ============================================================
 * POR QUÉ NO REVIVE REVOCACIONES (EL RIESGO DE LA MIG 148)
 * ============================================================
 *
 * Las tres son claves NUEVAS: no existían en el catálogo antes de este deploy,
 * así que ningún admin pudo habérselas quitado a ningún rol. La ambigüedad
 * "nunca la tuvo" vs "se la sacaron a mano" que hacía peligrosa a la 148 acá
 * no existe — es el caso seguro del docblock de `PermissionCatalog::since()`.
 *
 * Qué NO hace: no estampa `catalogVersion` (el mecanismo lazy es un aliado, no
 * un riesgo), no toca `cashier` (no tiene ninguna de las dos fuentes y no es
 * `owner`/`manager` para approve), y no modifica en runtime a `owner`
 * —`RoleService::_isOwnerRole()` le devuelve el catálogo completo sin leer
 * storage—, aunque sí deja su fila coherente si existe.
 *
 * Sin operadores jsonb en SQL: el filtrado se hace en PHP sobre el array
 * decodificado, igual que las migs 148, 181, 187 y 190. El operador `?` de
 * jsonb lo reescribe PDO a placeholder y aborta el boot del contenedor
 * (incidente de las migs 74/77).
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

$excludedSlug = 'device';

/**
 * Reglas de otorgamiento. `source` = clave fuente que hay que tener;
 * `slugs` = slugs a los que se otorga sin mirar clave fuente. Exactamente una
 * de las dos por regla.
 */
$rules = [
    ['perm' => 'purchases.paymentorder.view',    'source' => 'reports.purchases.view', 'slugs' => null],
    ['perm' => 'purchases.paymentorder.create',  'source' => 'finance.manage',         'slugs' => null],
    ['perm' => 'purchases.paymentorder.approve', 'source' => null,                     'slugs' => ['owner', 'manager']],
];

// LEFT JOIN al rol para poder reportar seed vs custom Y para excluir `device`.
//
// El `slug` sale de COALESCE(fila de permisos, fila del rol) y no solo del
// JOIN: `_savePermissions()` (RoleService) lo escribe DENTRO de la propia fila
// `roleData`. Si el LEFT JOIN por `sourceid` no resolviera, `slug` sería NULL,
// `device` no se excluiría, y como tiene `finance.manage` en su seed se
// llevaría el permiso de crear órdenes de pago — exactamente lo que esta mig
// no puede permitir. Con el COALESCE la exclusión falla del lado seguro.
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

$granted       = [];  // perm => cuántos roles la recibieron en esta corrida
$grantedCustom = [];  // perm => de esos, cuántos son roles custom (slug null)
$alreadyHad    = [];  // perm => ya la tenían (re-corrida de la mig)
$notEligible   = [];  // perm => no aplican
foreach ($rules as $rule) {
    $granted[$rule['perm']]       = 0;
    $grantedCustom[$rule['perm']] = 0;
    $alreadyHad[$rule['perm']]    = 0;
    $notEligible[$rule['perm']]   = 0;
}
$skippedDevice = 0;
$rowsTouched   = 0;

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE taxonomy SET taxonomyextra = ? WHERE taxonomyid = ?::uuid');

    foreach ($rows as $row) {
        $slug = $row['slug'] ?? null;
        if ($slug === $excludedSlug) {
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

        // Una sola escritura por fila aunque se otorguen las tres claves.
        $dirty = false;
        foreach ($rules as $rule) {
            $perm = $rule['perm'];

            if (in_array($perm, $perms, true)) {
                $alreadyHad[$perm]++;
                continue;
            }

            $eligible = $rule['slugs'] !== null
                ? ($slug !== null && in_array($slug, $rule['slugs'], true))
                : in_array($rule['source'], $perms, true);

            if (!$eligible) {
                $notEligible[$perm]++;
                continue;
            }

            $perms[] = $perm;
            $dirty = true;
            $granted[$perm]++;
            if ($slug === null) {
                $grantedCustom[$perm]++;
            }
        }

        if ($dirty) {
            $extra['permissions'] = array_values($perms);
            $upd->execute([json_encode($extra), $row['taxonomyid']]);
            $rowsTouched++;
        }
    }
    $pdo->commit();

    echo "[migrate] 197_backfill_payment_order_permissions: $rowsTouched fila(s) roleData actualizada(s), "
        . "$skippedDevice rol(es) device excluidos\n";
    foreach ($rules as $rule) {
        $perm = $rule['perm'];
        $from = $rule['slugs'] !== null
            ? 'slug ' . implode('/', $rule['slugs'])
            : 'fuente ' . $rule['source'];
        echo "[migrate]   $perm ($from): {$granted[$perm]} otorgada(s) "
            . "(de esas, {$grantedCustom[$perm]} custom), "
            . "{$alreadyHad[$perm]} ya la tenían, {$notEligible[$perm]} no aplican\n";
    }
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 197_backfill_payment_order_permissions: " . $e->getMessage() . "\n");
    throw $e;
}

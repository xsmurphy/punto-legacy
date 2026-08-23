<?php
/**
 * Migration 160 — Reparar los roles que quedaron SIN su fila `roleData`.
 *
 * Causa raíz (corregida en el mismo commit que esta migración):
 * `RoleService::_savePermissions()` insertaba la fila `roleData` sin
 * `taxonomyname`, y esa columna es NOT NULL en `taxonomy`
 * (db-schema-postgres.sql). El INSERT fallaba con 23502 y —como `ncmInsert()`
 * solo loguea el error de PG y devuelve false— la falla era SILENCIOSA.
 *
 * `_savePermissions()` es el ÚNICO punto donde se persisten permisos, así que
 * el bug afectaba los cuatro caminos que pasan por él:
 *
 *   - `seedCompanyRoles()`  → los roles de una company nueva nacían sin
 *                             roleData: sus usuarios quedaban con CERO
 *                             permisos (owner no, se resuelve en runtime).
 *   - `createRole()`        → el rol custom se creaba, sus permisos no.
 *   - `updateRole()`        → el upsert es DELETE + INSERT: editar permisos
 *                             BORRABA la fila y no la reescribía.
 *   - `_reconcileSeedGaps()`→ el backfill lazy reconciliaba en memoria pero
 *                             no persistía nunca, repitiendo el trabajo (y el
 *                             error) en cada request.
 *
 * La mig 52 (seed original de roles) sí insertaba `taxonomyname` —lo dice su
 * propio comentario: "taxonomyname es NOT NULL, usamos slug como descriptor"—
 * así que los tenants sembrados por ella y nunca editados están sanos. Los
 * rotos son los posteriores a esa migración y los que pasaron por updateRole.
 *
 * Qué repara, y por qué SOLO eso: una fila `roleData` faltante tiene dos
 * orígenes posibles, y solo uno es seguro de reconstruir.
 *
 *   (A) `seedCompanyRoles()` nunca logró escribirla → el rol NUNCA tuvo
 *       permisos. No hay nada que un admin haya podido revocar, porque nunca
 *       hubo lista. Re-crear el default es exacto, no una adivinanza.
 *   (B) `updateRole()` la borró y no la reescribió → el rol SÍ tenía permisos,
 *       y la fila falta JUSTAMENTE porque un admin la estaba editando. Lo que
 *       ese admin eligió (típicamente: quitar permisos) es irrecuperable, y
 *       escribirle el default COMPLETO le devolvería todos los permisos que
 *       acababa de sacar. Revivir una revocación deliberada es el incidente de
 *       seguridad que todo el mecanismo de `catalogVersion` existe para
 *       evitar (ver el docblock de RoleService y
 *       api/lib/Sales/verify_chain/verify_role_permission_backfill.php).
 *
 * Rol por rol los dos casos son indistinguibles. Por COMPANY sí se distinguen:
 * si una company no tiene NINGUNA fila `roleData` para ninguno de sus roles,
 * entonces jamás se persistió una lista ahí — no pudo haber una revocación, y
 * todos sus roles seed son caso (A). Esa es la única condición que esta
 * migración considera probada, y la única que repara. Cubre además el peor
 * escenario práctico: el tenant nuevo entero, con todos sus roles muertos.
 *
 * Todo lo demás se OMITE y se reporta por nombre, para que se re-guarde desde
 * el panel (que con el fix de `_savePermissions()` ya persiste bien):
 *   - Companies que tienen alguna `roleData` pero les falta la de este rol:
 *     puede ser caso (B). Fail-closed — sin fila, `_loadPermissions()` ya
 *     devuelve `[]`, que es recuperable; un permiso revivido no lo es.
 *   - Roles CUSTOM (slug=null): no tienen default de catálogo asociado, así
 *     que no hay nada correcto que escribirles.
 *   - Roles seed que SÍ tienen su fila: no se toca ni una.
 *
 * `catalogVersion` = CURRENT_VERSION: lo que se inserta es el default del
 * catálogo VIGENTE, así que la fila nace legítimamente sincronizada y el
 * backfill lazy no tiene nada que agregarle.
 *
 * Idempotente: el SELECT solo trae roles sin roleData; una segunda corrida no
 * encuentra ninguno y no escribe nada.
 */

declare(strict_types=1);

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

// Solo definiciones de clase (RoleService.php requiere PermissionCatalog.php y
// nada más) — no toca la DB ni depende del bootstrap de la API.
//
// La ruta a `lib/` NO se puede derivar con un solo `dirname(__DIR__, N)`: el
// layout del repo y el del container difieren. En el repo estas migraciones
// viven en `<repo>/api/database/migrations/postgres` (y `lib/` es
// `<repo>/api/lib`), pero el Dockerfile copia `database/` a `/var/www/database`
// (ver api/Dockerfile: la ruta está hardcodeada en el entrypoint), así que en
// producción son `/var/www/database/migrations/postgres` y `/var/www/api/lib`.
// `dirname(__DIR__, 3) . '/lib/...'` acertaba en el repo y apuntaba a
// `/var/www/lib/...` en el container — un fatal que, con el entrypoint
// fail-fast, deja el deploy entero sin arrancar. Se prueban ambos candidatos.
$migRoleLibDirs = [
    dirname(__DIR__, 3) . '/lib/Auth',        // repo: <repo>/api/lib/Auth
    dirname(__DIR__, 3) . '/api/lib/Auth',    // container: /var/www/api/lib/Auth
];
$migRoleLib = null;
foreach ($migRoleLibDirs as $dir) {
    if (is_file($dir . '/PermissionCatalog.php') && is_file($dir . '/RoleService.php')) {
        $migRoleLib = $dir;
        break;
    }
}
if ($migRoleLib === null) {
    fwrite(STDERR, "[migrate] ERROR 161: no encontré lib/Auth (probé: " . implode(', ', $migRoleLibDirs) . ")\n");
    return;
}
require_once $migRoleLib . '/PermissionCatalog.php';
require_once $migRoleLib . '/RoleService.php';

$seedPermissions = (new ReflectionClass('RoleService'))->getConstants()['SEED_PERMISSIONS'] ?? null;
if (!is_array($seedPermissions)) {
    fwrite(STDERR, "[migrate] ERROR 161: no pude leer RoleService::SEED_PERMISSIONS\n");
    return;
}

// owner => null en SEED_PERMISSIONS significa "todo el catálogo" (lo mismo que
// hace seedCompanyRoles()). RoleService igual resuelve owner en runtime sin
// leer roleData, pero dejamos la fila coherente con el resto.
$allPerms = PermissionCatalog::ids();

// Roles sin su fila de permisos. `taxonomyextra` se decodifica en PHP y NO
// con `::json->>'slug'` en el SQL: la columna es TEXT nullable, y un solo rol
// con contenido no-JSON haría fallar el cast y ABORTARÍA la migración entera
// (y con ella el deploy). Decodificar acá deja que esa fila se omita sola.
$rows = $pdo->query(
    "SELECT r.taxonomyid, r.companyid, r.taxonomyname, r.taxonomyextra
       FROM taxonomy r
      WHERE r.taxonomytype = 'role'
        AND r.companyid IS NOT NULL
        AND NOT EXISTS (
              SELECT 1 FROM taxonomy rd
               WHERE rd.taxonomytype = 'roleData'
                 AND rd.sourceid  = r.taxonomyid
                 AND rd.companyid = r.companyid
            )"
)->fetchAll(PDO::FETCH_ASSOC);

// Companies que SÍ tienen al menos una fila `roleData`. Su ausencia en este
// set es la única prueba disponible de que en esa company nunca se persistió
// una lista de permisos y, por lo tanto, de que no puede haber una revocación
// deliberada que reconstruir el default vaya a revivir. Casos (A) y (B) del
// docblock. Se usa como SET (solo importan las claves).
$companiesConRoleData = [];
foreach ($pdo->query(
    "SELECT DISTINCT companyid FROM taxonomy
      WHERE taxonomytype = 'roleData' AND companyid IS NOT NULL"
)->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $companiesConRoleData[(string) $cid] = false;
}

$repaired = 0;
$skipped  = 0;
$omitidos = []; // roles no reparados, para reportarlos por nombre

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare(
        "INSERT INTO taxonomy (taxonomyname, taxonomytype, sourceid, taxonomyextra, companyid)
         VALUES (?, 'roleData', ?::uuid, ?, ?)"
    );

    foreach ($rows as $row) {
        $roleName  = (string) ($row['taxonomyname'] ?? $row['taxonomyid']);
        $companyId = (string) $row['companyid'];

        // La company tiene alguna otra `roleData` → esta fila pudo haber sido
        // borrada por un `updateRole()` (caso B del docblock). Irreconstruible
        // sin riesgo de revivir una revocación: se omite y se reporta.
        if (array_key_exists($companyId, $companiesConRoleData)) {
            $skipped++;
            $omitidos[] = "$roleName (company $companyId, posible edición previa)";
            continue;
        }

        $extra = json_decode((string) ($row['taxonomyextra'] ?? ''), true);
        $slug  = is_array($extra) ? ($extra['slug'] ?? null) : null;

        // Rol CUSTOM (slug=null) o taxonomyextra ilegible: sin default de
        // catálogo asociado. Ver el docblock — no se le inventa ninguna lista.
        if (!is_string($slug) || $slug === '') {
            $skipped++;
            $omitidos[] = "$roleName (company $companyId, rol custom sin default)";
            continue;
        }

        if ($slug === 'owner') {
            $perms = $allPerms;
        } elseif (isset($seedPermissions[$slug]) && is_array($seedPermissions[$slug])) {
            $perms = $seedPermissions[$slug];
        } else {
            // Slug desconocido (un seed retirado, ej. los 'admin'/'viewer' que
            // eliminó la mig 57): sin default confiable, no se inventa nada.
            $skipped++;
            $omitidos[] = "$roleName (company $companyId, slug '$slug' sin default)";
            continue;
        }

        $ins->execute([
            // Mismo esquema de nombre que RoleService::_roleDataName(): único
            // por rol, requisito de uq_taxonomy_company_type_name (mig 38).
            'roleData:' . $row['taxonomyid'],
            $row['taxonomyid'],
            json_encode([
                'permissions'    => array_values($perms),
                'slug'           => $slug,
                'catalogVersion' => PermissionCatalog::CURRENT_VERSION,
            ]),
            $row['companyid'],
        ]);
        $repaired++;
    }

    $pdo->commit();
    echo "[migrate] 160_repair_missing_roledata: $repaired roles seed reparados, $skipped omitidos\n";
    // Los omitidos NO son un error: son roles que hay que re-guardar a mano
    // desde el panel (ver docblock). Se listan para que no queden invisibles.
    foreach ($omitidos as $o) {
        echo "[migrate]   omitido — re-guardar desde el panel: $o\n";
    }
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 160_repair_missing_roledata: " . $e->getMessage() . "\n");
    // Como PDOException: `migrate.php` solo atrapa ese tipo, y un Throwable
    // de otra clase escaparía como fatal sin la línea "[migrate] FAILED".
    throw $e instanceof PDOException ? $e : new PDOException($e->getMessage(), 0, $e);
}

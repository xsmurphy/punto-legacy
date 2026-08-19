<?php

declare(strict_types=1);

/**
 * verify_role_permission_backfill.php — arnés chico que demuestra, sin
 * mockear nada, el mecanismo general de backfill de permisos nuevos a roles
 * existentes (api/lib/Auth/RoleService.php, _reconcileSeedGaps() /
 * PermissionCatalog::since()+BASELINE_VERSION+CURRENT_VERSION), agregado
 * porque `settings.register.release` (única salida para liberar una caja
 * tomada por un dispositivo colgado) se agregó al catálogo pero solo
 * llegaba a companies NUEVAS — las existentes se quedaban sin él para
 * siempre, ya que RoleService::seedCompanyRoles() solo corre al CREAR un
 * rol. Ver commit que agrega este arnés para el mapa completo.
 *
 * Property central que este arnés verifica (la que justifica todo el
 * diseño): un rol seed (manager) que nunca fue tocado desde antes de que el
 * permiso existiera debe RECIBIRLO en la próxima lectura, sin intervención
 * manual — pero un rol al que se le quitó el permiso a propósito (o que fue
 * customizado de cualquier otra forma después de que el permiso ya existía
 * en el catálogo) NUNCA debe recuperarlo solo. Revivir una revocación
 * deliberada es, tal como lo pide la tarea, un incidente de seguridad — no
 * un detalle de implementación.
 *
 * Casos (todos contra la company fixture PY del resto del arnés, con roles
 * de prueba propios — no toca los roles seed reales de esa company):
 *   1. Rol "legacy" (slug=manager, permissions=default MENOS el permiso
 *      nuevo, SIN `catalogVersion` guardado — el estado real de cualquier
 *      rol manager creado antes de este mecanismo): RoleService::
 *      getPermissions() debe devolver el permiso nuevo, Y la fila en BD
 *      debe quedar persistida con catalogVersion=CURRENT_VERSION (para no
 *      repetir el trabajo en la próxima lectura).
 *   2. Rol "revocado" (mismo slug=manager, mismo default MENOS el permiso
 *      nuevo, pero YA estampado con catalogVersion=CURRENT_VERSION —
 *      simula tanto una revocación explícita post-mecanismo vía
 *      updateRole() como el resultado de la migración 148 sobre un rol que
 *      no matcheaba el default pristine): getPermissions() NO debe agregar
 *      el permiso. Repetir la lectura una segunda vez para confirmar que
 *      sigue sin agregarse (no es un fluke de cache).
 *   3. Rol custom (slug=null, mismo permissions que el caso 1): nunca se
 *      reconcilia — sin default de catálogo asociado. getPermissions() NO
 *      debe agregar el permiso aunque "parezca" un manager desactualizado.
 *   4. Idempotencia del caso 1: correr getPermissions() una segunda vez
 *      sobre el rol YA reconciliado no debe alterar la fila en BD (se
 *      compara el JSON crudo de taxonomyextra antes/después de la segunda
 *      lectura — debe ser byte-a-byte igual).
 *
 * Uso: ver run.sh, que lo invoca como paso propio con las mismas env vars
 * POSTGRES_*. Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/Auth/PermissionCatalog.php';
require_once dirname(__DIR__, 3) . '/lib/Auth/RoleService.php';

$PY_COMPANY = '0ea6c5d8-57e5-4226-8140-ec914deec024'; // fixture compartida del arnés, ver seed.sql

// UUIDs fijos y propios de este arnés — no colisionan con los roles seed
// reales de PY_COMPANY (owner/manager/cashier) ni con fixtures de otros
// pasos, así que no hace falta tocar seed.sql.
$ROLE_LEGACY_ID   = 'b1a1c9f0-1111-4a11-8a11-000000000001';
$ROLE_REVOKED_ID  = 'b1a1c9f0-1111-4a11-8a11-000000000002';
$ROLE_CUSTOM_ID   = 'b1a1c9f0-1111-4a11-8a11-000000000003';

$NEW_PERM = 'settings.register.release';
$failures = [];

// Default de manager MENOS el permiso nuevo — construido a partir del
// catálogo real, no hardcodeado por separado, para que este arnés no quede
// desactualizado si SEED_PERMISSIONS['manager'] cambia.
$managerDefault = null;
$ref = new ReflectionClass('RoleService');
$seedPermsProp = $ref->getConstants()['SEED_PERMISSIONS'] ?? null;
if (!is_array($seedPermsProp) || !isset($seedPermsProp['manager'])) {
    fwrite(STDERR, "[verify_role_permission_backfill] ERROR: no pude leer RoleService::SEED_PERMISSIONS['manager']\n");
    exit(1);
}
$managerDefault = $seedPermsProp['manager'];
if (!in_array($NEW_PERM, $managerDefault, true)) {
    fwrite(STDERR, "[verify_role_permission_backfill] ERROR: $NEW_PERM ya no está en SEED_PERMISSIONS['manager'] — arnés desactualizado\n");
    exit(1);
}
$managerWithoutNew = array_values(array_diff($managerDefault, [$NEW_PERM]));

/**
 * RoleService::$cache es un memo por-PROCESO (docblock: "Cache por
 * request") — en producción cada request PHP-FPM arranca con el cache
 * vacío, así que una "segunda lectura" real siempre re-entra a
 * _loadPermissions()/_reconcileSeedGaps(). Dentro de este mismo proceso CLI
 * tenemos que vaciarlo a mano entre lecturas para que las aserciones de
 * idempotencia/no-revive prueben el camino real contra la BD y no un
 * simple return de memoria.
 */
function resetRoleServiceCache(): void
{
    $prop = new ReflectionProperty('RoleService', 'cache');
    $prop->setAccessible(true);
    $prop->setValue(null, []);
}

/** Limpia + inserta un role + roleData de prueba, directo en `taxonomy`. */
function seedTestRole(string $roleId, string $companyId, ?string $slug, array $permissions, ?int $catalogVersion): void
{
    ncmExecute("DELETE FROM taxonomy WHERE taxonomyid = ?::uuid AND companyid = ?", [$roleId, $companyId], true);
    ncmExecute("DELETE FROM taxonomy WHERE taxonomytype = 'roleData' AND sourceid = ?::uuid AND companyid = ?", [$roleId, $companyId], true);

    ncmExecute(
        "INSERT INTO taxonomy (taxonomyid, taxonomyname, taxonomytype, taxonomyextra, companyid)
         VALUES (?::uuid, ?, 'role', ?, ?)",
        [$roleId, 'VERIFY test role', json_encode(['isSeed' => $slug !== null, 'slug' => $slug]), $companyId],
        true
    );

    $roleDataExtra = ['permissions' => array_values($permissions)];
    if ($catalogVersion !== null) {
        $roleDataExtra['catalogVersion'] = $catalogVersion;
        $roleDataExtra['slug'] = $slug;
    }
    ncmExecute(
        "INSERT INTO taxonomy (taxonomytype, sourceid, taxonomyextra, companyid)
         VALUES ('roleData', ?::uuid, ?, ?)",
        [$roleId, json_encode($roleDataExtra), $companyId],
        true
    );
}

function loadRoleDataRaw(string $roleId, string $companyId): ?array
{
    $row = ncmExecute(
        "SELECT taxonomyextra FROM taxonomy WHERE taxonomytype='roleData' AND sourceid=?::uuid AND companyid=?",
        [$roleId, $companyId]
    );
    if (!$row) return null;
    return json_decode((string)($row['taxonomyextra'] ?? '{}'), true) ?? [];
}

// ── Caso 1: rol legacy (sin catalogVersion) recibe el permiso nuevo ────────
seedTestRole($ROLE_LEGACY_ID, $PY_COMPANY, 'manager', $managerWithoutNew, null);

$perms = RoleService::getPermissions($ROLE_LEGACY_ID, $PY_COMPANY);
if (!in_array($NEW_PERM, $perms, true)) {
    $failures[] = "Caso 1: rol legacy (sin catalogVersion) NO recibió $NEW_PERM — esperado que sí (backfill)";
} else {
    echo "[verify_role_permission_backfill] OK caso 1: rol legacy recibió $NEW_PERM en la lectura\n";
}

$rawAfter1 = loadRoleDataRaw($ROLE_LEGACY_ID, $PY_COMPANY);
if (($rawAfter1['catalogVersion'] ?? null) !== PermissionCatalog::CURRENT_VERSION) {
    $failures[] = "Caso 1: la fila en BD no quedó con catalogVersion=" . PermissionCatalog::CURRENT_VERSION . " tras el backfill (quedó: " . var_export($rawAfter1['catalogVersion'] ?? null, true) . ")";
} elseif (!in_array($NEW_PERM, $rawAfter1['permissions'] ?? [], true)) {
    $failures[] = "Caso 1: el backfill devolvió el permiso en memoria pero NO lo persistió en BD";
} else {
    echo "[verify_role_permission_backfill] OK caso 1: persistido en BD con catalogVersion=" . PermissionCatalog::CURRENT_VERSION . "\n";
}

// ── Caso 4: idempotencia — segunda lectura no vuelve a escribir nada ───────
// Vaciamos el cache estático de RoleService a mano: sin esto, esta "segunda
// lectura" se serviría 100% de memoria y nunca volvería a entrar a
// _loadPermissions()/_reconcileSeedGaps() ni a tocar la BD — la aserición
// pasaría igual aunque la reconciliación real no fuera idempotente. En
// producción cada request PHP-FPM arranca con el cache vacío, así que esto
// replica el escenario real (dos requests separados leyendo el mismo rol).
resetRoleServiceCache();
$perms2 = RoleService::getPermissions($ROLE_LEGACY_ID, $PY_COMPANY);
$rawAfter2 = loadRoleDataRaw($ROLE_LEGACY_ID, $PY_COMPANY);
if ($perms2 !== $perms) {
    $failures[] = "Caso 4: la segunda lectura devolvió permisos distintos a la primera ya-reconciliada";
} elseif (json_encode($rawAfter2) !== json_encode($rawAfter1)) {
    $failures[] = "Caso 4: la segunda lectura modificó la fila en BD — no es idempotente";
} else {
    echo "[verify_role_permission_backfill] OK caso 4: segunda lectura no reescribió nada (idempotente)\n";
}

// ── Caso 2: rol ya reconciliado (catalogVersion=CURRENT) NUNCA recupera el permiso ──
seedTestRole($ROLE_REVOKED_ID, $PY_COMPANY, 'manager', $managerWithoutNew, PermissionCatalog::CURRENT_VERSION);

$permsRevoked = RoleService::getPermissions($ROLE_REVOKED_ID, $PY_COMPANY);
if (in_array($NEW_PERM, $permsRevoked, true)) {
    $failures[] = "Caso 2: rol revocado (catalogVersion ya al día) RECUPERÓ $NEW_PERM — esto es exactamente el incidente de seguridad que el mecanismo debe evitar";
} else {
    echo "[verify_role_permission_backfill] OK caso 2: rol revocado NO recuperó $NEW_PERM\n";
}

// Segunda lectura — confirma que no es un fluke de la primera evaluación
// (cache vaciado a mano, mismo motivo que en el caso 4).
resetRoleServiceCache();
$permsRevoked2 = RoleService::getPermissions($ROLE_REVOKED_ID, $PY_COMPANY);
if (in_array($NEW_PERM, $permsRevoked2, true)) {
    $failures[] = "Caso 2b: rol revocado recuperó $NEW_PERM en una lectura posterior";
} else {
    echo "[verify_role_permission_backfill] OK caso 2b: sigue sin recuperarlo en una segunda lectura\n";
}

// ── Caso 3: rol custom (slug=null) nunca se reconcilia, aunque falte el permiso ──
seedTestRole($ROLE_CUSTOM_ID, $PY_COMPANY, null, $managerWithoutNew, null);

$permsCustom = RoleService::getPermissions($ROLE_CUSTOM_ID, $PY_COMPANY);
if (in_array($NEW_PERM, $permsCustom, true)) {
    $failures[] = "Caso 3: rol custom (slug=null) recibió $NEW_PERM — los roles custom no tienen default de catálogo asociado, nunca deben auto-completarse";
} else {
    echo "[verify_role_permission_backfill] OK caso 3: rol custom no fue tocado\n";
}

// ── Limpieza ────────────────────────────────────────────────────────────
foreach ([$ROLE_LEGACY_ID, $ROLE_REVOKED_ID, $ROLE_CUSTOM_ID] as $roleId) {
    ncmExecute("DELETE FROM taxonomy WHERE taxonomytype = 'roleData' AND sourceid = ?::uuid AND companyid = ?", [$roleId, $PY_COMPANY], true);
    ncmExecute("DELETE FROM taxonomy WHERE taxonomyid = ?::uuid", [$roleId], true);
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_role_permission_backfill] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_role_permission_backfill] TODO OK\n";
exit(0);

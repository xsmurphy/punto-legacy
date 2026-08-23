<?php
/**
 * Migration 161 — Rol `device` para cada tenant, y las sesiones `pos-app`
 * dejan de decir que son el Dueño.
 *
 * El problema: `DeviceAuth::buildToken()` emitía la sesión del dispositivo con
 * `roleId='1'`. `RoleService::LEGACY_MAP` mapea el 1 a `owner`, y
 * `hasPermission()` le devuelve `true` a CUALQUIER clave cuando el rol es
 * owner, sin mirar el catálogo. Resultado: todos los `hasPermission()` de los
 * endpoints que aceptan el realm `pos-app` eran letra muerta para una caja, y
 * el Bearer eterno guardado en el localStorage de una tablet del mostrador
 * valía, a efectos de autorización, lo mismo que el token del dueño del
 * comercio. El camino explotable completo estaba en `/v1/contacts`: PUT sobre
 * un contacto `type=0` → `contacts.user.manage` → pasa → se le reescribe el
 * `contactPhone` al Dueño → `/v1/login.php` autentica por
 * `contactPhone AND type=0` → toma del tenant.
 *
 * Qué hace esta migración:
 *
 *   1. Siembra el rol seed `device` (slug + fila `roleData` con el piso de
 *      `RoleService::SEED_PERMISSIONS['device']`) en toda company que no lo
 *      tenga. Idempotente por slug.
 *   2. Re-apunta el `roleid` de las sesiones `auth_session` del realm
 *      `pos-app` que hoy guardan '1' (o cualquier int legacy, o NULL) al UUID
 *      del rol `device` de SU company.
 *
 * El paso 2 es higiene del dato, no el fix: el fix de verdad es que
 * `bootstrap.php` (apiAuthTenant) y `DeviceAuth::resolveDeviceToken()`
 * RESUELVEN el rol del device contra el tenant en cada request en vez de leer
 * la columna. Eso hace que una sesión vieja no pueda operar como owner ni
 * aunque esta migración no la alcance — pero dejar la columna mintiendo sería
 * un dato falso esperando a que alguien lo crea (el panel /admin muestra
 * sesiones).
 *
 * NO revoca ninguna sesión: los devices siguen pareados y las cajas siguen
 * operando sin re-pareo. Lo único que cambia es el conjunto de permisos con el
 * que se las evalúa.
 *
 * Queda a propósito sin tocar: las companies que no tienen NINGÚN rol (nunca
 * pasaron por seedCompanyRoles). No se les siembra solo el rol device —
 * sembrarles uno suelto, sin owner/manager/cashier, dejaría el tenant en un
 * estado que ningún otro camino produce. Sus sesiones pos-app conservan
 * roleid='1' en la columna, y `RoleService::deviceRoleId()` les siembra los
 * roles completos en la primera request. Que la columna quede vieja ahí no
 * habilita nada: los dos resolvers de contexto pos-app la ignoran.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

// Solo definiciones de clase — no toca la DB ni depende del bootstrap de la API.
// Las clases de auth no se pueden requerir con una ruta relativa fija: el
// código vive en DOS layouts distintos y las migraciones corren en los dos.
//   - repo / arneses:  api/database/migrations/postgres → api/lib/Auth
//   - container (api/Dockerfile): `COPY database ./database` duplica este
//     árbol en /var/www/database, y el entrypoint corre
//     /var/www/database/migrate.php — desde ahí dirname(__DIR__, 3) es
//     /var/www y el código está en /var/www/api/lib/Auth.
// `dirname(__DIR__, 3) . '/lib/Auth'` resuelve solo el primero: en el
// container tiraba "Failed opening required" y el entrypoint abortaba el boot
// con las migraciones a medio aplicar. Se busca hacia arriba en vez de contar
// niveles.
$authDir = null;
$probe   = __DIR__;
for ($i = 0; $i < 6; $i++) {
    foreach ([$probe . '/lib/Auth', $probe . '/api/lib/Auth'] as $cand) {
        if (is_file($cand . '/PermissionCatalog.php')) { $authDir = $cand; break 2; }
    }
    $probe = dirname($probe);
}
if ($authDir === null) {
    fwrite(STDERR, "[migrate] ERROR: no encuentro lib/Auth/PermissionCatalog.php desde " . __DIR__ . "\n");
    return;
}
require_once $authDir . '/PermissionCatalog.php';
require_once $authDir . '/RoleService.php';

$seedPermissions = (new ReflectionClass('RoleService'))->getConstants()['SEED_PERMISSIONS'] ?? null;
$seedNames       = (new ReflectionClass('RoleService'))->getConstants()['SEED_NAMES'] ?? null;
if (!is_array($seedPermissions) || !isset($seedPermissions['device'])) {
    fwrite(STDERR, "[migrate] ERROR 161: RoleService::SEED_PERMISSIONS no tiene el slug 'device'\n");
    return;
}
$devicePerms = $seedPermissions['device'];
$deviceName  = $seedNames['device'] ?? 'Dispositivo POS';

try {
    $pdo->beginTransaction();

    // Companies que YA tienen roles sembrados pero no el rol `device`. El
    // filtro por "tiene algún rol" es a propósito: una company sin ningún rol
    // nunca pasó por seedCompanyRoles() y la siembra completa la hace
    // RoleService en su primera lectura — no es trabajo de esta migración
    // adivinar el resto de sus seeds.
    $companies = $pdo->query(
        "SELECT DISTINCT t.companyid
           FROM taxonomy t
          WHERE t.taxonomytype = 'role'
            AND NOT EXISTS (
                SELECT 1 FROM taxonomy d
                 WHERE d.taxonomytype = 'role'
                   AND d.companyid = t.companyid
                   AND d.taxonomyextra::json->>'slug' = 'device'
            )"
    )->fetchAll(PDO::FETCH_COLUMN);

    // `taxonomy` tiene un UNIQUE por (companyId, taxonomyType, LOWER(taxonomyName))
    // (mig 38): el nombre del rol y el de su fila roleData tienen que ser
    // únicos dentro de su tipo. El de roleData se deriva del UUID del rol,
    // igual que RoleService::_roleDataName().
    $insRole = $pdo->prepare(
        "INSERT INTO taxonomy (taxonomyname, taxonomytype, taxonomyextra, companyid)
         VALUES (?, 'role', ?, ?)
         RETURNING taxonomyid"
    );
    $insData = $pdo->prepare(
        "INSERT INTO taxonomy (taxonomyname, taxonomytype, sourceid, taxonomyextra, companyid)
         VALUES (?, 'roleData', ?::uuid, ?, ?)"
    );

    // El nombre tiene que ser único dentro de (company, type='role') por el
    // UNIQUE de la mig 38. Nada impide que un admin ya tenga un rol custom
    // llamado "Dispositivo POS": el INSERT chocaría, y como todo el batch va en
    // UNA transacción, la colisión de UN tenant abortaba la siembra de TODOS.
    // Se resuelve el nombre antes de insertar y se desempata con un sufijo.
    $nameTaken = $pdo->prepare(
        "SELECT 1 FROM taxonomy
          WHERE taxonomytype = 'role' AND companyid = ? AND LOWER(taxonomyname) = LOWER(?)
          LIMIT 1"
    );
    $resolveName = static function (string $companyId) use ($nameTaken, $deviceName): string {
        $candidato = $deviceName;
        for ($i = 2; $i <= 50; $i++) {
            $nameTaken->execute([$companyId, $candidato]);
            if ($nameTaken->fetchColumn() === false) {
                return $candidato;
            }
            $candidato = $deviceName . " ($i)";
        }
        throw new RuntimeException("no pude resolver un nombre libre para el rol device de $companyId");
    };

    $seeded    = 0;
    $renamed   = [];
    foreach ($companies as $companyId) {
        $nombre = $resolveName((string) $companyId);
        if ($nombre !== $deviceName) {
            $renamed[] = "$companyId → \"$nombre\"";
        }
        $insRole->execute([
            $nombre,
            json_encode(['isSeed' => true, 'slug' => 'device']),
            $companyId,
        ]);
        $roleId = (string) $insRole->fetchColumn();
        if ($roleId === '') {
            throw new RuntimeException("no se pudo crear el rol device de la company $companyId");
        }
        $insData->execute([
            'roleData:' . $roleId,
            $roleId,
            json_encode([
                'permissions'    => array_values($devicePerms),
                'slug'           => 'device',
                'catalogVersion' => PermissionCatalog::CURRENT_VERSION,
            ]),
            $companyId,
        ]);
        $seeded++;
    }

    // Re-apuntar las sesiones pos-app vivas al rol device de su company.
    // Solo las que NO apuntan ya a un rol real (int legacy o NULL) — si alguna
    // apunta a un UUID es porque alguien la emitió a propósito con otro rol
    // (arneses) y no se la pisa.
    $upd = $pdo->exec(
        "UPDATE auth_session s
            SET roleid = r.taxonomyid::text
           FROM taxonomy r
          WHERE r.taxonomytype = 'role'
            AND r.companyid = s.companyid
            AND r.taxonomyextra::json->>'slug' = 'device'
            AND s.realm = 'pos-app'
            AND (s.roleid IS NULL OR s.roleid ~ '^[0-9]+$')"
    );

    $pdo->commit();
    echo "[migrate] 162_seed_device_role: $seeded roles 'device' sembrados, "
        . (int) $upd . " sesiones pos-app re-apuntadas\n";
    // El slug es lo que importa (RoleService resuelve por slug, nunca por
    // nombre), pero un nombre distinto al esperado se avisa igual.
    foreach ($renamed as $r) {
        echo "[migrate]   nombre ocupado, rol device creado como: $r\n";
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[migrate] ERROR en 162_seed_device_role: " . $e->getMessage() . "\n");
    // Como PDOException: `migrate.php` solo atrapa ese tipo, y un Throwable de
    // otra clase escaparía como fatal sin la línea "[migrate] FAILED".
    throw $e instanceof PDOException ? $e : new PDOException($e->getMessage(), 0, $e);
}

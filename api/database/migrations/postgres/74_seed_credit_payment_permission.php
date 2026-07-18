<?php
/**
 * Migration 74 — Backfill `pos.sale.creditPayment` en roles existentes.
 *
 * Contexto: api/v1/credit-payments.php pasó de auth exclusiva por device
 * pairing (Bearer POS, sin chequeo de rol) a apiAuthTenant(['panel','pos-app'])
 * + hasPermission('pos.sale.creditPayment') — el permiso es NUEVO en el
 * catálogo (PermissionCatalog.php) y en los seeds de manager/cashier
 * (RoleService::SEED_PERMISSIONS), pero esos seeds solo aplican a companies
 * nuevas o a un re-seed explícito. Sin este backfill, todo tenant existente
 * quedaría con cajeros/encargados bloqueados para cobrar crédito — regresión
 * de un flujo que hoy funciona sin gate de permisos.
 *
 * Estrategia: cualquier role (roleData) que ya tenga `pos.sale.create` —
 * proxy de "este role opera el POS/hace ventas" — recibe también
 * `pos.sale.creditPayment`. Los roles owner no necesitan backfill (RoleService
 * les da TODOS los permisos del catálogo incondicionalmente, sin leer roleData).
 *
 * Idempotente: solo actualiza roleData que tengan pos.sale.create y NO tengan
 * ya pos.sale.creditPayment.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

// Usamos jsonb_exists(...) — la FUNCIÓN equivalente al operador `?` — porque
// el carácter `?` colisiona con el placeholder de PDO/pgsql (el driver lo
// reescribe a `$1` aunque sea query() directo) → syntax error en el boot.
// taxonomyextra es text/json → cast explícito a jsonb.
$rows = $pdo->query(
    "SELECT taxonomyid, taxonomyextra
       FROM taxonomy
      WHERE taxonomytype = 'roleData'
        AND jsonb_exists(taxonomyextra::jsonb->'permissions', 'pos.sale.create')
        AND NOT jsonb_exists(taxonomyextra::jsonb->'permissions', 'pos.sale.creditPayment')"
)->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE taxonomy SET taxonomyextra = ? WHERE taxonomyid = ?::uuid');
    foreach ($rows as $row) {
        $extra = json_decode((string) $row['taxonomyextra'], true) ?? [];
        $perms = $extra['permissions'] ?? [];
        if (!in_array('pos.sale.creditPayment', $perms, true)) {
            $perms[] = 'pos.sale.creditPayment';
        }
        $extra['permissions'] = array_values($perms);
        $upd->execute([json_encode($extra), $row['taxonomyid']]);
        $updated++;
    }
    $pdo->commit();
    echo "[migrate] 74_seed_credit_payment_permission: $updated roleData actualizadas\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR en 74_seed_credit_payment_permission: " . $e->getMessage() . "\n");
    throw $e;
}

<?php
/**
 * Migration 52 — Seed 5 roles del sistema para todas las companies existentes.
 *
 * Idempotente: solo crea roles cuyo slug no existe aún para esa company.
 * Usa el runner migrationPdo (raw PDO) porque el bootstrap del app no está
 * disponible en este contexto.
 *
 * Nota: taxonomyextra es columna text (no jsonb), taxonomyid tiene default
 * gen_random_uuid() — se omite del INSERT y se recupera con RETURNING.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$allPerms = [
    'pos.sale.create','pos.sale.void','pos.sale.refund',
    'pos.drawer.open','pos.drawer.close','pos.discount.apply',
    'inventory.item.view','inventory.item.create','inventory.item.edit','inventory.item.delete',
    'inventory.stock.adjust','inventory.transfer',
    'contacts.customer.view','contacts.customer.create','contacts.customer.edit','contacts.customer.delete',
    'contacts.supplier.view','contacts.supplier.manage',
    'contacts.user.view','contacts.user.manage',
    'reports.sales.view','reports.drawers.view','reports.audit.view','reports.expenses.view',
    'reports.satisfaction.view','reports.giftcards.view','reports.purchases.view',
    'reports.schedule.view','reports.recurring.view',
    'settings.outlet.manage','settings.register.manage','settings.tax.manage',
    'settings.template.manage','settings.device.pair','settings.device.manage',
    'settings.role.manage','settings.company.edit',
    'billing.view','billing.manage',
    'ai.agent.use','ai.agent.elevated',
];

$seedRoles = [
    'owner'   => ['name' => 'Owner',   'perms' => $allPerms],
    'admin'   => ['name' => 'Admin',   'perms' => [
        'pos.sale.create','pos.sale.void','pos.sale.refund',
        'pos.drawer.open','pos.drawer.close','pos.discount.apply',
        'inventory.item.view','inventory.item.create','inventory.item.edit','inventory.item.delete',
        'inventory.stock.adjust','inventory.transfer',
        'contacts.customer.view','contacts.customer.create','contacts.customer.edit','contacts.customer.delete',
        'contacts.supplier.view','contacts.supplier.manage',
        'contacts.user.view','contacts.user.manage',
        'reports.sales.view','reports.drawers.view','reports.audit.view','reports.expenses.view',
        'reports.satisfaction.view','reports.giftcards.view','reports.purchases.view',
        'reports.schedule.view','reports.recurring.view',
        'settings.outlet.manage','settings.register.manage','settings.tax.manage',
        'settings.template.manage','settings.device.pair','settings.device.manage',
        'settings.company.edit',
        'ai.agent.use','ai.agent.elevated',
    ]],
    'manager' => ['name' => 'Manager', 'perms' => [
        'pos.sale.create','pos.sale.void','pos.sale.refund',
        'pos.drawer.open','pos.drawer.close','pos.discount.apply',
        'inventory.item.view','inventory.item.create','inventory.item.edit',
        'inventory.stock.adjust','inventory.transfer',
        'contacts.customer.view','contacts.customer.create','contacts.customer.edit',
        'contacts.supplier.view',
        'contacts.user.view',
        'reports.sales.view','reports.drawers.view','reports.expenses.view',
        'reports.satisfaction.view','reports.giftcards.view','reports.purchases.view',
        'reports.schedule.view','reports.recurring.view',
        'ai.agent.use',
    ]],
    'cashier' => ['name' => 'Cashier', 'perms' => [
        'pos.sale.create','pos.drawer.open','pos.drawer.close',
        'inventory.item.view',
        'contacts.customer.view','contacts.customer.create',
    ]],
    'viewer'  => ['name' => 'Viewer',  'perms' => [
        'inventory.item.view',
        'contacts.customer.view',
        'reports.sales.view','reports.drawers.view','reports.expenses.view',
        'reports.satisfaction.view','reports.giftcards.view','reports.purchases.view',
        'reports.schedule.view','reports.recurring.view',
    ]],
];

// Obtener todas las companies
$companies = $pdo->query('SELECT companyid FROM company')->fetchAll(PDO::FETCH_COLUMN);
$created = 0;

foreach ($companies as $companyId) {
    foreach ($seedRoles as $slug => $def) {
        // Idempotencia: verificar si ya existe
        $check = $pdo->prepare(
            "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'=?"
        );
        $check->execute([$companyId, $slug]);
        if ($check->fetch()) {
            continue;
        }

        // Insert role — taxonomyid tiene default gen_random_uuid(), se omite del INSERT
        $ins = $pdo->prepare(
            "INSERT INTO taxonomy (taxonomyname, taxonomytype, taxonomyextra, companyid)
             VALUES (?, 'role', ?, ?) RETURNING taxonomyid"
        );
        $ins->execute([
            $def['name'],
            json_encode(['isSeed' => true, 'slug' => $slug]),
            $companyId,
        ]);
        $roleId = $ins->fetchColumn();

        // Insertar permisos como roleData
        $dataIns = $pdo->prepare(
            "INSERT INTO taxonomy (taxonomytype, sourceid, taxonomyextra, companyid)
             VALUES ('roleData', ?::uuid, ?, ?)"
        );
        $dataIns->execute([
            $roleId,
            json_encode(['permissions' => $def['perms']]),
            $companyId,
        ]);
        $created++;
    }
}

echo "[migrate] 52_seed_roles: $created roles creados (" . count($companies) . " companies)\n";

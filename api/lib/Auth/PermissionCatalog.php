<?php
/**
 * Catálogo canónico de permisos del sistema.
 * ÚNICO source of truth — agregar un permiso nuevo requiere:
 *   1. Editar este array.
 *   2. Ajustar los seed defaults en RoleService::seedCompanyRoles().
 *   3. Enforcar el permiso en el endpoint correspondiente vía hasPermission().
 */
final class PermissionCatalog
{
    /** @return list<array{id: string, label: string, group: string}> */
    public static function all(): array
    {
        return [
            ['id' => 'pos.sale.create',        'label' => 'Crear ventas',              'group' => 'POS'],
            ['id' => 'pos.sale.void',           'label' => 'Anular ventas',             'group' => 'POS'],
            ['id' => 'pos.sale.refund',         'label' => 'Devoluciones',              'group' => 'POS'],
            ['id' => 'pos.drawer.open',         'label' => 'Abrir caja',               'group' => 'POS'],
            ['id' => 'pos.drawer.close',        'label' => 'Cerrar caja',              'group' => 'POS'],
            ['id' => 'pos.discount.apply',      'label' => 'Aplicar descuentos',       'group' => 'POS'],

            ['id' => 'inventory.item.view',    'label' => 'Ver catálogo',             'group' => 'Inventario'],
            ['id' => 'inventory.item.create',  'label' => 'Crear artículos',          'group' => 'Inventario'],
            ['id' => 'inventory.item.edit',    'label' => 'Editar artículos',         'group' => 'Inventario'],
            ['id' => 'inventory.item.delete',  'label' => 'Eliminar artículos',       'group' => 'Inventario'],
            ['id' => 'inventory.stock.adjust', 'label' => 'Ajustes de stock',         'group' => 'Inventario'],
            ['id' => 'inventory.transfer',     'label' => 'Transferencias entre sucursales', 'group' => 'Inventario'],

            ['id' => 'contacts.customer.view',   'label' => 'Ver clientes',           'group' => 'Contactos'],
            ['id' => 'contacts.customer.create', 'label' => 'Crear clientes',         'group' => 'Contactos'],
            ['id' => 'contacts.customer.edit',   'label' => 'Editar clientes',        'group' => 'Contactos'],
            ['id' => 'contacts.customer.delete', 'label' => 'Eliminar clientes',      'group' => 'Contactos'],
            ['id' => 'contacts.supplier.view',   'label' => 'Ver proveedores',        'group' => 'Contactos'],
            ['id' => 'contacts.supplier.manage', 'label' => 'Gestionar proveedores',  'group' => 'Contactos'],
            ['id' => 'contacts.user.view',       'label' => 'Ver usuarios',           'group' => 'Contactos'],
            ['id' => 'contacts.user.manage',     'label' => 'Gestionar usuarios',     'group' => 'Contactos'],

            ['id' => 'reports.sales.view',       'label' => 'Reportes de ventas',     'group' => 'Reportes'],
            ['id' => 'reports.drawers.view',     'label' => 'Reportes de cajas',      'group' => 'Reportes'],
            ['id' => 'reports.audit.view',       'label' => 'Auditoría',              'group' => 'Reportes'],
            ['id' => 'reports.expenses.view',    'label' => 'Reportes de gastos',     'group' => 'Reportes'],
            ['id' => 'reports.satisfaction.view','label' => 'Reportes de satisfacción','group' => 'Reportes'],
            ['id' => 'reports.giftcards.view',   'label' => 'Reportes de gift cards', 'group' => 'Reportes'],
            ['id' => 'reports.purchases.view',   'label' => 'Reportes de compras',    'group' => 'Reportes'],
            ['id' => 'reports.schedule.view',    'label' => 'Reportes de agenda',     'group' => 'Reportes'],
            ['id' => 'reports.recurring.view',   'label' => 'Reportes recurrentes',   'group' => 'Reportes'],

            ['id' => 'settings.outlet.manage',   'label' => 'Gestionar sucursales',   'group' => 'Configuración'],
            ['id' => 'settings.register.manage', 'label' => 'Gestionar cajas',        'group' => 'Configuración'],
            ['id' => 'settings.tax.manage',      'label' => 'Gestionar impuestos',    'group' => 'Configuración'],
            ['id' => 'settings.template.manage', 'label' => 'Gestionar plantillas',   'group' => 'Configuración'],
            ['id' => 'settings.device.pair',     'label' => 'Parear dispositivos POS','group' => 'Configuración'],
            ['id' => 'settings.device.manage',   'label' => 'Gestionar dispositivos', 'group' => 'Configuración'],
            ['id' => 'settings.role.manage',     'label' => 'Gestionar roles y permisos','group' => 'Configuración'],
            ['id' => 'settings.company.edit',    'label' => 'Editar datos del comercio','group' => 'Configuración'],

            ['id' => 'billing.view',             'label' => 'Ver facturación',         'group' => 'Facturación'],
            ['id' => 'billing.manage',           'label' => 'Gestionar plan y pagos',  'group' => 'Facturación'],

            ['id' => 'finance.manage',           'label' => 'Gestionar finanzas',      'group' => 'Finanzas'],

            ['id' => 'ai.agent.use',             'label' => 'Usar agente IA',          'group' => 'IA'],
            ['id' => 'ai.agent.elevated',        'label' => 'IA con permisos elevados','group' => 'IA'],
        ];
    }

    /** @return array<string, list<array{id: string, label: string}>> */
    public static function byGroup(): array
    {
        $grouped = [];
        foreach (self::all() as $p) {
            $grouped[$p['group']][] = ['id' => $p['id'], 'label' => $p['label']];
        }
        return $grouped;
    }

    /** Set rápido para validación. */
    public static function ids(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = array_column(self::all(), 'id');
        }
        return $ids;
    }
}

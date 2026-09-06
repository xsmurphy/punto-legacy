<?php
/**
 * Catálogo canónico de permisos del sistema.
 * ÚNICO source of truth — agregar un permiso nuevo requiere:
 *   1. Editar este array, agregando la entrada con `since` = CURRENT_VERSION + 1.
 *   2. Bumpear CURRENT_VERSION.
 *   3. Ajustar los seed defaults en RoleService::SEED_PERMISSIONS (owner los
 *      recibe siempre, sin editar nada — ver RoleService::getPermissions()).
 *   4. Enforcar el permiso en el endpoint correspondiente vía hasPermission().
 *
 * Backfill a roles existentes: NO es manual. RoleService reconcilia de forma
 * lazy — en la primera lectura de un rol seed (manager/cashier) después del
 * deploy, si el seed default incluye un permiso con `since` posterior a la
 * versión con la que ese rol fue guardado por última vez, se lo agrega y
 * persiste. Un permiso ausente con `since` <= la versión guardada del rol se
 * asume revocado a propósito y NUNCA se revive. Ver
 * RoleService::_reconcileSeedGaps().
 */
final class PermissionCatalog
{
    /**
     * Versión asumida para todo permiso sin `since` explícito: el catálogo tal
     * como existía antes de introducir este mecanismo de versionado. Roles
     * guardados antes del backfill (sin `catalogVersion` en su roleData) se
     * asumen sincronizados hasta acá — nunca antes, nunca después.
     */
    public const BASELINE_VERSION = 1;

    /** Versión actual del catálogo. Bumpear +1 cada vez que se agrega un permiso nuevo que deba propagarse solo. */
    public const CURRENT_VERSION = 9;

    /** @return list<array{id: string, label: string, group: string, since?: int}> */
    public static function all(): array
    {
        return [
            ['id' => 'pos.sale.create',        'label' => 'Crear ventas',              'group' => 'POS'],
            ['id' => 'pos.sale.void',           'label' => 'Anular ventas',             'group' => 'POS'],
            ['id' => 'pos.sale.refund',         'label' => 'Devoluciones',              'group' => 'POS'],
            ['id' => 'pos.sale.creditPayment',  'label' => 'Cobrar crédito',            'group' => 'POS'],
            ['id' => 'pos.drawer.open',         'label' => 'Abrir caja',               'group' => 'POS'],
            ['id' => 'pos.drawer.close',        'label' => 'Cerrar caja',              'group' => 'POS'],
            ['id' => 'pos.discount.apply',      'label' => 'Aplicar descuentos',       'group' => 'POS'],
            // Exclusividad de espacios (context/15, owner 2026-08-23): un espacio
            // con mozo asignado solo la opera ese mozo. Esta clave es la
            // válvula de escape del encargado — intervenir el espacio de otro
            // (cancelarla, moverla, unirla, reasignarla).
            //
            // `since` = 5 y clave NUEVA: el caso seguro del backfill (nunca
            // existió, así que no puede estar revocada a propósito en ningún
            // tenant — ver la advertencia del docblock de since()).
            //
            // NO está en el seed del rol `device`, y no debe estarlo: bajo
            // `pos-app` ese rol es el mismo para todos los que usan la tablet,
            // así que dárselo ahí anularía la regla para todo el mundo. Se
            // evalúa contra el rol del OPERADOR (Punto\Api\Auth\OperatorContext).
            ['id' => 'pos.space.override',      'label' => 'Intervenir espacios de otro mozo', 'group' => 'POS', 'since' => 5],

            // Asistente de IA en la caja (context/59 D4). Gatea SOLO el item de
            // la UI y su ruta de chat: qué datos puede leer el asistente lo
            // gobiernan los allowlists de realm de cada endpoint, no esta clave.
            //
            // Por qué NO se reusa `ai.agent.use`: `unlock-pin.php:127-131` filtra
            // los permisos del operador al prefijo `pos.` antes de mandarlos al
            // dispositivo —a propósito, para no cachear permisos de panel en una
            // tablet compartida (el motivo está escrito en `:122-126`)—, así que
            // una clave sin ese prefijo NUNCA llega a la caja. De paso separa los
            // dos asistentes: habilitar el del mostrador sin abrir el del panel
            // hoy no se puede expresar con una sola clave.
            //
            // Se evalúa contra el rol del OPERADOR (OperatorContext), no contra el
            // rol `device` — misma razón que `pos.space.override`: bajo `pos-app`
            // ese rol es el mismo para todos los que usan la tablet.
            //
            // `since` = 6 y clave NUEVA: el caso seguro del backfill (nunca
            // existió, así que no puede estar revocada a propósito en ningún
            // tenant — ver la advertencia del docblock de since()).
            ['id' => 'pos.ai.use',              'label' => 'Usar el asistente en la caja', 'group' => 'POS', 'since' => 6],

            // Conteo de stock desde la caja (context/63 F1). El cajero no
            // entra al panel, así que `inventory.stock.adjust` —la clave que
            // gatea el conteo del panel— no le sirve: `unlock-pin.php` filtra
            // los permisos del operador al prefijo `pos.` antes de mandarlos
            // al dispositivo, y una clave sin ese prefijo NUNCA llega a la
            // caja (mismo motivo que `pos.ai.use`).
            //
            // No es la misma capacidad, tampoco: `inventory.stock.adjust`
            // autoriza a mover el inventario a mano y por cualquier motivo;
            // esta autoriza a CONTAR lo que está en el mostrador y dejar que
            // el sistema derive el ajuste de esa cuenta. Se le da al cajero
            // sin darle la otra.
            //
            // Se evalúa contra el rol del OPERADOR del PIN
            // (`OperatorContext`), no contra el rol `device`: bajo `pos-app`
            // ese rol es el mismo para todos los que usan la tablet, así que
            // dárselo ahí habilitaría el conteo para cualquiera que agarre el
            // mostrador — y el conteo ajusta stock. Por eso tampoco está en el
            // seed de `device`.
            //
            // `since` = 7 y clave NUEVA: el caso seguro del backfill (nunca
            // existió, así que no puede estar revocada a propósito en ningún
            // tenant — ver la advertencia del docblock de since()).
            ['id' => 'pos.stock.count',         'label' => 'Contar stock desde la caja', 'group' => 'POS', 'since' => 7],

            // ── Anulación de un ítem de comanda ───────────────────────────
            //
            // Son DOS claves porque son dos decisiones distintas del comercio:
            // quién puede anular una línea (el que toma pedidos: se equivocó
            // al cargar, el cliente cambió de idea) y quién puede hacerlo
            // DESPUÉS de que la cocina ya la tenía (el encargado, que se hace
            // cargo de la merma). La segunda no reemplaza a la primera: es una
            // elevación sobre ella, así que un rol con `.late` y sin la base
            // no anula nada.
            //
            // Ninguna de las dos va al rol `device` — se evalúan contra el rol
            // del OPERADOR del PIN (OperatorContext), misma razón que
            // `pos.stock.count` y `pos.space.override`: dárselas al device
            // significa "cualquiera que agarre la tablet", que es exactamente
            // lo que esta feature vino a cerrar.
            //
            // `since` = 8 y claves NUEVAS: el caso seguro del backfill (nunca
            // existieron, así que no pueden estar revocadas a propósito en
            // ningún tenant — ver la advertencia del docblock de since()).
            ['id' => 'pos.order.item.cancel',      'label' => 'Anular ítems de una comanda',            'group' => 'POS', 'since' => 8],
            ['id' => 'pos.order.item.cancel.late', 'label' => 'Anular ítems fuera de la ventana de tiempo', 'group' => 'POS', 'since' => 8],

            ['id' => 'inventory.item.view',    'label' => 'Ver catálogo',             'group' => 'Inventario'],
            ['id' => 'inventory.item.create',  'label' => 'Crear artículos',          'group' => 'Inventario'],
            ['id' => 'inventory.item.edit',    'label' => 'Editar artículos',         'group' => 'Inventario'],
            ['id' => 'inventory.item.delete',  'label' => 'Eliminar artículos',       'group' => 'Inventario'],
            ['id' => 'inventory.stock.adjust', 'label' => 'Ajustes de stock',         'group' => 'Inventario'],
            ['id' => 'inventory.transfer',     'label' => 'Transferencias entre sucursales', 'group' => 'Inventario'],

            // ── Orden de pago a proveedor (context/modules/08-compras.md) ──
            //
            // El documento que AUTORIZA pagarle a un proveedor: se arma
            // agrupando facturas de compra a crédito pendientes, alguien lo
            // aprueba, y recién ahí se ejecuta llamando al pago que ya existe.
            //
            // `approve` está SEPARADA de `create` a propósito — es el punto
            // entero de la feature. Segregación de tareas: el que arma el pago
            // no es necesariamente el que lo autoriza. Fundirlas en una sola
            // clave dejaría a la orden de pago como un paso burocrático sin
            // control real.
            //
            // El gate DURO es esta clave y siempre aplica. Encima hay un
            // ajuste por comercio (`settingPaymentOrderRequireSecondApprover`,
            // APAGADO por default) que, prendido, impide además que quien creó
            // la orden sea quien la aprueba. Apagado, el dueño que arma y
            // aprueba solo trabaja sin fricción — mismo criterio de "nace
            // apagado" que `settingDrawerRequireClosedOrders`.
            //
            // NINGUNA de las tres va al rol `device`: el POS no le compra a
            // proveedores (el pago a proveedor ya está cerrado al realm
            // `panel` en credit-payments.php) y el rol `device` es el mismo
            // para todos los que agarran la tablet.
            //
            // `since` = 9 y claves NUEVAS: el caso seguro del backfill (nunca
            // existieron, así que no pueden estar revocadas a propósito en
            // ningún tenant — ver la advertencia del docblock de since()).
            // Backfill a roles custom en la mig 197.
            ['id' => 'purchases.paymentorder.view',    'label' => 'Ver órdenes de pago',      'group' => 'Compras', 'since' => 9],
            ['id' => 'purchases.paymentorder.create',  'label' => 'Crear órdenes de pago',    'group' => 'Compras', 'since' => 9],
            ['id' => 'purchases.paymentorder.approve', 'label' => 'Aprobar órdenes de pago',  'group' => 'Compras', 'since' => 9],

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
            // Dedicado y separado de settings.register.manage (context/29 §4,
            // F4): liberar la tenencia desconecta a un dispositivo que puede
            // estar operando ahora mismo y anula sus números arrendados no
            // consumidos — otro radio de impacto que el CRUD de la caja.
            ['id' => 'settings.register.release', 'label' => 'Liberar tenencia de caja', 'group' => 'Configuración', 'since' => 2],
            ['id' => 'settings.tax.manage',      'label' => 'Gestionar impuestos',    'group' => 'Configuración'],
            ['id' => 'settings.template.manage', 'label' => 'Gestionar plantillas',   'group' => 'Configuración'],
            ['id' => 'settings.device.pair',     'label' => 'Parear dispositivos POS','group' => 'Configuración'],
            ['id' => 'settings.device.manage',   'label' => 'Gestionar dispositivos', 'group' => 'Configuración'],
            ['id' => 'settings.role.manage',     'label' => 'Gestionar roles y permisos','group' => 'Configuración'],
            ['id' => 'settings.company.edit',    'label' => 'Editar datos del comercio','group' => 'Configuración'],
            // D7/E1b de context/48-escalamiento-de-datos.md (mig 157): cerrar
            // un período es irreversible (no hay endpoint de reabrir), por
            // eso va detrás de un permiso propio en vez de colgar de
            // settings.company.edit.
            ['id' => 'settings.periodClose',     'label' => 'Cerrar períodos contables','group' => 'Configuración', 'since' => 3],

            // `since` = 4 no significa "la clave nació en la v4" (existe desde
            // el baseline): significa "a partir de la v4 tiene que propagarse
            // a los roles seed que no la tengan". Es lo único que el campo
            // maneja — ver el docblock de since() y _reconcileSeedGaps().
            // Se agregó al default de `manager` cuando billing.php pasó a
            // estar gateado: el encargado ya veía la pantalla de plan y
            // consumo y la necesita (saber que se acabaron los créditos de IA
            // es operativo). billing.manage NO — gastar plata es del dueño.
            ['id' => 'billing.view',             'label' => 'Ver facturación',         'group' => 'Facturación', 'since' => 4],
            ['id' => 'billing.manage',           'label' => 'Gestionar plan y pagos',  'group' => 'Facturación'],
            ['id' => 'einvoice.manage',          'label' => 'Gestionar facturación electrónica', 'group' => 'Facturación'],

            ['id' => 'finance.manage',           'label' => 'Gestionar finanzas',      'group' => 'Finanzas'],

            ['id' => 'production.manage',        'label' => 'Gestionar producción y merma', 'group' => 'Producción'],

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

    /**
     * Versión a partir de la cual `$id` debe propagarse a los roles seed que
     * no lo tengan (ver RoleService::_reconcileSeedGaps). Normalmente coincide
     * con la versión en la que la clave se introdujo, pero lo que el campo
     * gobierna es la PROPAGACIÓN: agregar una clave preexistente al default de
     * un seed también requiere subirle el `since` y bumpear CURRENT_VERSION,
     * porque si no los roles ya guardados nunca la reciben.
     *
     * ⚠ SUBIR EL `since` DE UNA CLAVE PREEXISTENTE REVIVE REVOCACIONES
     * MANUALES, y no hay forma de distinguirlas. `_reconcileSeedGaps()` decide
     * "esto le falta y hay que dárselo" comparando `since` contra el
     * `catalogVersion` con el que el rol fue guardado por última vez; no tiene
     * registro de por qué la clave no está. Los dos casos son indistinguibles:
     *
     *   (A) el rol se guardó antes de que la clave entrara al default del seed
     *       → nunca la tuvo, y dársela es el backfill que se quiere;
     *   (B) un admin se la SACÓ a mano al rol desde el panel → dársela es
     *       deshacer su decisión, en silencio y en TODOS los tenants a la vez.
     *
     * Ya pasó: `billing.view` existía desde el baseline y se le subió el
     * `since` a 4 al agregarla al default de `manager`. Cualquier `manager`
     * al que un admin le hubiera quitado "ver facturación" la recupera sola
     * en la primera lectura de permisos post-deploy.
     *
     * O sea: subir el `since` de una clave que ya existía es un cambio que
     * PISA configuración del tenant, no un backfill inocuo. Vale la pena
     * cuando la clave es de lectura y su ausencia rompe una pantalla; para
     * cualquier clave de escritura, o de radio de impacto alto, la opción
     * correcta es dejar el `since` como está y que cada admin la agregue.
     *
     * BASELINE_VERSION si no declara `since`.
     */
    public static function since(string $id): int
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::all() as $p) {
                $map[$p['id']] = $p['since'] ?? self::BASELINE_VERSION;
            }
        }
        return $map[$id] ?? self::BASELINE_VERSION;
    }
}

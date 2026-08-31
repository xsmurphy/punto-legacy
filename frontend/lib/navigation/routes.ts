import {
  ArrowLeftRight,
  Banknote,
  BarChart3,
  Bell,
  Blocks,
  Bookmark,
  Boxes,
  Building2,
  CalendarDays,
  ChartPie,
  ClipboardEdit,
  ClipboardList,
  Coins,
  Contact,
  CreditCard,
  Factory,
  FileCheck,
  FileText,
  Flame,
  Gift,
  Globe,
  HandCoins,
  History,
  KeyRound,
  Landmark,
  LayoutDashboard,
  LayoutGrid,
  LayoutTemplate,
  ListOrdered,
  Lock,
  MessageCircle,
  Monitor,
  Package,
  Palette,
  Plug,
  Plus,
  Printer,
  Receipt,
  ReceiptText,
  RefreshCw,
  Route,
  Scale,
  ScanBarcode,
  ScanLine,
  ScrollText,
  Settings as SettingsIcon,
  ShieldCheck,
  ShoppingBasket,
  SquareKanban,
  Tag,
  TrendingUp,
  Truck,
  UserCog,
  Users,
  Wallet,
  Warehouse,
} from "lucide-react"

import type {
  PaletteGroupKey,
  RouteEntry,
  SidebarGroupKey,
} from "@/lib/navigation/types"

/**
 * REGISTRO DE RUTAS DEL PANEL — fuente de verdad de la navegación.
 *
 * Antes había dos listas hardcodeadas y paralelas: los items del sidebar en
 * `panel-auth-guard.tsx` y `EXTRA_ROUTES` en `app-command-palette.tsx`. Nada
 * obligaba a que ninguna coincidiera con las páginas que existen, así que
 * cada página nueva nacía invisible; llegaron a faltar 35 en el buscador.
 *
 * Ahora las dos superficies se derivan de acá (`lib/navigation/build.ts`) y
 * el test `__tests__/routes-coverage.test.ts` lee el filesystem de
 * `app/(panel)` + `app/(pos)` y falla si una página no está indexada ni
 * excluida a propósito en `UNINDEXED_PAGES`.
 *
 * Para agregar una página:
 *  1. Sumá una entrada acá con su `requires` REAL (el que exige el endpoint
 *     que consume — no uno plausible).
 *  2. `surface: "palette"` salvo que haya razón para ocupar el sidebar, que
 *     es deliberadamente minimalista.
 *  3. Keywords en el idioma que la gente tipea, incluidos los alias en inglés.
 */

// ── Grupos ───────────────────────────────────────────────────────────────

/** Grupos colapsables del sidebar. Se crean en la posición de su primer item. */
export const SIDEBAR_GROUPS: Record<
  SidebarGroupKey,
  { title: string; icon: RouteEntry["icon"] }
> = {
  ventas: { title: "Ventas", icon: HandCoins },
  articulos: { title: "Artículos", icon: LayoutTemplate },
  compras: { title: "Compras y Gastos", icon: ShoppingBasket },
  contactos: { title: "Contactos", icon: Contact },
}

/** Orden de los headings secundarios del palette (después de "Navegación"). */
export const PALETTE_GROUP_ORDER: PaletteGroupKey[] = [
  "Operaciones",
  "Reportes",
  "Finanzas",
  "Configuración",
  "Catálogo",
]

// ── Rutas del panel ──────────────────────────────────────────────────────

export const PANEL_ROUTES: RouteEntry[] = [
  // ═══ Sidebar ═══════════════════════════════════════════════════════════
  // El orden de este bloque ES el orden del menú lateral.

  {
    to: "/",
    title: "Dashboard",
    icon: LayoutDashboard,
    surface: "sidebar",
    keywords: ["inicio", "home", "tablero", "panel", "resumen"],
  },
  {
    to: "/chat",
    title: "Asistente",
    icon: MessageCircle,
    surface: "sidebar",
    // Gate real del endpoint que consume la página: api/v1/ai/execute.php:23.
    requires: "ai.agent.use",
    hideOnMobile: true,
    keywords: ["ia", "ai", "agente", "chat", "asistente"],
  },

  // Ventas
  {
    to: "/reports/transactions",
    title: "Transacciones",
    icon: ScrollText,
    surface: "sidebar",
    sidebarGroup: "ventas",
    requires: "reports.sales.view",
    keywords: ["transactions", "ventas", "facturas", "tickets", "comprobantes"],
  },
  {
    to: "/reports/open-invoices",
    title: "Cuentas por cobrar",
    icon: HandCoins,
    surface: "sidebar",
    sidebarGroup: "ventas",
    requires: "reports.sales.view",
    keywords: ["open invoices", "credito", "deuda", "cobrar", "income", "morosos"],
  },
  {
    to: "/reports/giftcards",
    title: "Gift cards",
    icon: Gift,
    surface: "sidebar",
    sidebarGroup: "ventas",
    requires: "reports.giftcards.view",
    keywords: ["giftcard", "tarjeta regalo", "vales", "saldo"],
  },
  {
    to: "/reports/recurring",
    title: "Facturas recurrentes",
    icon: RefreshCw,
    surface: "sidebar",
    sidebarGroup: "ventas",
    requires: "reports.recurring.view",
    keywords: ["recurring", "suscripciones", "abonos", "mensualidades"],
  },

  // Artículos
  {
    to: "/items",
    title: "Catálogo",
    icon: ShoppingBasket,
    surface: "sidebar",
    sidebarGroup: "articulos",
    requires: "inventory.item.view",
    keywords: ["items", "productos", "articulos", "servicios", "catalogo"],
  },
  {
    to: "/inventory-count",
    title: "Inventario",
    icon: Boxes,
    surface: "sidebar",
    sidebarGroup: "articulos",
    requires: "inventory.stock.adjust",
    keywords: ["conteo", "inventory count", "recuento", "toma de inventario"],
  },
  {
    to: "/stock-adjustment",
    title: "Ajustes de stock",
    icon: ClipboardEdit,
    surface: "sidebar",
    sidebarGroup: "articulos",
    requires: "inventory.stock.adjust",
    keywords: ["adjustment", "merma", "ajuste", "correccion de stock"],
  },
  {
    to: "/stock-transfer",
    title: "Transferencias",
    icon: ArrowLeftRight,
    surface: "sidebar",
    sidebarGroup: "articulos",
    requires: "inventory.transfer",
    keywords: ["transfer", "traslado", "entre depositos", "sucursales"],
  },
  // Remisión (context/42) — cubre motivos que NO son traslado entre depósitos
  // propios (ese es "Transferencias" arriba): venta, devolución a proveedor,
  // consignación, exposición, compra. Mismo permiso que Transferencias — es el
  // mismo dominio de traslado de mercadería, no hay permission key dedicada.
  {
    to: "/remisiones",
    title: "Remisiones",
    icon: Route,
    surface: "sidebar",
    sidebarGroup: "articulos",
    requires: "inventory.transfer",
    keywords: ["remision", "nota de remision", "traslado", "despacho"],
  },
  {
    to: "/produccion",
    title: "Producción",
    icon: Factory,
    surface: "sidebar",
    sidebarGroup: "articulos",
    requires: "production.manage",
    keywords: ["produccion", "manufactura", "receta", "armado", "ensamble"],
  },

  // Compras y Gastos
  {
    to: "/purchase",
    title: "Registro de compras",
    icon: Package,
    surface: "sidebar",
    sidebarGroup: "compras",
    keywords: ["compra", "purchase", "nueva compra", "factura de proveedor"],
  },
  {
    to: "/reports/purchases",
    title: "Compras y gastos",
    icon: ClipboardList,
    surface: "sidebar",
    sidebarGroup: "compras",
    requires: "reports.purchases.view",
    keywords: ["purchases", "egresos", "gastos", "proveedores"],
  },
  {
    to: "/reports/open-invoices?state=outcome",
    title: "Cuentas por pagar",
    icon: Wallet,
    surface: "sidebar",
    sidebarGroup: "compras",
    requires: "reports.sales.view",
    keywords: ["open invoices", "pagar", "deuda proveedores", "outcome"],
  },
  {
    to: "/reports/expenses",
    title: "Movimientos de caja",
    icon: Banknote,
    surface: "sidebar",
    sidebarGroup: "compras",
    requires: "reports.expenses.view",
    keywords: ["expenses", "extracciones", "ingresos manuales", "cajon", "retiros"],
  },

  // Contactos
  {
    to: "/contacts?type=1",
    title: "Clientes",
    icon: Users,
    surface: "sidebar",
    sidebarGroup: "contactos",
    requires: "contacts.customer.view",
    keywords: ["customers", "clientes", "compradores"],
  },
  {
    to: "/contacts?type=2",
    title: "Proveedores",
    icon: Truck,
    surface: "sidebar",
    sidebarGroup: "contactos",
    requires: "contacts.supplier.view",
    keywords: ["suppliers", "proveedores", "vendors"],
  },
  {
    to: "/contacts?type=0",
    title: "Equipo",
    icon: UserCog,
    surface: "sidebar",
    sidebarGroup: "contactos",
    requires: "contacts.user.view",
    keywords: ["team", "staff", "empleados", "usuarios", "personal"],
  },

  {
    to: "/finanzas",
    title: "Finanzas",
    icon: Landmark,
    surface: "sidebar",
    requires: "finance.manage",
    keywords: ["finanzas", "tesoreria", "bancos", "saldos"],
  },
  {
    to: "/reports",
    title: "Reportes",
    icon: ChartPie,
    surface: "sidebar",
    requires: "reports.sales.view",
    keywords: ["reports", "informes", "analytics", "estadisticas"],
  },
  // Caja = POS dentro del propio panel.
  {
    to: "/pos",
    title: "Caja",
    icon: ScanBarcode,
    surface: "sidebar",
    keywords: ["pos", "caja", "punto de venta", "vender", "cobrar"],
  },

  // ═══ Solo palette ══════════════════════════════════════════════════════

  // ── Operaciones ────────────────────────────────────────────────────────
  {
    to: "/outlets",
    title: "Sucursales",
    icon: Building2,
    surface: "palette",
    paletteGroup: "Operaciones",
    keywords: ["outlet", "tienda", "branch", "local", "depositos"],
  },
  {
    to: "/items/barcodes",
    title: "Códigos de barras",
    icon: ScanBarcode,
    surface: "palette",
    paletteGroup: "Operaciones",
    // Lee el catálogo: `itemsRequiredPermission()` en api/v1/items.php:220
    // devuelve `inventory.item.view` para todo GET.
    requires: "inventory.item.view",
    keywords: ["barcode", "etiquetas", "labels", "print", "imprimir"],
  },
  {
    to: "/purchase/drafts",
    title: "Borradores de factura",
    icon: FileCheck,
    surface: "palette",
    paletteGroup: "Operaciones",
    keywords: ["borradores", "drafts", "facturas pendientes", "revisar", "ocr"],
  },
  {
    to: "/remisiones/new",
    title: "Nueva remisión",
    icon: Plus,
    surface: "palette",
    paletteGroup: "Operaciones",
    requires: "inventory.transfer",
    keywords: ["crear remision", "nueva remision", "emitir remision", "despachar"],
  },
  {
    to: "/stock-transfer/new",
    title: "Nueva transferencia de stock",
    icon: Plus,
    surface: "palette",
    paletteGroup: "Operaciones",
    requires: "inventory.transfer",
    keywords: ["crear transferencia", "nueva transferencia", "mover stock"],
  },
  {
    to: "/notificaciones",
    title: "Notificaciones",
    icon: Bell,
    surface: "palette",
    paletteGroup: "Operaciones",
    keywords: ["notifications", "avisos", "alertas", "novedades", "campana"],
  },
  {
    to: "/history-billing",
    title: "Mi Plan",
    icon: ReceiptText,
    surface: "palette",
    paletteGroup: "Operaciones",
    // api/v1/billing.php:29 — el GET pide `billing.view`. Sin la clave, el
    // item existía en el palette y devolvía 403 al entrar.
    requires: "billing.view",
    keywords: ["plan", "billing", "estado de cuenta", "suscripcion", "pagos"],
  },

  // ── Reportes ───────────────────────────────────────────────────────────
  //
  // OJO con los `requires` de este bloque: la mayoría de los endpoints de
  // `api/v1/reports/` NO tienen `hasPermission()` sobre el GET — solo exigen
  // sesión de panel. Acá se refleja el gate REAL: poner una clave plausible
  // (`reports.<x>.view`) escondería el reporte de gente que sí puede verlo, y
  // esas claves ni siquiera existen en `PermissionCatalog.php`. Si mañana el
  // backend gatea uno, se agrega el `requires` acá.
  {
    to: "/reports/summary",
    title: "Resumen",
    paletteTitle: "Reportes · Resumen",
    icon: BarChart3,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["summary", "totales", "ventas totales", "kpi"],
  },
  {
    to: "/reports/summary-year",
    title: "Resumen anual",
    paletteTitle: "Reportes · Resumen anual",
    icon: BarChart3,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["anual", "year", "comparativo", "por mes", "ejercicio"],
  },
  {
    to: "/reports/products",
    title: "Productos y servicios",
    paletteTitle: "Reportes · Productos y servicios",
    icon: Tag,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["products", "ranking", "items", "vendidos", "top items"],
  },
  {
    to: "/reports/categories",
    title: "Categorías",
    paletteTitle: "Reportes · Categorías",
    icon: Tag,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["categorias", "categories", "ranking", "ventas por categoria"],
  },
  {
    to: "/reports/brands",
    title: "Marcas",
    paletteTitle: "Reportes · Marcas",
    icon: Building2,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["brands", "marcas", "ranking", "fabricantes"],
  },
  {
    to: "/reports/payment-methods",
    title: "Medios de pago",
    paletteTitle: "Reportes · Medios de pago",
    icon: CreditCard,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["payment methods", "tarjeta", "efectivo", "cobros", "qr"],
  },
  {
    to: "/reports/orders",
    title: "Órdenes",
    paletteTitle: "Reportes · Órdenes",
    icon: SquareKanban,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["orders", "pedidos", "comandas", "delivery", "mesas"],
  },
  {
    to: "/reports/customers",
    title: "Análisis de clientes",
    paletteTitle: "Reportes · Análisis de clientes",
    icon: Users,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["customers", "ranking clientes", "consumo", "loyalty"],
  },
  {
    to: "/reports/drawers",
    title: "Control de cajas",
    paletteTitle: "Reportes · Control de cajas",
    icon: Wallet,
    surface: "palette",
    paletteGroup: "Reportes",
    requires: "reports.drawers.view",
    keywords: ["drawers", "arqueo", "cierre de caja", "apertura", "turnos"],
  },
  {
    to: "/reports/cashflow",
    title: "Flujo de efectivo",
    paletteTitle: "Reportes · Flujo de efectivo",
    icon: TrendingUp,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["cashflow", "flujo", "caja", "ingresos egresos", "liquidez", "efectivo"],
  },
  {
    to: "/reports/balance",
    title: "Balance",
    paletteTitle: "Reportes · Balance",
    icon: Scale,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["balance", "activo", "pasivo", "patrimonio", "situacion", "cuanto tengo"],
  },
  {
    to: "/reports/inventory",
    title: "Movimientos de inventario",
    paletteTitle: "Reportes · Movimientos de inventario",
    icon: Boxes,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["inventory", "kardex", "movimientos de stock", "entradas salidas"],
  },
  {
    to: "/reports/stock",
    title: "Niveles de stock",
    paletteTitle: "Reportes · Niveles de stock",
    icon: Warehouse,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["stock", "existencias", "saldo", "faltantes", "reposicion"],
  },
  {
    to: "/reports/production",
    title: "Reporte de producción",
    paletteTitle: "Reportes · Producción",
    icon: Factory,
    surface: "palette",
    paletteGroup: "Reportes",
    keywords: ["produccion", "manufactura", "producido", "recetas"],
  },
  {
    to: "/reports/users",
    title: "Equipo",
    paletteTitle: "Reportes · Equipo",
    icon: UserCog,
    surface: "palette",
    paletteGroup: "Reportes",
    // "staff"/"usuarios" siguen como keywords: el módulo se renombró a Equipo
    // pero los cajeros lo buscan por el nombre viejo.
    keywords: ["users", "staff", "usuarios", "cajeros", "vendedores", "desempeño", "comisiones"],
  },
  {
    to: "/reports/audit",
    title: "Auditoría",
    paletteTitle: "Reportes · Auditoría",
    icon: History,
    surface: "palette",
    paletteGroup: "Reportes",
    requires: "reports.audit.view",
    keywords: ["audit", "log", "bitacora", "historial", "quien hizo"],
  },

  // ── Finanzas ───────────────────────────────────────────────────────────
  // Las sub-secciones son tabs de `/finanzas` (ver app/(panel)/finanzas/layout.tsx).
  {
    to: "/finanzas/movimientos",
    title: "Movimientos",
    paletteTitle: "Finanzas · Movimientos",
    icon: Banknote,
    surface: "palette",
    paletteGroup: "Finanzas",
    requires: "finance.manage",
    keywords: ["movimientos", "transacciones bancarias", "extracto"],
  },
  {
    to: "/finanzas/cuentas",
    title: "Cuentas",
    paletteTitle: "Finanzas · Cuentas",
    icon: Landmark,
    surface: "palette",
    paletteGroup: "Finanzas",
    requires: "finance.manage",
    keywords: ["cuentas", "bancos", "caja chica", "billeteras", "accounts"],
  },
  {
    to: "/finanzas/cheques",
    title: "Cheques",
    paletteTitle: "Finanzas · Cheques",
    icon: ReceiptText,
    surface: "palette",
    paletteGroup: "Finanzas",
    requires: "finance.manage",
    keywords: ["cheques", "checks", "diferidos", "cartera"],
  },
  {
    to: "/finanzas/creditos",
    title: "Créditos",
    paletteTitle: "Finanzas · Créditos",
    icon: HandCoins,
    surface: "palette",
    paletteGroup: "Finanzas",
    requires: "finance.manage",
    keywords: ["creditos", "prestamos", "loans", "financiamiento", "cuotas"],
  },
  {
    to: "/finanzas/prevision",
    title: "Previsión",
    paletteTitle: "Finanzas · Previsión",
    icon: TrendingUp,
    surface: "palette",
    paletteGroup: "Finanzas",
    requires: "finance.manage",
    keywords: ["prevision", "proyeccion", "forecast", "vencimientos", "a futuro"],
  },
  {
    to: "/finanzas/conciliacion",
    title: "Conciliación",
    paletteTitle: "Finanzas · Conciliación",
    icon: FileCheck,
    surface: "palette",
    paletteGroup: "Finanzas",
    requires: "finance.manage",
    keywords: ["conciliacion", "bancaria", "reconcile", "cruzar extracto"],
  },
  {
    to: "/finanzas/reportes",
    title: "Reportes de finanzas",
    paletteTitle: "Finanzas · Reportes",
    icon: BarChart3,
    surface: "palette",
    paletteGroup: "Finanzas",
    requires: "finance.manage",
    keywords: [
      "reportes financieros",
      "informes",
      "resultados",
      "gastos por centro de costo",
    ],
  },
  {
    to: "/finanzas/configuracion",
    title: "Configuración de finanzas",
    paletteTitle: "Finanzas · Configuración",
    icon: SettingsIcon,
    surface: "palette",
    paletteGroup: "Finanzas",
    requires: "finance.manage",
    // "centros de costo" y "codigo contable" entran acá y no como página
    // propia: son tabs de esta pantalla (mig 167). Sin la keyword, buscar
    // "centro de costo" en el palette no encuentra nada — el concepto existe
    // pero no tiene deep-link.
    keywords: [
      "configuracion",
      "categorias de gasto",
      "centros de costo",
      "codigo contable",
      "plan de cuentas",
      "medios de pago",
      "ajustes",
    ],
  },

  // ── Configuración ──────────────────────────────────────────────────────
  //
  // Los tabs internos de /settings NO tienen deep-link (`?section=` no está
  // implementado): todos abren la pantalla en "Empresa". Se indexan igual
  // porque el valor está en que el buscador encuentre el concepto.
  //
  // Sin `requires` a propósito (verificado contra el backend, no olvido):
  // Espacios (api/v1/spaces.php), Listas de precios (price_list.php),
  // Impresoras (printer_binding.php, station-printers.php), Sesiones
  // (sessions.php) y Módulos (modules.php) no llaman `hasPermission()` — les
  // alcanza con sesión de panel.
  {
    to: "/settings",
    title: "Empresa",
    paletteTitle: "Configuración · Empresa",
    icon: SettingsIcon,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["company", "datos", "razon social", "ruc", "logo"],
  },
  {
    to: "/settings",
    title: "Localización",
    paletteTitle: "Configuración · Localización",
    icon: Globe,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["idioma", "zona horaria", "moneda", "pais", "language", "formato"],
  },
  {
    to: "/settings",
    title: "POS",
    paletteTitle: "Configuración · POS",
    icon: ScanLine,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["caja", "pos", "punto de venta", "ventas"],
  },
  {
    to: "/settings",
    title: "Monedas",
    paletteTitle: "Configuración · Monedas",
    icon: Coins,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["monedas", "currency", "cotizacion", "dolar", "cambio"],
  },
  {
    to: "/settings",
    title: "Apariencia",
    paletteTitle: "Configuración · Apariencia",
    icon: Palette,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["tema", "dark", "light", "oscuro", "claro", "theme"],
  },
  {
    to: "/settings",
    title: "Documentos",
    paletteTitle: "Configuración · Documentos",
    icon: FileText,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["plantilla", "template", "factura", "ticket", "timbrado"],
  },
  {
    to: "/settings/print-templates",
    title: "Plantillas de impresión (editor)",
    icon: FileText,
    surface: "palette",
    paletteGroup: "Configuración",
    // api/v1/document-templates.php:41 gatea las mutaciones. Es un editor: sin
    // la clave no se puede hacer nada útil adentro.
    requires: "settings.template.manage",
    keywords: ["editor", "template builder", "diseñar", "ticket", "comanda"],
  },
  {
    to: "/settings/printers",
    title: "Impresoras",
    paletteTitle: "Configuración · Impresoras",
    icon: Printer,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["printers", "impresora", "comandera", "ticketera", "usb", "red"],
  },
  {
    to: "/settings/devices",
    title: "Dispositivos",
    paletteTitle: "Configuración · Dispositivos",
    icon: Monitor,
    surface: "palette",
    paletteGroup: "Configuración",
    // api/v1/devices.php:25 — gate de archivo entero, incluido el GET.
    requires: "settings.device.manage",
    keywords: ["devices", "cajas", "tablets", "pareo", "vincular", "terminal"],
  },
  {
    to: "/settings/sessions",
    title: "Sesiones",
    paletteTitle: "Configuración · Sesiones",
    icon: KeyRound,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["sessions", "sesiones activas", "cerrar sesion", "seguridad", "tokens"],
  },
  {
    to: "/settings/api-keys",
    title: "Keys de integración",
    paletteTitle: "Configuración · Keys de integración",
    icon: KeyRound,
    surface: "palette",
    paletteGroup: "Configuración",
    // api/v1/api-keys.php — gate de archivo entero, incluido el GET: listar ya
    // dice cuántas integraciones hay y cuándo se usaron por última vez.
    requires: "settings.company.edit",
    keywords: ["mcp", "api key", "claude", "integracion", "ia", "token"],
  },
  {
    to: "/settings/roles",
    title: "Roles y permisos",
    paletteTitle: "Configuración · Roles y permisos",
    icon: ShieldCheck,
    surface: "palette",
    paletteGroup: "Configuración",
    // api/v1/roles.php:8 — gate de archivo entero.
    requires: "settings.role.manage",
    keywords: ["roles", "permisos", "permissions", "accesos", "perfiles"],
  },
  {
    to: "/settings/price-lists",
    title: "Listas de precios",
    paletteTitle: "Configuración · Listas de precios",
    icon: ListOrdered,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["price lists", "precios", "mayorista", "tarifas", "lista"],
  },
  {
    to: "/settings/espacios",
    title: "Espacios",
    paletteTitle: "Configuración · Espacios",
    icon: LayoutGrid,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["espacios", "mesas", "salon", "layout", "tables", "planos"],
  },
  {
    to: "/settings/facturacion-electronica",
    title: "Facturación electrónica",
    paletteTitle: "Configuración · Facturación electrónica",
    icon: FileCheck,
    surface: "palette",
    paletteGroup: "Configuración",
    // api/v1/einvoice.php:137 + gate de UI en einvoice-manager.tsx:81.
    requires: "einvoice.manage",
    keywords: ["factura electronica", "sifen", "set", "kude", "cdc", "e-invoice"],
  },
  {
    to: "/settings/cierre-de-periodo",
    title: "Cierre de período",
    paletteTitle: "Configuración · Cierre de período",
    icon: Lock,
    surface: "palette",
    paletteGroup: "Configuración",
    // El GET del listado es ungated, pero la única acción de la pantalla —
    // cerrar el período — pide `settings.periodClose` (api/v1/period-close.php:121).
    // Sin la clave la pantalla no sirve para nada, así que se esconde.
    requires: "settings.periodClose",
    keywords: ["cierre", "periodo", "bloquear mes", "contable", "period close"],
  },
  {
    to: "/modules",
    title: "Módulos",
    paletteTitle: "Configuración · Módulos",
    icon: Blocks,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: ["modules", "modulos", "activar", "features", "espacios", "calendario"],
  },
  {
    // Mismo catálogo y mismo endpoint que Módulos (`/v1/modules`), filtrado
    // por `kind: "integration"` — ver `lib/modules-catalog.ts`. Sin `requires`
    // por la misma razón que Módulos: el endpoint no pide permiso propio.
    to: "/integraciones",
    title: "Integraciones",
    paletteTitle: "Configuración · Integraciones",
    icon: Plug,
    surface: "palette",
    paletteGroup: "Configuración",
    keywords: [
      "integraciones",
      "integrations",
      "bancard",
      "upay",
      "pasarela",
      "facturacion electronica",
      "sifen",
      "terceros",
    ],
  },

  // ── Catálogo (deep-link a cada tab) ────────────────────────────────────
  {
    to: "/settings/catalog?tab=categories",
    title: "Categorías",
    paletteTitle: "Catálogo · Categorías",
    icon: Tag,
    surface: "palette",
    paletteGroup: "Catálogo",
    keywords: ["categories", "tag", "clasificar", "rubros"],
  },
  {
    to: "/settings/catalog?tab=brands",
    title: "Marcas",
    paletteTitle: "Catálogo · Marcas",
    icon: Building2,
    surface: "palette",
    paletteGroup: "Catálogo",
    keywords: ["brands", "fabricantes", "marca"],
  },
  {
    to: "/settings/catalog?tab=taxes",
    title: "Impuestos",
    paletteTitle: "Catálogo · Impuestos",
    icon: Receipt,
    surface: "palette",
    paletteGroup: "Catálogo",
    keywords: ["taxes", "iva", "tax", "impuesto", "tasas"],
  },
]

// ── Rutas del POS ────────────────────────────────────────────────────────

/**
 * Menú de la caja. No entra al palette (dentro de `/pos` el buscador del panel
 * está desactivado: la caja tiene su propia lupa), pero vive en el registro
 * para que el test de cobertura también cuide `app/(pos)`.
 *
 * Espacios / Calendario / Órdenes dependen de módulos del tenant: si el módulo
 * no está confirmado como activo, el item no aparece (default conservador).
 */
export const POS_ROUTES: RouteEntry[] = [
  { to: "/pos", title: "Hotkeys", icon: Flame, surface: "sidebar" },
  {
    to: "/pos/espacios",
    title: "Espacios",
    icon: LayoutGrid,
    surface: "sidebar",
    requiresModule: "tables",
  },
  {
    to: "/pos/calendario",
    title: "Calendario",
    icon: CalendarDays,
    surface: "sidebar",
    requiresModule: "calendar",
  },
  {
    to: "/pos/ordenes",
    title: "Órdenes",
    icon: SquareKanban,
    surface: "sidebar",
    requiresModule: "ordersPanel",
  },
  {
    to: "/pos/guardadas",
    title: "Guardadas",
    icon: Bookmark,
    surface: "sidebar",
    badgeKey: "parkedSales",
  },
]

// ── Exclusiones deliberadas ──────────────────────────────────────────────

/**
 * Páginas que existen pero NO se indexan, con el motivo. El test de cobertura
 * exige que toda página real esté acá o en el registro — y también que estas
 * sigan existiendo, para que la lista no se pudra.
 *
 * Las rutas dinámicas (`[id]`) quedan fuera por definición: no se pueden
 * buscar sin conocer el registro que se quiere abrir.
 */
export const UNINDEXED_PAGES: Record<string, string> = {
  "/finanzas/ajustes":
    "Redirect a /finanzas/configuracion — sobrevive solo para links y bookmarks viejos.",
  "/finanzas/categorias":
    "Redirect a /finanzas/configuracion — sobrevive solo para links y bookmarks viejos.",
  "/settings/team":
    "Redirect a /contacts?tab=team — el destino real ya se indexa como Contactos · Equipo.",
  "/pos/transactions":
    "Vista interna de la caja: se abre desde la toolbar del POS, y dentro de /pos el palette del panel está desactivado.",
}

/**
 * Catálogo de módulos nativos de Punto.
 *
 * - `configKind` controla qué dialog de config se muestra en el panel.
 * - `configHref` (opcional): si está seteado, el botón "Configurar" navega
 *   a esa ruta en vez de abrir el `ModuleConfigDialog` — para módulos cuya
 *   config es una página propia, no un form chico de dialog (ej. Facturación
 *   Electrónica: conexión de cuenta + próximamente documentos/reintentos,
 *   demasiado para un dialog). `configKind` en ese caso queda en "none": el
 *   dialog nunca se monta, `configHref` es la única señal que importa.
 * - `status: 'soon'` → sin switch, muestra Badge "Próximamente", card muted.
 * - `status: 'available'` → switch normal, toggle habilitado.
 * - Los módulos `soon` (campaigns, reminder) NO se envían al backend
 *   (no están en el allowlist de ModulesService).
 */

import type { ComponentType } from "react"
import {
  Globe,
  Clock,
  ScanBarcode,
  Heart,
  MessageCircle,
  Contact,
  Megaphone,
  CalendarDays,
  LayoutGrid,
  ChefHat,
  MonitorSmartphone,
  Monitor,
  Tv,
  ClipboardList,
  Repeat,
  BellRing,
  FileText,
  Mail,
  Code,
  Bell,
  ReceiptText,
} from "lucide-react"

export type ConfigKind =
  | "none"
  | "loyalty"
  | "tables"
  | "orders"
  | "feedback"
  | "crm"
  | "comingSoon"

export type ModuleStatus = "available" | "soon"

export interface ModuleCatalogEntry {
  key: string
  title: string
  description: string
  icon: ComponentType<{ className?: string }>
  category: string
  configKind: ConfigKind
  status: ModuleStatus
  /** Ruta a la que navega "Configurar" en vez de abrir el dialog. Ver docblock arriba. */
  configHref?: string
}

export const MODULES_CATALOG: ModuleCatalogEntry[] = [
  // ── Destacados ───────────────────────────────────────────────────────────
  {
    key: "ecom",
    title: "eCommerce",
    description:
      "Llevá tu negocio a la web, un canal de ventas sincronizado con tu local.",
    icon: Globe,
    category: "Destacados",
    configKind: "comingSoon",
    status: "soon",
  },
  {
    key: "attendance",
    title: "Control de Asistencia",
    description: "Llevá el control de las horas trabajadas de tu staff.",
    icon: Clock,
    category: "Destacados",
    configKind: "none",
    status: "available",
  },
  {
    key: "priceCheck",
    title: "Verificador de Precios",
    description: "Permití a tus clientes consultar precios en el local.",
    icon: ScanBarcode,
    category: "Destacados",
    configKind: "none",
    status: "soon",
  },

  // ── Marketing y Fidelización ─────────────────────────────────────────────
  {
    key: "loyalty",
    title: "Fidelización",
    description: "Premiá a tus clientes más fieles con puntos.",
    icon: Heart,
    category: "Marketing y Fidelización",
    configKind: "loyalty",
    status: "available",
  },
  {
    key: "feedback",
    title: "Feedback",
    description: "Hacé que tus clientes califiquen su experiencia.",
    icon: MessageCircle,
    category: "Marketing y Fidelización",
    configKind: "feedback",
    status: "soon",
  },
  {
    key: "crm",
    title: "CRM",
    description: "Gestioná la relación con tus clientes.",
    icon: Contact,
    category: "Marketing y Fidelización",
    configKind: "crm",
    status: "soon",
  },
  {
    key: "campaigns",
    title: "Campañas masivas",
    description: "Enviá promociones por email y SMS.",
    icon: Megaphone,
    category: "Marketing y Fidelización",
    configKind: "none",
    status: "soon",
  },

  // ── Operativos ───────────────────────────────────────────────────────────
  {
    key: "calendar",
    title: "Agenda y Calendario",
    description: "Gestioná citas y reservas.",
    icon: CalendarDays,
    category: "Operativos",
    configKind: "none",
    status: "available",
  },
  {
    key: "tables",
    title: "Espacios",
    description: "Gestioná los espacios de tu local — mesas, sillas de atención, habitaciones.",
    icon: LayoutGrid,
    category: "Operativos",
    configKind: "tables",
    status: "available",
  },
  {
    key: "production",
    title: "Producción",
    description: "Recetas, mermas y compuestos.",
    icon: ChefHat,
    category: "Operativos",
    configKind: "none",
    status: "available",
  },
  // Las PANTALLAS (KDS, pantalla cliente, visor de cobro, despacho) no son
  // módulos: se crean como dispositivos en Configuración → Dispositivos
  // (device-invite-create-dialog). Estuvieron acá como cards toggleables
  // (kds/cds/cos) y se retiraron 2026-07-31 — el flag de company que leía el
  // POS legacy sigue existiendo en el backend (ModulesService.NATIVE_KEYS)
  // pero no se administra desde el catálogo.
  {
    key: "ordersPanel",
    title: "Panel de Órdenes",
    description: "Gestioná todas tus órdenes en un solo lugar.",
    icon: ClipboardList,
    category: "Operativos",
    configKind: "orders",
    status: "available",
  },

  // ── Facturación ──────────────────────────────────────────────────────────
  {
    key: "recurring",
    title: "Suscripciones",
    description: "Generá suscripciones, membresías y cuotas automáticas.",
    icon: Repeat,
    category: "Facturación",
    configKind: "none",
    status: "available",
  },
  {
    key: "dunning",
    title: "Cobranzas",
    description: "Seguimiento automatizado a clientes deudores por email y SMS.",
    icon: BellRing,
    category: "Facturación",
    configKind: "none",
    status: "soon",
  },
  {
    key: "digitalInvoice",
    title: "Factura en PDF",
    description: "Emití comprobantes digitales en PDF.",
    icon: FileText,
    category: "Facturación",
    configKind: "comingSoon",
    status: "available",
  },
  {
    key: "salesSummaryDaily",
    title: "Reportes diarios",
    description: "Recibí por email el rendimiento diario de tu negocio.",
    icon: Mail,
    category: "Facturación",
    configKind: "none",
    status: "soon",
  },
  {
    key: "einvoicePy",
    title: "Facturación Electrónica",
    description: "Emití facturas electrónicas habilitadas por la SET (SIFEN) directo desde tus ventas.",
    icon: ReceiptText,
    category: "Facturación",
    configKind: "none",
    status: "available",
    configHref: "/settings/facturacion-electronica",
  },

  // ── Otros ────────────────────────────────────────────────────────────────
  {
    key: "api",
    title: "API de Punto",
    description: "Conectá otras plataformas o sistemas a Punto.",
    icon: Code,
    category: "Otros",
    configKind: "none",
    status: "soon",
  },
  {
    key: "reminder",
    title: "Recordatorios",
    description: "Recordatorios personalizados para tu equipo.",
    icon: Bell,
    category: "Otros",
    configKind: "none",
    status: "soon",
  },
]

/** Categorías en el orden de presentación en el catálogo. */
export const MODULE_CATEGORIES = [
  "Destacados",
  "Marketing y Fidelización",
  "Operativos",
  "Facturación",
  "Otros",
] as const

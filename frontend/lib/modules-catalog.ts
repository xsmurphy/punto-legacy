/**
 * Catálogo de módulos nativos de Punto.
 *
 * - `configKind` controla qué dialog de config se muestra en el panel.
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
    status: "available",
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
    status: "available",
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
    status: "available",
  },
  {
    key: "crm",
    title: "CRM",
    description: "Gestioná la relación con tus clientes.",
    icon: Contact,
    category: "Marketing y Fidelización",
    configKind: "crm",
    status: "available",
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
    title: "Mesas y Espacios",
    description: "Gestioná las mesas de tu local.",
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
  {
    key: "kds",
    title: "Kitchen Display",
    description: "Gestioná pedidos desde una pantalla, sin imprimir.",
    icon: MonitorSmartphone,
    category: "Operativos",
    configKind: "none",
    status: "available",
  },
  {
    key: "cds",
    title: "Customer Display",
    description: "Avisá a tus clientes cuando su pedido esté listo.",
    icon: Monitor,
    category: "Operativos",
    configKind: "none",
    status: "available",
  },
  {
    key: "cos",
    title: "Visor de Cobro",
    description: "Pantalla de venta para el cliente en la caja.",
    icon: Tv,
    category: "Operativos",
    configKind: "none",
    status: "available",
  },
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
    status: "available",
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
    status: "available",
  },

  // ── Otros ────────────────────────────────────────────────────────────────
  {
    key: "api",
    title: "API de Punto",
    description: "Conectá otras plataformas o sistemas a Punto.",
    icon: Code,
    category: "Otros",
    configKind: "none",
    status: "available",
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

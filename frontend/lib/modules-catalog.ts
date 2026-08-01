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
  Heart,
  MessageCircle,
  CalendarDays,
  LayoutGrid,
  ChefHat,
  ClipboardList,
  Repeat,
  BellRing,
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
    key: "einvoicePy",
    title: "Facturación Electrónica",
    description: "Emití facturas electrónicas habilitadas por la SET (SIFEN) directo desde tus ventas.",
    icon: ReceiptText,
    category: "Destacados",
    configKind: "none",
    status: "available",
    configHref: "/settings/facturacion-electronica",
  },
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
  // pero no se administra desde el catálogo. El Verificador de Precios
  // (priceCheck) es también un dispositivo: cuando exista, va como tipo de
  // dispositivo en esa pantalla, no como módulo.
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
]

/** Categorías en el orden de presentación en el catálogo. */
export const MODULE_CATEGORIES = [
  "Destacados",
  "Operativos",
  "Marketing y Fidelización",
  "Facturación",
] as const

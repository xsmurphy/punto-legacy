/**
 * Catálogo de módulos e integraciones de Punto.
 *
 * Es UNA sola lista: `/modules` y `/integraciones` son dos vistas filtradas
 * por `kind` sobre este archivo. No duplicar entradas ni mantener una segunda
 * lista — el storage backend (`/v1/modules`) también es uno solo.
 *
 * - `kind: "module"` → capacidad del propio producto (se administra en /modules).
 * - `kind: "integration"` → puente con un sistema de un tercero; necesita
 *   credenciales o alta externa (se administra en /integraciones).
 * - `configKind` controla qué dialog de config se muestra en el panel.
 * - `configHref` (opcional): si está seteado, el botón "Configurar" navega
 *   a esa ruta en vez de abrir el `ModuleConfigDialog` — para módulos cuya
 *   config es una página propia, no un form chico de dialog (ej. Facturación
 *   Electrónica: conexión de cuenta + próximamente documentos/reintentos,
 *   demasiado para un dialog). `configKind` en ese caso queda en "none": el
 *   dialog nunca se monta, `configHref` es la única señal que importa.
 * - `configStatus` (opcional): traduce la config YA guardada a una etiqueta de
 *   estado que la fila muestra cuando el módulo está activo. Solo lee lo que
 *   el backend devuelve en `moduleData` — no hay healthcheck ni ping a nadie.
 * - `status: 'soon'` → sin switch, muestra Badge "Próximamente", fila muted.
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
  ClipboardCheck,
  ClipboardList,
  Repeat,
  BellRing,
  ReceiptText,
  CreditCard,
  QrCode,
} from "lucide-react"

import type {
  BancardConfig,
  LoyaltyConfig,
  ModuleConfig,
  OrdersConfig,
  TablesConfig,
} from "@/lib/types/module"

export type ConfigKind =
  | "none"
  | "loyalty"
  | "tables"
  | "orders"
  | "feedback"
  | "crm"
  | "bancard"
  | "comingSoon"

export type ModuleStatus = "available" | "soon"

/** Capacidad propia del producto vs. puente con un sistema de un tercero. */
export type ModuleKind = "module" | "integration"

/**
 * Estado de configuración de una entrada activa, derivado de su config.
 * `complete: false` = está prendida pero todavía no hace nada útil.
 */
export interface ModuleConfigStatus {
  label: string
  complete: boolean
}

export interface ModuleCatalogEntry {
  key: string
  kind: ModuleKind
  title: string
  description: string
  icon: ComponentType<{ className?: string }>
  category: string
  configKind: ConfigKind
  status: ModuleStatus
  /** Ruta a la que navega "Configurar" en vez de abrir el dialog. Ver docblock arriba. */
  configHref?: string
  /** Etiqueta de estado a partir de la config guardada. Ver docblock arriba. */
  configStatus?: (config: ModuleConfig | undefined) => ModuleConfigStatus | null
  /**
   * Países donde este módulo tiene sentido (ISO-3166 alpha-2). Ausente =
   * disponible en todos, que es el default correcto para casi todo.
   *
   * Existe porque hay módulos atados a la normativa o a proveedores de UN país:
   * la facturación electrónica de la SET, Bancard, uPay. Mostrárselos a un
   * comercio brasileño no es solo ruido — le ofrece configurar algo que no
   * puede usar, y el que lo intenta descubre el problema recién adentro.
   *
   * Regla del owner (2026-08-28): lo específico de un país se HABILITA por el
   * país del tenant, nunca queda prendido para todos. Ver
   * `context/08-convenciones-criticas.md` §62.
   */
  countries?: string[]
}

export const MODULES_CATALOG: ModuleCatalogEntry[] = [
  // "Destacados" se eliminó (owner 2026-08-08): era una vidriera, no una
  // categoría — sus 3 módulos no tenían nada en común y quedaban duplicando
  // el criterio del resto. Cada uno pasó a la categoría que le corresponde.

  // ── Integraciones (sistemas de terceros) ─────────────────────────────────
  {
    key: "einvoicePy",
    countries: ["PY"],
    kind: "integration",
    title: "Facturación Electrónica",
    description: "Emití facturas electrónicas habilitadas por la SET (SIFEN) directo desde tus ventas.",
    icon: ReceiptText,
    category: "Facturación",
    configKind: "none",
    status: "available",
    configHref: "/settings/facturacion-electronica",
  },
  {
    key: "bancard",
    countries: ["PY"],
    kind: "integration",
    title: "Bancard",
    description:
      "Cobros con Bancard: QR de pago en la pantalla del cliente y terminal físico (Caja POS). Cada canal se habilita por separado desde la configuración.",
    icon: CreditCard,
    category: "Cobros",
    configKind: "bancard",
    status: "available",
    // Canales de `moduleData.bancard`. Prendida sin ningún canal, la
    // integración no cobra nada: es el caso "activo pero incompleto".
    configStatus: (config) => {
      const c = config as BancardConfig | undefined
      const channels = [
        c?.qr ? "QR de pago" : null,
        c?.pos ? "Terminal físico" : null,
      ].filter((x): x is string => x !== null)
      if (channels.length === 0) {
        return { label: "Sin canales habilitados", complete: false }
      }
      return { label: channels.join(" + "), complete: true }
    },
  },
  {
    key: "upay",
    countries: ["PY"],
    kind: "integration",
    title: "uPay (ueno bank)",
    description:
      "Cobros con QR de uPay — interoperable con billeteras de Paraguay, Brasil y Argentina.",
    icon: QrCode,
    category: "Cobros",
    configKind: "comingSoon",
    status: "soon",
  },

  // ── Operativos ───────────────────────────────────────────────────────────
  {
    key: "ecom",
    kind: "module",
    title: "eCommerce",
    description:
      "Llevá tu negocio a la web, un canal de ventas sincronizado con tu local.",
    icon: Globe,
    category: "Operativos",
    configKind: "comingSoon",
    status: "soon",
  },
  {
    key: "attendance",
    kind: "module",
    title: "Control de Asistencia",
    description: "Llevá el control de las horas trabajadas de tu staff.",
    icon: Clock,
    category: "Operativos",
    configKind: "none",
    status: "available",
  },
  {
    key: "calendar",
    kind: "module",
    title: "Agenda y Calendario",
    description: "Gestioná citas y reservas.",
    icon: CalendarDays,
    category: "Operativos",
    configKind: "none",
    // "soon": /pos/calendario todavía es un placeholder (PosModulePlaceholder).
    // Cuando la agenda esté construida, pasar a "available" y sumar el item
    // "Calendario" al sidebar del POS (frontend/components/layout/pos-sidebar.tsx),
    // gateado por moduleEnabled(modules, ..., "calendar").
    status: "soon",
  },
  {
    key: "tables",
    kind: "module",
    title: "Espacios",
    description: "Gestioná los espacios de tu local — mesas, sillas de atención, habitaciones.",
    icon: LayoutGrid,
    category: "Operativos",
    configKind: "tables",
    status: "available",
    configStatus: (config) => {
      const count = (config as TablesConfig | undefined)?.count ?? 0
      return count > 0
        ? { label: `${count} espacios`, complete: true }
        : { label: "Sin espacios definidos", complete: false }
    },
  },
  {
    key: "production",
    kind: "module",
    title: "Producción",
    description: "Recetas, mermas y compuestos.",
    icon: ChefHat,
    category: "Operativos",
    configKind: "none",
    status: "available",
  },
  {
    // Conteo de stock en la caja (context/63). Opcional por comercio (D4): es
    // para el mostrador con producto terminado, donde el cajero cuenta y no
    // entra al panel. Un comercio que no lo necesita no lo ve.
    //
    // `configKind: "none"` y no un dialog propio: lo configurable —las listas
    // fijas y si el conteo ajusta el stock— vive en Ajustes, junto al resto de
    // las preferencias de inventario del comercio, no detrás del switch del
    // módulo.
    key: "stockCount",
    kind: "module",
    title: "Conteo en la caja",
    description:
      "El cajero cuenta el stock del mostrador desde la caja, sin entrar al panel.",
    icon: ClipboardCheck,
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
    kind: "module",
    title: "Panel de Órdenes",
    description: "Gestioná todas tus órdenes en un solo lugar.",
    icon: ClipboardList,
    category: "Operativos",
    configKind: "orders",
    status: "available",
    configStatus: (config) => {
      const min = (config as OrdersConfig | undefined)?.averageTime ?? 0
      return min > 0
        ? { label: `${min} min de preparación`, complete: true }
        : { label: "Sin tiempo de preparación", complete: false }
    },
  },

  // ── Marketing y Fidelización ─────────────────────────────────────────────
  {
    key: "loyalty",
    kind: "module",
    title: "Fidelización",
    description: "Premiá a tus clientes más fieles con puntos.",
    icon: Heart,
    category: "Marketing y Fidelización",
    configKind: "loyalty",
    status: "available",
    configStatus: (config) => {
      const value = (config as LoyaltyConfig | undefined)?.value ?? 0
      return value > 0
        ? { label: "Puntos configurados", complete: true }
        : { label: "Falta el valor del punto", complete: false }
    },
  },
  {
    key: "feedback",
    kind: "module",
    title: "Feedback",
    description: "Hacé que tus clientes califiquen su experiencia.",
    icon: MessageCircle,
    category: "Marketing y Fidelización",
    configKind: "feedback",
    status: "soon",
  },

  // ── Facturación ──────────────────────────────────────────────────────────
  {
    key: "recurring",
    kind: "module",
    title: "Suscripciones",
    description: "Generá suscripciones, membresías y cuotas automáticas.",
    icon: Repeat,
    category: "Facturación",
    configKind: "none",
    status: "available",
  },
  {
    key: "dunning",
    kind: "module",
    title: "Cobranzas",
    description: "Seguimiento automatizado a clientes deudores por email y SMS.",
    icon: BellRing,
    category: "Facturación",
    configKind: "none",
    status: "soon",
  },
]

/**
 * Orden de presentación de las categorías, compartido por las dos vistas.
 * Una categoría sin entradas en la vista actual simplemente no se pinta.
 */
export const MODULE_CATEGORIES = [
  "Cobros",
  "Operativos",
  "Marketing y Fidelización",
  "Facturación",
] as const

/** Entradas de una vista (módulos o integraciones), en orden de catálogo. */
/**
 * El catálogo que corresponde a un tenant, según su país.
 *
 * `country` viene del bootstrap (`bootstrap.country`). Si todavía no cargó
 * (undefined), se devuelven SOLO los módulos sin restricción: es preferible
 * mostrar de menos por un instante que ofrecer una integración de otro país y
 * tener que sacarla cuando llega el dato.
 */
export function modulesForCountry(country: string | null | undefined): ModuleCatalogEntry[] {
  const code = (country ?? "").trim().toUpperCase()
  return MODULES_CATALOG.filter((m) => {
    if (!m.countries) return true
    return code !== "" && m.countries.includes(code)
  })
}

export function catalogByKind(
  kind: ModuleKind,
  country?: string | null,
): ModuleCatalogEntry[] {
  // `country` opcional para no romper a los consumidores de /admin, que ven la
  // plataforma entera a propósito. El PANEL siempre lo pasa: un tenant no debe
  // ver módulos de un país que no es el suyo.
  return modulesForCountry(country).filter((entry) => entry.kind === kind)
}

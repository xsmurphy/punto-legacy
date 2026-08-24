import type { ComponentType } from "react"

/**
 * Tipos de la navegación del panel.
 *
 * Viven acá (y NO en `components/layout/app-sidebar.tsx`) porque el registro
 * de rutas — `lib/navigation/routes.ts` — es la fuente de verdad y no puede
 * depender de un componente client. `app-sidebar.tsx` los re-exporta para no
 * romper los imports existentes.
 */

// ── Nav renderizable (lo que consume el <AppSidebar>) ────────────────────

export interface NavItem {
  title: string
  to: string
  icon: ComponentType<{ className?: string }>
  badge?: string
  /** Permiso requerido para mostrar este item. Si no se provee, siempre visible. */
  requires?: string
  /** Oculta el item en mobile (≤sm). Útil cuando hay otra entrada equivalente
   *  para mobile (ej. el FAB del Asistente IA reemplaza al item del sidebar). */
  hideOnMobile?: boolean
}

export interface NavGroup {
  title: string
  icon: ComponentType<{ className?: string }>
  items: NavItem[]
  /** Si se provee, el header del grupo actúa como link directo Y el chevron
   *  es un botón separado (split-click). Sin `to`, se mantiene el modo legacy
   *  donde click en cualquier parte del header sólo hace toggle. */
  to?: string
}

export type NavEntry = NavItem | NavGroup

// ── Registro de rutas ────────────────────────────────────────────────────

/** Grupos colapsables del sidebar. El orden lo define `SIDEBAR_GROUPS`. */
export type SidebarGroupKey = "ventas" | "articulos" | "compras" | "contactos"

/** Headings del command palette. El orden lo define `PALETTE_GROUP_ORDER`. */
export type PaletteGroupKey =
  | "Operaciones"
  | "Reportes"
  | "Finanzas"
  | "Configuración"
  | "Catálogo"

/**
 * Una entrada del registro = una ruta alcanzable del panel.
 *
 * `surface` decide DÓNDE aparece, y es deliberadamente excluyente:
 *
 *  - `"sidebar"` → item del menú lateral. El palette lo indexa bajo el
 *    heading "Navegación" (no se duplica en los grupos secundarios).
 *  - `"palette"` → solo buscador. Es el default para todo lo secundario: el
 *    sidebar se mantiene minimalista a propósito y el palette es el catch-all.
 *
 * Meter algo en el sidebar es una decisión explícita, no el camino de menor
 * resistencia.
 */
export interface RouteEntry {
  /**
   * Href de destino. Puede llevar query string (`/contacts?type=1`,
   * `/settings/catalog?tab=brands`) — el test de cobertura compara contra el
   * pathname sin query.
   */
  to: string
  /** Título corto (el que se ve en el sidebar). */
  title: string
  icon: ComponentType<{ className?: string }>
  surface: "sidebar" | "palette"
  /** Grupo colapsable del sidebar. Sin esto, el item es de primer nivel. */
  sidebarGroup?: SidebarGroupKey
  /** Heading del palette. Obligatorio para `surface: "palette"`. */
  paletteGroup?: PaletteGroupKey
  /**
   * Título largo para el palette cuando el corto es ambiguo fuera de su
   * contexto (ej. "Catálogo · Marcas"). Default: `title`.
   */
  paletteTitle?: string
  /** Alias de búsqueda (otros idiomas, sinónimos, cómo lo llama la gente). */
  keywords?: string[]
  /** Clave de permiso. Sin esto, la entrada es visible para cualquier usuario. */
  requires?: string
  /** Módulo del tenant que debe estar activo (`/v1/modules`). */
  requiresModule?: string
  /** Oculta el item en mobile. Solo aplica a `surface: "sidebar"`. */
  hideOnMobile?: boolean
  /** Badge dinámico: la clave que resuelve el builder (ej. "parkedSales"). */
  badgeKey?: string
}

/** Sección renderizable del palette. */
export interface PaletteSection {
  heading: string
  items: Array<{
    title: string
    to: string
    icon: ComponentType<{ className?: string }>
    /** Título + keywords concatenados — es el `value` que filtra cmdk. */
    searchValue: string
  }>
}

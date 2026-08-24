import type {
  NavEntry,
  NavGroup,
  NavItem,
  PaletteGroupKey,
  PaletteSection,
  RouteEntry,
  SidebarGroupKey,
} from "@/lib/navigation/types"
import { PALETTE_GROUP_ORDER, SIDEBAR_GROUPS } from "@/lib/navigation/routes"

/**
 * Derivaciones del registro de rutas.
 *
 * Las DOS superficies de navegación del panel (menú lateral y command
 * palette) salen de acá. Ninguna mantiene su propia lista: si una página no
 * está en `lib/navigation/routes.ts`, no existe para el usuario — y el test
 * de cobertura (`__tests__/routes-coverage.test.ts`) falla.
 */

export interface NavContext {
  /** Permisos del usuario (bootstrap). */
  perms: string[]
  /**
   * `false` mientras el bootstrap carga. En ese estado NO se filtra por
   * permisos: se muestra todo y se filtra cuando llegan. Evita el flicker de
   * sidebar vacío y el hydration mismatch (React #418) que producía el filtro
   * con `perms=[]` en el primer render.
   */
  permsLoaded: boolean
  /**
   * Módulo del tenant activo. Default conservador: mientras la query de
   * módulos carga debe devolver `false` (no mostrar el item todavía).
   */
  moduleEnabled?: (key: string) => boolean
  /** Badges dinámicos, indexados por `badgeKey` de la entrada. */
  badges?: Record<string, string | undefined>
}

/** Pathname de una entrada, sin query string ni hash. */
export function routePathname(to: string): string {
  return to.split(/[?#]/, 1)[0]
}

function isVisible(entry: RouteEntry, ctx: NavContext): boolean {
  if (entry.requiresModule) {
    if (!ctx.moduleEnabled?.(entry.requiresModule)) return false
  }
  if (!ctx.permsLoaded) return true
  if (!entry.requires) return true
  return ctx.perms.includes(entry.requires)
}

function toNavItem(entry: RouteEntry, ctx: NavContext): NavItem {
  return {
    title: entry.title,
    to: entry.to,
    icon: entry.icon,
    requires: entry.requires,
    hideOnMobile: entry.hideOnMobile,
    badge: entry.badgeKey ? ctx.badges?.[entry.badgeKey] : undefined,
  }
}

/**
 * Items del menú lateral.
 *
 * El orden del registro ES el orden del sidebar: un grupo colapsable se crea
 * en la posición de su primer miembro visible, así que un grupo que se queda
 * sin items (por permisos o módulos) simplemente no aparece — sin poda extra.
 */
export function buildSidebarNav(routes: RouteEntry[], ctx: NavContext): NavEntry[] {
  const out: NavEntry[] = []
  const groups = new Map<SidebarGroupKey, NavGroup>()

  for (const entry of routes) {
    if (entry.surface !== "sidebar") continue
    if (!isVisible(entry, ctx)) continue

    if (!entry.sidebarGroup) {
      out.push(toNavItem(entry, ctx))
      continue
    }

    let group = groups.get(entry.sidebarGroup)
    if (!group) {
      const def = SIDEBAR_GROUPS[entry.sidebarGroup]
      group = { title: def.title, icon: def.icon, items: [] }
      groups.set(entry.sidebarGroup, group)
      out.push(group)
    }
    group.items.push(toNavItem(entry, ctx))
  }

  return out
}

/**
 * Índice del command palette — filtrado por permisos igual que el sidebar.
 *
 * Antes el palette renderizaba su lista secundaria sin mirar permisos: un
 * usuario sin `reports.expenses.view` encontraba "Movimientos de caja" y se
 * comía un 403 al entrar. Ahora las dos superficies aplican el mismo filtro.
 *
 * Dos niveles:
 *  1. "Navegación" — todo lo que también está en el sidebar, con el nombre
 *     del grupo como prefijo para que se entienda fuera de contexto.
 *  2. Un heading por `paletteGroup` — el catch-all de lo secundario.
 *
 * Una entrada nunca aparece en los dos niveles: `surface` es excluyente.
 */
export function buildPaletteSections(routes: RouteEntry[], ctx: NavContext): PaletteSection[] {
  const nav: PaletteSection["items"] = []
  const grouped = new Map<PaletteGroupKey, PaletteSection["items"]>()

  for (const entry of routes) {
    if (!isVisible(entry, ctx)) continue

    const keywords = entry.keywords ?? []

    if (entry.surface === "sidebar") {
      const groupTitle = entry.sidebarGroup ? SIDEBAR_GROUPS[entry.sidebarGroup].title : null
      const title = groupTitle ? `${groupTitle} · ${entry.title}` : entry.title
      nav.push({
        title,
        to: entry.to,
        icon: entry.icon,
        searchValue: [title, ...keywords].join(" "),
      })
      continue
    }

    if (!entry.paletteGroup) continue
    const title = entry.paletteTitle ?? entry.title
    const bucket = grouped.get(entry.paletteGroup) ?? []
    bucket.push({
      title,
      to: entry.to,
      icon: entry.icon,
      searchValue: [title, ...keywords].join(" "),
    })
    grouped.set(entry.paletteGroup, bucket)
  }

  const sections: PaletteSection[] = []
  if (nav.length > 0) sections.push({ heading: "Navegación", items: nav })
  for (const key of PALETTE_GROUP_ORDER) {
    const items = grouped.get(key)
    if (items && items.length > 0) sections.push({ heading: key, items })
  }
  return sections
}

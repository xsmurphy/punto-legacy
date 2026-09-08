import { PANEL_ROUTES, POS_ROUTES } from "@/lib/navigation/routes"

/**
 * El mapa del producto, para que el asistente pueda decir DÓNDE queda cada
 * cosa y darte el link directo.
 *
 * Sale del MISMO registro que el menú lateral y el buscador
 * (`lib/navigation/routes.ts`), no de una lista propia. Una copia se
 * desactualiza el día que se mueve una pantalla, y el asistente mandaría a la
 * gente a una URL que ya no existe — con la particularidad de que nadie se
 * entera, porque el modelo lo diría igual de convencido. El registro además
 * tiene un test de cobertura que falla si una página del panel no está
 * declarada, así que esto hereda esa garantía.
 *
 * Se descartan los iconos (componentes React, no viajan a un modelo) y se
 * conservan los alias de búsqueda: son cómo la gente llama de verdad a cada
 * sección, que es justamente lo que el modelo necesita para el match.
 *
 * SOBRE PERMISOS: la lista NO se filtra. No es una fuga — este mismo registro
 * ya viaja completo al browser de cualquier usuario en el bundle del panel, y
 * el permiso se hace cumplir al ENTRAR a la pantalla, no al nombrarla. Lo que
 * sí viaja es `requires`, para que el asistente pueda avisar que una sección
 * pide un permiso en vez de mandar a alguien a una pantalla que le va a dar
 * 403.
 */
export interface SitemapEntry {
  /** Href de destino, tal cual va en el link (puede llevar query string). */
  path: string
  title: string
  /** Dónde vive: el panel de gestión o la app de caja. */
  app: "panel" | "pos"
  /** Sección del menú o del buscador, cuando la entrada tiene una. */
  group?: string
  /** Cómo más la llama la gente. */
  keywords?: string[]
  /** Permiso que exige la pantalla, si exige alguno. */
  requires?: string
  /** Módulo del tenant que tiene que estar activo. */
  requiresModule?: string
}

function flatten(routes: typeof PANEL_ROUTES, app: SitemapEntry["app"]): SitemapEntry[] {
  return routes.map((r) => ({
    path: r.to,
    title: r.paletteTitle ?? r.title,
    app,
    group: r.paletteGroup ?? r.sidebarGroup,
    keywords: r.keywords,
    requires: r.requires,
    requiresModule: r.requiresModule,
  }))
}

export function buildSitemap(): SitemapEntry[] {
  return [...flatten(PANEL_ROUTES, "panel"), ...flatten(POS_ROUTES, "pos")]
}

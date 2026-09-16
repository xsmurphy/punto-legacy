import { NextResponse } from "next/server"
import type { NextRequest } from "next/server"

import { DOCS_URL } from "@/lib/site/links"

/**
 * Ruteo por host: el mismo deploy sirve tres superficies.
 *
 * - Panel y caja (`app.punto.la`): pasan sin tocar.
 * - Marketing (`punto.la`): `/` se reescribe al home del sitio (`/home`,
 *   route group `(site)`). Ninguna otra ruta se toca.
 * - Ayuda (`docs.punto.la`): TODA ruta se reescribe bajo `/ayuda` (route
 *   group `(docs)`), así el sitio vive en la raíz de su host —`/`,
 *   `/cobrar-una-venta-en-la-caja`, `/sitemap.xml`, `/robots.txt`—.
 */
const hostList = (value: string) =>
  value
    .split(",")
    .map((h) => h.trim())
    .filter(Boolean)

const MARKETING_HOSTS = hostList(process.env.MARKETING_HOSTS ?? "punto.la,www.punto.la")
const DOCS_HOSTS = hostList(process.env.DOCS_HOSTS ?? "docs.punto.la")

/** Segmento interno donde viven las páginas del sitio de ayuda. */
const DOCS_BASE = "/ayuda"

export function middleware(request: NextRequest) {
  const host = request.headers.get("host")?.split(":")[0] ?? ""
  const { pathname, search } = request.nextUrl

  if (DOCS_HOSTS.includes(host)) {
    const url = request.nextUrl.clone()
    url.pathname = pathname === "/" ? DOCS_BASE : `${DOCS_BASE}${pathname}`
    return NextResponse.rewrite(url)
  }

  // Fuera del host de ayuda, `/ayuda` NO es una segunda URL pública: se
  // redirige al host de ayuda en vez de servirla con `noindex`. Servirla
  // tampoco funcionaría bien: los links internos del sitio son relativos a
  // la raíz de SU host (`/<artículo>`), y desde `app.punto.la/ayuda` caerían
  // en rutas del panel. Una sola URL canónica por artículo, sin contenido
  // duplicado que indexar.
  if (pathname === DOCS_BASE || pathname.startsWith(`${DOCS_BASE}/`)) {
    const rest = pathname.slice(DOCS_BASE.length) || "/"
    return NextResponse.redirect(`${DOCS_URL}${rest}${search}`, 308)
  }

  if (pathname === "/" && MARKETING_HOSTS.includes(host)) {
    const url = request.nextUrl.clone()
    url.pathname = "/home"
    return NextResponse.rewrite(url)
  }

  return NextResponse.next()
}

export const config = {
  /**
   * El sitio de ayuda necesita el middleware en TODAS sus rutas (antes era
   * solo `/`). Quedan afuera, para que en ningún host se reescriban ni
   * paguen el middleware:
   *
   * - `/_next/*`: chunks, CSS e imágenes del build. Reescribirlos en el host
   *   de ayuda rompería la página entera.
   * - `/api/*`: los BFF del panel y del POS no dependen del host.
   * - Archivos con extensión de asset (íconos, `sw.js`, manifest, fuentes).
   *   `.xml` y `.txt` NO están excluidos a propósito: `/sitemap.xml` y
   *   `/robots.txt` del host de ayuda tienen que reescribirse.
   *
   * En los hosts del panel lo único que agrega es leer el header `host` y
   * comparar el pathname: no hay fetch, cookies ni auth.
   */
  matcher: [
    "/((?!_next/|api/|.*\\.(?:png|jpe?g|gif|svg|ico|webp|avif|js|mjs|map|css|woff2?|ttf|otf|json|webmanifest)$).*)",
  ],
}

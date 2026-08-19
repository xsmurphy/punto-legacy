import { NextResponse } from "next/server"
import type { NextRequest } from "next/server"

/**
 * Ruteo por host: el mismo deploy sirve el panel (app.punto.la) y el sitio
 * de marketing (punto.la). Cuando el host es de marketing, `/` se reescribe
 * al home del sitio (`/home`, route group `(site)`). Ninguna otra ruta se
 * toca — el matcher está acotado a `/`.
 */
const MARKETING_HOSTS = (process.env.MARKETING_HOSTS ?? "punto.la,www.punto.la")
  .split(",")
  .map((h) => h.trim())
  .filter(Boolean)

export function middleware(request: NextRequest) {
  const host = request.headers.get("host")?.split(":")[0] ?? ""
  if (MARKETING_HOSTS.includes(host)) {
    const url = request.nextUrl.clone()
    url.pathname = "/home"
    return NextResponse.rewrite(url)
  }
  return NextResponse.next()
}

export const config = {
  matcher: "/",
}

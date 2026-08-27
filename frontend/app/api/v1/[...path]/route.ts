/**
 * BFF catch-all proxy — Front (frontend) → BFF (este handler) → API (api.punto.la).
 *
 * Patrón decidido por el owner (2026-06-12):
 *   API   = raw data
 *   BFF   = reshape / cálculos / SSR del JWT
 *   Front = render
 *
 * Este handler reenvía CUALQUIER request a `/api/v1/<...path>` hacia
 * `<API_URL>/v1/<...path>`, preservando método, headers relevantes, query
 * string y body. La credencial viaja SIEMPRE en `Authorization: Bearer` (panel
 * y POS) — `bffProxy` reenvía ese header tal cual.
 *
 * Endpoints reshape específicos (ej. `app/api/dashboard/income-chart/route.ts`)
 * NO chocan con este catch-all: viven en `app/api/dashboard/*`, no en
 * `app/api/v1/*`.
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

// Edge runtime no — necesitamos Node fetch para forwarding completo de bodies.
export const runtime = "nodejs"
// Dynamic — el path es dinámico per-request; no cacheamos.
export const dynamic = "force-dynamic"

/**
 * TOKEN-ONLY, como todo el resto del BFF (context/54 F2, 2026-08-26).
 *
 * Esta era la ÚNICA puerta con `forwardCookie: true`, porque la credencial del
 * panel ERA la cookie `_jwt_panel`. Con el panel en Bearer ya no hay ninguna
 * cookie que reenviar: los dos realms que usan esta puerta —el panel
 * (`lib/api-client.ts`) y el POS (`lib/api/pos-client.ts`, para ventas y
 * cotizaciones)— mandan su propio Bearer, y `bffProxy` reenvía `authorization`
 * siempre.
 *
 * Que esta puerta ya no acepte cookies es el punto del cambio: mientras las
 * reenviaba, una request del POS podía llegar al backend con DOS credenciales
 * (su Bearer + la cookie del operador, que el browser adjuntaba sola), y de ahí
 * salieron cuatro incidentes de sesión cruzada. Ahora llega UNA, la que el
 * cliente eligió mandar. La precedencia de Bearer en `authResolve()` sigue en su
 * lugar como defensa en profundidad, pero ya no hay ambigüedad que resolver.
 */
async function proxy(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const { path } = await ctx.params
  const tail = (path ?? []).join("/")
  const search = req.nextUrl.search // incluye `?` si hay query
  return bffProxy(req, { upstreamPath: `/v1/${tail}${search}` })
}

export const GET     = proxy
export const POST    = proxy
export const PUT     = proxy
export const PATCH   = proxy
export const DELETE  = proxy
export const HEAD    = proxy
export const OPTIONS = proxy

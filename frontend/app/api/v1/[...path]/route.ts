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
 * string y body. La cookie `_jwt_panel` la lee el handler y la pasa como
 * cookie al backend (mismo nombre — el bootstrap PHP la lee idéntico).
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
 * ÚNICA puerta del BFF que reenvía la cookie (`forwardCookie: true`): es la
 * puerta del PANEL, cuya credencial ES la cookie `_jwt_panel`. Todo `/api/pos/*`
 * es token-only y no la reenvía (ver `forwardCookie` en `lib/bff/proxy.ts`).
 *
 * Esta puerta es multi-credencial a propósito y no puede dejar de serlo: el POS
 * también la usa, con Bearer, para los call-sites que no tienen un `/api/pos/*`
 * dedicado (ventas y cotizaciones vía `lib/api/pos-client.ts`). Por eso NO se
 * filtra el `authorization` acá — se rompería la venta.
 *
 * Lo que resuelve la ambigüedad cuando llegan las dos credenciales juntas es la
 * precedencia de Bearer de `authResolve()` (`api/includes/auth_session.php`):
 * con Bearer presente, la cookie se ignora y el realm lo define el Bearer.
 */
async function proxy(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const { path } = await ctx.params
  const tail = (path ?? []).join("/")
  const search = req.nextUrl.search // incluye `?` si hay query
  return bffProxy(req, { upstreamPath: `/v1/${tail}${search}`, forwardCookie: true })
}

export const GET     = proxy
export const POST    = proxy
export const PUT     = proxy
export const PATCH   = proxy
export const DELETE  = proxy
export const HEAD    = proxy
export const OPTIONS = proxy

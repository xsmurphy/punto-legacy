/**
 * BFF catch-all proxy — Front (panel-next) → BFF (este handler) → API (api.punto.la).
 *
 * Patrón decidido por el owner (2026-06-12):
 *   API   = raw data
 *   BFF   = reshape / cálculos / SSR del JWT
 *   Front = render
 *
 * Antes el `api-client.ts` apuntaba directo a `NEXT_PUBLIC_API_URL` (i.e.
 * `https://api.punto.la`), saltándose el BFF. Eso traía 3 problemas:
 *   1. Superficie de la API expuesta al browser (no minimal).
 *   2. CORS + cookie cross-subdomain (`.punto.la`) — frágil ante misconfigs.
 *   3. Imposible loguear errores server-side en panel-next — debug solo
 *      con consola del browser o logs de api.punto.la.
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

import { NextRequest, NextResponse } from "next/server"

// Edge runtime no — necesitamos Node fetch para forwarding completo de bodies.
export const runtime = "nodejs"
// Dynamic — el path es dinámico per-request; no cacheamos.
export const dynamic = "force-dynamic"

const TARGET_BASE = (() => {
  // En server, API_URL (privado) o NEXT_PUBLIC_API_URL como fallback.
  const url = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
  if (!url) {
    throw new Error("API base URL missing. Set API_URL or NEXT_PUBLIC_API_URL.")
  }
  return url.replace(/\/$/, "")
})()

/** Headers que NO reenviamos al backend (manejados por fetch interno). */
const HOP_BY_HOP = new Set([
  "host",
  "connection",
  "keep-alive",
  "transfer-encoding",
  "upgrade",
  "content-length",
  "accept-encoding",
])

async function proxy(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const { path } = await ctx.params
  const tail = (path ?? []).join("/")
  const search = req.nextUrl.search // incluye `?` si hay query
  const targetUrl = `${TARGET_BASE}/v1/${tail}${search}`

  // Copy headers excepto hop-by-hop. `cookie` y `authorization` no están en
  // HOP_BY_HOP — se copian automáticamente. `_jwt_panel` (panel, cookie) y
  // el Bearer token del device viajan al upstream sin intervención explícita.
  const headers = new Headers()
  req.headers.forEach((value, key) => {
    if (!HOP_BY_HOP.has(key.toLowerCase())) {
      headers.set(key, value)
    }
  })

  // Body solo para métodos que lo permiten.
  let body: BodyInit | undefined
  if (!["GET", "HEAD"].includes(req.method)) {
    // ArrayBuffer preserva binarios (multipart/form-data uploads).
    body = await req.arrayBuffer()
    // arrayBuffer() vacío → undefined, así no rompemos GET-equivalent semantics.
    if ((body as ArrayBuffer).byteLength === 0) body = undefined
  }

  let upstream: Response
  try {
    upstream = await fetch(targetUrl, {
      method: req.method,
      headers,
      body,
      // No queremos que Next cachee — la response se construye por request.
      cache: "no-store",
      // No follow de 3xx del upstream — devolvemos la response tal cual.
      redirect: "manual",
    })
  } catch (err) {
    // El upstream cayó o hay un error de red — devolvemos 502 con detalle
    // para el log de panel-next + un envelope canónico al client.
    console.error("[bff /api/v1] upstream fetch failed", {
      method: req.method,
      target: targetUrl,
      err: err instanceof Error ? err.message : String(err),
    })
    return NextResponse.json(
      {
        ok: false,
        error: {
          message: "BFF no pudo contactar a la API",
          code: 502,
          detail: err instanceof Error ? err.message : String(err),
        },
      },
      { status: 502 },
    )
  }

  // Logueamos solo 5xx upstream — los 4xx son errores del cliente esperados
  // (401 sin auth, 404 not found, 422 validation) y ensucian los logs. El
  // 5xx señala un bug real que vale la pena diagnosticar sin ir a Coolify.
  if (upstream.status >= 500) {
    const cloned = upstream.clone()
    let snippet = ""
    try {
      const text = await cloned.text()
      snippet = text.length > 500 ? text.slice(0, 500) + "…" : text
    } catch {
      snippet = "(no readable body)"
    }
    console.warn("[bff /api/v1] upstream 5xx", {
      method: req.method,
      target: targetUrl,
      status: upstream.status,
      body: snippet,
    })
  }

  // Copy response headers excepto los hop-by-hop. content-encoding lo dropeamos
  // porque Next ya descomprimió/decomprimirá según el client.
  //
  // Set-Cookie MERECE handling especial: Headers.forEach colapsa múltiples
  // valores del mismo nombre en una sola entry separada por comas — y comas
  // son válidas dentro del valor de una cookie (fechas Expires), así que el
  // colapso corrompe la cookie. Usamos getSetCookie() (Node undici) que
  // preserva las cookies como array y las re-appendeamos individualmente.
  const respHeaders = new Headers()
  upstream.headers.forEach((value, key) => {
    const lower = key.toLowerCase()
    if (HOP_BY_HOP.has(lower)) return
    if (lower === "content-encoding") return
    if (lower === "set-cookie") return // se maneja aparte
    respHeaders.set(key, value)
  })
  const setCookies = (upstream.headers as Headers & { getSetCookie?: () => string[] }).getSetCookie?.() ?? []
  for (const sc of setCookies) {
    respHeaders.append("set-cookie", sc)
  }

  return new NextResponse(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: respHeaders,
  })
}

export const GET    = proxy
export const POST   = proxy
export const PUT    = proxy
export const PATCH  = proxy
export const DELETE = proxy
export const HEAD   = proxy
export const OPTIONS = proxy

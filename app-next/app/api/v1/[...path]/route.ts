/**
 * BFF catch-all proxy — Front (app-next) → BFF (este handler) → API (/api compartida).
 *
 * Espejo del handler de panel-next, con dos diferencias:
 *   1. Cookie: lee `_jwt` (realm `pos-app`), NO `_jwt_panel`.
 *   2. Loopback in-container: usa PUNTO_SHARED_API_BASE + Host override
 *      PUNTO_SHARED_API_HOST para evitar salir a Cloudflare desde el BFF.
 *      Fallback dev: API_URL / NEXT_PUBLIC_API_URL (igual que panel-next).
 *
 * El front de app-next llama a `/api/v1/<path>` → este handler reenvía
 * a `<TARGET_BASE>/v1/<path>` pasando la cookie `_jwt` tal cual
 * (el backend PHP valida el realm `pos-app` en `apiAuthTenant`).
 *
 * Endpoints reshape específicos del POS (ej. `app/api/pos/bootstrap/route.ts`)
 * NO chocan con este catch-all: viven en `app/api/pos/*`.
 */

import { NextRequest, NextResponse } from "next/server"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

/**
 * Resuelve la URL base del upstream en tiempo de request (no a nivel módulo),
 * para que el build no falle cuando la env var no está seteada.
 * En producción la env var SIEMPRE debe estar presente antes de arrancar.
 */
function getTargetBase(): string {
  const loopbackBase = process.env.PUNTO_SHARED_API_BASE
  const url = loopbackBase ?? process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
  if (!url) {
    throw new Error(
      "API base URL missing. Set PUNTO_SHARED_API_BASE, API_URL, or NEXT_PUBLIC_API_URL.",
    )
  }
  return url.replace(/\/$/, "")
}

/** Host override para el loopback BFF→API (VHost nginx/apache). */
const HOST_OVERRIDE = process.env.PUNTO_SHARED_API_HOST

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
  const search = req.nextUrl.search
  const targetUrl = `${getTargetBase()}/v1/${tail}${search}`

  const headers = new Headers()
  req.headers.forEach((value, key) => {
    if (!HOP_BY_HOP.has(key.toLowerCase())) {
      headers.set(key, value)
    }
  })

  // Override Host para loopback in-container.
  if (HOST_OVERRIDE) {
    headers.set("host", HOST_OVERRIDE)
  }

  // Body solo para métodos que lo permiten.
  let body: BodyInit | undefined
  if (!["GET", "HEAD"].includes(req.method)) {
    body = await req.arrayBuffer()
    if ((body as ArrayBuffer).byteLength === 0) body = undefined
  }

  let upstream: Response
  try {
    upstream = await fetch(targetUrl, {
      method: req.method,
      headers,
      body,
      cache: "no-store",
      redirect: "manual",
    })
  } catch (err) {
    console.error("[bff pos /api/v1] upstream fetch failed", {
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

  if (upstream.status >= 500) {
    const cloned = upstream.clone()
    let snippet = ""
    try {
      const text = await cloned.text()
      snippet = text.length > 500 ? text.slice(0, 500) + "…" : text
    } catch {
      snippet = "(no readable body)"
    }
    console.warn("[bff pos /api/v1] upstream 5xx", {
      method: req.method,
      target: targetUrl,
      status: upstream.status,
      body: snippet,
    })
  }

  const respHeaders = new Headers()
  upstream.headers.forEach((value, key) => {
    const lower = key.toLowerCase()
    if (HOP_BY_HOP.has(lower)) return
    if (lower === "content-encoding") return
    respHeaders.set(key, value)
  })

  return new NextResponse(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: respHeaders,
  })
}

export const GET     = proxy
export const POST    = proxy
export const PUT     = proxy
export const PATCH   = proxy
export const DELETE  = proxy
export const HEAD    = proxy
export const OPTIONS = proxy

/**
 * BFF catch-all proxy del realm admin.
 *
 * Front /admin → /api/admin/<path> → ${API_URL}/v1/admin/<path>
 *
 * Reglas de aislamiento de realm:
 *   - Solo lee/escribe la cookie `_jwt_admin`. NUNCA toca `_jwt_panel`.
 *   - Excepción de la acción `?action=enter`: el upstream devuelve un token
 *     de impersonación que el BFF convierte en cookie `_jwt_panel` HttpOnly
 *     (el tenant lo leerá al abrir /). La cookie `_jwt_admin` permanece intacta.
 *   - El login (POST /api/admin/login.php) recibe {token} en el body del
 *     upstream; el BFF lo convierte en cookie `_jwt_admin` HttpOnly + Lax.
 *   - El logout (POST /api/admin/logout) limpia `_jwt_admin`.
 *
 * URL upstream: las rutas PHP de admin viven en api/v1/admin/*.php (desde
 * dissolve-panel 2026-06-29; antes en panel/API/v1/admin/).
 * API_URL apunta a la raíz de la API PHP compartida.
 */

import { NextRequest, NextResponse } from "next/server"
import { cookies } from "next/headers"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

// Lazy: resolver en cada request, NO en module-load. Si se evalúa en module-load
// (IIFE top-level), Next la dispara durante "Collecting page data" en build —
// que corre SIN env vars de runtime → throw → build falla. Incidente
// 2026-06-28 con Coolify cuando faltaba PANEL_URL en el builder.
function getApiBase(): string {
  const url = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
  if (!url) {
    throw new Error("API base URL missing. Set API_URL or NEXT_PUBLIC_API_URL.")
  }
  return url.replace(/\/$/, "")
}

const HOP_BY_HOP = new Set([
  "host",
  "connection",
  "keep-alive",
  "transfer-encoding",
  "upgrade",
  "content-length",
  "accept-encoding",
])

/** TTL de la cookie _jwt_admin (segundos). Default 8 h. */
const ADMIN_TTL = parseInt(process.env.ADMIN_JWT_TTL ?? "28800", 10)

/** Detecta si la request llegó por HTTPS (detrás de Traefik/proxy). */
function isHttps(req: NextRequest): boolean {
  return (
    req.nextUrl.protocol === "https:" ||
    req.headers.get("x-forwarded-proto") === "https"
  )
}

async function proxy(
  req: NextRequest,
  ctx: { params: Promise<{ path: string[] }> },
) {
  const { path } = await ctx.params
  const tail = (path ?? []).join("/")
  const search = req.nextUrl.search
  const targetUrl = `${getApiBase()}/v1/admin/${tail}${search}`

  // Logout especial — limpia la cookie y devuelve 200 sin llamar al upstream.
  if (req.method === "POST" && tail === "logout") {
    const res = NextResponse.json({ ok: true })
    res.cookies.set("_jwt_admin", "", {
      path: "/",
      httpOnly: true,
      sameSite: "lax",
      secure: isHttps(req),
      maxAge: 0,
    })
    return res
  }

  // Leer _jwt_admin de la cookie del request.
  const cookieStore = await cookies()
  const adminToken = cookieStore.get("_jwt_admin")?.value ?? ""

  // Construir headers de la request upstream.
  const headers = new Headers()
  req.headers.forEach((value, key) => {
    if (!HOP_BY_HOP.has(key.toLowerCase()) && key.toLowerCase() !== "cookie") {
      headers.set(key, value)
    }
  })
  // Solo enviamos _jwt_admin al upstream — NUNCA _jwt_panel ni otras cookies.
  if (adminToken) {
    headers.set("Cookie", `_jwt_admin=${adminToken}`)
  }

  // Body.
  let body: BodyInit | undefined
  if (!["GET", "HEAD"].includes(req.method)) {
    const buf = await req.arrayBuffer()
    if (buf.byteLength > 0) body = buf
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
    console.error("[bff /api/admin] upstream fetch failed", {
      method: req.method,
      target: targetUrl,
      err: err instanceof Error ? err.message : String(err),
    })
    return NextResponse.json(
      {
        ok: false,
        error: {
          message: "BFF no pudo contactar a la API admin",
          code: 502,
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
    console.warn("[bff /api/admin] upstream 5xx", {
      method: req.method,
      target: targetUrl,
      status: upstream.status,
      body: snippet,
    })
  }

  // Para login: el upstream devuelve {ok:true, data:{token,...}} → setear cookie HttpOnly.
  // IMPORTANTE: leer el body SIEMPRE con clone() para no agotar el stream original.
  // Si la rama no hace return, el stream original queda disponible para la respuesta genérica.
  if (req.method === "POST" && tail === "login.php" && upstream.status === 200) {
    let json: { ok?: boolean; data?: { token?: string; email?: string; name?: string } } | null = null
    try {
      const text = await upstream.clone().text()
      json = JSON.parse(text)
    } catch {
      // respuesta inválida — caemos a la respuesta genérica
    }
    if (json?.ok && json.data?.token) {
      const secure = isHttps(req)
      const res = NextResponse.json({
        ok: true,
        data: { email: json.data.email ?? "", name: json.data.name ?? "" },
      })
      res.cookies.set("_jwt_admin", json.data.token, {
        path: "/",
        httpOnly: true,
        sameSite: "lax",
        secure,
        maxAge: ADMIN_TTL,
      })
      return res
    }
    // Si el upstream devolvió 200 pero ok=false (credenciales inválidas) o sin token:
    // NO pasar la respuesta tal cual — el body ya fue consumido por clone().text() arriba
    // o el JSON no contiene token. En ambos casos devolvemos la respuesta upstream
    // pero NO el token (que no existe si ok=false, o el JSON es raro). Usar stream original.
    // Nota: si json?.ok es true pero data?.token es undefined, es un bug del backend — 502.
    if (json?.ok && !json.data?.token) {
      return NextResponse.json(
        { ok: false, error: { message: "Backend devolvió ok:true sin token", code: 502 } },
        { status: 502 },
      )
    }
    // ok=false → pasar la respuesta al cliente (error de credenciales) usando stream original.
  }

  // Para impersonación (action=enter): el upstream devuelve {ok:true, data:{token}} →
  // setear cookie _jwt_panel HttpOnly (el tenant la usará al abrir /).
  const urlParams = new URL(req.url).searchParams
  if (
    req.method === "POST" &&
    tail === "companies.php" &&
    urlParams.get("action") === "enter" &&
    upstream.status === 200
  ) {
    let json: { ok?: boolean; data?: { token?: string; expiresIn?: number } } | null = null
    try {
      const text = await upstream.clone().text()
      json = JSON.parse(text)
    } catch {
      // error parsing — caemos a respuesta genérica (stream original intacto)
    }
    if (json?.ok && json.data?.token) {
      const token = json.data.token
      const expiresIn = json.data.expiresIn ?? 28800
      const secure = isHttps(req)
      const res = NextResponse.json({ ok: true, redirectUrl: "/" })
      res.cookies.set("_jwt_panel", token, {
        path: "/",
        httpOnly: true,
        sameSite: "strict",
        secure,
        maxAge: expiresIn,
      })
      return res
    }
    // ok=true sin token = bug backend.
    if (json?.ok && !json.data?.token) {
      return NextResponse.json(
        { ok: false, error: { message: "Backend no devolvió token de impersonación", code: 502 } },
        { status: 502 },
      )
    }
    // ok=false → pasar respuesta al cliente usando stream original.
  }

  // Respuesta genérica — copiar headers (sin hop-by-hop ni content-encoding).
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

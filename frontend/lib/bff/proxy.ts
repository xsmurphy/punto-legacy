import { NextRequest, NextResponse } from "next/server"

const HOP_BY_HOP = new Set([
  "host", "connection", "keep-alive", "transfer-encoding",
  "upgrade", "content-length", "accept-encoding",
])

function getUpstreamBase(): string {
  const url = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
  if (!url) throw new Error("API base URL missing. Set API_URL or NEXT_PUBLIC_API_URL.")
  return url.replace(/\/$/, "")
}

const HOST_OVERRIDE = process.env.PUNTO_SHARED_API_HOST

export interface BffProxyOptions {
  /** Path en upstream, con `/v1/...` incluido. Ej: `/v1/drawer.php?resource=check` */
  upstreamPath: string
  /** Método HTTP. Por default el del request original. */
  method?: string
  /** Body crudo a reenviar (JSON.stringify o ArrayBuffer). Si undefined, usa el del request original. */
  body?: BodyInit
  /**
   * Si true, valida que la request traiga `Authorization: Bearer <token>` y rechaza
   * con 401 ANTES de pegarle al backend. Usalo en endpoints POS que solo aceptan
   * device token — evita un round-trip al backend cuando el caller olvidó adjuntar
   * el Bearer (bug clásico que causó incidentes de 401 fantasma).
   */
  requireBearer?: boolean
  /**
   * Si true, reenvía el header `cookie` upstream. Default FALSE: el proxy es
   * TOKEN-ONLY salvo que la puerta declare lo contrario.
   *
   * El default es "no" a propósito, y es lo que hace que `/api/pos/*` sea
   * token-only por construcción: una ruta POS nueva que nadie revisó no puede
   * filtrar la cookie del panel al backend por olvido. Hoy el ÚNICO opt-in
   * legítimo es el catch-all `/api/v1/[...path]`, que es la puerta del PANEL.
   *
   * Por qué importa (incidente 2026-08-25): el browser del operador lleva la
   * cookie `_jwt_panel` Y el Bearer del device. Si el BFF reenvía las dos, el
   * backend recibe dos credenciales y un Bearer revocado queda "rescatado" por
   * la cookie — el POS sigue operando, pero como panel, y devuelve payloads sin
   * las claves que solo se sirven al realm `pos-app` (el roster del lock
   * screen). Con una sola credencial, el backend solo puede responder como
   * `pos-app` o rechazar.
   *
   * La contraparte en el backend es la precedencia de Bearer de `authResolve()`
   * (`api/includes/auth_session.php`), que cubre la puerta que SÍ es
   * multi-credencial. Las dos son necesarias: esta corta el problema en el
   * borde, esa lo cierra donde el borde no llega.
   */
  forwardCookie?: boolean
  /** Override del content-type a enviar al upstream. */
  contentType?: string
}

export async function bffProxy(req: NextRequest, opts: BffProxyOptions): Promise<NextResponse> {
  const method = opts.method ?? req.method

  // Guard de Bearer si el endpoint lo requiere.
  if (opts.requireBearer) {
    const auth = req.headers.get("authorization") ?? ""
    if (!/^Bearer\s+\S+/i.test(auth)) {
      return NextResponse.json(
        { ok: false, error: { message: "Falta Bearer del device. Re-conectá el dispositivo desde el panel.", code: 401 } },
        { status: 401 },
      )
    }
  }

  // Headers: copiar todo excepto hop-by-hop. `authorization` se copia siempre;
  // `cookie` SOLO si la puerta lo pidió explícitamente (ver `forwardCookie`).
  const headers = new Headers()
  req.headers.forEach((value, key) => {
    const lower = key.toLowerCase()
    if (HOP_BY_HOP.has(lower)) return
    if (lower === "cookie" && !opts.forwardCookie) return
    headers.set(key, value)
  })
  if (HOST_OVERRIDE) headers.set("host", HOST_OVERRIDE)
  if (opts.contentType) headers.set("content-type", opts.contentType)

  // Body: si el caller pasó uno, usalo. Sino, tomá el del request original (para POST/PUT/PATCH/DELETE).
  let body: BodyInit | undefined = opts.body
  if (body === undefined && !["GET", "HEAD"].includes(method)) {
    const buf = await req.arrayBuffer()
    if (buf.byteLength > 0) body = buf
  }

  const base = getUpstreamBase()
  const url = `${base}${opts.upstreamPath}`

  let upstream: Response
  try {
    upstream = await fetch(url, {
      method,
      headers,
      body,
      cache: "no-store",
      redirect: "manual",
    })
  } catch (err) {
    console.error(`[bff] upstream fetch failed`, {
      method, url, err: err instanceof Error ? err.message : String(err),
    })
    return NextResponse.json(
      { ok: false, error: { message: "BFF no pudo contactar la API", code: 502 } },
      { status: 502 },
    )
  }

  // Loguear 5xx para debug
  if (upstream.status >= 500) {
    const snippet = await upstream.clone().text().catch(() => "(no body)")
    console.warn(`[bff] upstream 5xx`, {
      method, url, status: upstream.status,
      body: snippet.length > 500 ? snippet.slice(0, 500) + "…" : snippet,
    })
  }

  // Response headers: copiar excepto hop-by-hop + content-encoding (Next descomprime).
  // Set-Cookie merece handling especial (getSetCookie preserva múltiples valores).
  const respHeaders = new Headers()
  upstream.headers.forEach((v, k) => {
    const lower = k.toLowerCase()
    if (HOP_BY_HOP.has(lower)) return
    if (lower === "content-encoding") return
    if (lower === "set-cookie") return
    respHeaders.set(k, v)
  })
  const setCookies = (upstream.headers as Headers & { getSetCookie?: () => string[] }).getSetCookie?.() ?? []
  for (const sc of setCookies) respHeaders.append("set-cookie", sc)

  // Defense-in-depth: las respuestas del BFF son per-tenant/per-outlet (el scope
  // viaja en `X-Outlet-Id`, no en la URL). Sin no-store un cache HTTP podría
  // servir datos de otra sucursal para la misma URL al cambiar de scope.
  respHeaders.set("Cache-Control", "no-store")

  return new NextResponse(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: respHeaders,
  })
}

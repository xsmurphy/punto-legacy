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

  // Headers: copiar todo excepto hop-by-hop. cookie y authorization se copian automáticamente.
  const headers = new Headers()
  req.headers.forEach((value, key) => {
    if (!HOP_BY_HOP.has(key.toLowerCase())) headers.set(key, value)
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

  return new NextResponse(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: respHeaders,
  })
}

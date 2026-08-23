/**
 * BFF — Validación de PIN del lockscreen.
 *
 * Forwarda al endpoint PHP `/v1/unlock-pin`, que hace el match server-side y
 * nunca expone `lockPass`. Acepta cualquiera de las dos cookies del operador:
 *   - `_jwt_panel` (panel, 24h)
 *   - `_jwt`       (pos-app, device pairing, larga duración)
 *
 * Sin este dual-cookie support, un device paired que llegó a /pos sin pasar
 * por una sesión fresca de panel falla con 401 aunque el PIN sea correcto.
 */
import { NextRequest, NextResponse } from "next/server"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

function getTargetBase(): string {
  const url =
    process.env.PUNTO_SHARED_API_BASE ??
    process.env.API_URL ??
    process.env.NEXT_PUBLIC_API_URL
  if (!url) throw new Error("API base URL missing")
  return url.replace(/\/$/, "")
}

const HOST_OVERRIDE = process.env.PUNTO_SHARED_API_HOST

interface UpstreamEnvelope {
  ok?: boolean
  data?: {
    user?: { id: string; name: string }
    /**
     * Afirmación de operador firmada por la API (HMAC, ver
     * `api/lib/Auth/OperatorAssertion.php`). Es la ÚNICA prueba que tiene el
     * backend de qué persona está operando esta caja: el token del device
     * identifica la tablet, no al mozo. Se emite acá porque este es el único
     * punto donde el PIN se valida contra la BD.
     */
    operatorToken?: string
  }
  error?: { message?: string }
}

export async function POST(req: NextRequest): Promise<NextResponse> {
  const cookie = req.headers.get("cookie") ?? ""
  const authHeader = req.headers.get("authorization") ?? ""
  const hasPanel = /(?:^|;)\s*_jwt_panel=/.test(cookie)
  const hasBearerToken = /^Bearer\s+\S+/i.test(authHeader)
  if (!hasPanel && !hasBearerToken) {
    return NextResponse.json(
      { ok: false, error: { message: "No autenticado", code: 401 } },
      { status: 401 },
    )
  }

  let body: { pin?: unknown }
  try {
    body = await req.json()
  } catch {
    return NextResponse.json({ ok: false, error: { message: "Body JSON inválido" } }, { status: 400 })
  }

  const pin = typeof body.pin === "string" ? body.pin : ""
  if (!/^\d{4}$/.test(pin)) {
    return NextResponse.json({ ok: false, error: { message: "PIN inválido" } }, { status: 422 })
  }

  const base = getTargetBase()
  const headers = new Headers()
  headers.set("cookie", cookie)
  if (authHeader) headers.set("authorization", authHeader)
  headers.set("accept", "application/json")
  headers.set("content-type", "application/json")
  if (HOST_OVERRIDE) headers.set("host", HOST_OVERRIDE)

  try {
    const res = await fetch(`${base}/v1/unlock-pin`, {
      method: "POST",
      headers,
      body: JSON.stringify({ pin }),
      cache: "no-store",
    })
    const raw = await res.text()
    const envelope = raw ? (JSON.parse(raw) as UpstreamEnvelope) : null
    if (res.status === 401 || envelope?.ok === false) {
      return NextResponse.json(
        { ok: false, error: { message: envelope?.error?.message ?? "PIN incorrecto", code: res.status } },
        { status: res.status === 401 ? 401 : res.status },
      )
    }
    if (!res.ok || !envelope?.ok || !envelope.data?.user) {
      return NextResponse.json(
        { ok: false, error: { message: "Error validando PIN" } },
        { status: 502 },
      )
    }
    return NextResponse.json({
      ok: true,
      user: envelope.data.user,
      operatorToken: envelope.data.operatorToken ?? null,
    })
  } catch {
    return NextResponse.json(
      { ok: false, error: { message: "No se pudo contactar la API" } },
      { status: 502 },
    )
  }
}

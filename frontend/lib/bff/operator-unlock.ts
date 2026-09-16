/**
 * Reenvío al backend de los dos desbloqueos de la caja: con PIN
 * (`/v1/unlock-pin`) y sin PIN cuando la sucursal tiene un solo usuario
 * (`/v1/unlock-sole`, context/72 §9.3).
 *
 * Los dos devuelven lo MISMO (backend: `OperatorUnlock::grant()`), así que la
 * normalización de la respuesta vive acá una sola vez. Lo que NO vive acá es
 * la exigencia del Bearer: la hace cada route handler en su propio archivo,
 * que es donde la audita el guard `lib/bff/__tests__/pos-token-only.test.ts`.
 *
 * SOLO se reenvía el header `authorization` que el route handler ya validó.
 * Ninguna cookie (mandato token-only del POS).
 */
import { NextResponse } from "next/server"

function getTargetBase(): string {
  const url =
    process.env.PUNTO_SHARED_API_BASE ??
    process.env.API_URL ??
    process.env.NEXT_PUBLIC_API_URL
  if (!url) throw new Error("API base URL missing")
  return url.replace(/\/$/, "")
}

interface UpstreamEnvelope {
  ok?: boolean
  data?: {
    user?: { id: string; name: string }
    /**
     * Afirmación de operador firmada por la API (HMAC, ver
     * `api/lib/Auth/OperatorAssertion.php`). Es la ÚNICA prueba que tiene el
     * backend de qué persona está operando esta caja: el token del device
     * identifica la tablet, no al mozo.
     */
    operatorToken?: string
    /**
     * Permisos `pos.*` del operador (más la allowlist puntual de claves de
     * panel), contra el rol del CONTACTO, no el del device. Solo sirven para
     * que la caja no mienta; autorizar lo sigue haciendo el backend.
     */
    permissions?: string[]
  }
  error?: { message?: string; details?: { reason?: string } }
}

export async function forwardOperatorUnlock(opts: {
  authHeader: string
  upstreamPath: "/v1/unlock-pin" | "/v1/unlock-sole"
  body: Record<string, unknown>
  /** Mensaje cuando el backend rechaza sin decir por qué. */
  rejectedMessage: string
  /** Mensaje cuando la respuesta no se entiende (502). */
  badGatewayMessage: string
}): Promise<NextResponse> {
  const headers = new Headers()
  headers.set("authorization", opts.authHeader)
  headers.set("accept", "application/json")
  headers.set("content-type", "application/json")
  const hostOverride = process.env.PUNTO_SHARED_API_HOST
  if (hostOverride) headers.set("host", hostOverride)

  try {
    const res = await fetch(`${getTargetBase()}${opts.upstreamPath}`, {
      method: "POST",
      headers,
      body: JSON.stringify(opts.body),
      cache: "no-store",
    })
    const raw = await res.text()
    let envelope: UpstreamEnvelope | null = null
    try {
      envelope = raw ? (JSON.parse(raw) as UpstreamEnvelope) : null
    } catch {
      envelope = null
    }
    if (res.status === 401 || envelope?.ok === false) {
      return NextResponse.json(
        {
          ok: false,
          error: {
            message: envelope?.error?.message ?? opts.rejectedMessage,
            code: res.status,
            // `pin_required` (sin PIN con roster ≠ 1): la caja lo lee para
            // volver a mostrar el bloqueo.
            reason: envelope?.error?.details?.reason ?? null,
          },
        },
        { status: res.status === 401 ? 401 : res.status },
      )
    }
    if (!res.ok || !envelope?.ok || !envelope.data?.user) {
      return NextResponse.json({ ok: false, error: { message: opts.badGatewayMessage } }, { status: 502 })
    }
    return NextResponse.json({
      ok: true,
      user: envelope.data.user,
      operatorToken: envelope.data.operatorToken ?? null,
      // Default `[]` y no `null`: "no vino la lista" y "el operador no tiene
      // ningún permiso pos.*" se resuelven igual —sin capacidades extra—.
      permissions: Array.isArray(envelope.data.permissions) ? envelope.data.permissions : [],
    })
  } catch {
    return NextResponse.json(
      { ok: false, error: { message: "No se pudo contactar la API" } },
      { status: 502 },
    )
  }
}

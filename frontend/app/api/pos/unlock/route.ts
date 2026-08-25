/**
 * BFF — Validación de PIN del lockscreen.
 *
 * Forwarda al endpoint PHP `/v1/unlock-pin`, que hace el match server-side y
 * nunca expone `lockPass`.
 *
 * AUTH: token-only (Bearer del device), como todo `/api/pos/*`. La cookie del
 * operador NO se acepta ni se reenvía upstream.
 *
 * Antes esto aceptaba `_jwt_panel` O el Bearer, y reenviaba la cookie. El
 * argumento era que "un device pareado que llegó a /pos sin sesión fresca de
 * panel falla con 401 aunque el PIN sea correcto" — pero está al revés: un
 * device pareado SIEMPRE tiene Bearer (es lo que `PosAuthGuard` exige para
 * renderizar /pos), así que la rama de la cookie no rescataba a un device
 * pareado: habilitaba a uno NO pareado, con la sesión de panel de quien
 * tuviera el browser abierto.
 *
 * Además `/v1/unlock-pin` es multi-realm (`['panel','pos-app']`, con guard de
 * `module==='pos'`), así que reenviar las dos credenciales lo dejaba resolver
 * como panel. Con Bearer solo, resuelve como `pos-app` y el roster de PINs que
 * valida es el de ESTA caja. Es la misma clase de bug que dejó el lock screen
 * sin PINs el 2026-08-25.
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
    /**
     * Permisos `pos.*` del operador que acaba de probar su PIN — los emite el
     * mismo endpoint, contra el rol del CONTACTO (no el del device).
     *
     * Solo sirven para que la caja no mienta: un encargado tiene que ver
     * habilitadas las acciones sobre mesas de otro mozo, y un mozo tiene que
     * verlas apagadas CON el motivo en vez de comerse un 403 al tocarlas. La
     * autorización real la sigue haciendo el backend en cada request.
     */
    permissions?: string[]
  }
  error?: { message?: string }
}

export async function POST(req: NextRequest): Promise<NextResponse> {
  const authHeader = req.headers.get("authorization") ?? ""
  if (!/^Bearer\s+\S+/i.test(authHeader)) {
    return NextResponse.json(
      {
        ok: false,
        error: {
          message: "Falta Bearer del device. Re-conectá el dispositivo desde el panel.",
          code: 401,
        },
      },
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
  // SOLO el Bearer. La cookie no se reenvía: ver el docblock de arriba.
  headers.set("authorization", authHeader)
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
      // Default `[]` y no `null`: "no vino la lista" y "el operador no tiene
      // ningún permiso pos.*" se resuelven igual —sin capacidades extra— y un
      // solo tipo le ahorra al consumidor una rama que no cambia nada.
      permissions: Array.isArray(envelope.data.permissions) ? envelope.data.permissions : [],
    })
  } catch {
    return NextResponse.json(
      { ok: false, error: { message: "No se pudo contactar la API" } },
      { status: 502 },
    )
  }
}

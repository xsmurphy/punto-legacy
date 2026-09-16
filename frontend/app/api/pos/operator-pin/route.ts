/**
 * BFF — el operador de la caja elige SU PROPIO código POS cuando todavía es el
 * del alta de la cuenta (context/72 §9.3). Lo usa "Bloquear" con un solo
 * usuario: antes de bloquear, el dueño elige un código que conozca.
 *
 * Forwarda a `/v1/operator-pin`, que exige el Bearer del device Y la
 * afirmación de operador (`X-Operator-Token`, la adjunta `posFetch`) y cambia
 * el PIN del contacto de esa afirmación — nunca un id del body.
 *
 * AUTH: token-only (Bearer del device), como todo `/api/pos/*`. Solo se
 * reenvían `authorization` y `x-operator-token`; ninguna cookie.
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

  let body: { lockPass?: unknown }
  try {
    body = await req.json()
  } catch {
    return NextResponse.json({ ok: false, error: { message: "Body JSON inválido" } }, { status: 400 })
  }
  const lockPass = typeof body.lockPass === "string" ? body.lockPass : ""
  if (!/^\d{4}$/.test(lockPass)) {
    return NextResponse.json(
      { ok: false, error: { message: "El código POS debe tener 4 dígitos numéricos" } },
      { status: 422 },
    )
  }

  const headers = new Headers()
  headers.set("authorization", authHeader)
  const operatorToken = req.headers.get("x-operator-token")
  if (operatorToken) headers.set("x-operator-token", operatorToken)
  headers.set("accept", "application/json")
  headers.set("content-type", "application/json")
  const hostOverride = process.env.PUNTO_SHARED_API_HOST
  if (hostOverride) headers.set("host", hostOverride)

  try {
    const res = await fetch(`${getTargetBase()}/v1/operator-pin`, {
      method: "POST",
      headers,
      // Solo el PIN: el backend no lee ningún id del body, y acá tampoco se manda.
      body: JSON.stringify({ lockPass }),
      cache: "no-store",
    })
    const raw = await res.text()
    let envelope: { ok?: boolean; error?: { message?: string } } | null = null
    try {
      envelope = raw ? JSON.parse(raw) : null
    } catch {
      envelope = null
    }
    if (!res.ok || envelope?.ok !== true) {
      return NextResponse.json(
        { ok: false, error: { message: envelope?.error?.message ?? "No se pudo guardar el código", code: res.status } },
        { status: res.ok ? 502 : res.status },
      )
    }
    return NextResponse.json({ ok: true })
  } catch {
    return NextResponse.json({ ok: false, error: { message: "No se pudo contactar la API" } }, { status: 502 })
  }
}

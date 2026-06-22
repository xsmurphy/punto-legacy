/**
 * BFF — Validación de PIN del lockscreen.
 *
 * Recibe el PIN del cajero y lo valida contra el directorio de usuarios del
 * tenant (campo lockPass en /v1/users). El PIN nunca viaja al browser como
 * dato precargado — solo se evalúa aquí.
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

interface UpstreamEnvelope<T> {
  ok?: boolean
  data?: T
}

interface UpstreamUser {
  id: string
  name: string
  lockPass?: string | null
  status: number
}

export async function POST(req: NextRequest): Promise<NextResponse> {
  const cookie = req.headers.get("cookie")
  if (!cookie || !cookie.includes("_jwt_panel=")) {
    return NextResponse.json({ ok: false, error: { message: "No autenticado", code: 401 } }, { status: 401 })
  }

  let body: { pin?: unknown }
  try {
    body = await req.json()
  } catch {
    return NextResponse.json({ ok: false, error: { message: "Body JSON inválido" } }, { status: 400 })
  }

  const pin = typeof body.pin === "string" ? body.pin : ""
  if (pin.length !== 4 || !/^\d{4}$/.test(pin)) {
    return NextResponse.json({ ok: false, error: { message: "PIN inválido" } }, { status: 422 })
  }

  const base = getTargetBase()
  const headers = new Headers()
  headers.set("cookie", cookie)
  headers.set("accept", "application/json")
  if (HOST_OVERRIDE) headers.set("host", HOST_OVERRIDE)

  let usersData: { users: UpstreamUser[] } | null = null
  try {
    const res = await fetch(`${base}/v1/users?status=1`, { method: "GET", headers, cache: "no-store" })
    if (res.status === 401) {
      return NextResponse.json({ ok: false, error: { message: "No autenticado", code: 401 } }, { status: 401 })
    }
    if (res.status >= 500) {
      return NextResponse.json({ ok: false, error: { message: "Error del servidor" } }, { status: 502 })
    }
    const raw = await res.text()
    const envelope = raw ? (JSON.parse(raw) as UpstreamEnvelope<{ users: UpstreamUser[] }>) : null
    usersData = envelope?.ok === true && envelope.data ? envelope.data : null
  } catch {
    return NextResponse.json({ ok: false, error: { message: "No se pudo contactar la API" } }, { status: 502 })
  }

  if (!usersData) {
    return NextResponse.json({ ok: false, error: { message: "No se pudo obtener el directorio" } }, { status: 502 })
  }

  const match = usersData.users.find(
    (u) => u.status === 1 && typeof u.lockPass === "string" && u.lockPass === pin,
  )

  if (!match) {
    return NextResponse.json({ ok: false, error: { message: "PIN incorrecto" } }, { status: 401 })
  }

  return NextResponse.json({ ok: true, user: { id: match.id, name: match.name } })
}

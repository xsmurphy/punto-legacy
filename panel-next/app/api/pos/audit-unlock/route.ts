/**
 * BFF — Audit de unlock del lock screen.
 *
 * Registra que operador desbloqueo el POS y cuando, sin procesar PIN.
 * Llamado best-effort desde el lock screen tras match local con bcrypt.
 * Si falla por red (offline), el unlock ya ocurrio — este endpoint solo es logging.
 */
import { NextRequest, NextResponse } from "next/server"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function POST(req: NextRequest): Promise<NextResponse> {
  const cookie = req.headers.get("cookie") ?? ""
  const hasPanel = /(?:^|;)\s*_jwt_panel=/.test(cookie)
  const hasPosApp = /(?:^|;)\s*_jwt=/.test(cookie)
  if (!hasPanel && !hasPosApp) {
    return NextResponse.json({ ok: false }, { status: 401 })
  }

  let body: { contactId?: unknown }
  try {
    body = await req.json()
  } catch {
    return NextResponse.json({ ok: false }, { status: 400 })
  }

  const contactId = typeof body.contactId === "string" ? body.contactId : null
  if (!contactId) {
    return NextResponse.json({ ok: false }, { status: 422 })
  }

  console.info("[pos/audit-unlock]", { contactId, ts: new Date().toISOString() })

  return NextResponse.json({ ok: true })
}

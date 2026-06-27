/**
 * BFF — Audit de unlock del lock screen.
 *
 * Registra que operador desbloqueo el POS y cuando, sin procesar PIN.
 * Llamado best-effort desde el lock screen tras match local con bcrypt.
 * Si falla por red (offline), el unlock ya ocurrio — este endpoint solo es logging.
 *
 * NOTA DE SEGURIDAD (code-review 2026-06-25):
 * La verificacion de cookie en este endpoint solo comprueba PRESENCIA del header
 * (_jwt_panel= o _jwt=), NO verifica la firma del JWT. Esto significa que:
 *   1. El log NO puede usarse como evidencia forense confiable de quien opero.
 *   2. Un caller con cualquier string en cookie puede escribir contactIds arbitrarios
 *      al log (log-injection / audit-poisoning).
 * Mitigacion: el blast radius es limitado a stdout (logs de Coolify). No hay
 * DB writes ni datos devueltos. Documentar este limite si el log se consume
 * como fuente de verdad para auditorias de seguridad.
 * Para un audit trail confiable, verificar el JWT contra /v1/bootstrap.
 */
import { NextRequest, NextResponse } from "next/server"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function POST(req: NextRequest): Promise<NextResponse> {
  const cookie = req.headers.get("cookie") ?? ""
  const authHeader = req.headers.get("authorization") ?? ""
  const hasPanel = /(?:^|;)\s*_jwt_panel=/.test(cookie)
  const hasBearerToken = /^Bearer\s+\S+/i.test(authHeader)
  if (!hasPanel && !hasBearerToken) {
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

  // UNTRUSTED BEST-EFFORT: cookie no esta verificada con firma JWT.
  // Ver nota de seguridad en el JSDoc arriba.
  console.info("[pos/audit-unlock][untrusted]", { contactId, ts: new Date().toISOString() })

  return NextResponse.json({ ok: true })
}

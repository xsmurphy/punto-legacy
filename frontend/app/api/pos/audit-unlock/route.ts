/**
 * BFF — Audit de unlock del lock screen.
 *
 * Registra que operador desbloqueo el POS y cuando, sin procesar PIN.
 * Llamado best-effort desde el lock screen tras match local con bcrypt.
 * Si falla por red (offline), el unlock ya ocurrio — este endpoint solo es logging.
 *
 * AUTH: token-only (Bearer del device). La puerta `/api/pos/*` no acepta cookies
 * — ver la regla en `context/08-convenciones-criticas.md`. Antes alcanzaba con
 * la PRESENCIA de la cookie `_jwt_panel`, lo que hacía que este log lo pudiera
 * escribir el browser de cualquier operador con sesión de panel abierta,
 * estuviera o no operando esta caja.
 *
 * NOTA DE SEGURIDAD (code-review 2026-06-25, revisada 2026-08-25):
 * Este handler sigue sin VALIDAR el token contra la API — solo comprueba que
 * haya un Bearer bien formado. Sigue sin ser evidencia forense confiable: un
 * caller con cualquier string como Bearer puede escribir contactIds arbitrarios
 * al log (log-injection / audit-poisoning). Lo que cambió es la superficie: ya
 * no basta una cookie que el browser adjunta sola, hace falta fabricar un header
 * a propósito.
 * Mitigacion: el blast radius es limitado a stdout (logs de Coolify). No hay
 * DB writes ni datos devueltos. Para un audit trail confiable, este endpoint
 * tendría que resolver el token contra la API (hoy no lo hace).
 */
import { NextRequest, NextResponse } from "next/server"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function POST(req: NextRequest): Promise<NextResponse> {
  const authHeader = req.headers.get("authorization") ?? ""
  if (!/^Bearer\s+\S+/i.test(authHeader)) {
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

  // UNTRUSTED BEST-EFFORT: el Bearer no se valida contra la API.
  // Ver nota de seguridad en el JSDoc arriba.
  console.info("[pos/audit-unlock][untrusted]", { contactId, ts: new Date().toISOString() })

  return NextResponse.json({ ok: true })
}

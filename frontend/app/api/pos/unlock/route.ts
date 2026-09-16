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
 *
 * El reenvío y la forma de la respuesta los comparte con `/api/pos/unlock-sole`
 * (`lib/bff/operator-unlock.ts`): con PIN o sin PIN, el desbloqueo entrega lo
 * mismo.
 */
import { NextRequest, NextResponse } from "next/server"
import { forwardOperatorUnlock } from "@/lib/bff/operator-unlock"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

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

  // SOLO el Bearer. La cookie no se reenvía: ver el docblock de arriba.
  return forwardOperatorUnlock({
    authHeader,
    upstreamPath: "/v1/unlock-pin",
    body: { pin },
    rejectedMessage: "PIN incorrecto",
    badGatewayMessage: "Error validando PIN",
  })
}

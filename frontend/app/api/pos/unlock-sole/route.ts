/**
 * BFF — Desbloqueo de la caja SIN PIN cuando la sucursal tiene un único usuario
 * habilitado (context/72 §9.3, D-P2).
 *
 * Forwarda a `/v1/unlock-sole`, que es quien DECIDE: cuenta el roster de la
 * sucursal del device contra la BD y solo entonces emite la afirmación de
 * operador. El front no le pasa nada —ni usuario ni sucursal—: todo sale del
 * pareo del device. Si la regla no se cumple, responde 403 `pin_required` y la
 * caja vuelve a mostrar el bloqueo.
 *
 * AUTH: token-only (Bearer del device), como todo `/api/pos/*`. Ninguna cookie
 * ni sesión de panel se acepta o se reenvía; el backend además rechaza todo lo
 * que no sea un token de device.
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

  return forwardOperatorUnlock({
    authHeader,
    upstreamPath: "/v1/unlock-sole",
    body: {},
    rejectedMessage: "Esta caja se desbloquea con el código de usuario",
    badGatewayMessage: "Error desbloqueando la caja",
  })
}

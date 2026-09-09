/**
 * BFF — KuDE (PDF) de un documento fiscal, desde el POS.
 *
 * GET /api/pos/einvoice/kude?id=<einvoicedocid>
 *   → api/v1/einvoice.php?resource=kude&id=<einvoicedocid>
 *
 * Auth: Bearer del device (_jwt en localStorage), `requireBearer: true`. El POS
 * es token-only y esta puerta no es la excepción: `bffProxy` nunca reenvía la
 * cookie del operador, así que el backend solo puede resolver `pos-app` o
 * rechazar. El realm se abrió del lado de la API SOLO para este recurso y solo
 * por GET — ver el bloque de `api/v1/einvoice.php` sobre qué expone y qué no.
 *
 * ── Es un STREAM BINARIO, no el envelope JSON del proyecto ──────────────────
 *
 * `einvoice.php` responde el PDF crudo con `Content-Type: application/pdf` y
 * `exit`, sin pasar por `apiOk()`. Verificado que `bffProxy` no lo rompe: el
 * helper NO parsea el cuerpo — lo pasa tal cual como `new NextResponse(
 * upstream.body, …)` y copia los headers de respuesta salvo los hop-by-hop.
 * O sea que el passthrough binario ya era la semántica del helper, y agregarle
 * una opción de "modo binario" habría sido código nuevo para un caso que ya
 * funcionaba. El único header que se cae en el camino es `Content-Length`
 * (está en la lista hop-by-hop), lo que deja la respuesta sin barra de
 * progreso pero perfectamente legible: el consumidor la baja con `.blob()`.
 *
 * El error tampoco se rompe: un 409 ("todavía no está listo" / "no se emitió")
 * sale con el envelope JSON de siempre y el caller lo lee del cuerpo.
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  const id = req.nextUrl.searchParams.get("id") ?? ""
  if (!id) {
    return NextResponse.json(
      { ok: false, error: { message: "Falta id del documento", code: 400 } },
      { status: 400 },
    )
  }
  const qs = new URLSearchParams({ resource: "kude", id })
  return bffProxy(req, {
    upstreamPath: `/v1/einvoice.php?${qs.toString()}`,
    requireBearer: true,
  })
}

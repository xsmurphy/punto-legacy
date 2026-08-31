/**
 * BFF — Módulos activos del comercio, para el POS (SOLO LECTURA).
 *
 *   GET /api/pos/modules → { [key]: { enabled, config? } }
 *
 * Auth: Bearer del device (`_jwt`) — realm `pos-app`, que `api/v1/modules.php`
 * acepta para GET.
 *
 * POR QUÉ EXISTE: el sidebar del POS decide con esto si muestra Espacios y
 * Órdenes. Antes lo pedía con el cliente del PANEL (cookie `_jwt_panel`, 24 h
 * de vida) desde una pantalla que corre con la sesión del DISPOSITIVO (`_jwt`,
 * sin vencimiento). Al caducar la cookie del operador —típicamente de un día
 * para el otro— la request daba 401, el hook se quedaba sin datos y los módulos
 * desaparecían del sidebar sin ningún error visible, mientras el panel seguía
 * mostrándolos habilitados.
 *
 * Con este BFF el POS consulta con SU sesión: mientras el dispositivo esté
 * emparejado, los módulos se resuelven, haya o no un operador con panel abierto.
 *
 * SOLO GET: activar o configurar un módulo es administración del panel. El
 * upstream ya lo corta por realm; acá no se expone ningún otro método.
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, {
    upstreamPath: "/v1/modules",
    method: "GET",
    // Sin Bearer no hay sesión de dispositivo: cortar acá da un mensaje claro
    // en vez de dejar que el upstream responda un 401 genérico que el sidebar
    // volvería a interpretar como "no hay módulos".
    requireBearer: true,
  })
}

export async function POST() {
  return NextResponse.json(
    { ok: false, error: "El dispositivo POS solo puede leer los módulos" },
    { status: 405 },
  )
}

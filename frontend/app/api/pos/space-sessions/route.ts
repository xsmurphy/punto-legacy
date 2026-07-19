/**
 * BFF — POS Space Sessions (context/15-espacios-module-plan.md F2).
 *
 * Front (POS, `/pos/espacios`) → /api/pos/space-sessions → api/v1/space-sessions.php
 *
 * Mismo patrón que /api/pos/orders y /api/pos/spaces: Bearer del device
 * (realm `pos-app`), query string reenviado íntegro (id, action, status).
 * Para pos-app, outletId sale del device ctx server-side.
 *
 * GET  /api/pos/space-sessions?id=<uuid>                            → detalle
 * POST /api/pos/space-sessions                                      → abre sesión (body: tableId, guests?, waiterId?)
 * POST /api/pos/space-sessions?id=<uuid>&action=request-bill|cancel|close
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/space-sessions.php${req.nextUrl.search}`, requireBearer: true })
}

export async function POST(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/space-sessions.php${req.nextUrl.search}`, requireBearer: true })
}

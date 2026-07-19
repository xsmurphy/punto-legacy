/**
 * BFF — POS Table Sessions (context/15-mesas-module-plan.md F2).
 *
 * Front (POS, `/pos/mesas`) → /api/pos/table-sessions → api/v1/table-sessions.php
 *
 * Mismo patrón que /api/pos/orders y /api/pos/tables: Bearer del device
 * (realm `pos-app`), query string reenviado íntegro (id, action, status).
 * Para pos-app, outletId sale del device ctx server-side.
 *
 * GET  /api/pos/table-sessions?id=<uuid>                            → detalle
 * POST /api/pos/table-sessions                                      → abre sesión (body: tableId, guests?, waiterId?)
 * POST /api/pos/table-sessions?id=<uuid>&action=request-bill|cancel|close
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/table-sessions.php${req.nextUrl.search}`, requireBearer: true })
}

export async function POST(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/table-sessions.php${req.nextUrl.search}`, requireBearer: true })
}

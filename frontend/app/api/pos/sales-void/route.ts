/**
 * BFF — POS anulación de ventas (F6, context/40-anulacion-y-nota-credito.md).
 *
 * GET  /api/pos/sales-void?id=<txId>          → { canVoid, lines }
 * POST /api/pos/sales-void { id, reason, lines? } → anula la venta
 *
 * Auth: Bearer token del device (_jwt en localStorage) — mismo patrón que
 * `returns/route.ts`, la acción hermana en el mismo detalle de transacción.
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  const { searchParams } = req.nextUrl
  const id = searchParams.get("id") ?? ""

  const qs = new URLSearchParams({ id })
  return bffProxy(req, { upstreamPath: `/v1/sales-void.php?${qs.toString()}`, requireBearer: true })
}

export async function POST(req: NextRequest) {
  return bffProxy(req, {
    upstreamPath: "/v1/sales-void.php",
    contentType: "application/json",
    requireBearer: true,
  })
}

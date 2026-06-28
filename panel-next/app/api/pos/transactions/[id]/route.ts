/**
 * BFF — POS Transaction detalle por ID.
 *
 * GET /api/pos/transactions/[id] → api/v1/transactions.php?resource=single&id=[id]
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest, ctx: { params: Promise<{ id: string }> }) {
  const { id } = await ctx.params
  if (!id) return NextResponse.json({ ok: false, error: { message: "ID requerido", code: 400 } }, { status: 400 })
  const qs = new URLSearchParams({ resource: "single", id })
  return bffProxy(req, { upstreamPath: `/v1/transactions.php?${qs.toString()}`, requireBearer: true })
}

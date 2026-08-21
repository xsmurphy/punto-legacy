/**
 * BFF — POS Returns.
 *
 * GET  /api/pos/returns?action=listForParent&parentId=UUID
 * GET  /api/pos/returns?action=returnOptions&parentId=UUID → D2 (context/40):
 *      qué es posible reponer por línea vendida + cupo disponible.
 * POST /api/pos/returns  { action: "create", parentTransactionId,
 *      items: [{itemId, qty, restock?, itemSoldId?}], refundMode, note? }
 *
 * `action` viaja tal cual al upstream — no hace falta un branch nuevo acá
 * por cada acción de `returns.php` (ver `api/v1/returns.php`).
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  const { searchParams } = req.nextUrl
  const action   = searchParams.get("action") ?? "listForParent"
  const parentId = searchParams.get("parentId") ?? ""

  const qs = new URLSearchParams({ action })
  if (parentId) qs.set("parentId", parentId)

  return bffProxy(req, { upstreamPath: `/v1/returns.php?${qs.toString()}`, requireBearer: true })
}

export async function POST(req: NextRequest) {
  return bffProxy(req, {
    upstreamPath: "/v1/returns.php",
    contentType: "application/json",
    requireBearer: true,
  })
}

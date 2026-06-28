/**
 * BFF — POS Transactions lista.
 *
 * Front (POS) → /api/pos/transactions → api/v1/transactions.php?resource=mainList
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 *
 * Parámetros opcionales forwarded:
 *   ?date=YYYY-MM-DD   — filtrar por fecha
 *   ?limit=N           — cantidad de resultados (default 30)
 *   ?offset=N          — paginación (default 0)
 *   ?q=texto           — búsqueda por nombre de cliente / comprobante
 *   ?type=tipo         — filtrar por tipo
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  const sp = req.nextUrl.searchParams
  const qs = new URLSearchParams({ resource: "mainList" })
  for (const k of ["date", "limit", "offset", "q", "type"] as const) {
    const v = sp.get(k)
    if (v !== null) qs.set(k, v)
  }
  if (!qs.has("limit")) qs.set("limit", "30")
  if (!qs.has("offset")) qs.set("offset", "0")
  return bffProxy(req, { upstreamPath: `/v1/transactions.php?${qs.toString()}`, requireBearer: true })
}

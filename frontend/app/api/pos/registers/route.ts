/**
 * BFF — POS Registers (solo lectura).
 *
 * GET /api/pos/registers → lista todas las cajas del tenant (selector de
 * Ajustes → Caja). Pega contra /v1/register?resource=listAll, que ya acepta
 * el realm `pos-app` (Bearer del device) además de `panel`.
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: "/v1/register?resource=listAll", requireBearer: true })
}

/**
 * BFF — POS Space Settlements / split de cuenta (context/15-espacios-module-plan.md F3).
 *
 * Front (POS, `/pos/espacios`) → /api/pos/space-settlements → api/v1/space-settlements.php
 *
 * Mismo patrón que /api/pos/space-sessions: Bearer del device (realm
 * `pos-app`), query string reenviado íntegro (sessionId, action).
 *
 * GET  /api/pos/space-settlements?sessionId=<uuid>              → saldo (total, paid, balance, items)
 * POST /api/pos/space-settlements?sessionId=<uuid>&action=pay    → registra un pago parcial
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/space-settlements.php${req.nextUrl.search}`, requireBearer: true })
}

export async function POST(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/space-settlements.php${req.nextUrl.search}`, requireBearer: true })
}

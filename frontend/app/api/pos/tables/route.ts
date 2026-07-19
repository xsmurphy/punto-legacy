/**
 * BFF — POS Dining Tables (context/15-mesas-module-plan.md F2).
 *
 * Front (POS, `/pos/mesas`) → /api/pos/tables → api/v1/dining-tables.php
 *
 * El device POS no tiene sesión de panel (`_jwt_panel`); pega con el Bearer
 * del device (realm `pos-app`). Reenvía todo el query string (id, resource,
 * sectorId, action) — dining-tables.php es query-driven, mismo patrón que
 * /api/pos/orders. Para pos-app, outletId sale del device ctx server-side
 * (no hace falta mandarlo).
 *
 * GET  /api/pos/tables?resource=state          → plano operativo (listWithState)
 * GET  /api/pos/tables?id=<uuid>                 → detalle
 * POST /api/pos/tables?action=bulk|layout        → alta rápida / layout (config, no operativo)
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/dining-tables.php${req.nextUrl.search}`, requireBearer: true })
}

export async function POST(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/dining-tables.php${req.nextUrl.search}`, requireBearer: true })
}

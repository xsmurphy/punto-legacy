/**
 * BFF — POS Table Sectors, solo lectura (context/15-mesas-module-plan.md F2).
 *
 * Front (POS, `/pos/mesas`) → /api/pos/table-sectors → api/v1/table-sectors.php
 *
 * El plano operativo necesita los sectores para los tabs de navegación.
 * table-sectors.php admite GET desde pos-app; escritura queda restringida a
 * panel (config, `/settings/tables`) — este BFF solo expone GET a propósito.
 *
 * GET /api/pos/table-sectors → lista de sectores del outlet del device
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/table-sectors.php${req.nextUrl.search}`, requireBearer: true })
}

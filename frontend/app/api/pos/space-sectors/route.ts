/**
 * BFF — POS Space Sectors, solo lectura (context/15-espacios-module-plan.md F2).
 *
 * Front (POS, `/pos/espacios`) → /api/pos/space-sectors → api/v1/space-sectors.php
 *
 * El plano operativo necesita los sectores para los tabs de navegación.
 * space-sectors.php admite GET desde pos-app; escritura queda restringida a
 * panel (config, `/settings/espacios`) — este BFF solo expone GET a propósito.
 *
 * GET /api/pos/space-sectors → lista de sectores del outlet del device
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/space-sectors.php${req.nextUrl.search}`, requireBearer: true })
}

/**
 * BFF — POS Orders (O1, context/24-orders-module-plan.md).
 *
 * Front (POS) → /api/pos/orders → api/v1/orders-core.php
 *
 * El device POS no tiene sesión de panel (`_jwt_panel`); pega contra
 * `/v1/orders-core.php` con el Bearer del device (realm `pos-app`, ya
 * habilitado server-side — orders-core.php acepta `apiAuthTenant(['panel',
 * 'pos-app'])`). Reenvía TODO el query string (id, action, resource, status,
 * source, from, to, q) — orders-core.php es query-driven para list/find y
 * para las transiciones (?id=X&action=send|status|mark-paid, o
 * ?resource=item-status&id=Y), así que no hace falta parsear nada acá.
 *
 * GET  /api/pos/orders                          → list (filtros por query string)
 * GET  /api/pos/orders?id=<uuid>                 → detalle con ítems
 * POST /api/pos/orders                           → create (body JSON)
 * POST /api/pos/orders?id=<uuid>&action=send|status|mark-paid
 * POST /api/pos/orders?resource=item-status&id=<orderItemId>
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/orders-core.php${req.nextUrl.search}`, requireBearer: true })
}

export async function POST(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/orders-core.php${req.nextUrl.search}`, requireBearer: true })
}

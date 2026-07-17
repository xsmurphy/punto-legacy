/**
 * BFF — POS Outlets (solo lectura).
 *
 * GET /api/pos/outlets → lista de sucursales del tenant (selector de Ajustes
 * → Sucursal). El device POS no tiene sesión de panel (`_jwt_panel`); pega
 * contra /v1/outlets con el Bearer del device (realm `pos-app`, habilitado
 * server-side solo para GET en api/v1/outlets.php).
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: "/v1/outlets", requireBearer: true })
}

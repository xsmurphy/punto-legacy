/**
 * BFF — POS Register Config (toggles Ajustes → controlCaja, tecladoVirtual, etc).
 *
 * El device POS no tiene sesión de panel (`_jwt_panel`); pega contra
 * `/v1/register?resource=config` con el Bearer del device (realm `pos-app`,
 * habilitado server-side para GET y PUT en api/v1/register.php — registerId
 * siempre sale del JWT/ctx, nunca del body).
 *
 * GET  /api/pos/register-config → { config }
 * PUT  /api/pos/register-config { config: Partial<PosRegisterConfig> } → { config }
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: "/v1/register?resource=config", requireBearer: true })
}

export async function PUT(req: NextRequest) {
  return bffProxy(req, { upstreamPath: "/v1/register?resource=config", requireBearer: true })
}

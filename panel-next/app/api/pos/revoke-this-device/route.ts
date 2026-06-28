/**
 * BFF — Revocar dispositivo POS.
 *
 * Front (POS) → /api/pos/revoke-this-device → api/v1/auth/unpair-pos-device.php
 *
 * El BFF extrae el deviceId del Bearer token (decode naive sin verificar firma)
 * para no exponer el deviceId al cliente. El PHP usa el mismo Bearer para
 * validar ownership en apiAuthPosContext().
 *
 * Lógica especial que no entra en bffProxy: extraer deviceId del JWT y
 * construir el form body con él. El fetch en sí va por bffProxy.
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

function extractDeviceIdFromJwt(token: string): string | null {
  try {
    const parts = token.split(".")
    if (parts.length !== 3) return null
    const payload = JSON.parse(atob(parts[1].replace(/-/g, "+").replace(/_/g, "/")))
    return typeof payload.did === "string" ? payload.did : null
  } catch {
    return null
  }
}

export async function POST(req: NextRequest): Promise<NextResponse> {
  // El deviceId se extrae del Bearer token — no se acepta ningún otro método de auth.
  const authHeader = req.headers.get("authorization") ?? ""
  const bearerMatch = authHeader.match(/^Bearer\s+(\S+)/i)
  const jwtToken = bearerMatch?.[1] ?? ""
  const deviceId = extractDeviceIdFromJwt(jwtToken)

  if (!deviceId) {
    return NextResponse.json(
      { ok: false, error: { message: "Sin sesión de dispositivo" } },
      { status: 401 }
    )
  }

  const formBody = new URLSearchParams({ deviceId })

  return bffProxy(req, {
    upstreamPath: "/v1/auth/unpair-pos-device.php",
    body: formBody,
    contentType: "application/x-www-form-urlencoded",
    requireBearer: true,
  })
}

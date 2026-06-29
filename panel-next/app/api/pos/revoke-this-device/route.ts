/**
 * BFF — Revocar dispositivo POS.
 *
 * Front (POS) → /api/pos/revoke-this-device → api/v1/auth/unpair-pos-device.php
 *
 * El cliente manda { deviceId } en el body (lo lee de getDeviceClaims("pos").deviceId)
 * porque el token opaco no es decodificable client-side. El PHP valida ownership
 * con el Bearer token vía apiAuthPosContext().
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function POST(req: NextRequest): Promise<NextResponse> {
  let deviceId: string | null = null

  try {
    const body = (await req.json()) as { deviceId?: string }
    deviceId = typeof body.deviceId === "string" && body.deviceId !== "" ? body.deviceId : null
  } catch {
    // body vacío o no JSON
  }

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

/**
 * BFF — Revocar dispositivo POS.
 *
 * Front (POS) → /api/pos/revoke-this-device → api/v1/auth/unpair-pos-device.php
 *
 * El BFF extrae el deviceId del cookie _jwt (decode naive sin verificar firma)
 * para no exponer el deviceId al cliente. El PHP usa el mismo cookie _jwt para
 * validar ownership en apiAuthPosContext().
 */

import { NextRequest, NextResponse } from "next/server"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

function getApiBase(): string {
  const url = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
  if (!url) throw new Error("API base URL missing. Set API_URL or NEXT_PUBLIC_API_URL.")
  return url.replace(/\/$/, "")
}

const HOST_OVERRIDE = process.env.PUNTO_SHARED_API_HOST

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
  const cookieHeader = req.headers.get("cookie") ?? ""
  const jwtMatch = cookieHeader.match(/(?:^|;\s*)_jwt=([^;]+)/)
  const jwtToken = jwtMatch?.[1] ?? ""
  const deviceId = extractDeviceIdFromJwt(jwtToken)

  if (!deviceId) {
    return NextResponse.json(
      { ok: false, error: { message: "Sin sesión de dispositivo" } },
      { status: 401 }
    )
  }

  const base = getApiBase()
  const headers = new Headers()
  headers.set("cookie", cookieHeader)
  headers.set("accept", "application/json")
  headers.set("content-type", "application/x-www-form-urlencoded")
  if (HOST_OVERRIDE) headers.set("host", HOST_OVERRIDE)

  const formBody = new URLSearchParams({ deviceId })

  let upstream: Response
  try {
    upstream = await fetch(`${base}/v1/auth/unpair-pos-device.php`, {
      method: "POST",
      headers,
      body: formBody,
      cache: "no-store",
      redirect: "manual",
    })
  } catch (err) {
    console.error("[bff /api/pos/revoke-this-device] upstream fetch failed", {
      err: err instanceof Error ? err.message : String(err),
    })
    return NextResponse.json(
      { ok: false, error: { message: "BFF no pudo contactar la API", code: 502 } },
      { status: 502 }
    )
  }

  const upstreamBody = await upstream.text()
  const respHeaders = new Headers()
  upstream.headers.forEach((v, k) => {
    const lower = k.toLowerCase()
    if (["transfer-encoding", "content-encoding", "connection"].includes(lower)) return
    respHeaders.set(k, v)
  })
  if (!respHeaders.has("content-type")) {
    respHeaders.set("content-type", "application/json")
  }

  return new NextResponse(upstreamBody, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: respHeaders,
  })
}

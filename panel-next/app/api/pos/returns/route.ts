/**
 * BFF — POS Returns.
 *
 * GET  /api/pos/returns?action=listForParent&parentId=UUID
 * POST /api/pos/returns  { action: "create", parentTransactionId, items, refundMode, note? }
 *
 * Auth: cookie `_jwt_panel` (realm panel). Mismo patrón que /api/pos/transactions.
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

async function proxyToApi(
  method: string,
  path: string,
  req: NextRequest,
  body?: BodyInit,
): Promise<NextResponse> {
  const base = getApiBase()
  const cookieHeader = req.headers.get("cookie") ?? ""
  const authHeader = req.headers.get("authorization") ?? ""

  const headers: Record<string, string> = {
    cookie: cookieHeader,
    "content-type": method === "POST" ? "application/json" : "application/x-www-form-urlencoded",
  }
  if (authHeader) headers["authorization"] = authHeader
  if (HOST_OVERRIDE) headers["host"] = HOST_OVERRIDE

  const res = await fetch(`${base}${path}`, {
    method,
    headers,
    body,
    cache: "no-store",
  })

  const data = await res.json().catch(() => ({ error: "invalid json" }))
  return NextResponse.json(data, { status: res.status })
}

export async function GET(req: NextRequest): Promise<NextResponse> {
  const { searchParams } = req.nextUrl
  const action   = searchParams.get("action") ?? "listForParent"
  const parentId = searchParams.get("parentId") ?? ""

  const qs = new URLSearchParams({ action })
  if (parentId) qs.set("parentId", parentId)

  return proxyToApi("GET", `/v1/returns.php?${qs.toString()}`, req)
}

export async function POST(req: NextRequest): Promise<NextResponse> {
  const rawBody = await req.text()
  return proxyToApi("POST", "/v1/returns.php", req, rawBody)
}

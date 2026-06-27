/**
 * BFF — POS Transaction detalle por ID.
 *
 * GET /api/pos/transactions/[id] → api/v1/transactions.php?resource=single&id=[id]
 *
 * Auth: cookie `_jwt` (realm pos-app).
 * Devuelve la misma estructura que getSingle: transactionId, transactionDatas,
 * customerId, total, pMethods, documentNo, date, type, etc.
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
): Promise<NextResponse> {
  const base = getApiBase()
  const cookieHeader = req.headers.get("cookie") ?? ""
  const authHeader = req.headers.get("authorization") ?? ""

  const headers: Record<string, string> = {
    cookie: cookieHeader,
    "content-type": "application/x-www-form-urlencoded",
  }
  if (authHeader) headers["authorization"] = authHeader
  if (HOST_OVERRIDE) headers["host"] = HOST_OVERRIDE

  const res = await fetch(`${base}${path}`, {
    method,
    headers,
    cache: "no-store",
  })

  const data = await res.json().catch(() => ({ error: "invalid json" }))
  return NextResponse.json(data, { status: res.status })
}

export async function GET(
  req: NextRequest,
  { params }: { params: Promise<{ id: string }> },
): Promise<NextResponse> {
  const { id } = await params
  if (!id) {
    return NextResponse.json({ error: "ID requerido" }, { status: 400 })
  }

  const qs = new URLSearchParams({ resource: "single", id })
  return proxyToApi("GET", `/v1/transactions.php?${qs.toString()}`, req)
}

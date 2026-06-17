/**
 * BFF — POS Transactions lista.
 *
 * Front (POS) → /api/pos/transactions → api/v1/transactions.php?resource=mainList
 *
 * Auth: cookie `_jwt` (realm pos-app). Misma estrategia que /api/pos/drawer.
 *
 * Parámetros opcionales forwarded:
 *   ?date=YYYY-MM-DD   — filtrar por fecha
 *   ?limit=N           — cantidad de resultados (default 30)
 *   ?search=texto      — búsqueda por nombre de cliente / comprobante
 *
 * La API backend devuelve { transactionsList: [...] } con campos:
 *   transactionId (enc), customerId (enc), name, type, status,
 *   date, total, discount, documentNo, invoicePrefix, pMethods, etc.
 *
 * Ref: api/lib/services/TransactionService.php getSingle y getTransactionList.
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

  const headers: Record<string, string> = {
    cookie: cookieHeader,
    "content-type": "application/x-www-form-urlencoded",
  }
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
  const date = searchParams.get("date")
  const limit = searchParams.get("limit") ?? "50"

  const qs = new URLSearchParams({ resource: "mainList" })
  if (date) qs.set("date", date)
  qs.set("limit", limit)

  return proxyToApi("GET", `/v1/transactions.php?${qs.toString()}`, req)
}

/**
 * BFF — POS Drawer (Slice Drawer-1).
 *
 * Front (POS) → /api/pos/drawer → api/v1/drawer.php
 *
 * Auth: cookie `_jwt` (realm pos-app). El catch-all /api/v1/[...path] usa
 * `_jwt_panel` y pierde contexto de caja — este route handler lo envía
 * explícitamente con todas las cookies del request (incluye `_jwt`).
 *
 * Endpoints expuestos:
 *   GET  /api/pos/drawer           → resumen completo (list, date, subtotal, total, tips, returns)
 *   GET  /api/pos/drawer?check=1   → { isOpen: bool }
 *   POST /api/pos/drawer           → abrir/cerrar caja (body: { type, amount, date, user })
 *   POST /api/pos/drawer/expense   → extracción (body: { amount, note, date })
 *   POST /api/pos/drawer/income    → ingreso    (body: { amount, note, date })
 */

import { NextRequest, NextResponse } from "next/server"
import { cookies } from "next/headers"

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
  extraHeaders?: Record<string, string>,
): Promise<NextResponse> {
  const base = getApiBase()
  const cookieHeader = req.headers.get("cookie") ?? ""
  const headers = new Headers()
  headers.set("cookie", cookieHeader)
  headers.set("accept", "application/json")
  if (HOST_OVERRIDE) headers.set("host", HOST_OVERRIDE)
  if (body) {
    if (body instanceof URLSearchParams || typeof body === "string") {
      headers.set("content-type", "application/x-www-form-urlencoded")
    } else {
      headers.set("content-type", "application/json")
    }
  }
  if (extraHeaders) {
    Object.entries(extraHeaders).forEach(([k, v]) => headers.set(k, v))
  }

  let upstream: Response
  try {
    upstream = await fetch(`${base}${path}`, {
      method,
      headers,
      body,
      cache: "no-store",
      redirect: "manual",
    })
  } catch (err) {
    console.error("[bff /api/pos/drawer] upstream fetch failed", {
      method,
      path,
      err: err instanceof Error ? err.message : String(err),
    })
    return NextResponse.json(
      { ok: false, error: { message: "BFF no pudo contactar la API", code: 502 } },
      { status: 502 },
    )
  }

  if (upstream.status >= 500) {
    const text = await upstream.clone().text().catch(() => "(no body)")
    console.warn("[bff /api/pos/drawer] upstream 5xx", { method, path, status: upstream.status, body: text.slice(0, 500) })
  }

  const upstreamBody = await upstream.text()
  const respHeaders = new Headers()
  upstream.headers.forEach((v, k) => {
    const lower = k.toLowerCase()
    if (["transfer-encoding", "content-encoding", "connection"].includes(lower)) return
    respHeaders.set(k, v)
  })
  // Asegurar content-type JSON si no lo trajo el upstream
  if (!respHeaders.has("content-type")) {
    respHeaders.set("content-type", "application/json")
  }

  return new NextResponse(upstreamBody, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: respHeaders,
  })
}

// ── GET /api/pos/drawer  ──────────────────────────────────────────────────────

export async function GET(req: NextRequest): Promise<NextResponse> {
  const check = req.nextUrl.searchParams.get("check")
  if (check === "1") {
    return proxyToApi("GET", "/v1/drawer.php?resource=check", req)
  }
  return proxyToApi("GET", "/v1/drawer.php", req)
}

// ── POST /api/pos/drawer  ─────────────────────────────────────────────────────
// Body: { action: "open"|"close"|"expense"|"income", amount, note?, date }
// El backend de apertura/cierre usa action.php (legacy POS realm).
// El backend de expense/income también usa action.php.
// Exponemos un único endpoint POST con `action` para simplificar el front.

export async function POST(req: NextRequest): Promise<NextResponse> {
  let body: Record<string, unknown>
  try {
    body = await req.json()
  } catch {
    return NextResponse.json({ ok: false, error: { message: "Body JSON inválido" } }, { status: 400 })
  }

  const action = body.action as string | undefined

  if (action === "open" || action === "close") {
    // Apertura/cierre: action.php con key `openCloseDrawer`
    const params = new URLSearchParams()
    params.set(
      "data[]",
      JSON.stringify({
        openCloseDrawer: {
          type: action,
          amount: body.amount ?? 0,
          date: body.date ?? new Date().toISOString().replace("T", " ").slice(0, 19),
          user: body.user ?? "",
          timestamp: Date.now(),
        },
      }),
    )
    return proxyToApi("POST", "/action.php", req, params)
  }

  if (action === "expense") {
    // Extracción de efectivo
    const params = new URLSearchParams()
    params.set(
      "data[]",
      JSON.stringify({
        expense: {
          amount: body.amount ?? 0,
          note: body.note ?? "",
          date: body.date ?? new Date().toISOString().replace("T", " ").slice(0, 19),
        },
      }),
    )
    return proxyToApi("POST", "/action.php", req, params)
  }

  if (action === "income") {
    // Ingreso de efectivo
    const params = new URLSearchParams()
    params.set(
      "data[]",
      JSON.stringify({
        drwrIncome: {
          amount: body.amount ?? 0,
          note: body.note ?? "",
          date: body.date ?? new Date().toISOString().replace("T", " ").slice(0, 19),
        },
      }),
    )
    return proxyToApi("POST", "/action.php", req, params)
  }

  return NextResponse.json({ ok: false, error: { message: "Acción no soportada" } }, { status: 400 })
}

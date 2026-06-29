/**
 * BFF — POS Drawer (Slice Drawer-1).
 *
 * Front (POS) → /api/pos/drawer → api/v1/drawer.php
 *
 * Auth: Bearer token del device (_jwt en localStorage). El catch-all /api/v1/[...path]
 * usa _jwt_panel y pierde contexto de caja — este route handler envía todos los
 * headers del request incluyendo Authorization Bearer.
 *
 * Endpoints expuestos:
 *   GET  /api/pos/drawer           → resumen completo (list, date, subtotal, total, tips, returns)
 *   GET  /api/pos/drawer?check=1   → { isOpen: bool }
 *   POST /api/pos/drawer           → { action: "open"|"close"|"expense"|"income", amount, date, note?, user? }
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

// ── GET /api/pos/drawer  ──────────────────────────────────────────────────────

export async function GET(req: NextRequest): Promise<NextResponse> {
  const check = req.nextUrl.searchParams.get("check")
  if (check === "1") {
    return bffProxy(req, { upstreamPath: "/v1/drawer.php?resource=check", requireBearer: true })
  }
  return bffProxy(req, { upstreamPath: "/v1/drawer.php", requireBearer: true })
}

// ── POST /api/pos/drawer  ─────────────────────────────────────────────────────
// Body: { action: "open"|"close"|"expense"|"income", amount, date, note?, user? }

export async function POST(req: NextRequest): Promise<NextResponse> {
  let body: Record<string, unknown>
  try {
    body = await req.json()
  } catch {
    return NextResponse.json({ ok: false, error: { message: "Body JSON inválido" } }, { status: 400 })
  }

  const action = body.action as string | undefined
  if (!action || !["open", "close", "expense", "income"].includes(action)) {
    return NextResponse.json({ ok: false, error: { message: "Acción no soportada" } }, { status: 400 })
  }

  const payload: Record<string, unknown> = {
    action,
    amount: body.amount ?? 0,
    date: body.date ?? new Date().toISOString().replace("T", " ").slice(0, 19),
    note: body.note ?? "",
    user: body.user ?? "",
  }

  return bffProxy(req, {
    upstreamPath: "/v1/drawer.php",
    body: JSON.stringify(payload),
    contentType: "application/json",
    requireBearer: true,
  })
}

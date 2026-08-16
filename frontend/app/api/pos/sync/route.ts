/**
 * BFF — sync incremental del POS (context/43-sync-incremental.md).
 *
 *   GET  /api/pos/sync?resource=watermarks
 *       → { ok: true, data: { items, customers, settings, serverTime } }
 *
 *   POST /api/pos/sync   body: { section: "items"|"customers", since: string|null }
 *       → { ok: true, data: { items|customers: [...], deletedIds: [...], full, serverTime } }
 *
 * Reenvía a `/v1/sync` (ver ese archivo para el contrato completo del
 * backend). El POST reshapea la fila upstream a `PosItem`/`PosCustomer` con
 * los MISMOS helpers que `/api/pos/items-batch` y `/api/pos/customers-batch`
 * (`lib/pos-bff/reshape.ts`) — evita que este tercer camino diverja del
 * shape que `useCatalogStore` espera.
 *
 * Un item que el delta trae pero que ya NO es vendible (archivado o
 * `itemCanSale=false`) se trata igual que un borrado real — mismo criterio
 * que `items-batch` (context/15 §Modelo quirúrgico): se suma a
 * `deletedIds` en vez de a `items`, así el caller solo necesita dos listas
 * (patch vs remove), nunca un tercer estado.
 *
 * Auth: Bearer del device vía `bffProxy` — mismo cliente que el resto de
 * `/api/pos/*`.
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"
import {
  reshapeItem,
  reshapeCustomer,
  isSellableItemRow,
  type UpstreamItemRow,
  type UpstreamContactRow,
} from "@/lib/pos-bff/reshape"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, {
    upstreamPath: "/v1/sync?resource=watermarks",
    method: "GET",
    requireBearer: true,
  })
}

interface UpstreamSectionData {
  items?: UpstreamItemRow[]
  customers?: UpstreamContactRow[]
  deletedIds?: string[]
  full?: boolean
  serverTime?: string
}

export async function POST(req: NextRequest) {
  let body: unknown
  try {
    body = await req.json()
  } catch {
    return NextResponse.json(
      { ok: false, error: { message: "Body inválido (esperaba JSON)", code: 422 } },
      { status: 422 },
    )
  }

  const section = (body as { section?: unknown })?.section
  const since = (body as { since?: unknown })?.since
  if (section !== "items" && section !== "customers") {
    return NextResponse.json(
      { ok: false, error: { message: "section debe ser 'items' o 'customers'", code: 422 } },
      { status: 422 },
    )
  }
  const sinceValue = typeof since === "string" && since !== "" ? since : null

  const upstream = await bffProxy(req, {
    upstreamPath: `/v1/sync?section=${section}`,
    method: "POST",
    body: JSON.stringify({ since: sinceValue }),
    contentType: "application/json",
    requireBearer: true,
  })

  if (!(upstream.headers.get("content-type") ?? "").includes("json")) {
    return upstream
  }

  const parsed = (await upstream.json().catch(() => null)) as
    | { ok?: boolean; data?: UpstreamSectionData }
    | null
  if (parsed === null) {
    return NextResponse.json(
      { ok: false, error: { message: "Respuesta inválida de la API", code: 502 } },
      { status: 502 },
    )
  }
  if (parsed.ok !== true) {
    return NextResponse.json(parsed, { status: upstream.status })
  }

  const data = parsed.data ?? {}
  const full = data.full === true
  const serverTime = data.serverTime ?? null
  const deletedIds = Array.isArray(data.deletedIds) ? data.deletedIds : []

  if (section === "items") {
    const rows = data.items ?? []
    const sellable = rows.filter(isSellableItemRow)
    const nonSellableIds = rows.filter((r) => !isSellableItemRow(r)).map((r) => r.itemId)
    return NextResponse.json({
      ok: true,
      data: {
        items: sellable.map(reshapeItem),
        deletedIds: [...deletedIds, ...nonSellableIds],
        full,
        serverTime,
      },
    })
  }

  const rows = data.customers ?? []
  return NextResponse.json({
    ok: true,
    data: { customers: rows.map(reshapeCustomer), deletedIds, full, serverTime },
  })
}

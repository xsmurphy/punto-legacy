/**
 * BFF — fetch puntual de ítems por id, en lote (sync realtime quirúrgico,
 * context/15-realtime-sync-plan.md §Modelo quirúrgico).
 *
 *   POST /api/pos/items-batch   body: { ids: string[] }
 *       → { ok: true, data: { items: PosItem[] } }
 *
 * Por qué POST y no GET ?id=a,b,c: la lista de ids puede ser grande (bulk
 * edit de miles de productos desde el panel, o el batch que
 * `Inventory::manageStock()` acumula en una venta multi-línea) — un GET con
 * esa cantidad en query string pega el límite de largo de URL. Un POST
 * tampoco lo cachea el browser ni un proxy intermedio: la caja siempre
 * recibe el dato fresco, justo el punto de un trigger "esto cambió recién".
 *
 * Auth: Bearer del device (`_jwt`, realm `pos-app`) vía `bffProxy` — mismo
 * cliente que el resto de `/api/pos/*`. `bffProxy` reenvía el body/headers
 * tal cual al upstream `/v1/items?resource=bulk-get`
 * (`apiAuthTenant(['panel','pos-app'])`, ver api/v1/items.php), que YA
 * filtra `companyId = ?` server-side — un id que no es del tenant del token
 * simplemente no vuelve en la respuesta, nunca se confía en el body.
 *
 * Solo devuelve ítems VENDIBLES (activo + canSale, `isSellableItemRow` —
 * mismo criterio que `/api/pos/bootstrap`). El caller (`lib/catalog/
 * realtime-catalog-sync.ts`) compara los ids pedidos contra los ids que
 * volvieron: cualquier id pedido que NO aparece en la respuesta (borrado,
 * desactivado, o archivado) se interpreta como "sacalo del store" — no hace
 * falta un campo separado para eso.
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"
import { reshapeItem, isSellableItemRow, type UpstreamItemRow } from "@/lib/pos-bff/reshape"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

/**
 * Techo por request. Mismo valor que el hard-cap del upstream
 * (`api/v1/items.php` resource=bulk-get) — si el caller manda más, el BFF
 * corta ACÁ (más barato que dejar que el upstream lo trunque después de ya
 * haber armado el JSON grande) y el caller debe mandar tandas.
 */
const MAX_IDS = 2000

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

  const rawIds = (body as { ids?: unknown })?.ids
  if (!Array.isArray(rawIds)) {
    return NextResponse.json(
      { ok: false, error: { message: "Falta ids (array)", code: 422 } },
      { status: 422 },
    )
  }

  const ids = Array.from(
    new Set(
      rawIds
        .map((v) => String(v).trim())
        .filter((v) => v !== ""),
    ),
  ).slice(0, MAX_IDS)

  if (ids.length === 0) {
    return NextResponse.json({ ok: true, data: { items: [] } })
  }

  const upstream = await bffProxy(req, {
    upstreamPath: "/v1/items?resource=bulk-get",
    method: "POST",
    body: JSON.stringify({ ids }),
    contentType: "application/json",
    requireBearer: true,
  })

  if (!(upstream.headers.get("content-type") ?? "").includes("json")) {
    return upstream
  }

  const parsed = (await upstream.json().catch(() => null)) as
    | { ok?: boolean; data?: { items?: UpstreamItemRow[] } }
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

  const rows = parsed.data?.items ?? []
  const items = rows.filter(isSellableItemRow).map(reshapeItem)

  return NextResponse.json({ ok: true, data: { items } })
}

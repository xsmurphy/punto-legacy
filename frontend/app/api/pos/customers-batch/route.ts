/**
 * BFF — fetch puntual de clientes por id, en lote (sync realtime quirúrgico,
 * context/15-realtime-sync-plan.md §Modelo quirúrgico).
 *
 *   POST /api/pos/customers-batch   body: { ids: string[] }
 *       → { ok: true, data: { customers: PosCustomer[] } }
 *
 * Mismo criterio que `/api/pos/items-batch` (ver ese docblock: por qué POST,
 * por qué en lote, por qué el aislamiento multi-tenant vive en el upstream y
 * no acá). Diferencia con items: no hay filtro de "vendible" — un contacto
 * archivado (contactStatus=0) SÍ vuelve en la respuesta y se patchea igual;
 * solo un evento `delete` explícito saca un cliente del store (ver
 * `lib/catalog/realtime-catalog-sync.ts`). Un id que no existe (borrado de
 * verdad) o que no es del tenant del token simplemente no vuelve — el
 * caller lo interpreta como "sacalo del store".
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"
import { reshapeCustomer, type UpstreamContactRow } from "@/lib/pos-bff/reshape"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

/** Mismo techo que el upstream (`api/v1/contacts.php` resource=bulk-get). */
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
    return NextResponse.json({ ok: true, data: { customers: [] } })
  }

  const upstream = await bffProxy(req, {
    upstreamPath: "/v1/contacts?resource=bulk-get",
    method: "POST",
    body: JSON.stringify({ ids }),
    contentType: "application/json",
    requireBearer: true,
  })

  if (!(upstream.headers.get("content-type") ?? "").includes("json")) {
    return upstream
  }

  const parsed = (await upstream.json().catch(() => null)) as
    | { ok?: boolean; data?: { contacts?: UpstreamContactRow[] } }
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

  const rows = parsed.data?.contacts ?? []
  const customers = rows.map(reshapeCustomer)

  return NextResponse.json({ ok: true, data: { customers } })
}

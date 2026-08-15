/**
 * BFF — Add-ons de un producto para el POS (SOLO LECTURA).
 *
 *   GET /api/pos/item-addons?itemId=<uuid>
 *       → { groups: [{ id, name, minSelect, maxSelect, sort, options[] }] }
 *
 * Auth: Bearer del device (`_jwt` en localStorage) — realm `pos-app`, que
 * `api/v1/item_addons.php` ya acepta (`apiAuthTenant(['panel','pos-app'])`).
 *
 * SOLO GET. El endpoint upstream también expone `replace` y `copy` por POST
 * (administración del catálogo, exclusiva del panel); acá se corta al método,
 * no a la acción: la superficie del device es consultar, no administrar. Mismo
 * criterio que `app/api/pos/items/route.ts`.
 *
 * PROYECCIÓN explícita de campos, no denylist — misma decisión que la ficha de
 * producto del POS: un campo nuevo del upstream (un costo, un flag interno del
 * catálogo) no llega al device hasta que alguien lo agregue acá a propósito.
 * De `AddonService::listForItem` se descarta `itemPrice` (precio de catálogo
 * de la opción): el POS cobra el `priceDelta`, el precio suelto del producto
 * add-on no se muestra en el modal y sólo confundiría al cajero.
 *
 * Los grupos con `status = false` se filtran ACÁ: el POS nunca debe ofrecer un
 * grupo dado de baja, y dejar el filtro en el componente es una condición que
 * el próximo render se puede olvidar. El backend igual revalida al vender
 * (`AddonService::validateSelections`).
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === "object" && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

function asArray(value: unknown): unknown[] {
  return Array.isArray(value) ? value : []
}

/** `true` real, o sus representaciones de PG vía PDO ('t'/'true'/1). */
function asBoolean(value: unknown): boolean {
  return value === true || value === "t" || value === "true" || value === 1
}

/** NUMERIC de PG puede llegar string; basura → fallback. */
function asNumber(value: unknown, fallback: number): number {
  const n = typeof value === "string" ? Number(value) : value
  return typeof n === "number" && Number.isFinite(n) ? n : fallback
}

function projectGroups(raw: unknown): Record<string, unknown>[] {
  return asArray(asRecord(raw).groups)
    .map(asRecord)
    .filter((g) => asBoolean(g.status ?? true))
    .map((g) => ({
      id: String(g.id ?? ""),
      name: typeof g.name === "string" ? g.name : "",
      minSelect: asNumber(g.minSelect, 0),
      maxSelect: g.maxSelect === null || g.maxSelect === undefined ? null : asNumber(g.maxSelect, 0),
      sort: asNumber(g.sort, 0),
      options: asArray(g.options)
        .map(asRecord)
        .map((o) => ({
          id: String(o.id ?? ""),
          itemId: String(o.itemId ?? ""),
          itemName: typeof o.itemName === "string" ? o.itemName : "",
          priceDelta: asNumber(o.priceDelta, 0),
          isDefault: asBoolean(o.isDefault),
          isLocked: asBoolean(o.isLocked),
          maxQty: Math.max(1, Math.trunc(asNumber(o.maxQty, 1))),
          sort: asNumber(o.sort, 0),
        }))
        .filter((o) => o.id !== ""),
    }))
    .filter((g) => g.id !== "")
}

export async function GET(req: NextRequest) {
  const itemId = (req.nextUrl.searchParams.get("itemId") ?? "").trim()

  if (!UUID_RE.test(itemId)) {
    return NextResponse.json(
      { ok: false, error: { message: "itemId inválido", code: 422 } },
      { status: 422 },
    )
  }

  const upstream = await bffProxy(req, {
    upstreamPath: `/v1/item_addons?itemId=${encodeURIComponent(itemId)}`,
    requireBearer: true,
  })

  // Errores del proxy (401/502) y respuestas no-JSON pasan tal cual.
  if (!(upstream.headers.get("content-type") ?? "").includes("json")) {
    return upstream
  }

  const body = (await upstream.json().catch(() => null)) as
    | { ok?: boolean; data?: unknown }
    | null
  if (body === null) {
    return NextResponse.json(
      { ok: false, error: { message: "Respuesta inválida de la API", code: 502 } },
      { status: 502 },
    )
  }

  const headers = new Headers(upstream.headers)
  // El body cambia de tamaño al proyectarlo: un content-length viejo trunca
  // la respuesta.
  headers.delete("content-length")

  if (body.ok !== true) {
    return NextResponse.json(body, { status: upstream.status, headers })
  }

  return NextResponse.json(
    { ok: true, data: { groups: projectGroups(body.data) } },
    { status: upstream.status, headers },
  )
}

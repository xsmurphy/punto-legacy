/**
 * BFF — Ficha de producto del POS (SOLO LECTURA).
 *
 *   GET /api/pos/items?id=<uuid>
 *       → detalle del ítem (nombre, descripción, precio, etiquetas, umbrales)
 *   GET /api/pos/items?id=<uuid>&resource=inventory-movements
 *       → { summary, breakdown: { total, outlets[] }, items[] }
 *
 * Auth: Bearer del device (`_jwt` en localStorage) — realm `pos-app`, que
 * `api/v1/items.php` ya acepta (`apiAuthTenant(['panel','pos-app'])`).
 *
 * SOLO GET, y con los recursos en whitelist: el endpoint upstream autentica al
 * realm `pos-app` para TODO el CRUD de items (alta, PUT, archive, bulk-edit).
 * Este BFF es la superficie que expone el device, así que acá se corta: una
 * caja consulta el catálogo, no lo administra. Si mañana el POS necesita
 * escribir un ítem, se agrega el método explícitamente y no por herencia.
 *
 * COSTOS: la respuesta upstream trae costo unitario (`itemCost`), costo
 * promedio (`avgCost`), valorizado (`totalValue`) y el COGS de cada movimiento
 * (`deltaCogs`). La ficha del POS muestra precio de VENTA y nunca costo (regla
 * del owner), y el filtro va ACÁ y no en el componente: si el dato no llega al
 * device, no hay forma de que un render futuro —ni el DevTools de la tablet en
 * el mostrador— lo exponga.
 *
 * Por eso este route PROYECTA la respuesta a los campos que la ficha consume,
 * en vez de borrar una lista de claves prohibidas. Una denylist hay que
 * mantenerla cada vez que el endpoint upstream —que no es de este BFF— suma un
 * campo, y el primer olvido es una fuga silenciosa (el review encontró
 * exactamente eso con `deltaCogs`). Con la proyección, un campo nuevo no llega
 * hasta que alguien lo agregue acá a propósito.
 *
 * GALERÍA: el upstream (`presentItem()` + `ItemImageService::listForItem()`)
 * ya trae `images[]` (hasta 5, ordenadas por `sort`) en el GET de detalle —
 * hasta ahora se descartaba a propósito acá (comentario viejo: "se queda del
 * lado del panel"). La ficha del POS (2026-08-18) sí la necesita para que el
 * cajero pueda ver fotos del producto. Se proyecta solo `id`/`url`/`sort` —
 * ni `objectKey` (ruta interna de S3) ni `mime`/`sizeBytes` tienen uso en el
 * device.
 */

import { NextRequest, NextResponse } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

/** Recursos de `/v1/items` que la ficha del POS puede consultar. */
const ALLOWED_RESOURCES = new Set(["", "inventory-movements"])

/**
 * Campos escalares del detalle que viajan al device. Todo lo demás (costo,
 * flags de catálogo, JSONB del kind) se queda del lado del panel. La galería
 * de imágenes NO es escalar — viaja aparte, proyectada por `projectDetail()`
 * más abajo (`out.images`), con su propia whitelist de subcampos.
 */
const DETAIL_FIELDS = [
  "itemName",
  "itemSKU",
  "itemDescription",
  "itemPrice",
  "itemUOM",
  "categoryName",
  "brandName",
  "itemMinStock",
  "itemMaxStock",
  "itemTrackInventory",
] as const

function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === "object" && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

function asArray(value: unknown): unknown[] {
  return Array.isArray(value) ? value : []
}

function projectDetail(raw: unknown): Record<string, unknown> {
  const src = asRecord(raw)
  const out: Record<string, unknown> = {}
  for (const field of DETAIL_FIELDS) out[field] = src[field] ?? null
  out.tags = asArray(src.tags).map((t) => {
    const tag = asRecord(t)
    return { id: tag.id ?? "", name: tag.name ?? "" }
  })
  // Galería: proyección mínima, ordenada por `sort` (el upstream ya la trae
  // ordenada, pero no vale la pena confiar en eso acá).
  out.images = asArray(src.images)
    .map((img) => {
      const image = asRecord(img)
      return {
        id: image.imageId ?? "",
        url: image.url ?? "",
        sort: typeof image.sort === "number" ? image.sort : 0,
      }
    })
    .filter((img) => img.url !== "")
    .sort((a, b) => a.sort - b.sort)
  return out
}

/**
 * Del recurso `inventory-movements` sólo sale el `breakdown` (cuánto hay y
 * dónde). El `summary` es pura valorización y el historial de movimientos no
 * se muestra en la ficha.
 */
function projectStock(raw: unknown): Record<string, unknown> {
  const breakdown = asRecord(asRecord(raw).breakdown)
  return {
    breakdown: {
      total: breakdown.total ?? 0,
      outlets: asArray(breakdown.outlets).map((o) => {
        const outlet = asRecord(o)
        return {
          outletId: outlet.outletId ?? "",
          outletName: outlet.outletName ?? "",
          qty: outlet.qty ?? 0,
          locations: asArray(outlet.locations).map((l) => {
            const loc = asRecord(l)
            return {
              locationId: loc.locationId ?? null,
              locationName: loc.locationName ?? null,
              qty: loc.qty ?? 0,
            }
          }),
        }
      }),
    },
  }
}

export async function GET(req: NextRequest) {
  const sp = req.nextUrl.searchParams
  const id = (sp.get("id") ?? "").trim()
  const resource = sp.get("resource") ?? ""

  if (id === "") {
    return NextResponse.json(
      { ok: false, error: { message: "Falta id", code: 422 } },
      { status: 422 },
    )
  }
  if (!ALLOWED_RESOURCES.has(resource)) {
    return NextResponse.json(
      { ok: false, error: { message: "Recurso no disponible para el POS", code: 404 } },
      { status: 404 },
    )
  }

  const qs = new URLSearchParams({ id })
  if (resource !== "") qs.set("resource", resource)
  if (resource === "inventory-movements") {
    // La ficha muestra el saldo por sucursal/depósito (`breakdown`), no el
    // historial: se pide la página mínima para no arrastrar movimientos que
    // nadie va a renderizar por una red de caja que puede ser 3G.
    qs.set("limit", "1")
    qs.set("offset", "0")
  }

  const upstream = await bffProxy(req, {
    upstreamPath: `/v1/items?${qs.toString()}`,
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

  // Envelope de error del upstream (401/404/422): pasa tal cual, no trae datos.
  if (body.ok !== true) {
    return NextResponse.json(body, { status: upstream.status, headers })
  }

  const data = resource === "" ? projectDetail(body.data) : projectStock(body.data)
  return NextResponse.json({ ok: true, data }, { status: upstream.status, headers })
}

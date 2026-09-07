/**
 * BFF — Ficha de producto del POS (SOLO LECTURA).
 *
 *   GET /api/pos/items?id=<uuid>
 *       → detalle del ítem (nombre, descripción, precio, etiquetas, umbrales)
 *   GET /api/pos/items?id=<uuid>&resource=inventory-movements
 *       → { breakdown: { total, outlets[] } }
 *   GET /api/pos/items?id=<uuid>&resource=producible
 *       → { hasRecipe, outlets: [{ outletId, outletName, capacity, limiting }] }
 *         "Producibles ahora" de un ítem con receta. Va contra
 *         `/v1/production?resource=producible` (motor `RecipeCapacity`), no
 *         contra `/v1/items`.
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

/**
 * Recursos que la ficha del POS puede consultar. Los dos primeros van a
 * `/v1/items`; `producible` va a `/v1/production` (ver `upstreamFor()`) — se
 * expone por acá y no con un route propio porque para el cajero es una sección
 * MÁS de la misma ficha, y así el whitelist de lo que un device puede leer del
 * catálogo queda en un solo archivo.
 */
const ALLOWED_RESOURCES = new Set(["", "inventory-movements", "producible"])

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

/**
 * "Producibles ahora": cuántas unidades salen HOY con el stock de los insumos,
 * y cuál es el que limita. El cálculo es la explosión de la receta contra el
 * ledger (`RecipeCapacity`), no un dato del catálogo — por eso se pide al abrir
 * la ficha y no viaja en el bootstrap.
 *
 * Proyección, no denylist, por el mismo motivo que `projectDetail()`: el
 * upstream devuelve un ingrediente por hoja de la receta y cualquier campo que
 * le agreguen mañana (costos, por ejemplo) no llega al device hasta que alguien
 * lo ponga acá a propósito.
 *
 * El realm `pos-app` acota el alcance a la sucursal del dispositivo, así que
 * `outlets` trae exactamente una entrada — se proyecta igual como lista para no
 * inventar un shape distinto del que devuelve la API.
 */
function projectProducible(raw: unknown): Record<string, unknown> {
  const src = asRecord(raw)
  return {
    hasRecipe: src.hasRecipe === true,
    outlets: asArray(src.outlets).map((o) => {
      const outlet = asRecord(o)
      const limiting = asRecord(outlet.limiting)
      return {
        outletId: outlet.outletId ?? "",
        outletName: outlet.outletName ?? "",
        // `null` = ningún insumo con control de inventario limita. NO es 0,
        // que significa "no se puede producir ni una".
        capacity: outlet.capacity ?? null,
        limiting:
          outlet.limiting === null || outlet.limiting === undefined
            ? null
            : {
                itemId: limiting.itemId ?? "",
                itemName: limiting.itemName ?? "",
                onHand: limiting.onHand ?? null,
                neededPerUnit: limiting.neededPerUnit ?? 0,
                unitsSupported: limiting.unitsSupported ?? null,
              },
      }
    }),
  }
}

/**
 * A qué endpoint de la API va cada recurso de la ficha. `producible` es el
 * único que sale de `/v1/items`.
 */
function upstreamFor(resource: string, id: string): string {
  if (resource === "producible") {
    // La sucursal NO viaja en la query: el realm `pos-app` la resuelve desde la
    // fila del device (ver el guard de `api/v1/production.php`). Mandarla desde
    // el browser sería una segunda puerta sin ese chequeo.
    return `/v1/production?resource=producible&itemId=${encodeURIComponent(id)}`
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
  return `/v1/items?${qs.toString()}`
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

  const upstream = await bffProxy(req, {
    upstreamPath: upstreamFor(resource, id),
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

  const project =
    resource === "" ? projectDetail : resource === "producible" ? projectProducible : projectStock
  return NextResponse.json(
    { ok: true, data: project(body.data) },
    { status: upstream.status, headers },
  )
}

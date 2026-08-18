"use client"

/**
 * Datos de la ficha de producto del POS (`ProductInfoDialog`).
 *
 * Dos fuentes, una sola query:
 *   - `GET /api/pos/items?id=X`                            → ficha del ítem
 *   - `GET /api/pos/items?id=X&resource=inventory-movements` → stock por
 *     sucursal y depósito
 *
 * Van juntas en un `Promise.all` bajo una sola key porque la ficha no tiene
 * sentido a medias: mostrar el precio mientras el stock sigue cargando obliga
 * al cajero a esperar igual, con el layout saltando en el medio.
 *
 * Cliente: `posFetch` (Bearer del device, realm `pos-app`). NUNCA `api-client`
 * — ese lleva la cookie del panel y en un device sin sesión de operador da 401
 * silencioso (regla del proyecto: un cliente HTTP por realm).
 *
 * Sin costo: el BFF borra `itemCost`/`avgCost`/`totalValue` antes de que el
 * JSON llegue al device (ver `app/api/pos/items/route.ts`). Los tipos de acá
 * declaran solo lo que la ficha consume.
 */

import { useQuery } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"

/** Saldo de un depósito dentro de una sucursal. `null` = depósito principal. */
export interface PosItemStockLocation {
  locationId: string | null
  locationName: string | null
  qty: number
}

export interface PosItemStockOutlet {
  outletId: string
  outletName: string
  qty: number
  locations: PosItemStockLocation[]
}

export interface PosItemStockBreakdown {
  total: number
  outlets: PosItemStockOutlet[]
}

/** Imagen de la galería del ítem (proyección mínima, ver BFF). */
export interface PosItemImage {
  id: string
  url: string
  sort: number
}

/** Subset del detalle de `/v1/items?id=` que la ficha necesita. */
export interface PosItemDetail {
  itemName: string
  itemSKU: string | null
  itemDescription: string | null
  itemPrice: number
  itemUOM: string | null
  categoryName: string | null
  brandName: string | null
  /** `null` = sin umbral definido (≠ 0, que es "avisame al llegar a cero"). */
  itemMinStock: number | null
  itemMaxStock: number | null
  itemTrackInventory: boolean
  tags: { id: string; name: string }[]
  /** Galería completa (0..5), ordenada. Requiere red — no viaja en el bootstrap offline. */
  images: PosItemImage[]
}

export interface PosItemInfo {
  detail: PosItemDetail
  stock: PosItemStockBreakdown
}

/** Envelope `{ ok, data }` de la API compartida. */
async function posGetJson<T>(url: string): Promise<T> {
  const res = await posFetch(url, { method: "GET" })
  const json = await res.json().catch(() => null)
  if (!res.ok || !json?.ok) {
    throw new Error(json?.error?.message ?? `Error ${res.status}`)
  }
  return json.data as T
}

/** `"12.5"` (numeric de PG llega como string) → 12.5; basura → fallback. */
function toNumber(value: unknown, fallback: number): number {
  const n = typeof value === "string" ? Number(value) : value
  return typeof n === "number" && Number.isFinite(n) ? n : fallback
}

/** Igual que `toNumber` pero preservando el `null` de "sin umbral". */
function toNullableNumber(value: unknown): number | null {
  if (value === null || value === undefined || value === "") return null
  const n = typeof value === "string" ? Number(value) : value
  return typeof n === "number" && Number.isFinite(n) ? n : null
}

function toBoolean(value: unknown): boolean {
  if (typeof value === "boolean") return value
  if (typeof value === "number") return value > 0
  if (typeof value === "string") return value === "t" || value === "true" || value === "1"
  return false
}

function normalizeImages(raw: unknown): PosItemImage[] {
  const arr = Array.isArray(raw) ? raw : []
  return arr
    .map((i) => {
      const img = i as { id?: unknown; url?: unknown; sort?: unknown }
      return {
        id: String(img?.id ?? ""),
        url: typeof img?.url === "string" ? img.url : "",
        sort: toNumber(img?.sort, 0),
      }
    })
    .filter((img) => img.url !== "")
}

function normalizeDetail(raw: Record<string, unknown>): PosItemDetail {
  const tags = Array.isArray(raw.tags) ? raw.tags : []
  return {
    itemName: typeof raw.itemName === "string" ? raw.itemName : "",
    itemSKU: typeof raw.itemSKU === "string" && raw.itemSKU !== "" ? raw.itemSKU : null,
    itemDescription:
      typeof raw.itemDescription === "string" && raw.itemDescription.trim() !== ""
        ? raw.itemDescription
        : null,
    itemPrice: toNumber(raw.itemPrice, 0),
    itemUOM: typeof raw.itemUOM === "string" && raw.itemUOM !== "" ? raw.itemUOM : null,
    categoryName: typeof raw.categoryName === "string" ? raw.categoryName : null,
    brandName: typeof raw.brandName === "string" ? raw.brandName : null,
    itemMinStock: toNullableNumber(raw.itemMinStock),
    itemMaxStock: toNullableNumber(raw.itemMaxStock),
    itemTrackInventory: toBoolean(raw.itemTrackInventory),
    tags: tags
      .map((t) => {
        const tag = t as { id?: unknown; name?: unknown }
        return {
          id: String(tag?.id ?? ""),
          name: typeof tag?.name === "string" ? tag.name : "",
        }
      })
      .filter((t) => t.name !== ""),
    images: normalizeImages(raw.images),
  }
}

function normalizeBreakdown(raw: unknown): PosItemStockBreakdown {
  const b = (raw ?? {}) as { total?: unknown; outlets?: unknown }
  const outlets = Array.isArray(b.outlets) ? b.outlets : []
  return {
    total: toNumber(b.total, 0),
    outlets: outlets.map((o) => {
      const outlet = o as Record<string, unknown>
      const locations = Array.isArray(outlet.locations) ? outlet.locations : []
      return {
        outletId: String(outlet.outletId ?? ""),
        outletName: typeof outlet.outletName === "string" ? outlet.outletName : "Sucursal",
        qty: toNumber(outlet.qty, 0),
        locations: locations.map((l) => {
          const loc = l as Record<string, unknown>
          return {
            locationId: loc.locationId ? String(loc.locationId) : null,
            locationName:
              typeof loc.locationName === "string" && loc.locationName !== ""
                ? loc.locationName
                : null,
            qty: toNumber(loc.qty, 0),
          }
        }),
      }
    }),
  }
}

/**
 * Ficha completa de un ítem. `itemId = null` deja la query desactivada (el
 * diálogo cerrado no pide nada).
 *
 * `staleTime` corto: el stock de otra sucursal cambia mientras el cajero
 * atiende, y el caso de uso es justamente derivar al cliente ahí.
 *
 * `retry: 1` — el POS puede estar sin red. Un solo reintento y el diálogo
 * pinta su estado de error con botón de reintentar, en vez de dejar un
 * spinner girando contra una conexión caída.
 */
export function usePosItemInfo(itemId: string | null) {
  return useQuery<PosItemInfo>({
    queryKey: ["pos", "item-info", itemId],
    enabled: itemId !== null,
    staleTime: 15 * 1000,
    retry: 1,
    queryFn: async () => {
      const id = encodeURIComponent(itemId as string)
      const [detail, stock] = await Promise.all([
        posGetJson<Record<string, unknown>>(`/api/pos/items?id=${id}`),
        posGetJson<{ breakdown?: unknown }>(
          `/api/pos/items?id=${id}&resource=inventory-movements`,
        ),
      ])
      return {
        detail: normalizeDetail(detail),
        stock: normalizeBreakdown(stock?.breakdown),
      }
    },
  })
}

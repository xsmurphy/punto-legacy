"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posApi as api } from "@/lib/api/pos-client"
import type { CartLine } from "@/lib/cart/store"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"

// ── Tipos ─────────────────────────────────────────────────────────────────────

export interface ParkedSaleData {
  cart: CartLine[]
  customer: PosCustomer | null
  notes?: string | null
  title?: string | null
  /**
   * Descuento de venta activo al guardar — sin esto, retomar perdía el descuento.
   * `lineIds` = alcance congelado (ver store). Las ventas guardadas antes del
   * 2026-07-30 no lo traen; al retomarlas se recalcula sobre las líneas sin
   * descuento propio, que es el comportamiento que tenían cuando se guardaron.
   */
  saleDiscount?: { value: number; mode: "percent" | "money"; lineIds?: string[] } | null
  /** Etiquetas de la venta al guardar. */
  tags?: string[]
}

export interface ParkedSale {
  id: string
  data: ParkedSaleData
  createdAt: string
}

// ── Normalización ─────────────────────────────────────────────────────────────
//
// `data` es un jsonb sin schema validado en el momento del guardado (ver
// api/v1/parked-sales.php) — puede venir null, con `cart` faltante, o con
// líneas incompletas de un guardado viejo (el shape de CartLine cambió con el
// tiempo). Antes esto se parchaba campo por campo en el componente cada vez
// que aparecía un crash nuevo (commits 304dc562, 74450e49: primero `cart`,
// después `title`/`customer`) — el próximo campo faltante iba a volver a
// tumbar la página. Normalizamos ACÁ, una sola vez, para que el resto de la
// app pueda confiar en el shape de `ParkedSale.data` sin `?.` disperso.
/**
 * Add-ons guardados en una línea aparcada. `undefined` si no hay ninguno
 * válido, para que la línea quede idéntica a una sin add-ons (la clave de
 * identidad del carrito compara `selections` vacío y ausente como lo mismo,
 * pero un array vacío ensuciaría el payload de la venta).
 */
function normalizeSelections(raw: unknown): CartLine["selections"] {
  if (!Array.isArray(raw)) return undefined
  const out = raw
    .filter((s): s is Record<string, unknown> => !!s && typeof s === "object")
    .map((s) => ({
      optionId: typeof s.optionId === "string" ? s.optionId : "",
      qty: typeof s.qty === "number" && Number.isFinite(s.qty) && s.qty >= 1 ? Math.trunc(s.qty) : 1,
      itemId: typeof s.itemId === "string" ? s.itemId : "",
      name: typeof s.name === "string" ? s.name : "",
      priceDelta: typeof s.priceDelta === "number" && Number.isFinite(s.priceDelta) ? s.priceDelta : 0,
    }))
    .filter((s) => s.optionId !== "")
  return out.length > 0 ? out : undefined
}

function normalizeParkedSaleData(raw: unknown): ParkedSaleData {
  const d = (raw ?? {}) as Record<string, unknown>
  const rawCart: unknown[] = Array.isArray(d.cart) ? d.cart : []
  const cart: CartLine[] = rawCart
    .filter((l): l is Record<string, unknown> => !!l && typeof l === "object")
    .map((l) => ({
      lineId: typeof l.lineId === "string" ? l.lineId : crypto.randomUUID(),
      itemId: typeof l.itemId === "string" ? l.itemId : "",
      name: typeof l.name === "string" ? l.name : "(sin nombre)",
      qty: typeof l.qty === "number" && Number.isFinite(l.qty) ? l.qty : 0,
      unitPrice: typeof l.unitPrice === "number" && Number.isFinite(l.unitPrice) ? l.unitPrice : 0,
      note: typeof l.note === "string" ? l.note : undefined,
      sellerId: typeof l.sellerId === "string" ? l.sellerId : undefined,
      discount: typeof l.discount === "number" ? l.discount : undefined,
      tags: Array.isArray(l.tags) ? (l.tags as string[]) : undefined,
      giftcard: (l.giftcard && typeof l.giftcard === "object" ? l.giftcard : undefined) as CartLine["giftcard"],
      // Add-ons de la línea (F4, context/41). Este normalizador es una
      // WHITELIST: sin esta rama, retomar una venta aparcada devolvía la línea
      // con el precio de los add-ons ya sumado en `unitPrice` pero SIN las
      // selecciones — se cobraba el recargo y no llegaba nada a cocina.
      // `unitPrice` no se toca acá: ya venía con el recargo incluido.
      selections: normalizeSelections(l.selections),
    }))

  const customer =
    d.customer && typeof d.customer === "object" && typeof (d.customer as { name?: unknown }).name === "string"
      ? (d.customer as PosCustomer)
      : null

  const rawDiscount = d.saleDiscount as { value?: unknown; mode?: unknown } | null | undefined
  const saleDiscount =
    rawDiscount &&
    typeof rawDiscount === "object" &&
    typeof rawDiscount.value === "number" &&
    (rawDiscount.mode === "percent" || rawDiscount.mode === "money")
      ? {
          value: rawDiscount.value,
          mode: rawDiscount.mode as "percent" | "money",
          lineIds: Array.isArray((rawDiscount as { lineIds?: unknown }).lineIds)
            ? ((rawDiscount as { lineIds: unknown[] }).lineIds.filter(
                (x): x is string => typeof x === "string",
              ))
            : undefined,
        }
      : null

  return {
    cart,
    customer,
    notes: typeof d.notes === "string" ? d.notes : null,
    title: typeof d.title === "string" ? d.title : null,
    saleDiscount,
    tags: Array.isArray(d.tags) ? (d.tags as string[]).filter((t) => typeof t === "string") : [],
  }
}

// ── Query key ─────────────────────────────────────────────────────────────────

const PARKED_SALES_KEY = ["parked-sales"] as const

// ── Hooks ─────────────────────────────────────────────────────────────────────

/** Lista las ventas guardadas del usuario en el outlet activo.
 * `enabled` para que los call-sites del panel (que NO tienen Bearer device)
 * no disparen la query — el endpoint requiere `apiAuthPosContext` y solo
 * devolvería 401. Default `true` para back-compat con el POS. */
export function useParkedSales(opts: { enabled?: boolean } = {}) {
  return useQuery<ParkedSale[]>({
    queryKey: PARKED_SALES_KEY,
    queryFn: () => api.get<ParkedSale[]>("/v1/parked-sales"),
    // Normaliza acá — una sola vez por respuesta, no en cada componente que
    // consuma el hook. Un dato faltante o corrupto en una fila nunca debe
    // tumbar la lista entera (ni cualquier pantalla que la use después).
    select: (sales) =>
      sales.map((s) => ({ ...s, data: normalizeParkedSaleData(s.data) })),
    staleTime: 30 * 1000,
    enabled: opts.enabled ?? true,
  })
}

/** Guarda la venta en curso. */
export function useSaveParkedSale() {
  const qc = useQueryClient()
  return useMutation<ParkedSale, Error, { data: ParkedSaleData }>({
    mutationFn: (payload) =>
      api.post<ParkedSale>("/v1/parked-sales", payload as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: PARKED_SALES_KEY })
    },
  })
}

/** Elimina una venta guardada por id. */
export function useDeleteParkedSale() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/parked-sales?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: PARKED_SALES_KEY })
    },
  })
}

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
    }))

  const customer =
    d.customer && typeof d.customer === "object" && typeof (d.customer as { name?: unknown }).name === "string"
      ? (d.customer as PosCustomer)
      : null

  return {
    cart,
    customer,
    notes: typeof d.notes === "string" ? d.notes : null,
    title: typeof d.title === "string" ? d.title : null,
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

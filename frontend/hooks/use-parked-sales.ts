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
//
// OJO: el map de abajo es una WHITELIST, no un spread — todo campo de
// `CartLine` que NO esté enumerado se descarta en silencio al retomar, y TS no
// avisa (sobran propiedades, no faltan). Campo nuevo en `CartLine` ⇒ rama nueva
// acá. Los cinco que faltaban (`basePrice`, `priceOverridden`, `taxId`,
// `taxIncluded`, `voucher`) tocaban plata del carrito; ver los comentarios en
// cada rama.
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

/**
 * Metadata de CANJE de vale de una línea aparcada (context/36). Se exige que
 * `voucherId` Y `code` sean strings no vacíos: una línea con `voucher`
 * incompleto no se puede quitar con `removeVoucher()` (busca por voucherId),
 * así que preferimos degradarla a línea normal antes que dejar una cobertura
 * imposible de deshacer.
 */
function normalizeVoucher(raw: unknown): CartLine["voucher"] {
  if (!raw || typeof raw !== "object") return undefined
  const v = raw as Record<string, unknown>
  if (typeof v.voucherId !== "string" || v.voucherId === "") return undefined
  if (typeof v.code !== "string" || v.code === "") return undefined
  return { voucherId: v.voucherId, code: v.code }
}

/**
 * Campo tri-estado `T | null | undefined` de la línea: se preserva el `null`
 * explícito (que en `taxId`/`taxIncluded` significa "sin impuesto" / "sin
 * override", no "dato faltante"). Cualquier otro tipo cae a `undefined`.
 */
function normalizeNullable<T>(raw: unknown, is: (x: unknown) => x is T): T | null | undefined {
  if (raw === null) return null
  return is(raw) ? raw : undefined
}

const isString = (x: unknown): x is string => typeof x === "string"
const isBoolean = (x: unknown): x is boolean => typeof x === "boolean"

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
      // Precio PELADO de catálogo (sin recargos de add-ons). Perderlo no era
      // cosmético: `applyResolvedPrices` re-fija la base desde `unitPrice`
      // cuando la línea llega sin ella, así que una venta retomada tomaba como
      // base un precio YA resuelto y cada ciclo de `usePriceContext` le volvía
      // a aplicar el ajuste de la lista (mismo bucle de realimentación que
      // documenta store.ts:1076 — 60.000 caían a ~168).
      basePrice:
        typeof l.basePrice === "number" && Number.isFinite(l.basePrice) ? l.basePrice : undefined,
      // Override manual del cajero. Sin este flag, la resolución automática de
      // precios pisa un precio que el cajero fijó a mano.
      priceOverridden: typeof l.priceOverridden === "boolean" ? l.priceOverridden : undefined,
      note: typeof l.note === "string" ? l.note : undefined,
      sellerId: typeof l.sellerId === "string" ? l.sellerId : undefined,
      discount: typeof l.discount === "number" ? l.discount : undefined,
      tags: Array.isArray(l.tags) ? (l.tags as string[]) : undefined,
      giftcard: (l.giftcard && typeof l.giftcard === "object" ? l.giftcard : undefined) as CartLine["giftcard"],
      // Canje de vale (context/36). Perderlo convertía la línea cubierta por
      // el vale en una línea normal: `lineSubtotal` devuelve 0 SOLO si hay
      // `voucher`, así que retomar cobraba de nuevo algo ya pagado al emitir
      // el vale, y la línea entraba en el prorrateo del descuento de venta.
      voucher: normalizeVoucher(l.voucher),
      // Impuesto congelado al agregar (F2b, context/38). `selectCartIva` busca
      // la tasa por `taxId`; sin él trata la línea como EXENTA — retomar
      // cambiaba el IVA informativo del ticket. El `null` explícito se
      // preserva: es "sin impuesto", distinto de "el dato no vino".
      taxId: normalizeNullable(l.taxId, isString),
      taxIncluded: normalizeNullable(l.taxIncluded, isBoolean),
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

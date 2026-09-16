/**
 * `GET /v1/sales?uid=` — ¿quedó registrada la venta con este uid?
 *
 * Transporte de `SaleLookup` (ver `pending-charges.ts`). Va por `posApi`, o sea
 * con el Bearer del device y sin cookies: el endpoint es token-only y responde
 * acotado a la empresa y la sucursal del device.
 *
 * Contrato que el llamador necesita y que se defiende acá: SOLO un 404 significa
 * "no existe". Todo lo demás —red, timeout, 5xx, 401— se propaga como error,
 * porque tratar "no pude preguntar" como "no existe" es exactamente lo que
 * dispara un segundo cobro.
 */

import { ApiError } from '@/lib/api-client'
import { posApi } from '@/lib/api/pos-client'
import type { RegisteredSale, SaleLookup } from '@/lib/pos/pending-charges'

/** Corte propio: una consulta colgada no puede dejar el cobro esperando. */
const LOOKUP_TIMEOUT_MS = 10_000

interface RawRegisteredSale {
  transactionId: string
  uid: string
  invoiceNo: number | null
  invoicePrefix: string | null
  invoiceSerie: string | null
  total: number | null
  einvoicePortalUrl: string | null
}

/** Normaliza la venta que devuelve el backend (`ExistingSale::toApiPayload()`). */
export function toRegisteredSale(raw: RawRegisteredSale): RegisteredSale {
  return {
    transactionId: String(raw.transactionId),
    uid: String(raw.uid),
    invoiceNo: raw.invoiceNo ?? null,
    invoicePrefix: raw.invoicePrefix ?? null,
    invoiceSerie: raw.invoiceSerie ?? null,
    total: raw.total ?? null,
    einvoicePortalUrl: raw.einvoicePortalUrl ?? null,
  }
}

/**
 * 404 DEL BACKEND (envelope `{ ok: false }`), no cualquier 404: una página de
 * error de un proxy o una ruta mal armada también es 404, y leerla como "la
 * venta no existe" habilitaría un segundo cobro.
 */
function isApiNotFound(err: unknown): boolean {
  if (!(err instanceof ApiError) || err.status !== 404) return false
  const payload = err.payload as { ok?: unknown } | null
  return typeof payload === 'object' && payload !== null && payload.ok === false
}

export const lookupSaleByUid: SaleLookup = async (uid) => {
  const timeout = new Promise<never>((_, reject) =>
    setTimeout(() => reject(new Error('lookup timeout')), LOOKUP_TIMEOUT_MS),
  )
  try {
    const res = await Promise.race([
      posApi.get<{ sale: RawRegisteredSale }>(`/v1/sales?uid=${encodeURIComponent(uid)}`),
      timeout,
    ])
    if (!res?.sale?.transactionId) {
      throw new Error('respuesta sin venta')
    }
    return toRegisteredSale(res.sale)
  } catch (err) {
    if (isApiNotFound(err)) return null
    throw err
  }
}

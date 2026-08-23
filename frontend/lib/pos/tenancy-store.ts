/**
 * Veredicto de tenencia de caja, en memoria — para que el gate del cobro sea
 * SÍNCRONO.
 *
 * `PayDialog` tiene que decidir si deja emitir en el mismo tick en que el
 * cajero aprieta cobrar, antes de consumir el número de comprobante. Leer
 * IndexedDB ahí sería async: habría un frame en el que el diálogo ya avanzó y
 * el número ya se quemó. Por eso el grant se hidrata una vez al montar el
 * workspace (`register-tenancy.ts` → `hydrateTenancy`) y vive acá.
 *
 * Zustand y no react-query: esto NO es una query cacheada, es el estado
 * derivado de un dato persistido en el device — se lee sin red, sobrevive al
 * corte, y se refresca por latido/realtime, no por invalidación de cache.
 *
 * `verdict === null` significa "todavía no se hidrató", distinto de "no tiene
 * tenencia" (`kind: 'never'`). Los call-sites tratan `null` como "no emitir
 * todavía" igual, pero el mensaje al cajero no puede ser el mismo.
 */

import { create } from 'zustand'
import type { TenancyVerdict } from '@/lib/pos/register-tenancy'

interface TenancyState {
  verdict: TenancyVerdict | null
  /** Hay un claim en vuelo — para que el botón de reintento no dispare dos. */
  refreshing: boolean
  setVerdict: (v: TenancyVerdict) => void
  setRefreshing: (v: boolean) => void
}

export const useTenancyStore = create<TenancyState>((set) => ({
  verdict: null,
  refreshing: false,
  setVerdict: (verdict) => set({ verdict }),
  setRefreshing: (refreshing) => set({ refreshing }),
}))

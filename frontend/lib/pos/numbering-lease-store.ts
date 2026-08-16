/**
 * Store reactivo del arriendo de numeración (`numbering-lease.ts`).
 *
 * El lease en sí vive en `localStorage` (no reactivo — mismo criterio que
 * `sync-watermarks.ts`, sobrevive el cierre del browser). Este store solo
 * espeja el REMANENTE calculado para que un componente React (`OfflineBanner`)
 * pueda reaccionar sin pollear `localStorage`.
 *
 * Escalado por el owner 2026-08-16 ("no puede salir una venta sin número de
 * factura"): antes de esto, quedarse sin números era un caso mudo — el
 * cajero se enteraba recién cuando `getNextInvoiceNo()` explotaba a mitad de
 * una venta. El banner necesita ver el remanente ANTES de llegar a cero.
 */

import { create } from 'zustand'

interface NumberingLeaseState {
  /**
   * Comprobantes que quedan en el lease vigente. `null` = todavía no se
   * calculó (arranque, antes de `primeLeaseStatus()`) — se trata como "no
   * mostrar nada", no como 0 (0 SÍ es agotado de verdad).
   */
  remaining: number | null
  setRemaining: (remaining: number) => void
}

export const useNumberingLeaseStore = create<NumberingLeaseState>()((set) => ({
  remaining: null,
  setRemaining: (remaining) => set({ remaining }),
}))

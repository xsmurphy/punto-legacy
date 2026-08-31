/**
 * Interruptores de DIAGNÓSTICO de la caja — herramientas que no cambian cómo
 * opera el POS, solo qué se puede medir sobre él.
 *
 * ── Por qué no van a la config de la caja ──────────────────────────────────
 * Los ajustes de `AjustesPanel` viven en `posConfig` del REGISTER: son del
 * comercio, se sincronizan al servidor y valen para cualquier dispositivo que
 * abra esa caja. Un diagnóstico es lo contrario: se prende en EL teléfono que
 * muestra el síntoma, para sacarle una captura, y no tiene por qué aparecerle
 * al resto de las cajas ni quedar guardado en el negocio. Además tiene que
 * poder prenderse offline y sin esperar un round-trip — el bug que se está
 * persiguiendo puede ser justamente el que impide operar.
 *
 * Por eso es local del dispositivo, con el mismo patrón que
 * `lib/pos/workspace-store.ts`: `persist` sobre localStorage. La persistencia
 * NO es un detalle — el POS se recarga y se bloquea sola, y una sonda que se
 * apaga en cada reload no sirve para observar el arranque.
 */

import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"

interface PosDebugState {
  /**
   * Sonda de viewport (`components/pos/viewport-probe.tsx`).
   *
   * Existe además del `?debug=viewport` de siempre, no en su lugar: la caja se
   * usa como PWA INSTALADA, sin barra de direcciones, así que el query param
   * es inalcanzable justo en el modo donde aparecen los bugs de viewport y de
   * teclado. Se suman — el param sigue sirviendo en el browser.
   */
  viewportProbe: boolean
  setViewportProbe: (v: boolean) => void
}

export const usePosDebugStore = create<PosDebugState>()(
  persist(
    (set) => ({
      viewportProbe: false,
      setViewportProbe: (v) => set({ viewportProbe: v }),
    }),
    {
      name: "pos-debug",
      storage: createJSONStorage(() => localStorage),
      partialize: (s) => ({ viewportProbe: s.viewportProbe }),
    },
  ),
)

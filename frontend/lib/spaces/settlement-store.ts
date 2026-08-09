"use client"

/**
 * Estado del flujo "cobrar un espacio" (context/15-espacios-module-plan.md
 * §F3) que tiene que sobrevivir a la navegación entre módulos del POS —
 * bug T8: `handleSplitCharge` cargaba el carrito y dependía de que
 * `/pos/espacios` siguiera montado para la reconciliación post-cobro; en
 * mobile/tablet el módulo se pinta como Dialog fullscreen ENCIMA del
 * CartPanel (`app/(pos)/pos/layout.tsx`), tapando el botón "Cliente".
 *
 * Por qué un store aparte (ni `lib/cart/store.ts` ni `lib/ui/store.ts`):
 * - NO en el cart store: `clearCart()` (pay-dialog.tsx `handleClose`) resetea
 *   `settlementIntent` a `null` ANTES de que el PayDialog dispare
 *   `onOpenChange(false)`. Si `settlingSpace` viviera ahí, ya estaría en
 *   null cuando el efecto de reconciliación necesita leerlo.
 * - NO en `usePosUIStore`: ese store son toggles de diálogos (open/close);
 *   esto es estado de DOMINIO — identifica una mesa+sesión en cobro, no un
 *   booleano de visibilidad.
 *
 * Ambos campos solo necesitan `sessionId` + `spaceName`: ni
 * `SplitBillDialog` ni el armado del carrito (`handleSplitCharge`, ahora en
 * `components/spaces/space-settlement-provider.tsx`) usan otro campo de
 * `SpaceWithState` — confirmado antes de este refactor.
 */

import { create } from "zustand"

export interface SpaceSplitTarget {
  sessionId: string
  spaceName: string
}

interface SpaceSettlementState {
  /**
   * Mesa con el diálogo de split (`SplitBillDialog`) abierto. Dos orígenes,
   * mismo campo: "Cobrar" en `espacios/page.tsx` (elección inicial de modo)
   * y la reconciliación post-cobro parcial, que lo reabre con el saldo
   * nuevo — puede disparar en cualquier ruta del POS, no solo en Espacios.
   */
  splitTarget: SpaceSplitTarget | null
  setSplitTarget: (target: SpaceSplitTarget | null) => void
  /**
   * Mesa con un cobro PARCIAL en curso en el PayDialog. Seteada al armar el
   * carrito (`loadForSettlement`), leída por el efecto de reconciliación
   * cuando el PayDialog se cierra.
   */
  settlingSpace: SpaceSplitTarget | null
  setSettlingSpace: (target: SpaceSplitTarget | null) => void
}

export const useSpaceSettlementStore = create<SpaceSettlementState>()((set) => ({
  splitTarget: null,
  setSplitTarget: (target) => set({ splitTarget: target }),
  settlingSpace: null,
  setSettlingSpace: (target) => set({ settlingSpace: target }),
}))

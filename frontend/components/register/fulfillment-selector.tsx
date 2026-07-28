"use client"

/**
 * Selector de fulfillment del carrito — MOSTRADOR / RETIRO / ENVÍO
 * (context/27-delivery-sla-plan.md §B.4/§D.4). "Mostrador" y no "En el
 * local": es el mismo vocabulario que usa la comanda (KDS, despacho,
 * /pos/ordenes, ticket impreso) para este destino — dos nombres para lo
 * mismo era exactamente la confusión que había que sacar de encima.
 *
 * Son los MISMOS chips que CRÉDITO/INTERNO/IVA/VACIAR (`ToggleChip`): misma
 * fila, mismo lugar, misma forma — el cajero ya los reconoce. Lo único
 * distinto es que acá son excluyentes (siempre hay exactamente uno activo), y
 * eso se resuelve en el handler, no con un componente aparte. Un segmented
 * control con íconos y alto propio sería una regla de diseño NUEVA, y el
 * design system existente manda (context/14-ui-conventions.md).
 *
 * Solo visible en `cartMode==="orden-mostrador"` (ver CartBottom en
 * cart-panel.tsx) — en `orden-espacio` el fulfillment es `dine_in` por
 * construcción, no hay nada que elegir.
 *
 * "Envío" no cambia el estado acá directamente: el caller (`cart-panel.tsx`)
 * intercepta el intento de elegir "delivery" para abrir primero
 * CustomerDialog (si falta cliente) y después DeliveryAddressDialog — recién
 * al confirmar esos flujos se llama `setFulfillment("delivery")` +
 * `setDeliveryAddress`. Por eso este componente expone `onSelect` en vez de
 * pegarle directo a `useCartStore.setFulfillment`.
 */

import { ToggleChip } from "@/components/register/toggle-chip"
import type { Fulfillment } from "@/hooks/use-orders"

const OPTIONS: Array<{ value: Fulfillment; label: string }> = [
  { value: "dine_in", label: "MOSTRADOR" },
  { value: "takeaway", label: "RETIRO" },
  { value: "delivery", label: "ENVÍO" },
]

export function FulfillmentSelector({
  value,
  onSelect,
}: {
  value: Fulfillment
  onSelect: (f: Fulfillment) => void
}) {
  return (
    <>
      {OPTIONS.map((opt) => (
        <ToggleChip
          key={opt.value}
          label={opt.label}
          active={value === opt.value}
          onClick={() => onSelect(opt.value)}
        />
      ))}
    </>
  )
}

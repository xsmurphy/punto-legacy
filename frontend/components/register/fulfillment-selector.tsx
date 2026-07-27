"use client"

/**
 * Selector de fulfillment del carrito — "En el local" / "Retiro" / "Envío"
 * (context/27-delivery-sla-plan.md §B.4/§D.4).
 *
 * Segmented control de 3 opciones EXCLUYENTES (ToggleGroup type="single",
 * shadcn — Regla #2/#6 de context/14-ui-conventions.md: sin `<button>`
 * nativo, íconos lucide). Solo visible en `cartMode==="orden-mostrador"`
 * (ver CartBottom en cart-panel.tsx) — en `orden-espacio` el fulfillment es
 * `dine_in` por construcción, no hay nada que elegir.
 *
 * "Envío" no cambia el estado acá directamente: el caller (`cart-panel.tsx`)
 * intercepta el intento de elegir "delivery" para abrir primero
 * CustomerDialog (si falta cliente) y después DeliveryAddressDialog — recién
 * al confirmar esos flujos se llama `setFulfillment("delivery")` +
 * `setDeliveryAddress`. Por eso este componente expone `onSelect` en vez de
 * pegarle directo a `useCartStore.setFulfillment`.
 */

import { Store, ShoppingBag, Bike } from "lucide-react"
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group"
import type { Fulfillment } from "@/hooks/use-orders"

const OPTIONS: Array<{ value: Fulfillment; label: string; icon: typeof Store }> = [
  { value: "dine_in", label: "En el local", icon: Store },
  { value: "takeaway", label: "Retiro", icon: ShoppingBag },
  { value: "delivery", label: "Envío", icon: Bike },
]

export function FulfillmentSelector({
  value,
  onSelect,
}: {
  value: Fulfillment
  onSelect: (f: Fulfillment) => void
}) {
  return (
    <ToggleGroup
      type="single"
      variant="outline"
      value={value}
      onValueChange={(next) => {
        // type="single" de radix permite des-seleccionar (value=""); acá no
        // tiene sentido — siempre hay un fulfillment activo — así que se
        // ignora ese caso en vez de dejar el carrito sin valor.
        if (next) onSelect(next as Fulfillment)
      }}
      className="w-full"
    >
      {OPTIONS.map((opt) => (
        <ToggleGroupItem
          key={opt.value}
          value={opt.value}
          aria-label={opt.label}
          // h-10 mínimo: POS touch-first, tablet — se toca con el dedo del
          // cajero (Regla #2 de context/14-ui-conventions.md).
          className="h-10 flex-1 gap-1.5 text-xs font-medium"
        >
          <opt.icon className="size-4" aria-hidden />
          <span className="truncate">{opt.label}</span>
        </ToggleGroupItem>
      ))}
    </ToggleGroup>
  )
}

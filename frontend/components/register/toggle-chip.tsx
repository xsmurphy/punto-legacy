"use client"

/**
 * Chip de la fila de atributos del carrito — CRÉDITO / INTERNO / IVA / VACIAR
 * en modo venta, MOSTRADOR / RETIRO / ENVÍO en modo orden.
 *
 * Vivía privado dentro de `cart-panel.tsx`; se extrajo cuando el selector de
 * fulfillment necesitó exactamente el mismo chip. Es el ÚNICO estilo válido
 * para esa fila: pill `rounded-full`, borde, `text-[10px] font-bold
 * tracking-wide`, activo en `brand`. No inventar variantes (segmented
 * controls, íconos, alturas propias) — la fila es un patrón cerrado del POS y
 * el cajero la reconoce por forma, no por lectura.
 */

import { cn } from "@/lib/utils"

export function ToggleChip({
  label,
  active,
  onClick,
}: {
  label: string
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      onClick={onClick}
      className={cn(
        "rounded-full border px-2.5 py-0.5 text-[10px] font-bold tracking-wide transition-colors",
        active
          ? "border-brand bg-brand/20 text-brand"
          : "border-border bg-transparent text-muted-foreground hover:border-muted-foreground",
      )}
    >
      {label}
    </button>
  )
}

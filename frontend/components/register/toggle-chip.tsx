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

/**
 * Forma y tamaño del chip — la ÚNICA definición.
 *
 * La exporta para los dos chips de la misma fila que no son toggles y por eso
 * no pasan por `<ToggleChip>`: el de IVA (muestra un monto, no un estado
 * on/off) y VACIAR (acción destructiva). Antes cada uno repetía la cadena de
 * clases a mano, así que agrandarlos en móvil habría sido tres ediciones que
 * se desincronizan a la primera.
 *
 * Móvil primero: el owner pidió los pills "un poco más grandes" en el teléfono
 * (2026-08-25) — `text-xs` con más padding, que además acerca el área táctil a
 * lo que la caja necesita. Desde `sm` vuelve al tamaño original: en desktop y
 * tablet la fila convive con el resto del carrito y no se toca con el dedo.
 */
export const CHIP_BASE =
  "rounded-full border px-3 py-1 text-xs font-bold tracking-wide transition-colors sm:px-2.5 sm:py-0.5 sm:text-[10px]"

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
        CHIP_BASE,
        active
          ? "border-brand bg-brand/20 text-brand"
          : "border-border bg-transparent text-muted-foreground hover:border-muted-foreground",
      )}
    >
      {label}
    </button>
  )
}

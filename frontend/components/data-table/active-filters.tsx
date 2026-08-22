"use client"

import { X } from "lucide-react"

import { Badge } from "@/components/ui/badge"

export interface ActiveFilterItem {
  /** Key estable para el chip (no se muestra). */
  key: string
  /** Nombre del filtro (ej. "Método de pago"). */
  label: string
  /** Valor elegido (ej. "Efectivo"). */
  value: string
  onRemove: () => void
}

/**
 * Chips de filtros activos para usar debajo del toolbar de un <DataTable>.
 * Reusable entre listados — no asume qué filtros existen, solo los muestra.
 */
export function ActiveFilters({ items }: { items: ActiveFilterItem[] }) {
  if (items.length === 0) return null

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {items.map((item) => (
        <Badge key={item.key} variant="secondary" className="flex items-center gap-1 pr-1">
          <span className="text-muted-foreground">{item.label}:</span> {item.value}
          <button
            type="button"
            onClick={item.onRemove}
            aria-label={`Quitar filtro: ${item.label}`}
            className="ml-0.5 flex size-3.5 items-center justify-center rounded-full hover:bg-muted-foreground/20"
          >
            <X className="size-3.5" />
          </button>
        </Badge>
      ))}
    </div>
  )
}

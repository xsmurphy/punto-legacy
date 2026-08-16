"use client"

import * as React from "react"
import { Skeleton } from "@/components/ui/skeleton"

/**
 * Tile chico de KPI (label + valor) — usado en varias secciones de la ficha
 * de contacto (tab Financiero, estado de cuenta) y en el detalle por
 * contacto del reporte de cuentas por cobrar/pagar. Extraído de
 * `contact-detail-view.tsx` para no duplicarlo (era local a ese archivo).
 *
 * `value === null` pinta un skeleton — convención de esta pantalla para
 * "cargando" sin desplazar layout.
 */
export function KpiCard({
  label, value,
}: {
  label: string; value: React.ReactNode
}) {
  return (
    <div className="flex flex-col gap-1 rounded-lg border bg-card px-3 py-2.5">
      <div className="text-[11px] uppercase tracking-wide text-muted-foreground">
        {label}
      </div>
      {value === null ? <Skeleton className="h-5 w-20" /> : (
        <span className="text-base font-semibold tabular-nums leading-tight">{value}</span>
      )}
    </div>
  )
}

import * as React from "react"

import { splitShares } from "@/lib/charts/split"
import { cn } from "@/lib/utils"

/**
 * SplitBar — una barra horizontal partida en dos + las dos filas debajo.
 *
 * Reemplaza al donut de dos porciones: la misma proporción en una línea, y el
 * dato (label, monto, %) en filas que se leen sin pasar el mouse. Colores del
 * chart (`--chart-1` / `--chart-3`), nunca hex. Los montos llegan YA
 * formateados (`display`) con los helpers del tenant; el componente solo sabe
 * de proporciones (`lib/charts/split.ts`).
 *
 * Solo tiene sentido con las DOS partes en > 0 — esa regla la aplica quien lo
 * monta (ej. `showSplitBar` del dashboard).
 */
export interface SplitPart {
  label: string
  /** Magnitud cruda. */
  value: number
  /** Valor formateado que se lee. */
  display: React.ReactNode
}

const COLORS = ["bg-[var(--chart-1)]", "bg-[var(--chart-3)]"] as const

export function SplitBar({
  parts,
  className,
}: {
  parts: [SplitPart, SplitPart]
  className?: string
}) {
  const { widths, percents } = splitShares(parts[0].value, parts[1].value)
  return (
    <div className={cn("flex flex-col gap-3", className)}>
      <div
        role="img"
        aria-label={parts.map((p, i) => `${p.label} ${percents[i]}%`).join(", ")}
        className="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full"
      >
        {parts.map((p, i) =>
          widths[i] > 0 ? (
            <div
              key={p.label}
              className={cn("h-full first:rounded-l-full last:rounded-r-full", COLORS[i])}
              style={{ flexGrow: widths[i], flexBasis: 0, minWidth: 4 }}
            />
          ) : null,
        )}
      </div>
      <ul className="flex flex-col divide-y divide-border/60 text-sm">
        {parts.map((p, i) => (
          <li key={p.label} className="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
            <span className="flex min-w-0 items-center gap-2 text-muted-foreground">
              <span aria-hidden className={cn("size-2 shrink-0 rounded-full", COLORS[i])} />
              <span className="truncate">{p.label}</span>
            </span>
            <span className="flex shrink-0 items-baseline gap-2 tabular-nums">
              <span className="font-medium">{p.display}</span>
              <span className="w-9 text-right text-xs text-muted-foreground">{percents[i]}%</span>
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}

import * as React from "react"

import { Card, CardContent } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { cn } from "@/lib/utils"

/**
 * StatsRow + StatTile — KPI compacto canónico para reportes.
 *
 * Reemplaza los divs sueltos (label + número sin card) que cada página de
 * reporte armaba a mano. Ver context/20-design-system.md — entrada
 * 2026-07-31.
 *
 * Uso:
 *   <StatsRow>
 *     <StatTile label="Ingresos" value={formatMoney(x, bootstrap)} tone="positive" />
 *     <StatTile label="Neto" value={formatMoney(y, bootstrap)} tone={y >= 0 ? "positive" : "negative"} emphasis />
 *   </StatsRow>
 *
 * `formatMoney` ya antepone `bootstrap.currency` — nunca volver a prefijar
 * `${bootstrap?.currency ?? ""}` antes de pasarlo a `value`.
 */

function StatsRow({
  children,
  className,
}: {
  children: React.ReactNode
  className?: string
}) {
  return (
    <div className={cn("flex flex-col gap-3 md:flex-row", className)}>
      {children}
    </div>
  )
}

function StatTile({
  icon,
  label,
  value,
  tone = "neutral",
  emphasis,
  isLoading,
  className,
}: {
  icon?: React.ReactNode
  label: string
  value: React.ReactNode
  /** Acento semántico — mismas clases que ya usa el resto del panel (positivo/negativo). */
  tone?: "positive" | "negative" | "neutral"
  /** Resalta el tile "hero" de la fila (ej. Neto / Total). */
  emphasis?: boolean
  isLoading?: boolean
  className?: string
}) {
  return (
    <Card variant="soft" size="sm" className={cn("flex-1", className)}>
      <CardContent className="flex flex-col gap-1">
        <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          {icon}
          <span className="truncate">{label}</span>
        </span>
        {isLoading ? (
          <Skeleton className="h-6 w-24" />
        ) : (
          <span
            className={cn(
              "tabular-nums",
              emphasis ? "text-xl font-bold" : "text-lg font-semibold",
              tone === "positive" && "text-emerald-600",
              tone === "negative" && "text-destructive",
            )}
          >
            {value}
          </span>
        )}
      </CardContent>
    </Card>
  )
}

export { StatsRow, StatTile }

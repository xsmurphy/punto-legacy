import * as React from "react"

import { Card, CardContent } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { cn } from "@/lib/utils"

/**
 * StatsRow + StatTile — KPI compacto canónico del panel.
 *
 * Reemplaza los divs sueltos (label + número sin card) que cada página
 * armaba a mano. Ver context/20-design-system.md — entradas 2026-07-31 y
 * 2026-09-09.
 *
 * Vive en `components/` y no en `components/domain/reports/`: el KPI es del
 * panel entero, no de reportes — ya lo usan el menú del POS, `/admin` y
 * `/finanzas`. El fondo gris (`Card variant="soft"`) es lo que separa el
 * bloque de resumen del contenido: gris = números del período, blanco =
 * tablas, charts y entidades.
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

/**
 * Comparativa contra el período anterior.
 *
 * `null` = no se puede calcular (el período anterior fue cero y este no: el
 * porcentaje sería infinito). Se dice, no se esconde ni se inventa un 0.
 *
 * `higherIsBetter` decide el color. Para devoluciones o gastos, subir es malo.
 */
export interface StatDelta {
  pct: number | null
  higherIsBetter?: boolean
}

function DeltaLine({ pct, higherIsBetter = true }: StatDelta) {
  if (pct === null) {
    return (
      <span className="text-xs text-muted-foreground">
        Sin base para comparar
      </span>
    )
  }
  // Cero es SIN CAMBIOS, y eso no es ni bueno ni malo. Antes caía del lado de
  // "subió" (`0 >= 0`) y un período idéntico al anterior se pintaba de rojo en
  // cualquier métrica donde bajar es lo deseable.
  const color =
    pct === 0
      ? "text-muted-foreground"
      : pct > 0 === higherIsBetter
        ? "text-emerald-600"
        : "text-destructive"
  const sign = pct > 0 ? "+" : ""
  return (
    <span className={cn("text-xs tabular-nums", color)}>
      {pct === 0 ? "Sin cambios" : `${sign}${pct.toFixed(1)}%`} vs período anterior
    </span>
  )
}

function StatTile({
  icon,
  label,
  value,
  tone = "neutral",
  emphasis,
  delta,
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
  /**
   * Comparativa contra el período anterior, cuando la pantalla la tiene.
   *
   * Vive acá y no en un componente por página: `/reports/summary` tenía su
   * propio KPI con delta y por eso sus cards se veían distintas a las del
   * resto de los reportes (reportado por el owner 2026-09-10). Que el tile
   * canónico sepa mostrarlo es lo que permite que cualquier reporte lo sume
   * sin volver a inventar la card.
   */
  delta?: StatDelta
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
        {delta &&
          (isLoading ? (
            <Skeleton className="h-3 w-28" />
          ) : (
            <DeltaLine pct={delta.pct} higherIsBetter={delta.higherIsBetter} />
          ))}
      </CardContent>
    </Card>
  )
}

export { StatsRow, StatTile }

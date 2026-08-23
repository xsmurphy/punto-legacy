"use client"

/**
 * Semáforo de cuadre del arqueo — verde cuadra, rojo falta, amarillo sobra.
 *
 * Vive en un componente compartido porque lo pintan el listado de Control de
 * Cajas y el detalle de una caja. Un cierre que en la tabla figura "Cuadra" y
 * al abrirlo dice "Faltante" es peor que no tener semáforo, y esa divergencia
 * aparece sola en cuanto dos archivos deciden el color por su cuenta. El
 * VEREDICTO igual no se calcula acá: viene resuelto del backend
 * (`Reports\CashCountStatus`), que es el único que conoce la tolerancia del
 * comercio. Este componente solo elige cómo se ve.
 *
 * El color NO es el único canal (accesibilidad / daltonismo): cada estado
 * lleva SIEMPRE su palabra ("Cuadra" / "Faltante" / "Sobrante") y un ícono de
 * forma distinta (check / flecha abajo / flecha arriba). Apagando el color, la
 * columna se sigue leyendo.
 */

import * as React from "react"
import { ArrowDown, ArrowUp, Check, Minus } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { formatMoney } from "@/lib/format"
import { cn } from "@/lib/utils"

/** Veredicto del backend (`Reports\CashCountStatus`). */
export type CashStatus = "ok" | "short" | "over" | "unknown"

/** De dónde salió el monto esperado (mig 164). */
export type ExpectedSource = "frozen" | "estimated" | "live" | null

export interface CashCountBadgeProps {
  status: CashStatus
  /** `closeAmount − expectedAmount`. Negativo = falta. */
  difference: number | null
  expectedSource: ExpectedSource
  /** Margen con el que el backend clasificó — se explica en el tooltip. */
  tolerance?: number
  bootstrap: Parameters<typeof formatMoney>[1]
  /** El detalle tiene lugar para la frase completa; la tabla no. */
  size?: "sm" | "md"
}

/**
 * Tonos del semáforo.
 *
 * `destructive` es token. El verde y el ámbar NO tienen token semántico en el
 * design system: son las dos excepciones que `context/20` §3 ya documenta
 * (`text-emerald-600` para estado positivo, ámbar para alerta), y acá además
 * el owner pidió explícitamente los tres colores por nombre — verde cuadra,
 * rojo falta, amarillo sobra. Sin hex sueltos: clases de la escala de Tailwind.
 */
const TONE: Record<CashStatus, string> = {
  ok:      "text-emerald-600 border-emerald-600/30",
  short:   "text-destructive border-destructive/40",
  over:    "text-amber-600 border-amber-600/40",
  unknown: "text-muted-foreground border-border",
}

const ICON: Record<CashStatus, React.ComponentType<{ className?: string }>> = {
  ok:      Check,
  short:   ArrowDown,
  over:    ArrowUp,
  unknown: Minus,
}

const LABEL: Record<CashStatus, string> = {
  ok:      "Cuadra",
  short:   "Faltante",
  over:    "Sobrante",
  unknown: "Sin cierre",
}

export function CashCountBadge({
  status,
  difference,
  expectedSource,
  tolerance,
  bootstrap,
  size = "sm",
}: CashCountBadgeProps) {
  const Icon = ICON[status]
  const estimated = expectedSource === "estimated"

  // El monto solo acompaña a faltante/sobrante: en "Cuadra" la diferencia es
  // ruido de redondeo y mostrarla invita a discutir 1 guaraní.
  const amount =
    (status === "short" || status === "over") && difference !== null
      ? formatMoney(Math.abs(difference), bootstrap)
      : null

  const badge = (
    <Badge
      variant="outline"
      className={cn(
        "gap-1 font-medium tabular-nums",
        TONE[status],
        // `border-dashed` marca el estimado también sin color ni tooltip.
        estimated && "border-dashed",
        size === "md" && "text-sm",
      )}
    >
      <Icon className="size-3.5" />
      {LABEL[status]}
      {amount && <span>{amount}</span>}
      {estimated && <span className="font-normal opacity-70">estimado</span>}
    </Badge>
  )

  const hint = tooltipText(status, estimated, tolerance, bootstrap)
  if (!hint) return badge

  // `TooltipProvider` local: el `ui/tooltip.tsx` de este repo NO se
  // auto-envuelve (la versión de shadcn que se copió deja el Provider a cargo
  // del call-site) y no hay uno global en el layout. Sin esto, Radix tira en
  // runtime la primera vez que se pinta la columna.
  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex">{badge}</span>
        </TooltipTrigger>
        <TooltipContent className="max-w-xs">{hint}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

function tooltipText(
  status: CashStatus,
  estimated: boolean,
  tolerance: number | undefined,
  bootstrap: Parameters<typeof formatMoney>[1],
): string | null {
  if (estimated) {
    // Honestidad sobre el dato: este cierre es anterior a que se empezara a
    // guardar el esperado, así que el número se recalculó ahora y el veredicto
    // no es algo que haya quedado registrado ese día.
    return (
      "Cierre anterior al registro del monto esperado. El esperado se recalculó " +
      "con los movimientos del turno, así que el resultado es una estimación y no " +
      "lo que se arqueó ese día."
    )
  }
  if (status === "ok" && tolerance !== undefined && tolerance > 0) {
    return `Cuadra dentro de la tolerancia del comercio (${formatMoney(tolerance, bootstrap)}).`
  }
  if (status === "unknown") {
    return "La caja sigue abierta: todavía no hay monto contado con qué comparar."
  }
  return null
}

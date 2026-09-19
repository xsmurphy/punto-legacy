import * as React from "react"
import Link from "next/link"
import { ChevronRight } from "lucide-react"

import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { DeltaLine, type StatDelta } from "@/components/stat-tile"
import { cn } from "@/lib/utils"

/**
 * Escala tipográfica de las cards de Tablero (`context/84` §8, owner
 * 2026-09-19). Cada card del dashboard se arma SOLO con estos primitives;
 * ninguna card pone un tamaño de texto propio:
 *
 *  - Título ............ `CardTitle` a secas (`TileCard`), header sin overrides.
 *  - Cifra destacada ... `TileFigure`: `text-2xl font-semibold tabular-nums`,
 *                        unidad al lado `text-sm text-muted-foreground`. UN
 *                        solo tamaño para Ganancia, "5 activas", "2 de 14"…
 *  - Fila .............. `TileRow`: label `text-sm text-muted-foreground`,
 *                        valor `text-sm font-medium` (`emphasis` = semibold).
 *                        Filas separadas por `TileRows` (`divide-y`).
 *  - Línea secundaria .. `TileNote`: `text-xs` (muted, o tono de estado).
 *                        Nunca más grande que el cuerpo.
 *  - Comparativa ....... `DeltaLine compact`, el pill compartido.
 *
 * `BigMetricCard` (Ingresos/Egresos) conserva su estilo propio por decisión
 * del owner y no pasa por acá.
 */

type Tone = "muted" | "destructive" | "positive"

const NOTE_TONE: Record<Tone, string> = {
  muted: "text-muted-foreground",
  destructive: "font-medium text-destructive",
  positive: "text-emerald-700 dark:text-emerald-400",
}

/** Card de Tablero: título canónico, acceso opcional y el contenido apilado. */
export function TileCard({
  title,
  href,
  linkLabel,
  description,
  variant = "soft",
  contentClassName,
  children,
}: {
  title: string
  href?: string
  /** Nombre accesible del acceso; por default "Ir a {title}". */
  linkLabel?: string
  description?: React.ReactNode
  /** `soft` (gris) = números; `default` (blanca) = rankings y gráficos. */
  variant?: "soft" | "default"
  /** Solo layout (flex, gap, items); nunca tipografía. */
  contentClassName?: string
  children: React.ReactNode
}) {
  return (
    <Card variant={variant}>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {description && <CardDescription className="tabular-nums">{description}</CardDescription>}
        {href && (
          <CardAction>
            <Link
              href={href}
              className="text-muted-foreground transition-colors hover:text-foreground"
              aria-label={linkLabel ?? `Ir a ${title}`}
            >
              <ChevronRight className="size-4" />
            </Link>
          </CardAction>
        )}
      </CardHeader>
      <CardContent className={cn("flex flex-col gap-4", contentClassName)}>{children}</CardContent>
    </Card>
  )
}

/**
 * La cifra destacada de una card, con su unidad al lado. La comparativa y la
 * nota van debajo, alineadas al borde izquierdo de la cifra.
 */
export function TileFigure({
  label,
  value,
  unit,
  delta,
  note,
  loading,
}: {
  label?: React.ReactNode
  value: React.ReactNode
  unit?: React.ReactNode
  delta?: StatDelta
  /** Una `TileNote` debajo de la cifra. */
  note?: React.ReactNode
  loading?: boolean
}) {
  return (
    <div className="flex flex-col items-start gap-1.5">
      {label && <span className="text-sm text-muted-foreground">{label}</span>}
      {loading ? (
        <Skeleton className="h-8 w-36" />
      ) : (
        <span className="flex items-baseline gap-2">
          <span className="text-2xl font-semibold tabular-nums">{value}</span>
          {unit && <span className="text-sm text-muted-foreground">{unit}</span>}
        </span>
      )}
      {delta && !loading && <DeltaLine {...delta} compact />}
      {note}
    </div>
  )
}

/** Contenedor de filas: divisores sutiles, sin caja propia. */
export function TileRows({ children, className }: { children: React.ReactNode; className?: string }) {
  return <div className={cn("flex flex-col divide-y divide-border", className)}>{children}</div>
}

/**
 * Fila label / valor. `value === null` pinta el skeleton del valor; sin
 * `value` la fila es solo el label (con su nota). Con `href` la fila entera
 * es el link, con la flecha al final.
 */
export function TileRow({
  label,
  note,
  value,
  delta,
  emphasis,
  tone,
  href,
}: {
  label: React.ReactNode
  /** Una `TileNote` debajo del label. */
  note?: React.ReactNode
  value?: React.ReactNode | null
  delta?: StatDelta
  emphasis?: boolean
  tone?: "destructive"
  href?: string
}) {
  const destructive = tone === "destructive"
  const body = (
    <>
      <span className="flex min-w-0 flex-col gap-0.5">
        <span
          className={cn(
            "min-w-0 text-sm",
            destructive ? "font-medium text-destructive" : "text-muted-foreground",
            href && !destructive && "transition-colors group-hover:text-foreground",
          )}
        >
          {label}
        </span>
        {note}
      </span>
      <span className="flex shrink-0 items-center gap-1">
        {value !== undefined &&
          (value === null ? (
            <Skeleton className="h-5 w-16" />
          ) : (
            <span className="flex flex-col items-end gap-1">
              <span
                className={cn(
                  "whitespace-nowrap text-sm tabular-nums",
                  emphasis ? "font-semibold" : "font-medium",
                  destructive && "text-destructive",
                )}
              >
                {value}
              </span>
              {delta && <DeltaLine {...delta} compact />}
            </span>
          ))}
        {href && <ChevronRight className="size-3.5 text-muted-foreground" />}
      </span>
    </>
  )
  const cls = "flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0"
  if (href) {
    return (
      <Link href={href} className={cn("group", cls)}>
        {body}
      </Link>
    )
  }
  return <div className={cls}>{body}</div>
}

/** Línea secundaria: estado, meta, "y 3 más". Siempre `text-xs`. */
export function TileNote({
  tone = "muted",
  children,
}: {
  tone?: Tone
  children: React.ReactNode
}) {
  return <span className={cn("text-xs tabular-nums", NOTE_TONE[tone])}>{children}</span>
}

/** Fila de tasa: label / porcentaje con la barra de progreso debajo. */
export function TileMeter({
  label,
  value,
  percent,
  barColor,
}: {
  label: React.ReactNode
  value: React.ReactNode
  /** 0-100, se recorta al rango. */
  percent: number
  barColor: string
}) {
  const clamped = Math.max(0, Math.min(100, percent))
  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-center justify-between gap-3 text-sm">
        <span className="text-muted-foreground">{label}</span>
        <span className="font-medium tabular-nums">{value}</span>
      </div>
      <div className="h-1 w-full overflow-hidden rounded-full bg-foreground/10">
        <div
          className="h-full rounded-full transition-all"
          style={{ width: `${clamped}%`, backgroundColor: barColor }}
        />
      </div>
    </div>
  )
}

"use client"

/**
 * Ranking visual — barras horizontales del top N.
 *
 * Existe como componente y no como un `<BarChart>` por página porque el mismo
 * gráfico lo piden todos los informes de ranking: categorías, marcas, medios
 * de pago y productos comparten la forma "un nombre, un número, ordenado
 * desc". Copiarlo en cada uno garantizaba que se fueran separando.
 *
 * ── Por qué horizontal ──────────────────────────────────────────────────────
 *
 * Los nombres del catálogo son largos ("Bebidas con Alcohol", "Materia
 * Prima"). En barras verticales esas etiquetas se rotan o se recortan; en
 * horizontales entran completas y el ojo lee la lista de arriba hacia abajo,
 * que es como se lee un ranking.
 *
 * ── Por qué top N y no todo ─────────────────────────────────────────────────
 *
 * Un gráfico con 80 categorías no se interpreta: se scrollea. El chart es
 * para leer la forma de la distribución —quién domina, dónde cae— y la tabla
 * de abajo es para el dato completo. Por eso el pie dice cuántos quedaron
 * afuera en vez de esconderlo.
 *
 * Color: `--chart-1` monocromático. La escala verde de charts es de marca y
 * NO se reparte por serie acá — cada barra es la misma magnitud medida en
 * artículos distintos, así que darles colores distintos sugeriría una
 * categorización que no existe (context/20 §charts).
 */

import * as React from "react"
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts"

import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"

export interface RankingDatum {
  label: string
  value: number
}

export function RankingBarChart({
  data,
  valueLabel,
  formatValue,
  limit = 10,
  emptyMessage = "Sin datos para graficar en este período.",
  className,
}: {
  data: RankingDatum[]
  /** Nombre de la magnitud — sale en el tooltip y en la leyenda del tooltip. */
  valueLabel: string
  formatValue: (v: number) => string
  limit?: number
  /** Qué decir cuando ninguna fila tiene un valor mayor a cero. */
  emptyMessage?: string
  className?: string
}) {
  const top = React.useMemo(
    () =>
      [...data]
        .filter((d) => Number.isFinite(d.value) && d.value > 0)
        .sort((a, b) => b.value - a.value)
        .slice(0, limit),
    [data, limit],
  )

  const config = React.useMemo(
    () => ({ value: { label: valueLabel, color: "var(--chart-1)" } }) satisfies ChartConfig,
    [valueLabel],
  )

  // Sin barras se dice por qué, en vez de pintar un eje solo. Pasa de verdad:
  // un comercio que no carga marcas deja el corte por marca vacío aunque
  // tenga ventas.
  if (top.length === 0) {
    return <p className="text-sm text-muted-foreground">{emptyMessage}</p>
  }

  const hidden = data.filter((d) => d.value > 0).length - top.length

  return (
    <div className={className}>
      <ChartContainer
        config={config}
        // Alto proporcional a la cantidad de barras: con una altura fija, tres
        // barras quedan gordas y diez apretadas.
        style={{ height: `${Math.max(160, top.length * 34 + 40)}px` }}
        className="w-full"
      >
        <BarChart
          data={top}
          layout="vertical"
          margin={{ top: 4, right: 16, left: 4, bottom: 4 }}
        >
          <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" horizontal={false} />
          <XAxis
            type="number"
            tickFormatter={formatValue}
            fontSize={10}
            stroke="var(--muted-foreground)"
            tickLine={false}
            axisLine={false}
          />
          <YAxis
            type="category"
            dataKey="label"
            width={140}
            fontSize={11}
            stroke="var(--muted-foreground)"
            tickLine={false}
            axisLine={false}
            // El nombre completo va en el tooltip; acá se corta para que el
            // eje no se coma el ancho del gráfico.
            tickFormatter={(v: string) => (v.length > 22 ? `${v.slice(0, 21)}…` : v)}
          />
          <ChartTooltip
            cursor={{ fill: "var(--accent)", opacity: 0.4 }}
            content={
              <ChartTooltipContent
                formatter={(value) => formatValue(Number(value))}
              />
            }
          />
          <Bar dataKey="value" fill="var(--color-value)" radius={[0, 4, 4, 0]} />
        </BarChart>
      </ChartContainer>
      {hidden > 0 && (
        <p className="mt-1 text-xs text-muted-foreground">
          Se muestran los {limit} primeros. Hay {hidden} más en la tabla.
        </p>
      )}
    </div>
  )
}

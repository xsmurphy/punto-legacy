"use client"

/**
 * Composición — dona con la parte que le toca a cada grupo.
 *
 * Es el gráfico para "dónde se concentra", no para "quién es el primero". Un
 * ranking se lee mejor en barras (comparar longitudes es más preciso que
 * comparar ángulos); una dona gana cuando la pregunta es qué porción del todo
 * se lleva cada uno.
 *
 * ── La cola se agrupa en "Otras", no se recorta ─────────────────────────────
 *
 * Es la diferencia entre una dona correcta y una que miente. Si se muestran
 * solo los 6 primeros de 40 categorías, las porciones se calculan sobre esos 6
 * y cada una se ve mucho más grande de lo que es: el gráfico afirma que la
 * primera categoría es el 40% del negocio cuando es el 12%. Agrupando el resto
 * en una porción, el todo sigue siendo el todo.
 *
 * ── Por qué pocos segmentos ─────────────────────────────────────────────────
 *
 * Con 15 porciones no se distingue ninguna y la leyenda tapa el gráfico. El
 * detalle completo está en la tabla; acá se lee la forma.
 *
 * Color: escala verde monocromática de charts (`--chart-1..5`), de más fuerte
 * a más apagado según el tamaño de la porción — el orden ES la jerarquía. La
 * cola va en el gris del borde para que se lea como "el resto" y no como un
 * grupo más (context/20 §charts).
 */

import * as React from "react"
import { Cell, Pie, PieChart } from "recharts"

import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"

/** Verde de charts, de la porción más grande a la más chica. */
const SLICE_COLORS = [
  "var(--chart-1)",
  "var(--chart-2)",
  "var(--chart-3)",
  "var(--chart-4)",
  "var(--chart-5)",
]
const REST_COLOR = "var(--border)"

export function CompositionDonutChart({
  data,
  formatValue,
  limit = 5,
  restLabel = "Otras",
  emptyMessage = "Sin datos para graficar en este período.",
}: {
  data: Array<{ label: string; value: number }>
  formatValue: (v: number) => string
  /** Cuántas porciones propias antes de agrupar la cola. */
  limit?: number
  restLabel?: string
  emptyMessage?: string
}) {
  const { slices, total } = React.useMemo(() => {
    const positivas = data
      .filter((d) => Number.isFinite(d.value) && d.value > 0)
      .sort((a, b) => b.value - a.value)
    const total = positivas.reduce((acc, d) => acc + d.value, 0)

    const top = positivas.slice(0, limit)
    const colaValor = positivas.slice(limit).reduce((acc, d) => acc + d.value, 0)

    const slices = top.map((d, i) => ({
      key: d.label,
      value: d.value,
      fill: SLICE_COLORS[i] ?? SLICE_COLORS[SLICE_COLORS.length - 1],
    }))
    if (colaValor > 0) {
      slices.push({ key: restLabel, value: colaValor, fill: REST_COLOR })
    }
    return { slices, total }
  }, [data, limit, restLabel])

  const config = React.useMemo<ChartConfig>(() => {
    const c: ChartConfig = {}
    for (const s of slices) c[s.key] = { label: s.key, color: s.fill }
    return c
  }, [slices])

  if (slices.length === 0) {
    return <p className="text-sm text-muted-foreground">{emptyMessage}</p>
  }

  return (
    <div className="flex flex-col gap-3">
      <ChartContainer config={config} className="mx-auto aspect-square h-[200px]">
        <PieChart>
          <ChartTooltip
            content={
              <ChartTooltipContent
                nameKey="key"
                hideLabel
                formatter={(value, name) => {
                  const n = Number(value)
                  const pct = total > 0 ? (n / total) * 100 : 0
                  return `${name}: ${formatValue(n)} · ${pct.toFixed(1)}%`
                }}
              />
            }
          />
          <Pie
            data={slices}
            dataKey="value"
            nameKey="key"
            innerRadius="62%"
            outerRadius="100%"
            paddingAngle={2}
            strokeWidth={0}
          >
            {slices.map((s) => (
              <Cell key={s.key} fill={s.fill} />
            ))}
          </Pie>
        </PieChart>
      </ChartContainer>

      {/* Leyenda propia y no `ChartLegend`: con el monto al lado del nombre se
          lee sin pasar por el tooltip, que en una tablet del mostrador no
          siempre está a mano. */}
      <ul className="flex flex-col gap-1 text-xs">
        {slices.map((s) => (
          <li key={s.key} className="flex items-center gap-2">
            <span
              className="size-2 shrink-0 rounded-[2px]"
              style={{ backgroundColor: s.fill }}
              aria-hidden
            />
            <span className="min-w-0 flex-1 truncate">{s.key}</span>
            <span className="shrink-0 tabular-nums text-muted-foreground">
              {total > 0 ? `${((s.value / total) * 100).toFixed(1)}%` : "—"}
            </span>
            <span className="shrink-0 tabular-nums font-medium">
              {formatValue(s.value)}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}

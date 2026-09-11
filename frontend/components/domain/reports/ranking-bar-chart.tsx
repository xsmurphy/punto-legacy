"use client"

/**
 * Ranking visual — columnas del top N.
 *
 * Existe como componente y no como un `<BarChart>` por página porque el mismo
 * gráfico lo piden todos los informes de ranking: categorías, marcas, medios
 * de pago y productos comparten la forma "un nombre, un número, ordenado
 * desc". Copiarlo en cada uno garantizaba que se fueran separando.
 *
 * ── Verticales, con las etiquetas rotadas ───────────────────────────────────
 *
 * Decisión del owner (2026-09-10). El costo conocido es que los nombres del
 * catálogo son largos ("Bebidas con Alcohol"), así que el eje los rota y los
 * trunca; el nombre completo vive en el tooltip, que es donde se lee sin
 * pelearse con el espacio.
 *
 * ── Por qué top N y no todo ─────────────────────────────────────────────────
 *
 * Un gráfico con 80 categorías no se interpreta: se scrollea. El chart es
 * para leer la forma de la distribución —quién domina, dónde cae— y la tabla
 * de abajo es para el dato completo. Por eso el pie dice cuántos quedaron
 * afuera en vez de esconderlo.
 *
 * ── La serie superpuesta ────────────────────────────────────────────────────
 *
 * `overlay` apila una porción DENTRO de la barra (ej. la utilidad dentro de la
 * facturación). Va primera en el stack, o sea que arranca en cero en todas las
 * barras: así se comparan entre sí de un vistazo. Apilarla al revés —costo
 * abajo, utilidad arriba— haría que cada segmento de utilidad empezara a una
 * altura distinta y el ojo no puede comparar eso.
 *
 * Solo se superpone algo que COMPARTE unidad y escala con el total. Un
 * porcentaje (el margen) no: necesitaría un segundo eje, y dos escalas
 * elegidas a mano hacen que "una supera a la otra" no signifique nada. El
 * margen va al tooltip.
 *
 * Color: escala verde monocromática de charts. Las barras del ranking son la
 * misma magnitud medida en artículos distintos, así que darles colores
 * distintos sugeriría una categorización que no existe (context/20 §charts).
 */

import * as React from "react"
import { Bar, BarChart, CartesianGrid, Cell, XAxis, YAxis } from "recharts"

import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"

export interface RankingDatum {
  label: string
  /** Magnitud que ordena el ranking y define el alto de la barra. */
  value: number
  /** Porción de `value` a resaltar dentro de la barra (ver `overlay`). */
  overlayValue?: number
  /**
   * La porción no se pudo calcular: falta el dato, no es que valga cero.
   *
   * Sin esto la barra se pinta entera del color de la porción y el gráfico
   * AFIRMA algo que no sabe — en el reporte de artículos, un costo que nadie
   * cargó se vería como margen del 100%.
   */
  overlayUnknown?: boolean
}

/**
 * Top N ordenado desc, con la barra partida en `part` + `rest`.
 *
 * Función pura y exportada porque es donde vive la aritmética del gráfico —
 * y donde estuvo el bug de las barras vacías: sin `overlay`, `part` salía 0 y
 * el chart no dibujaba nada, sin tirar ningún error. Un gráfico que falla en
 * silencio necesita un test, y un test necesita que esto no esté adentro del
 * render.
 */
export function buildRankingSlices(
  data: RankingDatum[],
  limit: number,
  hasOverlay: boolean,
): Array<RankingDatum & { part: number; rest: number }> {
  return [...data]
    .filter((d) => Number.isFinite(d.value) && d.value > 0)
    .sort((a, b) => b.value - a.value)
    .slice(0, limit)
    .map((d) => {
      // Sin `overlay` la barra es UNA sola y vale el total.
      if (!hasOverlay) return { ...d, part: d.value, rest: 0 }
      // Con `overlay`, el resto se DERIVA del total en vez de pasarse aparte:
      // así la barra siempre suma exactamente el valor que ordena el ranking,
      // aunque el desglose de costos no cierre (descuento, comisión).
      const part = Math.max(0, Math.min(d.overlayValue ?? 0, d.value))
      return { ...d, part, rest: d.value - part }
    })
}


/** Corta el nombre para el eje; el completo va en el tooltip. */
function shortLabel(v: string): string {
  return v.length > 14 ? `${v.slice(0, 13)}…` : v
}

export function RankingBarChart({
  data,
  valueLabel,
  formatValue,
  overlay,
  limit = 10,
  emptyMessage = "Sin datos para graficar en este período.",
  className,
}: {
  data: RankingDatum[]
  /** Nombre de la magnitud — sale en el tooltip. */
  valueLabel: string
  formatValue: (v: number) => string
  /**
   * Resalta una porción de cada barra. `label` nombra la porción; el resto de
   * la barra se pinta apagado y se nombra con `restLabel`.
   */
  overlay?: { label: string; restLabel: string }
  limit?: number
  /** Qué decir cuando ninguna fila tiene un valor mayor a cero. */
  emptyMessage?: string
  className?: string
}) {
  const hasOverlay = overlay !== undefined
  const top = React.useMemo(
    () => buildRankingSlices(data, limit, hasOverlay),
    [data, limit, hasOverlay],
  )

  const config = React.useMemo<ChartConfig>(() => {
    const c: ChartConfig = {
      part: {
        label: overlay ? overlay.label : valueLabel,
        color: "var(--chart-1)",
      },
    }
    if (overlay) {
      // `--chart-5` y no `--chart-4`: los dos son verdes de la escala de marca,
      // pero el 4 queda tan cerca del 1 que el corte entre las dos porciones no
      // se distingue (reportado por el owner 2026-09-10). El 5 es el extremo
      // oscuro, o sea el mayor contraste posible sin salirse de la paleta.
      c.rest = { label: overlay.restLabel, color: "var(--chart-5)" }
    }
    return c
  }, [overlay, valueLabel])

  // Sin barras se dice por qué, en vez de pintar un eje solo. Pasa de verdad:
  // un comercio que no carga marcas deja el corte por marca vacío aunque
  // tenga ventas.
  if (top.length === 0) {
    return <p className="text-sm text-muted-foreground">{emptyMessage}</p>
  }

  const hidden = data.filter((d) => d.value > 0).length - top.length

  return (
    <div className={className}>
      <ChartContainer config={config} className="h-[280px] w-full">
        <BarChart data={top} margin={{ top: 8, right: 8, left: -8, bottom: 44 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
          <XAxis
            dataKey="label"
            tickFormatter={shortLabel}
            angle={-35}
            textAnchor="end"
            interval={0}
            height={56}
            fontSize={10}
            stroke="var(--muted-foreground)"
            tickLine={false}
            axisLine={false}
          />
          <YAxis
            tickFormatter={formatValue}
            fontSize={10}
            width={72}
            stroke="var(--muted-foreground)"
            tickLine={false}
            axisLine={false}
          />
          <ChartTooltip
            cursor={{ fill: "var(--accent)", opacity: 0.4 }}
            content={
              <ChartTooltipContent
                // El nombre completo, sin el truncado del eje.
                labelFormatter={(_, payload) =>
                  String(payload?.[0]?.payload?.label ?? "")
                }
                formatter={(value, name) => {
                  const n = Number(value)
                  const label = config[String(name)]?.label ?? name
                  return `${label}: ${formatValue(n)}`
                }}
              />
            }
          />
          {/* `part` primero: arranca en cero en todas las barras, así las
              porciones se comparan entre sí. Sin `overlay` es la barra
              entera. */}
          <Bar dataKey="part" stackId="v" radius={overlay ? 0 : [4, 4, 0, 0]}>
            {top.map((d) => (
              <Cell
                key={d.label}
                // Dato faltante en gris, NO en el color de la porción: una
                // barra sin costo cargado pintada de "utilidad" afirma un
                // margen perfecto que nadie midió.
                fill={d.overlayUnknown ? "var(--muted-foreground)" : "var(--color-part)"}
                fillOpacity={d.overlayUnknown ? 0.35 : 1}
              />
            ))}
          </Bar>
          {overlay && (
            <Bar dataKey="rest" stackId="v" fill="var(--color-rest)" radius={[4, 4, 0, 0]} />
          )}
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
